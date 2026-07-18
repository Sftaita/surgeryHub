<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/** Lot 7 (D-070) — commentaire optionnel, contrairement au refus (reject). */
class MissionEncodingReopenRequest
{
    #[Assert\Type('string')]
    public ?string $comment = null;
}
