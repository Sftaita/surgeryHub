<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class MaterialItemRequestCreateRequest
{
    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    #[Assert\Positive]
    public ?int $missionInterventionFirmId = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $label = null;

    #[Assert\Length(max: 255)]
    public ?string $referenceCode = null;

    #[Assert\Length(max: 2000)]
    public ?string $comment = null;
}
