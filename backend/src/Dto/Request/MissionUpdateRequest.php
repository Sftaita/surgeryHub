<?php

namespace App\Dto\Request;

use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Symfony\Component\Validator\Constraints as Assert;

class MissionUpdateRequest
{
    /**
     * PATCH => tout optionnel
     */

    #[Assert\Type(type: \DateTimeImmutable::class)]
    public ?\DateTimeImmutable $startAt = null;

    #[Assert\Type(type: \DateTimeImmutable::class)]
    public ?\DateTimeImmutable $endAt = null;

    #[Assert\Type(type: SchedulePrecision::class)]
    public ?SchedulePrecision $schedulePrecision = null;

    #[Assert\Type(type: MissionType::class)]
    public ?MissionType $type = null;

    #[Assert\Positive]
    public ?int $siteId = null;
}
