<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class SurgeonRatingRequest
{
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5)]
    public ?int $cordiality = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5)]
    public ?int $punctuality = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5)]
    public ?int $missionRespect = null;

    #[Assert\Length(max: 2000)]
    public ?string $comment = null;

    #[Assert\NotNull]
    #[Assert\Type('boolean')]
    public ?bool $isFirstCollaboration = null;
}
