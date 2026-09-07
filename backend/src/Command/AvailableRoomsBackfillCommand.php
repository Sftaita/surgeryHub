<?php

namespace App\Command;

use App\Entity\Absence;
use App\Service\ReleasedOperatingRoomSlotService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * « Salles libérées » (Lot D, post D-114) — backfill basé sur la RÉALITÉ des absences et des
 * occurrences BLOCK futures (`SurgeonAbsenceBlockOccurrenceResolver`), jamais sur l'existence
 * d'un journal `ROOM_RELEASE`. Remplace `app:available-rooms:backfill-from-room-release`
 * (supprimé, jamais exécuté en production) : cet ancien backfill ratait structurellement
 * toute absence n'ayant jamais généré d'email Room Release (site avec
 * `notifyColleaguesEnabled=false` à l'époque, ou aucun collègue affilié) — angle mort confirmé
 * par audit prod le 2026-09-07 (absence #42 : 4 occurrences BLOCK futures réelles, zéro
 * communication de tout type).
 *
 * Répond à « quelles salles ont réellement été libérées par des absences existantes et ont
 * encore une occurrence future ? » — jamais à « quels emails ROOM_RELEASE ont déjà été
 * envoyés ? ». Réutilise `ReleasedOperatingRoomSlotService::resolveFutureOccurrences()`
 * (lecture) et `::onAbsenceUpdated()` (écriture, chemin identique au 9ᵉ collaborateur temps
 * réel) — cette commande ne réimplémente jamais la logique de création, seule la boucle sur
 * les absences lui est propre. N'envoie aucun email, ne crée aucune
 * `SurgeonAbsenceCommunication`, ne modifie aucun historique D-114 — le service qu'elle
 * appelle ne connaît même pas ces concepts.
 *
 * Idempotent par la même contrainte unique que le service temps réel — peut être rejoué sans
 * effet si déjà exécuté.
 */
#[AsCommand(
    name: 'app:available-rooms:backfill',
    description: 'Projette les occurrences BLOCK futures des absences existantes vers released_operating_room_slot (Lot D), à exécuter au besoin — --dry-run pour prévisualiser sans écrire.',
)]
class AvailableRoomsBackfillCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReleasedOperatingRoomSlotService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\'écrit rien en base ; affiche uniquement ce qui serait créé.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $today = new \DateTimeImmutable('today');

        // « Absences encore pertinentes » : resolveFutureOccurrences() ne peut jamais
        // retourner d'occurrence >= aujourd'hui pour une fenêtre entièrement passée — filtrer
        // sur dateEnd ici est un pur gain de performance, jamais un changement de
        // comportement par rapport au chemin temps réel.
        $absences = $this->em->createQuery(
            'SELECT a FROM App\Entity\Absence a WHERE a.dateEnd >= :today ORDER BY a.id ASC'
        )
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->getResult();

        $analyzed = count($absences);
        $futureOccurrences = 0;
        $alreadyExisting = 0;
        $rows = [];
        $errors = [];

        foreach ($absences as $absence) {
            /** @var Absence $absence */
            try {
                // Chaque absence est traitée indépendamment (même principe que le rattrapage
                // Lot C, docs/decisions.md D-114 Lot C §26) : une ligne de données incohérente
                // (ex. FK vers un User supprimé hors du chemin applicatif normal, jamais
                // censée exister mais rencontrée en pratique sur une base ancienne — voir
                // audit prod du 2026-09-07) ne doit jamais faire échouer tout le rattrapage.
                foreach ($this->service->resolveFutureOccurrences($absence) as $occurrence) {
                    $futureOccurrences++;

                    if ($occurrence['alreadyExists']) {
                        $alreadyExisting++;
                        continue;
                    }

                    $rows[] = [
                        $occurrence['surgeon']->getDrName(),
                        $occurrence['site']->getName(),
                        $occurrence['date']->format('Y-m-d'),
                        $occurrence['period']->value,
                        $occurrence['post']->getId(),
                    ];
                }

                // Jamais de confiance aveugle dans la lecture ci-dessus (même principe que le
                // rattrapage Lot C, docs/decisions.md D-114 Lot C §15) : l'écriture réelle
                // repasse par le chemin normal, qui revalide tout au moment du flush.
                if (!$dryRun) {
                    $this->service->onAbsenceUpdated($absence, $absence->getUser());
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('Absence #%d : %s', $absence->getId(), $e->getMessage());
            }
        }

        $wouldCreate = count($rows);

        $io->section($dryRun ? 'Dry-run — aucune écriture en base' : 'Backfill — exécution réelle');
        $io->table(
            ['Absences analysées', 'Occurrences BLOCK futures', 'Déjà existants', $dryRun ? 'Seraient créés' : 'Créés'],
            [[$analyzed, $futureOccurrences, $alreadyExisting, $wouldCreate]],
        );

        if (!empty($rows)) {
            $io->table(['Chirurgien', 'Site', 'Date', 'Période', 'postId'], $rows);
        }

        if (!empty($errors)) {
            $io->warning(sprintf('%d absence(s) ignorée(s) suite à une erreur (données incohérentes, jamais bloquant pour le reste du rattrapage) :', count($errors)));
            $io->listing($errors);
        }

        $io->success(sprintf(
            '%s : %d absences analysées, %d occurrences BLOCK futures, %d déjà existants, %d %s%s.',
            $dryRun ? 'Dry-run' : 'Backfill',
            $analyzed,
            $futureOccurrences,
            $alreadyExisting,
            $wouldCreate,
            $dryRun ? 'seraient créés' : 'créés',
            empty($errors) ? '' : sprintf(', %d ignorée(s) suite à une erreur', count($errors)),
        ));

        return Command::SUCCESS;
    }
}
