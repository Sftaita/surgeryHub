<?php

namespace App\Command;

use App\Activity\ActivityJournal;
use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\ReleasedRoomSlotStatus;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\ReleasedRoomSlotTakeoverService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * D-177 — administrative regularization of a take-over made BEFORE the take-over feature
 * linked rooms and missions (4 Nov incident: surgeon request #1 → mission #626, while the
 * released rooms of that day stayed AVAILABLE and could be taken over a second time).
 *
 * Links ONE released room to ONE existing, live mission of another surgeon: the room becomes
 * CLAIMED by that mission's surgeon with that mission as its take-over mission. Nothing else:
 * the mission (status, instrumentist, hours, encoding) is never touched, no mission is
 * created, no priority starts, no notification is sent. Audited as
 * ROOM_SLOT_TAKEOVER_REGULARIZED (real date of the regularization, request reference).
 *
 * Dry-run by default (read-only, prints every check and the exact change); `--execute`
 * applies it in one transaction under the room's row lock (re-checked AVAILABLE); `--rollback`
 * undoes exactly a regularization (room back to AVAILABLE, ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED),
 * and refuses if anything happened to the room since. Idempotent: a second --execute finds
 * the room CLAIMED and changes nothing.
 */
#[AsCommand(name: 'app:released-rooms:regularize-takeover', description: 'D-177 — links a released room to an existing take-over mission (dry-run by default).')]
final class RegularizeRoomTakeoverCommand extends Command
{
    public const REASON = 'Régularisation administrative d\'une reprise antérieure';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReleasedRoomSlotTakeoverService $takeovers,
        private readonly MissionEligibilityService $eligibility,
        private readonly AuditService $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('slot', null, InputOption::VALUE_REQUIRED, 'Released room (released_operating_room_slot.id)')
            ->addOption('mission', null, InputOption::VALUE_REQUIRED, 'Existing take-over mission (mission.id)')
            ->addOption('request', null, InputOption::VALUE_REQUIRED, 'Surgeon request at the origin of the mission (surgeon_mission_request.id)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Apply the regularization (default: dry-run, read-only)')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Undo a previous regularization of this room / mission');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slot = $this->em->find(ReleasedOperatingRoomSlot::class, (int) $input->getOption('slot'));
        $mission = $this->em->find(Mission::class, (int) $input->getOption('mission'));
        $requestId = $input->getOption('request') !== null ? (int) $input->getOption('request') : null;
        $request = $requestId !== null ? $this->em->find(SurgeonMissionRequest::class, $requestId) : null;
        $execute = (bool) $input->getOption('execute');
        $rollback = (bool) $input->getOption('rollback');

        if ($slot === null || $mission === null || ($requestId !== null && $request === null)) {
            $output->writeln('<error>Room, mission or request not found.</error>');
            return Command::INVALID;
        }

        $this->printState($output, $slot, $mission, $request);

        if ($rollback) {
            return $this->rollback($output, $slot, $mission, $execute);
        }

