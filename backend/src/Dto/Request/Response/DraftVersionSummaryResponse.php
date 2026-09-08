<?php

namespace App\Dto\Request\Response;

use App\Entity\PlanningVersion;

/** CAS D (D-115) — minimal version metadata alongside a reopened draft's lines. */
final class DraftVersionSummaryResponse
{
    public function __construct(
        public int $id,
        public string $status,
        public string $periodStart,
        public string $periodEnd,
        public ?int $siteId,
        public ?string $siteName,
        public string $generatedAt,
    ) {
    }

    public static function fromVersion(PlanningVersion $version): self
    {
        $site = $version->getSite();

        return new self(
            id: $version->getId(),
            status: $version->getStatus()->value,
            periodStart: $version->getPeriodStart()->format('Y-m-d'),
            periodEnd: $version->getPeriodEnd()->format('Y-m-d'),
            siteId: $site?->getId(),
            siteName: $site?->getName(),
            generatedAt: $version->getGeneratedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
