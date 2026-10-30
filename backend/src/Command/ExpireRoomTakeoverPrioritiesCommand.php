<?php

namespace App\Command;

use App\Service\RoomTakeoverPriorityService;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * D-177 — backstop of the room take-over priority life cycle, meant for cron (every 5 min,
 * like app:missions:start-due). Ends every priority whose deadline is reached (EXPIRED) and
 * every priority whose recipient has an absence covering the mission (RECIPIENT_UNAVAILABLE),
 * then opens the pool — the same idempotent RoomTakeoverPriorityService transitions the
 * delayed message and the absence coordinator use. Re-running it changes nothing.
 */
#[AsCommand(name: 'app:room-takeover:expire-priorities', description: 'D-177 — ends due room take-over priorities and opens the pool.')]
final class ExpireRoomTakeoverPrioritiesCommand extends Command
{
    public function __construct(
        private readonly RoomTakeoverPriorityService $priorities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be ended, change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $due = $this->priorities->findDuePriorityIds(Clock::get()->now());
        $absent = array_values(array_diff($this->priorities->findPrioritiesWithAbsentRecipient(), $due));

        $output->writeln(sprintf('Priorities due: %d, recipient absent: %d%s', count($due), count($absent), $dryRun ? ' (dry-run)' : ''));
        if ($dryRun) {
            foreach ($due as $id) {
                $output->writeln("  would expire publication #{$id}");
            }
            foreach ($absent as $id) {
                $output->writeln("  would end publication #{$id} (recipient absent)");
            }

            return Command::SUCCESS;
        }

        $expired = 0;
        $ended = 0;
        $errors = 0;
        foreach ($due as $id) {
            try {
                $expired += $this->priorities->expire($id) ? 1 : 0;
            } catch (\Throwable $e) {
                $errors++;
                $output->writeln("<error>publication #{$id}: {$e->getMessage()}</error>");
            }
        }
        foreach ($absent as $id) {
            try {
                $ended += $this->priorities->endForUnavailableRecipient($id) ? 1 : 0;
            } catch (\Throwable $e) {
                $errors++;
                $output->writeln("<error>publication #{$id}: {$e->getMessage()}</error>");
            }
        }

        $output->writeln(sprintf('Expired: %d, ended (absence): %d, errors: %d', $expired, $ended, $errors));

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
