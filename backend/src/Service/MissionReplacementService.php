<?php

namespace App\Service;

use App\Activity\CorrelationContext;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Exception\MissionCancellationException;
use App\Message\MissionReassignmentNotificationMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-182 — « Mission remplacée par une autre mission » : a programme ended up with two missions
 * (the planned one, whose instrumentist could not come, and the one her replacement created).
 * The manager keeps the existing replacement mission and cancels the planned one as a
 * duplicate — no double count, nothing of the replacement touched.
 *
 * ONE transaction, both mission rows locked (in id order): the replacement is re-checked
 * (MissionCancellationPolicy::replacementIncompatibility, not already kept for another
 * mission, unchanged since the manager saw it), its declaration is approved through the
 * existing workflow when the manager asked for it (DECLARED → ASSIGNED, audited), and the
 * planned mission is cancelled (MissionPostDeployService::cancelByManager, reason
 * REPLACED_BY_MISSION, Mission::replacedBy). Any refusal rolls everything back: never a
 * cancellation without its replacement, never an approval without the cancellation.
 *
 * Notifications, after the commit, as ONE MissionReassignmentNotificationMessage (mode
 * REPLACED): the planned instrumentist (mission taken off her planning), the replacement
 * (mission kept and validated — only when an approval really happened) and the surgeon (change
 * of instrumentist, never a programme cancellation). No PLANNING_MISSION_CANCELLED, no
 * MISSION_DECLARED_APPROVED on top: never two contradictory emails for one programme.
 */
class MissionReplacementService
{
    public const MODE_REPLACED = 'REPLACED';

