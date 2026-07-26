<?php

namespace App\Command;

use App\Service\EncodingReminderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-083 — rappel unique d'encodage D+1, au plus tôt 08 h Europe/Brussels.
 *
 * Orchestre uniquement (sélection → traitement isolé par mission → résumé) ; toute la
 * décision (éligibilité, canal, idempotence) vit dans EncodingReminderService.
 *
 * Cron recommandé : toutes les 15-30 min (garde métier interne ci-dessous, pas une
 * exécution unique à 08h00 pile — évite toute dérive été/hiver si le cron serveur est en
 * UTC fixe, voir docs/production.md). Une exécution avant 08h00 locale ne fait rien.
 *   php bin/console app:notifications:send-encoding-reminders
 */
#[AsCommand(
    name: 'app:notifications:send-encoding-reminders',
    description: "Envoie le rappel d'encodage D+1 (Push, repli email) aux missions éligibles, au plus tôt 08h Europe/Brussels."
)]
class SendEncodingRemindersCommand extends Command
{
    private const TIMEZONE = 'Europe/Brussels';
    private const EARLIEST_HOUR = 8;

    public function __construct(
        private readonly EncodingReminderService $service,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /** Overridden in tests to freeze "now" — no Clock abstraction exists in this codebase yet. */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->now();

        if ((int) $now->format('G') < self::EARLIEST_HOUR) {
            $io->success(sprintf(
                'Trop tôt (%s, avant 08h00 Europe/Brussels) — aucun rappel envoyé.',
                $now->format('H:i'),
            ));

            return Command::SUCCESS;
        }

        $missions = $this->service->findEligibleMissions($now);

        $counts = ['eligible' => count($missions), 'push_sent' => 0, 'email_sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($missions as $mission) {
            try {
                match ($this->service->processMission($mission, $now)) {
                    'push'  => $counts['push_sent']++,
                    'email' => $counts['email_sent']++,
                    default => $counts['skipped']++,
                };
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->logger->error('encoding_reminder.failed', [
                    'missionId' => $mission->getId(),
                    'reason'    => $e->getMessage(),
                ]);
            }
        }

        $io->success(sprintf(
            'eligible=%d push_sent=%d email_sent=%d skipped=%d failed=%d',
            $counts['eligible'],
            $counts['push_sent'],
            $counts['email_sent'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return Command::SUCCESS;
    }
}
