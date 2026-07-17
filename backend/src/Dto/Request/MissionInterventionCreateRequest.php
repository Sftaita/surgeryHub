<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lot 5 (D-068) : `code`/`label` texte libre remplacés par `interventionTypeId`
 * (obligatoire — le référentiel fermé est désormais la seule source acceptée pour un
 * nouvel encodage) + `primaryFirmId` (facultatif). Le nom/code affichés sont dérivés
 * côté serveur depuis l'InterventionType résolu (voir InterventionService::create()),
 * jamais fournis par le client.
 */
class MissionInterventionCreateRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $interventionTypeId = null;

    #[Assert\Positive]
    public ?int $primaryFirmId = null;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $orderIndex = 0;
}
