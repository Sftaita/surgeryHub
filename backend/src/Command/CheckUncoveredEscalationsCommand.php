<?php

namespace App\Command;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Message\MissionUncoveredEscalationMessage;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-110 (J-14) — notifies the surgeon once per OPEN episode when a Mission is still
 * uncovered inside the 14-day-before-start window.
 *
 * Selects Missions where status=OPEN, startAt is still in the future, startAt is within
 * 14 days from now, and no escalation has been sent yet for the CURRENT OPEN episode
 * (mission.uncoveredEscalationSentAt IS NULL — reset by MissionPostDeployService every
 * time a Mission truly leaves OPEN, so a later, genuinely new episode can escalate again).
 * Deliberately NOT "startAt is exactly 14 days out": a Mission that becomes OPEN directly
 * inside the window (e.g. released at day 7) is escalated at the very next run, not held
 * until some exact date that has already passed.
 *
 * Idempotent by construction: mission-by-mission, each candidate is re-validated under a
 * pessimistic write lock (MissionPostDeployService::markUncoveredEscalationSent(), same
 * convention as claim()/start()) immediately before marking it — so running this command
 * twice in a row, or two overlapping invocations (cron + a manual run), never double-sends.
 * A single Mission's failure is logged and skipped; it never aborts the rest of the batch.
 *
 * Not scheduled automatically yet — run manually or wire into cron/systemd (recommended:
 * daily, e.g. 07:00 Europe/Brussels — see docs/production.md):
 *   php bin/console app:planning:check-uncovered-escalations
 */
#[AsCommand(
    name: 'app:planning:check-uncovered-escalations',
    description: 'Notify the surgeon once per OPEN episode when a Mission is still uncovered within 14 days of its start (cron, daily).'
)]
class CheckUncoveredEscalationsCommand extends Command
{
    private const SYSTEM_ACTOR_EMAIL = 'system@surgicalhub.internal';
    private const ESCALATION_WINDOW_DAYS = 14;

    // Same rationale as MissionStartDueCommand: Mission.startAt is persisted as a
    // timezone-naive wall-clock value already treated as Europe/Brussels throughout this
    // codebase — "now" must use the same timezone for the window comparison to be correct
    // around DST transitions.
    private const MISSION_TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $missionPostDeployService,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $systemActor = $this->em->getRepository(User::class)
            ->findOneBy(['email' => self::SYSTEM_ACTOR_EMAIL]);
        if ($systemActor === null) {
            $io->error(sprintf(
                'System actor "%s" not found — run migrations (Version20260715064809).',
                self::SYSTEM_ACTOR_EMAIL,
            ));
            return Command::FAILURE;
        }

        $now     = new \DateTimeImmutable('now', new \DateTimeZone(self::MISSION_TIMEZONE));
        $horizon = $now->modify('+' . self::ESCALATION_WINDOW_DAYS . ' days');

        $candidates = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.status = :open')
            ->andWhere('m.startAt IS NOT NULL')
            ->andWhere('m.startAt > :now')
            ->andWhere('m.startAt <= :horizon')
            ->andWhere('m.uncoveredEscalationSentAt IS NULL')
            ->setParameter('open', MissionStatus::OPEN)
            ->setParameter('now', $now)
            ->setParameter('horizon', $horizon)
            ->getQuery()
            ->getResult();

        if (empty($candidates)) {
            $io->success('No uncovered mission to escalate.');
            return Command::SUCCESS;
        }

        $escalated = 0;
        $skipped   = 0;
        $errored   = 0;

        foreach ($candidates as $mission) {
            try {
                $marked = $this->missionPostDeployService->markUncoveredEscalationSent($mission, $systemActor);

                if (!$marked) {
                    // Re-check after the lock found it no longer applicable — already
                    // handled by a concurrent run, or it left OPEN between the SELECT
                    // above and this mission's turn. Not an error.
                    $skipped++;
                    continue;
                }

                $surgeon = $mission->getSurgeon();
                if ($surgeon === null) {
                    // No one to notify — the marker is still correctly set (never
                    // re-escalated for this episode), just nothing to dispatch.
                    continue;
                }

                $site = $mission->getSite();
                $this->bus->dispatch(new MissionUncoveredEscalationMessage(
                    missionId:   $mission->getId(),
                    surgeonId:   $surgeon->getId(),
                    surgeonName: trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')),
                    siteId:      $site?->getId(),
                    siteName:    $site?->getName(),
                    startAt:     $mission->getStartAt()->format(\DateTimeInterface::ATOM),
                    occurredAt:  new \DateTimeImmutable(),
                ));

                $escalated++;
            } catch (\Throwable $e) {
                $errored++;
                $io->warning(sprintf('Mission #%d: %s', $mission->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Escalated %d mission(s), %d already handled by a concurrent run, %d error(s).',
            $escalated,
            $skipped,
            $errored,
        ));

        return Command::SUCCESS;
    }
}
