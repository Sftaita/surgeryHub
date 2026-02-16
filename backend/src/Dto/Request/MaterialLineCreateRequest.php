<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MaterialLineCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Positive]
    public int $itemId;

    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    #[Assert\Type('string')]
    public ?string $quantity = null;

    #[Assert\Type('string')]
    public ?string $comment = null;
}
