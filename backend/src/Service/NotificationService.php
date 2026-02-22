<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\PublicationChannel;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * Déclaration -> managers/admins globaux.
     */
    public function missionDeclaredNotifyManagersAdmins(Mission $mission): void
    {
        $recipients = $this->userRepository->findManagersAndAdmins(true);

        foreach ($recipients as $user) {
            $this->createInApp($user, $mission, 'MISSION_DECLARED');
        }
    }

    /**
     * Approbation -> instrumentiste.
     */
    public function missionDeclaredApprovedNotifyInstrumentist(Mission $mission): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            return;
        }

        $this->createInApp($instrumentist, $mission, 'MISSION_DECLARED_APPROVED');
    }

    /**
     * Rejet -> instrumentiste.
     */
    public function missionDeclaredRejectedNotifyInstrumentist(Mission $mission): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            return;
        }

        $this->createInApp($instrumentist, $mission, 'MISSION_DECLARED_REJECTED');
    }

    private function createInApp(User $user, Mission $mission, string $eventType): void
    {
        $evt = new NotificationEvent();
        $evt
            ->setUser($user)
            ->setMission($mission)
            ->setEventType($eventType)
            ->setChannel(PublicationChannel::IN_APP)
            ->setPayload([
                'missionId' => $mission->getId(),
                'siteId' => $mission->getSite()?->getId(),
                'status' => $mission->getStatus()->value,
            ])
            ->setSentAt(new \DateTimeImmutable());

        $this->em->persist($evt);
        // flush géré par MissionService
    }
}