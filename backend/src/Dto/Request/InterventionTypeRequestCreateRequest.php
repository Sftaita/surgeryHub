<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class InterventionTypeRequestCreateRequest
{
    #[Assert\NotBlank(message: 'Le nom de l\'intervention demandée est obligatoire.')]
    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $label = null;

    #[Assert\Type('string')]
    #[Assert\Length(max: 64)]
    public ?string $suggestedCode = null;

    #[Assert\Type('string')]
    public ?string $comment = null;

    /**
     * EPIC Revue instrumentiste, Lot 3 — firme demandée par l'instrumentiste pour le
     * MissionInterventionDraft créé en même temps que cette demande, résolue et validée
     * (existence + active) côté serveur par ActiveFirmResolver — jamais créée
     * automatiquement.
     *
     * D-146 bis — « Avec quelle firme cette intervention a-t-elle été réalisée ? » est
     * OBLIGATOIRE : exactement l'une des deux informations, requestedFirmId OU
     * proposedFirmName (refus 422 FIRM_REQUIRED / FIRM_AMBIGUOUS).
     */
    #[Assert\Positive]
    public ?int $requestedFirmId = null;

    /** D-146 bis — « Ma firme n'est pas dans la liste » : nom libre, jamais une firme créée. */
    #[Assert\Type('string')]
    #[Assert\Length(max: 150)]
    public ?string $proposedFirmName = null;
}
