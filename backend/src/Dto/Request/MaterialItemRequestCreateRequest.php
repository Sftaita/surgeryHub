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
}
