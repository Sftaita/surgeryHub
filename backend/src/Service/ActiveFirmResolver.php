<?php

namespace App\Service;

use App\Entity\Firm;
use App\Exception\PrimaryFirmInactiveException;
use App\Exception\PrimaryFirmNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3 — extrait de InterventionService::resolveActiveFirm()
 * (Lot 5, D-068) pour être partagé avec MissionInterventionDraftService::
 * createForRequest() sans dupliquer la validation existence+actif : même règle, qu'une
 * firme soit demandée comme primaryFirm d'une MissionIntervention réelle ou comme
 * requestedFirm d'un MissionInterventionDraft.
 */
final class ActiveFirmResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function resolveActive(int $firmId): Firm
    {
        $firm = $this->em->find(Firm::class, $firmId);
        if (!$firm instanceof Firm) {
            throw new PrimaryFirmNotFoundException('Firme introuvable.');
        }
        if (!$firm->isActive()) {
            throw new PrimaryFirmInactiveException('Cette firme est désactivée.');
        }
        return $firm;
    }
}
