<?php

namespace App\Command;

use App\Service\EncodingReminderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-143 — relance progressive d'encodage J+1 / J+2 / J+4 à 09 h Europe/Brussels
 * (remplace le rappel unique D+1 08 h de D-083).
 *
 * Orchestre uniquement (garde horaire → sélection → traitement isolé par mission →
 * résumé) ; toute la décision (échéance, état, canaux, idempotence) vit dans
 * EncodingReminderService.
 *
 * Cron recommandé : toutes les 15 min (garde métier interne ci-dessous, pas une
 * exécution unique à 09h00 pile — aucune dérive été/hiver si le cron serveur est en
 * UTC, voir docs/production.md). Une exécution avant 09h00 locale ne fait rien ; une
 * exécution répétée n'envoie jamais deux fois la même échéance.
 *   php bin/console app:notifications:send-encoding-reminders
 *
 * Recette uniquement (jamais en prod) : `--at="2026-07-15 09:05"` fixe l'horloge (heure de
 * Bruxelles) pour exécuter le parcours complet à une échéance choisie, dans un
 * environnement isolé. Refusé quand kernel.environment = prod : l'horloge de production
 * n'est jamais modifiable.
 */
#[AsCommand(
    name: 'app:notifications:send-encoding-reminders',
    description: "Relance progressive d'encodage J+1/J+2/J+4 (09h Europe/Brussels) — push ou e-mail de secours, e-mail + managers à J+4."
)]
class SendEncodingRemindersCommand extends Command
{
    private const TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly EncodingReminderService $service,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('at', null, InputOption::VALUE_REQUIRED, 'Recette uniquement (hors prod) : horloge fixée, heure de Bruxelles (ex. "2026-07-15 09:05").');
    }

    /** Overridden in tests to freeze "now" — no Clock abstraction exists in this codebase yet. */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $at = $input->getOption('at');
        if ($at !== null) {
            if ($this->environment === 'prod') {
                $io->error("--at est réservé à la recette : l'horloge de production n'est jamais modifiable.");

                return Command::FAILURE;
            }
            $now = new \DateTimeImmutable((string) $at, new \DateTimeZone(self::TIMEZONE));
        } else {
            $now = $this->now()->setTimezone(new \DateTimeZone(self::TIMEZONE));
        }

        if ((int) $now->format('G') < EncodingReminderService::SEND_HOUR) {
            $io->success(sprintf(
                'Trop tôt (%s, avant %02dh00 Europe/Brussels) — aucune relance envoyée.',
                $now->format('H:i'),
                EncodingReminderService::SEND_HOUR,
            ));

            return Command::SUCCESS;
        }

        $due = $this->service->findDueReminders($now);

        $counts = ['due' => count($due), 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($due as ['mission' => $mission, 'stage' => $stage]) {
            try {
                $this->service->processReminder($mission, $stage, $now) === 'sent'
                    ? $counts['sent']++
                    : $counts['skipped']++;
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->logger->error('encoding_reminder.failed', [
                    'missionId' => $mission->getId(),
                    'stage'     => $stage->value,
                    'error'     => $e::class,
                ]);
            }
        }

        $io->success(sprintf(
            'due=%d sent=%d skipped=%d failed=%d',
            $counts['due'],
            $counts['sent'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return Command::SUCCESS;
    }
}
