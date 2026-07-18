<?php

namespace App\Dto\Request;

use App\Enum\HoursSource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lot 1 (Exécution & Valorisation) — DTO LEGACY, forme figée pour compatibilité
 * frontend (PATCH /api/missions/{id}/service, voir ServiceController). `hours` est
 * converti en actualDurationMinutes par le contrôleur. `consultationFeeApplied` et
 * `status` sont acceptés sans validation stricte (jamais rejetés pour compatibilité)
 * mais ne sont plus utilisés — ce sont les champs financiers morts retirés par ce lot
 * (App\Enum\ServiceStatus a été supprimé, donc plus de contrainte Assert\Choice ici).
 */
class ServiceUpdateRequest
{
    #[Assert\PositiveOrZero]
    public ?float $hours = null;

    #[Assert\PositiveOrZero]
    public ?float $consultationFeeApplied = null;

    #[Assert\Choice(callback: [HoursSource::class, 'cases'])]
    public ?HoursSource $hoursSource = null;

    public ?string $status = null;
}
