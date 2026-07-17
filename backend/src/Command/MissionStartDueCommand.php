<?php

namespace App\Command;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-064: transitions ASSIGNED missions to IN_PROGRESS once their startAt has passed.
 *
 * Nothing else in the codebase ever set Mission to IN_PROGRESS before this command —
 * the status existed in the enum (used by MissionVoter, PlanningCoverageService, etc.)
 * but had no way to be reached, so the "En cours" pill on the instrumentist's
 * Aujourd'hui hero card (docs/design/components/mission-hero.md) never actually showed
 * for real data. This command is the missing transition.
 *
 * Only missions still inside their [startAt, endAt) window are touched — an ASSIGNED
 * mission whose endAt has already passed (never submitted) is left alone rather than
 * being flipped to a stale "in progress" state; that's a separate overdue-encoding
 * concern, out of scope here.
 *
 * Not scheduled automatically yet — run manually or wire into cron/Scheduler
 * (recommended: every 5 minutes):
 *   php bin/console app:missions:start-due
 */
#[AsCommand(
    name: 'app:missions:start-due',
    description: 'Transition ASSIGNED missions to IN_PROGRESS once startAt has passed (cron, ~every 5min).'
)]
class MissionStartDueCommand extends Command
{
    private const SYSTEM_ACTOR_EMAIL = 'system@surgicalhub.internal';

    // Mission.startAt/endAt are DateTimeImmutable columns persisted with no timezone
    // conversion (Doctrine writes format('Y-m-d H:i:s') on whatever offset the PHP
    // object carries — verified empirically: a value submitted as "+02:00" is stored
    // as its own local wall-clock digits, offset dropped). MissionService's own
    // today-boundary logic already treats stored mission times as Europe/Brussels wall
    // clock (DEFAULT_TIMEZONE) for the same reason — "now" here must use the same
    // timezone, not the container's UTC default, or every comparison is off by the
    // DST offset (missions would look "not due yet" for hours after they actually start).
    private const MISSION_TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface   $em,
        private readonly MissionPostDeployService $missionPostDeployService,
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

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::MISSION_TIMEZONE));

        $due = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.status = :assigned')
            ->andWhere('m.startAt IS NOT NULL')
            ->andWhere('m.startAt <= :now')
            ->andWhere('m.endAt IS NULL OR m.endAt > :now')
            ->setParameter('assigned', MissionStatus::ASSIGNED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if (empty($due)) {
            $io->success('No mission to start.');
            return Command::SUCCESS;
        }

        foreach ($due as $mission) {
            $this->missionPostDeployService->start($mission, $systemActor);
        }

        $io->success(sprintf('Started %d mission(s).', count($due)));

        return Command::SUCCESS;
    }
}
