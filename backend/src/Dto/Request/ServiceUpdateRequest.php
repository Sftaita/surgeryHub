<?php

namespace App\Dto\Request;

use App\Enum\HoursSource;
use App\Enum\ServiceStatus;
use Symfony\Component\Validator\Constraints as Assert;

class ServiceUpdateRequest
{
    #[Assert\PositiveOrZero]
    public ?float $hours = null;

    #[Assert\PositiveOrZero]
    public ?float $consultationFeeApplied = null;

    #[Assert\Choice(callback: [HoursSource::class, 'cases'])]
    public ?HoursSource $hoursSource = null;

    #[Assert\Choice(callback: [ServiceStatus::class, 'cases'])]
    public ?ServiceStatus $status = null;
}
