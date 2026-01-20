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

    /**
     * ISO 8601 attendu dans le JSON (ex: 2026-01-27T08:00:00+01:00)
     * Hydraté par Symfony Serializer en DateTimeImmutable.
     */
    #[Assert\NotNull]
    #[Assert\Type(\DateTimeInterface::class)]
    public ?\DateTimeImmutable $startAt = null;

    #[Assert\NotNull]
    #[Assert\Type(\DateTimeInterface::class)]
    public ?\DateTimeImmutable $endAt = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $surgeonUserId = null;

    #[Assert\Positive]
    public ?int $instrumentistUserId = null;
}
