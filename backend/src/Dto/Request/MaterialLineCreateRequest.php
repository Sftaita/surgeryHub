<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MaterialLineCreateRequest
{
    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    #[Assert\Positive]
    public ?int $missionInterventionFirmId = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $itemId = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?float $quantity = 1.0;

    #[Assert\Length(max: 1000)]
    public ?string $comment = null;
}
