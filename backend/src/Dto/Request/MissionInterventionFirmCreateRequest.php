<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionInterventionFirmCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $firmName = null;
}
