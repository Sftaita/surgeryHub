<?php

namespace App\Dto;

use App\Entity\Mission;

/**
 * D-165 — the slot responsible for a candidate's SCHEDULE_CONFLICT, as shown to the manager
 * ("déjà affectée au Dr X de 07:45 à 18:00"): a persisted mission (SOURCE_PERSISTED), or a
 * line the manager is proposing in the editor and has not saved yet (SOURCE_PROPOSED).
 */
final readonly class ScheduleConflictDetail
{
    public const SOURCE_PERSISTED = 'PERSISTED';
    public const SOURCE_PROPOSED  = 'PROPOSED';

    public function __construct(
        public ?int $missionId,
        public ?string $missionStatus,
        public ?string $siteName,
        public ?string $surgeonName,
        public ?\DateTimeImmutable $startAt,
        public ?\DateTimeImmutable $endAt,
        public string $source,
    ) {}

    public static function fromMission(Mission $mission): self
    {
        $surgeon = $mission->getSurgeon();
        $name    = $surgeon !== null ? trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')) : '';

        return new self(
            $mission->getId(),
            $mission->getStatus()?->value,
            $mission->getSite()?->getName(),
            $name !== '' ? $name : null,
            $mission->getStartAt(),
            $mission->getEndAt(),
            self::SOURCE_PERSISTED,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'missionId'     => $this->missionId,
            'missionStatus' => $this->missionStatus,
            'siteName'      => $this->siteName,
            'surgeonName'   => $this->surgeonName,
            'startAt'       => $this->startAt?->format(\DateTimeInterface::ATOM),
            'endAt'         => $this->endAt?->format(\DateTimeInterface::ATOM),
            'source'        => $this->source,
        ];
    }
}
