<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissingMaterialReportCreateRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public ?string $firmName = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public ?string $searchText = null;

    // On garde une logique similaire à MaterialLine (decimal en string côté entité).
    // Ici on accepte un nombre (int/float) côté DTO.
    #[Assert\PositiveOrZero]
    public float|int|null $quantity = null;

    #[Assert\Type('string')]
    public ?string $comment = null;
}
