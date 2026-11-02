<?php

namespace App\Dto\Request;

use App\Enum\MissionDispatchMode;
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

    /**
     * D-156 lot 1 — optional dispatch decided in the same creation (wizard « Publier »):
     * POOL | TARGETED | DIRECT. Validated BEFORE the mission is created, applied right after,
     * in the same request — never two independent calls that could leave an orphan DRAFT
     * (or a duplicate on retry) when the second one fails. Null = stays DRAFT (unchanged).
     */
    public ?MissionDispatchMode $dispatchMode = null;

    #[Assert\Positive]
    public ?int $dispatchInstrumentistId = null;
}
