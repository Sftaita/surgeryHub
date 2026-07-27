<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lot 7 (D-070) suite — `comment` n'est PAS toujours obligatoire (contrairement à
 * MissionEncodingRejectRequest) : il ne l'est que si la mission n'a aucune ligne de
 * matériel encodée, une condition qui dépend de l'état serveur, pas du payload client —
 * la validation conditionnelle vit donc dans MissionEncodingWorkflowService::complete(),
 * jamais ici via une contrainte statique.
 */
class MissionEncodingCompleteRequest
{
    #[Assert\Type('string')]
    public ?string $comment = null;
}
