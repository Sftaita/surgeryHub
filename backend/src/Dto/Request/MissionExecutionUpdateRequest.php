<?php

namespace App\Dto\Request;

use App\Enum\HoursSource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lot 1 (Exécution & Valorisation) — corps de PATCH /api/missions/{id}/execution.
 * Tous les champs sont optionnels (sémantique PATCH : seuls les champs présents sont
 * appliqués) — voir MissionExecutionService::updateActuals() pour les règles de
 * cohérence appliquées entre actualStartAt/actualEndAt/actualDurationMinutes.
 */
class MissionExecutionUpdateRequest
{
    #[Assert\Type('string')]
    public ?string $actualStartAt = null;

    #[Assert\Type('string')]
    public ?string $actualEndAt = null;

    #[Assert\PositiveOrZero]
    public ?int $actualDurationMinutes = null;

    #[Assert\Choice(callback: [HoursSource::class, 'cases'])]
    public ?HoursSource $hoursSource = null;
}
