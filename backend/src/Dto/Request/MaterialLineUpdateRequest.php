<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MaterialLineUpdateRequest
{
    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    #[Assert\Positive]
    public ?int $missionInterventionFirmId = null;

    #[Assert\Positive]
    public ?int $itemId = null;

    #[Assert\Positive]
    public ?float $quantity = null;

    #[Assert\Length(max: 1000)]
    public ?string $comment = null;
}
