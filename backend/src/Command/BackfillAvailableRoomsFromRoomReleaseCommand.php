<?php

namespace App\Command;

use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonSchedulePost;
use App\Enum\AbsenceCommunicationType;
use App\Enum\ShiftPeriod;
use App\Repository\ReleasedOperatingRoomSlotRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * « Salles libérées » (Lot D, post D-114) — backfill à exécuter UNE FOIS au déploiement du
 * Lot D. Projette les `occurrencesSnapshot` des `SurgeonAbsenceCommunication` de type
 * `ROOM_RELEASE` **encore futures** vers `ReleasedOperatingRoomSlot` — jamais l'historique
 * passé, jamais un recalcul depuis les absences elles-mêmes (§25 de la demande : respecte
 * exactement ce qui a déjà été considéré comme libéré par le Lot A, sans réinterpréter).
 *
 * Idempotent par construction (même contrainte unique que `ReleasedOperatingRoomSlotService`)
 * — peut être rejoué sans effet si déjà exécuté.
 */
#[AsCommand(
    name: 'app:available-rooms:backfill-from-room-release',
    description: 'Projette les ROOM_RELEASE encore futures vers released_operating_room_slot (Lot D, une seule fois au déploiement).',
)]
class BackfillAvailableRoomsFromRoomReleaseCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReleasedOperatingRoomSlotRepository $slots,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new \DateTimeImmutable('today');

        $communications = $this->em->createQueryBuilder()
            ->select('c')
            ->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.type = :type')
            ->setParameter('type', AbsenceCommunicationType::ROOM_RELEASE)
            ->getQuery()
            ->toIterable();

        $created = 0;
        $skippedExisting = 0;
        $skippedPast = 0;

        foreach ($communications as $communication) {
            /** @var SurgeonAbsenceCommunication $communication */
            $site = $communication->getSite();
            $surgeon = $communication->getSurgeon();
            if ($site === null || $surgeon === null) {
                continue;
            }

            foreach ($communication->getOccurrencesSnapshot() as $occurrence) {
                $date = new \DateTimeImmutable($occurrence['date']);
                if ($date < $today) {
                    $skippedPast++;
                    continue;
                }

                $postId = $occurrence['postId'];
                if ($this->slots->existsFor($site->getId(), $postId, $date)) {
                    $skippedExisting++;
                    continue;
                }

                $period = ShiftPeriod::from($occurrence['period']);

                $slot = new ReleasedOperatingRoomSlot();
                $slot->setSite($site);
                $slot->setPostId($postId);
                $slot->setSchedulePost($this->em->find(SurgeonSchedulePost::class, $postId));
                $slot->setOccurrenceDate($date);
                $slot->setPeriod($period);
                $slot->setSurgeon($surgeon);
                $slot->setSourceAbsence($communication->getAbsence());

                $config = $this->shiftPeriodConfig($site, $period);
                if ($config !== null) {
                    $slot->setStartTime($config->getStartTime());
                    $slot->setEndTime($config->getEndTime());
                }

                $this->em->persist($slot);

                try {
                    $this->em->flush();
                    $created++;
                } catch (UniqueConstraintViolationException) {
                    $this->em->detach($slot);
                    $skippedExisting++;
                }
            }

            $this->em->detach($communication);
        }

        $io->success(sprintf(
            'Slots créés : %d, déjà existants (idempotence) : %d, occurrences passées ignorées : %d.',
            $created,
            $skippedExisting,
            $skippedPast,
        ));

        return Command::SUCCESS;
    }

    private function shiftPeriodConfig(Hospital $site, ShiftPeriod $period): ?ShiftPeriodConfig
    {
        $config = $this->em->getRepository(ShiftPeriodConfig::class)->findOneBy(['site' => $site, 'period' => $period]);

        return $config !== null && $config->isActive() ? $config : null;
    }
}
