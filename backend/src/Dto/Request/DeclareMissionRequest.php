<?php

namespace App\Dto\Request;

use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Symfony\Component\Validator\Constraints as Assert;

class DeclareMissionRequest
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

    #[Assert\NotNull]
    #[Assert\Type(\DateTimeInterface::class)]
    public ?\DateTimeImmutable $startAt = null;

    #[Assert\NotNull]
    #[Assert\Type(\DateTimeInterface::class)]
    public ?\DateTimeImmutable $endAt = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $surgeonUserId = null;

    // ✅ plus obligatoire
    public ?string $declaredComment = null;
}