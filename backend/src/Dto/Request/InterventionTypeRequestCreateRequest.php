<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class InterventionTypeRequestCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public string $label;

    #[Assert\Type('string')]
    public ?string $suggestedCode = null;

    #[Assert\Type('string')]
    public ?string $comment = null;

    /**
     * EPIC Revue instrumentiste, Lot 3 — firme demandée par l'instrumentiste pour le
     * MissionInterventionDraft créé en même temps que cette demande. Toujours
     * facultative, résolue et validée (existence + active) côté serveur par
     * ActiveFirmResolver — jamais créée automatiquement.
     */
    #[Assert\Positive]
    public ?int $requestedFirmId = null;
}
