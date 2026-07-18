<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/** Lot 7 (D-070) — commentaire obligatoire : "matériel manquant, quantité incorrecte,
 *  mauvaise firme, intervention incomplète..." doit toujours être explicité au refus. */
class MissionEncodingRejectRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public string $comment;
}
