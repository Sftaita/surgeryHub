<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MaterialItemRequestCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public string $label;

    #[Assert\Type('string')]
    public ?string $referenceCode = null;

    #[Assert\Type('string')]
    public ?string $comment = null;

    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 4 — alternative à missionInterventionId,
     * jamais les deux à la fois (voir MaterialAttachmentResolver).
     */
    #[Assert\Positive]
    public ?int $interventionDraftId = null;
}
