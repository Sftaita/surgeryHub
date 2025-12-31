<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionSubmitRequest
{
    #[Assert\Type('boolean')]
    public ?bool $noMaterial = null;

    #[Assert\Type('string')]
    public ?string $comment = null;
}
