<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class InterventionTypeRequestCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public string $label;

    #[Assert\Type('string')]
    public ?string $suggestedCode = null;

    #[Assert\Type('string')]
    public ?string $comment = null;
}
