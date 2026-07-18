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

    /**
     * Generic record for post-deploy lifecycle events (Batch 15B+).
     * $extraPayload must include name snapshots per R-12 (never rely on FK at read-time).
     * flush() is the caller's responsibility (R-05: flush before dispatch).
     */
    public function record(Mission $mission, User $actor, AuditEventType $type, array $extraPayload = []): void
    {
        $evt = new AuditEvent();
        $evt
            ->setMission($mission)
            ->setActor($actor)
            ->setEventType($type)
            ->setPayload(array_merge([
                'missionId' => $mission->getId(),
                'siteId'    => $mission->getSite()?->getId(),
                'siteName'  => $mission->getSite()?->getName(),
                'status'    => $mission->getStatus()->value,
            ], $extraPayload));

        $this->em->persist($evt);
    }

    /**
     * D-072 (Lot 2) — pour les événements sans Mission à rattacher (catalogue tarifaire :
     * PricingRule, InstrumentistRate). $payload doit être auto-suffisant (acteur, ancien/
     * nouveau tarif, devise, date d'effet, périmètre métier) puisqu'aucune Mission ne
     * fournit de contexte implicite ici. flush() reste la responsabilité de l'appelant
     * (R-05, même convention que record()).
     */
    public function recordGlobal(User $actor, AuditEventType $type, array $payload = []): void
    {
        $evt = new AuditEvent();
        $evt
            ->setMission(null)
            ->setActor($actor)
            ->setEventType($type)
            ->setPayload($payload);

        $this->em->persist($evt);
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