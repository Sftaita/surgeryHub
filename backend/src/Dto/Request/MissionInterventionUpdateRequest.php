<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionInterventionUpdateRequest
{
    #[Assert\Length(max: 100)]
    public ?string $code = null;

    #[Assert\Length(max: 255)]
    public ?string $label = null;

    #[Assert\PositiveOrZero]
    public ?int $orderIndex = null;
}
