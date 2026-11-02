<?php

namespace App\Command;

use App\Service\MissionScheduleLinkBackfillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-156 — links the missions generated before the stable post ↔ mission identity to their
 * post occurrence (MissionScheduleLinkBackfillService). Without --apply: check only, nothing
 * is written — every planned link with its class, and the ambiguous ones left to review.
 *
 * Exit codes: 0 = nothing to link, or links written; 1 = links pending (check);
 * 2 = invalid option. Idempotent: a second --apply finds only the TO_REVIEW rows.
 */
#[AsCommand(
    name: 'app:mission:link-schedule-posts',
    description: 'Rattache les anciennes missions générées à leur occurrence de poste (D-156) — vérification seule sans --apply.',
)]
final class MissionScheduleLinkCommand extends Command
{
    public function __construct(private readonly MissionScheduleLinkBackfillService $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Premier mois traité (YYYY-MM).');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Dernier mois traité, inclus (YYYY-MM).');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limite aux sites (id, répétable) ; par défaut tous les sites ayant un poste.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les liens CLEAR et INTERCHANGEABLE (une transaction) ; jamais les TO_REVIEW.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = \DateTimeImmutable::createFromFormat('!Y-m', (string) $input->getOption('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m', (string) $input->getOption('to'));
        if ($from === false || $to === false || $to < $from) {
            $io->error('--from et --to sont obligatoires (YYYY-MM), --to postérieur ou égal à --from.');

            return Command::INVALID;
        }
        $siteIds = null;
        if ($input->getOption('site') !== []) {
            $siteIds = [];
            foreach ((array) $input->getOption('site') as $id) {
                if (!ctype_digit((string) $id)) {
                    $io->error(sprintf('--site attend un identifiant entier, reçu « %s ».', $id));

                    return Command::INVALID;
                }
                $siteIds[] = (int) $id;
            }
        }
        $months = [];
        for ($m = $from; $m <= $to; $m = $m->modify('+1 month')) {
            $months[] = $m->format('Y-m');
        }

        $apply = (bool) $input->getOption('apply');
        $io->title($apply ? 'Lien poste ↔ mission (D-156) — écriture' : 'Lien poste ↔ mission (D-156) — vérification, aucune écriture');

        $plan = $this->backfill->plan($months, $siteIds);
        $count = static fn (string $status) => count(array_filter($plan, static fn (array $r) => $r['status'] === $status));
        if ($plan !== []) {
            $io->table(
                ['mission', 'poste', 'occurrence', 'classe', 'statut', 'début', 'fin', 'instrumentiste', 'site', 'équivalentes'],
                array_map(static fn (array $r) => [
                    $r['missionId'], $r['postId'], $r['occurrenceDate'], $r['status'], $r['missionStatus'],
                    $r['start'], $r['end'], $r['instrumentistId'] ?? '—', $r['siteId'], implode(', ', $r['ambiguousWith']) ?: '—',
                ], $plan),
            );
        }
        $io->definitionList(
            ['CLEAR (rattachées)' => $count(MissionScheduleLinkBackfillService::CLEAR)],
            ['INTERCHANGEABLE (rattachées, choix sans effet visible)' => $count(MissionScheduleLinkBackfillService::INTERCHANGEABLE)],
            ['TO_REVIEW (jamais rattachées, à arbitrer)' => $count(MissionScheduleLinkBackfillService::TO_REVIEW)],
        );

        $writable = $count(MissionScheduleLinkBackfillService::CLEAR) + $count(MissionScheduleLinkBackfillService::INTERCHANGEABLE);
        if (!$apply) {
            $writable === 0 ? $io->success('Aucun lien à écrire.') : $io->warning(sprintf('%d lien(s) à écrire : relancer avec --apply.', $writable));

            return $writable === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $linked = $this->backfill->apply($plan);
        $io->success(sprintf('%d mission(s) rattachée(s).', $linked));

        return Command::SUCCESS;
    }
}
