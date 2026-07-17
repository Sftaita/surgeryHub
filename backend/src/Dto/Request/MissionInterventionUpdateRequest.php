<?php

namespace App\Dto\Request;

/**
 * Lot 5 (D-068) : construit manuellement par InterventionController::update() depuis le
 * corps JSON brut (pas par le serializer Symfony) — `primaryFirmId` a besoin d'un vrai
 * tri-état (absent / explicitement null pour retirer / valeur pour définir) qu'un DTO
 * désérialisé automatiquement ne peut pas distinguer proprement sur une propriété
 * nullable. `interventionTypeId` ne supporte pas le retrait (un type reste toujours
 * obligatoire une fois l'intervention créée) : absent = inchangé, présent = nouvelle
 * valeur (jamais null).
 */
final class MissionInterventionUpdateRequest
{
    public ?int $interventionTypeId = null;

    public bool $primaryFirmIdProvided = false;
    public ?int $primaryFirmId = null;

    public ?int $orderIndex = null;
}
