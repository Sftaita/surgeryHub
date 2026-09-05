<?php

namespace App\Command;

use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Enum\AbsenceCommunicationStatus;
use App\Message\SendTemplatedEmailMessage;
use App\Service\AbsenceCommunicationJournalService;
use App\Service\BlockManagementCommunicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Communication des absences chirurgiens — Lot B (D-114). Envoie les communications
 * « gestion du bloc » programmées (`SCHEDULED`, `scheduledAt` échu) — délai configurable
 * par site avant le début du congé. Calquée sur `CheckUncoveredEscalationsCommand` (D-110) :
 * scan synchrone → claim sous verrou pessimiste par ligne
 * (`AbsenceCommunicationJournalService::claimScheduledBlockManagementDelivery()`, qui
 * re-résout To/CC "live", jamais figé à la programmation) → dispatch strictement après le
 * commit du claim → résumé, `Command::SUCCESS` toujours.
 *
 * Idempotent par construction : deux exécutions concurrentes (ou un retry) ne peuvent
 * jamais dispatcher deux fois le même email — le claim re-vérifie `SCHEDULED` sous verrou
 * avant toute action ; un run qui perd la course trouve la ligne déjà traitée et la
 * compte "déjà traitée par un run concurrent", jamais une erreur.
 *
 * Pas encore planifiée automatiquement — à câbler en cron (recommandé : horaire,
 * `0 * * * *` — le délai se compte en jours, une granularité plus fine n'a aucune utilité
 * réelle) :
 *   php bin/console app:absences:send-scheduled-communications
 */
#[AsCommand(
    name: 'app:absences:send-scheduled-communications',
    description: 'Envoie les communications "gestion du bloc" programmées dont l\'échéance est atteinte (cron, horaire).',
)]
class SendScheduledAbsenceCommunicationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceCommunicationJournalService $journal,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $mailerFromName,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $candidates = $this->em->createQueryBuilder()
            ->select('d')
            ->from(SurgeonAbsenceCommunicationDelivery::class, 'd')
            ->where('d.status = :scheduled')
            ->andWhere('d.scheduledAt IS NOT NULL')
            ->andWhere('d.scheduledAt <= :now')
            // dispatchClaimedAt IS NULL — garde-fou supplémentaire au niveau du scan
            // (le vrai claim atomique a lieu sous verrou dans
            // claimScheduledBlockManagementDelivery(), mais filtrer ici évite de re-sélectionner
            // inutilement des lignes déjà en cours de traitement par un run précédent/concurrent).
            ->andWhere('d.dispatchClaimedAt IS NULL')
            ->setParameter('scheduled', AbsenceCommunicationStatus::SCHEDULED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if (empty($candidates)) {
            $io->success('Aucune communication programmée à échéance.');
            return Command::SUCCESS;
        }

        $sent = 0;
        $cancelled = 0;
        $failed = 0;
        $skipped = 0;
        $errored = 0;

        foreach ($candidates as $delivery) {
            /** @var SurgeonAbsenceCommunicationDelivery $delivery */
            try {
                $claim = $this->journal->claimScheduledBlockManagementDelivery($delivery);

                switch ($claim['outcome']) {
                    case 'already-handled':
                        $skipped++;
                        continue 2;
                    case 'disabled':
                        $cancelled++;
                        continue 2;
                    case 'invalid':
                        $failed++;
                        continue 2;
                }

                $communication = $delivery->getCommunication();
                $surgeon = $communication->getSurgeon();

                try {
                    $this->bus->dispatch(new SendTemplatedEmailMessage(
                        to: (string) $claim['to'],
                        subject: $communication->getSubjectSnapshot(),
                        fromAddress: $this->mailerFromAddress,
                        fromName: $this->mailerFromName,
                        htmlTemplate: BlockManagementCommunicationService::templateFor($communication->getType()),
                        context: BlockManagementCommunicationService::contextFor($communication),
                        absenceCommunicationDeliveryId: $delivery->getId(),
                        cc: $claim['cc'],
                        replyTo: $surgeon->getEmail(),
                    ));
                } catch (\Throwable $dispatchException) {
                    // Le dispatch Messenger lui-même a échoué (jamais un échec SMTP ultérieur
                    // dans le worker, qui reste de la responsabilité de celui-ci) — libérer le
                    // claim pour qu'un prochain run retente, plutôt que de laisser la ligne
                    // bloquée indéfiniment (§1 de la revue finale).
                    $this->journal->releaseDispatchClaim($delivery->getId(), $dispatchException->getMessage());
                    throw $dispatchException;
                }

                $sent++;
            } catch (\Throwable $e) {
                $errored++;
                $io->warning(sprintf('Delivery #%d: %s', $delivery->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Dispatché %d, annulé (config désactivée) %d, échoué (config invalide) %d, déjà traité par un run concurrent %d, erreur %d.',
            $sent,
            $cancelled,
            $failed,
            $skipped,
            $errored,
        ));

        return Command::SUCCESS;
    }
}
