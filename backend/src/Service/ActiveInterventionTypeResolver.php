<?php

namespace App\Service;

use App\Entity\InterventionType;
use App\Exception\InterventionTypeInactiveException;
use App\Exception\InterventionTypeNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 5 — extrait de
 * InterventionService::resolveActiveInterventionType() (Lot 5, D-068), même
 * raisonnement que ActiveFirmResolver (commit 3) : la même validation
 * existence+actif est nécessaire à la fois pour InterventionService::create() et pour
 * MissionInterventionDraftService::resolve() (via le contrôleur, qui résout
 * l'InterventionType choisi par le manager avant d'appeler le service) — partagée
 * plutôt que dupliquée.
 */
final class ActiveInterventionTypeResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function resolveActive(int $interventionTypeId): InterventionType
    {
        $type = $this->em->find(InterventionType::class, $interventionTypeId);
        if (!$type instanceof InterventionType) {
            throw new InterventionTypeNotFoundException('Type d\'intervention introuvable.');
        }
        if (!$type->isActive()) {
            throw new InterventionTypeInactiveException('Ce type d\'intervention est désactivé.');
        }
        return $type;
    }
}