    private const TZ = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $postDeploy,
        private readonly MissionService $missions,
        private readonly MissionAssignmentService $assignments,
        private readonly AuditService $audit,
        private readonly MissionReassignmentService $reassignments,
        private readonly MessageBusInterface $bus,
        private readonly CorrelationContext $correlation,
    ) {
    }

    /**
     * The existing missions that may be kept in place of $mission: same day, site, surgeon,
     * overlapping hours, someone on it, not already kept for another cancelled mission.
     *
     * @return list<Mission>
     */
    public function candidates(Mission $mission): array
    {
        $start = $mission->getStartAt();
        if ($start === null || $mission->getSite() === null) {
            return [];
        }
        $day     = $start->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0);
        $nextDay = $day->modify('+1 day');

        /** @var list<Mission> $found */
        $found = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.site = :site AND m.id <> :id AND m.status IN (:statuses)
               AND m.startAt < :to AND m.endAt > :from
             ORDER BY m.startAt ASC, m.id ASC'
        )->setParameter('site', $mission->getSite())
         ->setParameter('id', (int) $mission->getId())
         ->setParameter('statuses', array_map(static fn (MissionStatus $s) => $s->value, MissionCancellationPolicy::REPLACEMENT_STATUSES))
         ->setParameter('from', $day)
         ->setParameter('to', $nextDay)
         ->getResult();

        $used = $this->replacementsAlreadyUsed(array_map(static fn (Mission $m) => (int) $m->getId(), $found), (int) $mission->getId());

        return array_values(array_filter(
            $found,
            static fn (Mission $m) => !in_array((int) $m->getId(), $used, true)
                && MissionCancellationPolicy::replacementIncompatibility($mission, $m) === null,
        ));
    }

    /**
     * @param string|null $expectedReplacementStatus the replacement's status the manager saw:
     *        when it changed meanwhile, nothing is done (409 CANCELLATION_REPLACEMENT_CHANGED)
     * @param bool $correctionConfirmed the planned mission's submitted / validated encoding is
     *        first corrected (MissionCancellationPolicy::correction()), in this same transaction
     * @return array{operationId: string, cancelledMissionId: int, replacementMissionId: int, approved: bool, recipients: list<array<string,mixed>>}
     */
    public function cancelAsReplaced(
        User $actor,
        Mission $cancelled,
        int $replacementId,
        bool $approveReplacement,
        ?string $expectedReplacementStatus = null,
        ?string $internalComment = null,
        bool $correctionConfirmed = false,
    ): array {
        $replacement = $this->em->find(Mission::class, $replacementId);
        if ($replacement === null) {
            throw new NotFoundHttpException('Mission de remplacement introuvable.');
        }

        $fromId   = $cancelled->getInstrumentist()?->getId();
        $approved = false;

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            [$first, $second] = (int) $cancelled->getId() < (int) $replacement->getId() ? [$cancelled, $replacement] : [$replacement, $cancelled];
            $approved = $this->assignments->inLockedChange($first, fn (): bool => $this->assignments->inLockedChange($second,
                fn (): bool => $this->apply($actor, $cancelled, $replacement, $approveReplacement, $expectedReplacementStatus, $internalComment, $correctionConfirmed)));
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            // Nothing half-done stays in memory either (same request, later reads).
            foreach ([$cancelled, $replacement] as $mission) {
                if ($this->em->contains($mission)) {
                    $this->em->refresh($mission);
                }
            }
            throw $e;
        }

        $change = [
            'mode'                => self::MODE_REPLACED,
            'missionId'           => (int) $replacement->getId(),
            'originalMissionId'   => (int) $cancelled->getId(),
            'fromInstrumentistId' => $fromId,
            'toInstrumentistId'   => (int) $replacement->getInstrumentist()?->getId(),
            'surgeonId'           => $cancelled->getSurgeon()?->getId(),
            'startAt'             => $replacement->getStartAt()?->format(\DateTimeInterface::ATOM),
            'endAt'               => $replacement->getEndAt()?->format(\DateTimeInterface::ATOM),
            'approved'            => $approved,
        ];
        $operationId = $this->correlation->correlationId();
        $this->bus->dispatch(new MissionReassignmentNotificationMessage($operationId, (int) $actor->getId(), [$change]));

        return [
            'operationId'          => $operationId,
            'cancelledMissionId'   => (int) $cancelled->getId(),
            'replacementMissionId' => (int) $replacement->getId(),
            'approved'             => $approved,
            'recipients'           => $this->reassignments->recipients([$change]),
        ];
    }

    /** Runs with both rows locked, in the caller's transaction; returns whether an approval happened. */
    private function apply(User $actor, Mission $cancelled, Mission $replacement, bool $approveReplacement, ?string $expectedReplacementStatus, ?string $internalComment, bool $correctionConfirmed): bool
    {
        $context = ['missionId' => $cancelled->getId(), 'replacementMissionId' => $replacement->getId()];

        if ($expectedReplacementStatus !== null && $expectedReplacementStatus !== $replacement->getStatus()->value) {
            throw new MissionCancellationException(MissionCancellationPolicy::REPLACEMENT_CHANGED,
                'La mission de remplacement a été modifiée entre-temps : rechargez la liste avant de recommencer.', $context + ['status' => $replacement->getStatus()->value]);
        }
        $incompatibility = MissionCancellationPolicy::replacementIncompatibility($cancelled, $replacement);
        if ($incompatibility !== null) {
            throw new MissionCancellationException(MissionCancellationPolicy::REPLACEMENT_INVALID, $incompatibility, $context);
        }
        if ($this->replacementsAlreadyUsed([(int) $replacement->getId()], (int) $cancelled->getId()) !== []) {
            throw new MissionCancellationException(MissionCancellationPolicy::REPLACEMENT_INVALID,
                'Cette mission est déjà conservée à la place d\'une autre mission annulée.', $context);
        }
        // The planned mission must be cancellable before anything is changed on the replacement.
        $blocked = MissionCancellationPolicy::blockedReason($cancelled, $this->postDeploy->prestationSize($cancelled));
        if ($blocked !== null) {
            throw new MissionCancellationException($blocked['code'], $blocked['message'], $context + ['status' => $cancelled->getStatus()->value]);
        }

        $previousStatus = $replacement->getStatus();
        $approved = false;
        if ($approveReplacement && $previousStatus === MissionStatus::DECLARED) {
            $this->missions->applyApproveDeclared($replacement, $actor);
            $approved = true;
        }

        $comment = MissionPostDeployService::cleanComment($internalComment);
        $this->audit->record($replacement, $actor, AuditEventType::MISSION_KEPT_AS_REPLACEMENT, [
            'cancelledMissionId'    => $cancelled->getId(),
            'previousStatus'        => $previousStatus->value,
            'missionStatus'         => $replacement->getStatus()->value,
            'approved'              => $approved,
            'fromInstrumentistId'   => $cancelled->getInstrumentist()?->getId(),
            'instrumentistId'       => $replacement->getInstrumentist()?->getId(),
            'actorId'               => $actor->getId(),
        ] + ($comment !== null ? ['internalComment' => $comment] : []));

        // Flushes both missions (and the approval audit) in this same transaction.
        $this->postDeploy->cancelByManager(
            $cancelled, $actor,
            MissionCancellationPolicy::REASON_REPLACED_BY_MISSION,
            notify: false,
            replacement: $replacement,
            internalComment: $comment,
            correctionConfirmed: $correctionConfirmed,
        );

        return $approved;
    }

    /**
     * Among $missionIds, those already kept in place of another cancelled mission than $exceptCancelledId.
     *
     * @param list<int> $missionIds
     * @return list<int>
     */
    private function replacementsAlreadyUsed(array $missionIds, int $exceptCancelledId): array
    {
        if ($missionIds === []) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->executeQuery(
            'SELECT DISTINCT replaced_by_mission_id FROM mission WHERE replaced_by_mission_id IN (?) AND id <> ?',
            [$missionIds, $exceptCancelledId],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
        )->fetchFirstColumn());
    }
}