        $checks = $this->checks($slot, $mission, $request);
        $output->writeln('');
        $output->writeln('Safety checks:');
        $ok = true;
        foreach ($checks as [$label, $passed, $detail]) {
            $ok = $ok && $passed;
            $output->writeln(sprintf('  [%s] %s%s', $passed ? 'OK' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : ''));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Planned change: released_operating_room_slot #%d status AVAILABLE → CLAIMED, claimed_by_id NULL → %d, claimed_at NULL → <now>, takeover_mission_id NULL → %d ; audit ROOM_SLOT_TAKEOVER_REGULARIZED on mission #%d. Mission #%d unchanged. No notification, no priority.',
            $slot->getId(), $mission->getSurgeon()?->getId(), $mission->getId(), $mission->getId(), $mission->getId(),
        ));

        if (!$ok) {
            $output->writeln('<error>At least one check failed: nothing done.</error>');
            return Command::FAILURE;
        }
        if (!$execute) {
            $output->writeln('<info>Dry-run: nothing written. Re-run with --execute to apply.</info>');
            return Command::SUCCESS;
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($slot, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($slot);
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($mission);

            $recheck = array_filter($this->checks($slot, $mission, $request), static fn (array $c) => !$c[1]);
            if ($recheck !== []) {
                $connection->rollBack();
                $output->writeln('<error>State changed under lock: ' . implode('; ', array_map(static fn (array $c) => $c[0], $recheck)) . ' — nothing done.</error>');
                return Command::FAILURE;
            }

            $now = new \DateTimeImmutable();
            $slot->markClaimed($mission->getSurgeon(), $mission, $now);
            $this->audit->record($mission, $this->systemActor(), AuditEventType::ROOM_SLOT_TAKEOVER_REGULARIZED, $this->snapshot($slot, $mission, $request) + [
                'reason'        => self::REASON,
                'regularizedAt' => $now->format(\DateTimeInterface::ATOM),
            ]);

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $output->writeln(sprintf('<info>Done: room #%d CLAIMED by %s, take-over mission #%d.</info>', $slot->getId(), $mission->getSurgeon()?->getDrName(), $mission->getId()));

        return Command::SUCCESS;
    }

    /** @return list<array{0: string, 1: bool, 2: string}> */
    private function checks(ReleasedOperatingRoomSlot $slot, Mission $mission, ?SurgeonMissionRequest $request): array
    {
        $interval = $this->takeovers->slotInterval($slot);
        $missionDay = $mission->getStartAt()?->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('Y-m-d');
        $source = $slot->getSurgeon();
        $alreadyLinked = (int) $this->em->createQuery(
            'SELECT COUNT(s.id) FROM App\Entity\ReleasedOperatingRoomSlot s WHERE s.takeoverMission = :m AND s.id <> :id'
        )->setParameter('m', $mission)->setParameter('id', $slot->getId())->getSingleScalarResult();

        return [
            ['room is AVAILABLE', $slot->getStatus() === ReleasedRoomSlotStatus::AVAILABLE, 'status ' . $slot->getStatus()->value],
            ['room has no take-over mission', $slot->getTakeoverMission() === null && $slot->getClaimedBy() === null, ''],
            ['room date not past', $slot->getOccurrenceDate() >= new \DateTimeImmutable('today'), $slot->getOccurrenceDate()->format('Y-m-d')],
            ['mission is live (not DRAFT / CANCELLED)', !in_array($mission->getStatus(), [MissionStatus::DRAFT, MissionStatus::CANCELLED], true), 'status ' . $mission->getStatus()->value],
            ['same site', $mission->getSite()?->getId() === $slot->getSite()?->getId(), sprintf('mission %s / room %s', $mission->getSite()?->getId(), $slot->getSite()?->getId())],
            ['same day', $missionDay === $slot->getOccurrenceDate()->format('Y-m-d'), sprintf('mission %s / room %s', $missionDay, $slot->getOccurrenceDate()->format('Y-m-d'))],
            ['mission hours overlap the room', $interval !== null && $mission->getStartAt() < $interval[1] && $mission->getEndAt() > $interval[0],
                $interval !== null ? sprintf('mission %s–%s / room %s–%s', $mission->getStartAt()?->format('H:i'), $mission->getEndAt()?->format('H:i'), $interval[0]->format('H:i'), $interval[1]->format('H:i')) : 'room hours unknown'],
            ['mission surgeon is not the absent surgeon', $mission->getSurgeon() !== null && $mission->getSurgeon()->getId() !== $source?->getId(), ''],
            ['absent surgeon still absent that day', $source !== null && $this->eligibility->findBlockingAbsence($source, $slot->getOccurrenceDate()) !== null, ''],
            ['mission not already linked to another room', $alreadyLinked === 0, $alreadyLinked > 0 ? "{$alreadyLinked} room(s)" : ''],
            ['request (if given) created this mission', $request === null || $request->getCreatedMission()?->getId() === $mission->getId(), $request !== null ? 'request #' . $request->getId() : 'no request given'],
        ];
    }

    private function rollback(OutputInterface $output, ReleasedOperatingRoomSlot $slot, Mission $mission, bool $execute): int
    {
        /** @var array{id: int|string, event_type: string}|null $last */
        $last = $this->em->getConnection()->fetchAssociative(
            "SELECT id, event_type FROM audit_event
              WHERE event_type LIKE 'ROOM\\_SLOT\\_%' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.roomSlotId')) = ?
              ORDER BY id DESC LIMIT 1",
            [(string) $slot->getId()],
        ) ?: null;

        $ok = $slot->getStatus() === ReleasedRoomSlotStatus::CLAIMED
            && $slot->getTakeoverMission()?->getId() === $mission->getId()
            && ($last['event_type'] ?? null) === AuditEventType::ROOM_SLOT_TAKEOVER_REGULARIZED->value;

        $output->writeln('');
        $output->writeln(sprintf('Rollback check: room CLAIMED by this mission and last room event is the regularization — %s', $ok ? 'OK' : 'FAIL'));
        if (!$ok) {
            $output->writeln('<error>Not a pristine regularization (or already rolled back): nothing done.</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Planned change: room #%d CLAIMED → AVAILABLE (claimed_by_id, claimed_at, takeover_mission_id → NULL) ; audit ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED. Mission #%d unchanged.', $slot->getId(), $mission->getId()));
        if (!$execute) {
            $output->writeln('<info>Dry-run: nothing written. Re-run with --rollback --execute to apply.</info>');
            return Command::SUCCESS;
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($slot, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($slot);
            if ($slot->getStatus() !== ReleasedRoomSlotStatus::CLAIMED || $slot->getTakeoverMission()?->getId() !== $mission->getId()) {
                $connection->rollBack();
                $output->writeln('<error>State changed under lock — nothing done.</error>');
                return Command::FAILURE;
            }
            $slot->markAvailableAgain();
            $this->audit->record($mission, $this->systemActor(), AuditEventType::ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED, $this->snapshot($slot, $mission, null) + [
                'regularizationEventId' => (int) $last['id'],
                'revertedAt'            => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $output->writeln(sprintf('<info>Rolled back: room #%d AVAILABLE again.</info>', $slot->getId()));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function snapshot(ReleasedOperatingRoomSlot $slot, Mission $mission, ?SurgeonMissionRequest $request): array
    {
        $instrumentist = $mission->getInstrumentist();

        return [
            'roomSlotId'              => $slot->getId(),
            'postId'                  => $slot->getPostId(),
            'occurrenceDate'          => $slot->getOccurrenceDate()->format('Y-m-d'),
            'period'                  => $slot->getPeriod()->value,
            'startTime'               => $slot->getStartTime()?->format('H:i'),
            'endTime'                 => $slot->getEndTime()?->format('H:i'),
            'siteId'                  => $slot->getSite()?->getId(),
            'siteName'                => $slot->getSite()?->getName(),
            'originalSurgeonId'       => $slot->getSurgeon()?->getId(),
            'originalSurgeonName'     => $slot->getSurgeon()?->getDrName(),
            'takenById'               => $mission->getSurgeon()?->getId(),
            'takenByName'             => $mission->getSurgeon()?->getDrName(),
            'surgeonMissionRequestId' => $request?->getId(),
            'missionStatus'           => $mission->getStatus()->value,
            'missionStartAt'          => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
            'missionEndAt'            => $mission->getEndAt()?->format(\DateTimeInterface::ATOM),
            'instrumentistId'         => $instrumentist?->getId(),
            'instrumentistName'       => $instrumentist !== null ? ActivityJournal::displayName($instrumentist) : null,
        ];
    }

    private function printState(OutputInterface $output, ReleasedOperatingRoomSlot $slot, Mission $mission, ?SurgeonMissionRequest $request): void
    {
        $output->writeln(sprintf(
            'Room #%d: site %s, %s %s %s–%s, released by %s (absence #%s), status %s, claimed_by %s, takeover_mission %s',
            $slot->getId(), $slot->getSite()?->getName(), $slot->getOccurrenceDate()->format('Y-m-d'), $slot->getPeriod()->value,
            $slot->getStartTime()?->format('H:i') ?? '?', $slot->getEndTime()?->format('H:i') ?? '?', $slot->getSurgeon()?->getDrName(),
            $slot->getSourceAbsence()?->getId() ?? '-', $slot->getStatus()->value, $slot->getClaimedBy()?->getId() ?? 'NULL',
            $slot->getTakeoverMission()?->getId() ?? 'NULL',
        ));
        $output->writeln(sprintf(
            'Mission #%d: %s, surgeon %s, instrumentist %s, %s → %s, site %s',
            $mission->getId(), $mission->getStatus()->value, $mission->getSurgeon()?->getDrName(),
            $mission->getInstrumentist() !== null ? ActivityJournal::displayName($mission->getInstrumentist()) : 'none',
            $mission->getStartAt()?->format('Y-m-d H:i'), $mission->getEndAt()?->format('H:i'), $mission->getSite()?->getName(),
        ));
        if ($request !== null) {
            $output->writeln(sprintf('Request #%d: %s, created mission #%s, comment: %s', $request->getId(), $request->getStatus(), $request->getCreatedMission()?->getId() ?? '-', $request->getComment() ?? ''));
        }
    }

    private function systemActor(): User
    {
        $system = $this->em->getRepository(User::class)->findOneBy(['email' => ActivityJournal::SYSTEM_USER_EMAIL]);
        if (!$system instanceof User) {
            throw new \RuntimeException('System actor not found.');
        }

        return $system;
    }
}
