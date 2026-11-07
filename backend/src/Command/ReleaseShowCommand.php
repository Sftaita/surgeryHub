<?php

namespace App\Command;

use App\Release\ReleaseRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-162 — contrôle de cohérence (docs/deployment-versioning.md §5) : commit exécuté, statut
 * et tag officiel, exactement ce que `GET /api/version` renvoie à l'application.
 */
#[AsCommand(name: 'app:release:show', description: 'Affiche la version exécutée (commit, statut, tag officiel) et le registre des versions.')]
final class ReleaseShowCommand extends Command
{
    public function __construct(private readonly ReleaseRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $current = $this->registry->current();
        $io->definitionList(
            ['Commit exécuté' => $this->registry->runningCommit() ?? '— (développement)'],
            ['Statut' => $current['status']],
            ['Version officielle' => $current['version'] ?? '—'],
        );
        $rows = array_map(static fn (array $e) => [$e['tag'], substr($e['commit'], 0, 7), $e['markedAt']], array_reverse($this->registry->entries()));
        $io->table(['Tag', 'Commit', 'Enregistré le'], $rows);

        return Command::SUCCESS;
    }
}
