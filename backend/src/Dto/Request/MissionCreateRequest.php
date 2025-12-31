<?php

namespace App\Dto\Request;

use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Symfony\Component\Validator\Constraints as Assert;

class MissionCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $siteId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [MissionType::class, 'cases'])]
    public ?MissionType $type = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [SchedulePrecision::class, 'cases'])]
    public ?SchedulePrecision $schedulePrecision = SchedulePrecision::EXACT;

    #[Assert\DateTime]
    public ?string $startAt = null;

    #[Assert\DateTime]
    public ?string $endAt = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $surgeonUserId = null;

    #[Assert\Positive]
    public ?int $instrumentistUserId = null;
}
