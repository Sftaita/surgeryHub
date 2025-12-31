<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionInterventionCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $code = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $label = null;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $orderIndex = 0;
}
