<?php

namespace App\Command;

use App\Service\MissionAssignmentResyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-150 — realignment of `mission_assignment` after a code rollback (docs/production.md
 * §Rollback A, « Avant de réactiver le code D-150 »). Without --apply: check only, nothing is
 * written — invariants, every planned correction with the origin of its dates, and the
 * calculations reopened by the old code (D-153, listed, never modified).
 *
 * Exit codes: 0 = aligned (check) or corrections committed with every invariant at 0 (apply);
 * 1 = corrections pending (check) or apply rolled back (nothing written); 2 = invalid option.
 * Idempotent: a second --apply finds nothing to do.
 */
#[AsCommand(
    name: 'app:mission-assignment:resync',
    description: 'Réaligne mission_assignment sur mission après un retour arrière du code (D-150) — vérification seule sans --apply.',
)]
final class MissionAssignmentResyncCommand extends Command
{
    public function __construct(private readonly MissionAssignmentResyncService $resync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit les corrections listées (une transaction, annulée si un invariant reste faux).');
        $this->addOption('mission', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limite la vérification et les corrections à ces missions (id, répétable).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $missionIds = null;
        if ($input->getOption('mission') !== []) {
            $missionIds = [];
            foreach ((array) $input->getOption('mission') as $id) {
                if (!ctype_digit((string) $id)) {
                    $io->error(sprintf('--mission attend un identifiant entier, reçu « %s ».', $id));

                    return Command::INVALID;
                }
                $missionIds[] = (int) $id;
            }
            $io->note('Limité aux missions : ' . implode(', ', $missionIds));
        }

        $io->title($apply ? 'Réalignement des affectations (D-150) — écriture' : 'Réalignement des affectations (D-150) — vérification, aucune écriture');

        $before = $this->resync->invariants($missionIds);
        $this->invariantTable($io, 'Invariants avant', $before);

        $plan = $this->resync->plan($missionIds);
        $fmt = static fn (\DateTimeImmutable $d) => $d->format('Y-m-d H:i:s');
        $total = count($plan['close']) + count($plan['open']) + count($plan['closedRow']);

        if ($plan['close'] !== []) {
            $io->section(sprintf('Affectations ouvertes à fermer (%d)', count($plan['close'])));
            $io->table(['affectation', 'mission', 'instrumentiste', 'statut mission', 'end_reason', 'ended_at', 'origine de ended_at'], array_map(
                static fn (array $r) => [$r['assignmentId'], $r['missionId'], $r['instrumentistId'], $r['missionStatus'], $r['endReason'], $fmt($r['endedAt']), $r['endedAtSource']],
                $plan['close'],
            ));
        }
        if ($plan['open'] !== []) {
            $io->section(sprintf('Affectations LEGACY à ouvrir (%d)', count($plan['open'])));
            $io->table(['mission', 'instrumentiste', 'statut mission', 'assigned_at', 'origine'], array_map(
                static fn (array $r) => [$r['missionId'], $r['instrumentistId'], $r['missionStatus'], $fmt($r['assignedAt']), $r['assignedAtSource']],
                $plan['open'],
            ));
        }
        if ($plan['closedRow'] !== []) {
            $io->section(sprintf('Lignes LEGACY fermées à créer pour des missions définitives (%d)', count($plan['closedRow'])));
            $io->table(['mission', 'instrumentiste', 'statut', 'assigned_at', 'ended_at', 'origine de ended_at'], array_map(
                static fn (array $r) => [$r['missionId'], $r['instrumentistId'], $r['missionStatus'], $fmt($r['assignedAt']), $fmt($r['endedAt']), $r['endedAtSource']],
                $plan['closedRow'],
            ));
        }

        $stale = $this->resync->staleCalculations();
        if ($stale !== []) {
            $io->warning(sprintf('%d calcul(s) actif(s) dont la mission a été rouverte sans marqueur D-153 — à décider manuellement, rien n\'est modifié :', count($stale)));
            $io->table(['calcul', 'mission', 'statut', 'calculé le', 'rouvert le'], array_map(
                static fn (array $r) => [$r['calculationId'], $r['missionId'], $r['status'], $r['calculatedAt'], $r['reopenedAt']],
                $stale,
            ));
        }

        if ($total === 0) {
            $io->success('Aucune correction nécessaire : mission_assignment est aligné sur mission.');

            return array_sum($before) === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$apply) {
            $io->note(sprintf('%d correction(s) nécessaire(s). Relancer avec --apply pour les écrire (aucune écriture faite).', $total));

            return Command::FAILURE;
        }

        try {
            $after = $this->resync->apply($plan, $missionIds);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $this->invariantTable($io, 'Invariants après', $after);
        $io->success(sprintf('%d correction(s) écrite(s) (instant de la reprise : %s).', $total, $fmt($plan['at'])));

        return Command::SUCCESS;
    }

    /** @param array<string, int> $invariants */
    private function invariantTable(SymfonyStyle $io, string $title, array $invariants): void
    {
        $io->section($title);
        $io->table(['contrôle', 'lignes en écart'], array_map(static fn (string $k, int $n) => [$k, $n], array_keys($invariants), $invariants));
    }
}
