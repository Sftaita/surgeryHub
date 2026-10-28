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

    /**
     * Refonte Catalogue/Prestations (D-092) — même tri-état que primaryFirmId : absent =
     * inchangé, présent avec null = retire explicitement la réponse (rare), présent avec
     * une valeur = Oui/Non.
     */
    public bool $representativePresentProvided = false;
    public ?bool $representativePresent = null;

    /**
     * D-172 — correction manager seulement (MissionVoter::RECORD_UNKNOWN_REPRESENTATIVE_PRESENCE) :
     * la présence du délégué est historiquement inconnue. Enregistrée telle quelle (null),
     * jamais transformée en « non » ; ses conséquences restent celles du backend (encodage
     * non validable, calcul en anomalie tant qu'elle est requise et non renseignée).
     */
    public bool $representativePresenceUnknown = false;

    /**
     * Tarification firme conditionnée à un choix obligatoire — même tri-état que
     * representativePresent : absent = inchangé, présent avec null = retire
     * explicitement le choix (rare), présent avec une valeur = nouvelle option.
     */
    public bool $selectedChoiceOptionIdProvided = false;
    public ?int $selectedChoiceOptionId = null;

    /**
     * §8 du prompt — un changement de choix qui rendrait du matériel déjà encodé
     * incompatible exige cette confirmation explicite (sinon 409, voir
     * ChoiceOptionChangeRequiresConfirmationException) ; jamais de suppression
     * silencieuse.
     */
    public bool $confirmRemoveIncompatibleMaterial = false;
}
