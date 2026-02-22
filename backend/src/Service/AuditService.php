<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use Doctrine\ORM\EntityManagerInterface;

class AuditService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function missionDeclared(Mission $mission, User $actor): void
    {
        $this->persist($mission, $actor, AuditEventType::MISSION_DECLARED);
    }

    public function missionDeclaredApproved(Mission $mission, User $actor): void
    {
        $this->persist($mission, $actor, AuditEventType::MISSION_DECLARED_APPROVED);
    }

    public function missionDeclaredRejected(Mission $mission, User $actor): void
    {
        $this->persist($mission, $actor, AuditEventType::MISSION_DECLARED_REJECTED);
    }

    private function persist(Mission $mission, User $actor, AuditEventType $type): void
    {
        $evt = new AuditEvent();
        $evt
            ->setMission($mission)
            ->setActor($actor)
            ->setEventType($type)
            ->setPayload([
                'missionId' => $mission->getId(),
                'siteId' => $mission->getSite()?->getId(),
                'status' => $mission->getStatus()->value,
            ]);

        $this->em->persist($evt);
        // flush géré par MissionService
    }
}