<?php

namespace App\Command;

use App\Release\ReleaseRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-162 — dernière étape d'un déploiement validé (docs/deployment-versioning.md §7) : après
 * les tests de santé et la création du tag Git, enregistre ce tag comme version officielle
 * du code exécuté. Refuse un commit qui n'est pas celui qui tourne. Idempotent.
 */
#[AsCommand(name: 'app:release:mark', description: 'Enregistre le tag de production validé pour le commit exécuté (après les tests de santé et le tag Git).')]
final class ReleaseMarkCommand extends Command
{
    public function __construct(private readonly ReleaseRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tag', InputArgument::REQUIRED, 'Tag Git de production (vYYYY.MM.DD-prod[-N]).');
        $this->addOption('commit', null, InputOption::VALUE_REQUIRED, 'SHA (court ou complet) du commit pointé par le tag, vérifié par l\'opérateur — doit être le commit exécuté.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $commit = (string) $input->getOption('commit');
        if ($commit === '') {
            $io->error('--commit=<sha> est obligatoire : le SHA pointé par le tag Git, à comparer au commit exécuté.');

            return Command::INVALID;
        }

        try {
            $added = $this->registry->mark((string) $input->getArgument('tag'), $commit);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $current = $this->registry->current();
        $io->success(sprintf('%s · %s · %s%s', 'SurgicalHub', $current['version'], $current['commit'], $added ? '' : ' (déjà enregistré)'));

        return Command::SUCCESS;
    }
}
