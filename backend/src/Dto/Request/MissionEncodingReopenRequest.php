<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/** Lot 7 (D-070) — D-148 : motif de la correction obligatoire, comme pour le refus (reject). */
class MissionEncodingReopenRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public string $comment;
}
