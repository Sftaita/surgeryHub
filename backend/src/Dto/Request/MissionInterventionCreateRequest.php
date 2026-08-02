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

    /**
     * Refonte Catalogue/Prestations (D-092) — donnée factuelle facultative à la
     * création : la question n'est affichée par le frontend que si
     * FirmServiceOffering.representativePresenceRelevant est vrai pour (firm, type),
     * mais rien n'empêche de la fournir dès la création si déjà connue.
     */
    public ?bool $representativePresent = null;

    /**
     * @deprecated EPIC Revue instrumentiste, Lot 3 — n'est plus utilisé par
     * InterventionService::create() : le serveur alloue seul la position via
     * MissionEntryOrderAllocator (voir son docblock). Le champ reste accepté en entrée
     * pour ne pas casser le contrat HTTP existant tant que le frontend l'envoie encore,
     * mais toute valeur fournie ici est silencieusement ignorée à la création — la
     * réponse contient l'index réellement alloué (`orderIndex` dans le corps de
     * POST /api/missions/{id}/interventions). Encore utilisé pour PATCH (réordonnancement
     * manuel explicite, voir MissionInterventionUpdateRequest — non concerné par cette
     * dépréciation).
     */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $orderIndex = 0;
}
