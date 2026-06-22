<?php

namespace App\Command;

use App\Entity\PlanningDeployment;
use App\Enum\PlanningDeploymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovery command for PlanningDeployment rows left stuck on PROCESSING.
 *
 * PlanningDeployPdfsMessageHandler already marks a deployment FAILED on any catchable
 * exception (try/catch around the heavy work). It cannot do that for a PHP fatal —
 * "Allowed memory size exhausted" terminates the worker process immediately, before any
 * catch block runs, leaving the row on PROCESSING forever with hasError=false. This
 * command is the recovery side of that gap: it finds deployments that have been
 * PROCESSING for longer than a threshold (the worker is long gone by then) and marks
 * them FAILED with an explanatory errorLog, so they become visible to managers instead
 * of silently stuck.
 *
 * Safe to run repeatedly (only touches rows past the threshold) and safe to run
 * concurrently with a healthy worker (a deployment legitimately still PROCESSING within
 * the threshold window is left untouched).
 *
 * Not scheduled automatically yet — run manually or wire into cron/Scheduler:
 *   php bin/console app:planning-deployments:reconcile-stuck
 */
#[AsCommand(
    name: 'app:planning-deployments:reconcile-stuck',
    description: 'Mark PlanningDeployment rows stuck on PROCESSING (worker crash/OOM) as FAILED.'
)]
class PlanningDeploymentReconcileStuckCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'threshold-minutes',
            null,
            InputOption::VALUE_REQUIRED,
            'How long a deployment may stay PROCESSING before being considered stuck.',
            '10',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $thresholdMinutes = (int) $input->getOption('threshold-minutes');
        if ($thresholdMinutes < 1) {
            $io->error('threshold-minutes must be >= 1.');
            return Command::FAILURE;
        }

        $cutoff = (new \DateTimeImmutable())->modify("-{$thresholdMinutes} minutes");

        $stuck = $this->em->createQueryBuilder()
            ->select('d')
            ->from(PlanningDeployment::class, 'd')
            ->where('d.status = :processing')
            ->andWhere('d.startedAt IS NOT NULL')
            ->andWhere('d.startedAt < :cutoff')
            ->setParameter('processing', PlanningDeploymentStatus::PROCESSING)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        if (empty($stuck)) {
            $io->success('No stuck deployments found.');
            return Command::SUCCESS;
        }

        foreach ($stuck as $deployment) {
            $deployment->setStatus(PlanningDeploymentStatus::FAILED);
            $deployment->setErrorLog(sprintf(
                'Marked failed by watchdog: worker did not report completion within %d minute(s) of starting ' .
                'at %s. The worker process likely crashed (e.g. PHP fatal/OOM during PDF generation) without ' .
                'reaching its own error handler. Re-deploy if the underlying issue has been addressed.',
                $thresholdMinutes,
                $deployment->getStartedAt()?->format(\DateTimeInterface::ATOM) ?? 'unknown',
            ));
        }

        $this->em->flush();

        $io->success(sprintf('Marked %d stuck deployment(s) as FAILED.', count($stuck)));

        return Command::SUCCESS;
    }
}
