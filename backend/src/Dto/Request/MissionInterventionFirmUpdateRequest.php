<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionInterventionFirmUpdateRequest
{
    #[Assert\Length(max: 255)]
    public ?string $firmName = null;
}
