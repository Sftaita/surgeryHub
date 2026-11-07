<?php

namespace App\Absence;

use App\Enum\AbsenceImpactConsequence;
use App\Enum\AbsenceImpactLevel;

/**
 * H4 (D-130) — one occurrence impacted by one absence, at exactly one level (the most
 * concrete state wins: PUBLISHED > DRAFT > USUAL_SCHEDULE). Produced only by
 * AbsenceImpactResolver; a plain value object (no entity) so it travels in Messenger
 * messages and survives the deletion of a DRAFT or of the absence itself.
 *
 * The people are snapshotted from the CONCRETE source of the occurrence: the Mission's own
 * surgeon / instrumentist for N2/N3 (never the template), the post (or its
 * INSTRUMENTIST_OVERRIDE exception) for N1.
 */
final class AbsenceImpact
{
    public const ROLE_SURGEON       = 'SURGEON';
    public const ROLE_INSTRUMENTIST = 'INSTRUMENTIST';

    public function __construct(
        public readonly int $absenceId,
        public readonly int $absentUserId,
        /** SURGEON | INSTRUMENTIST — who is absent */
        public readonly string $absentRole,
        /** Y-m-d */
        public readonly string $date,
        public readonly AbsenceImpactLevel $level,
        public readonly AbsenceImpactConsequence $consequence,
        public readonly ?int $missionId,
        public readonly ?string $missionStatus,
        public readonly ?int $postId,
        public readonly ?int $siteId,
        public readonly ?string $siteName,
        public readonly ?int $surgeonId,
        public readonly ?string $surgeonName,
        public readonly ?int $instrumentistId,
        public readonly ?string $instrumentistName,
        /** H:i, null when unknown */
        public readonly ?string $startTime,
        public readonly ?string $endTime,
    ) {
    }

    /**
     * The person to inform: the OTHER member of the pair — the planned instrumentist when the
     * surgeon is absent, the surgeon when the instrumentist is absent. Null when nobody is
     * planned (e.g. an OPEN mission, or a post without a usual instrumentist).
     */
    public function recipientId(): ?int
    {
        return $this->absentRole === self::ROLE_SURGEON ? $this->instrumentistId : $this->surgeonId;
    }

    /** Stable identity of the occurrence for this absence and level — idempotency anchor. */
    public function key(): string
    {
        $anchor = $this->missionId !== null ? 'mission:' . $this->missionId : 'post:' . $this->postId;

        return sprintf('absence:%d:%s:%s:%s', $this->absenceId, $this->level->value, $this->date, $anchor);
    }

    /** Same occurrence regardless of the absence row (used to diff two ranges of one absence). */
    public function occurrenceKey(): string
    {
        return sprintf('%s:%s:%s', $this->level->value, $this->date, $this->missionId !== null ? 'mission:' . $this->missionId : 'post:' . $this->postId);
    }

    public function periodLabel(): ?string
    {
        if ($this->startTime === null) {
            return null;
        }

        return ((int) substr($this->startTime, 0, 2)) < 12 ? 'Matin' : 'Après-midi';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'absenceId'         => $this->absenceId,
            'absentUserId'      => $this->absentUserId,
            'absentRole'        => $this->absentRole,
            'date'              => $this->date,
            'level'             => $this->level->value,
            'consequence'       => $this->consequence->value,
            'missionId'         => $this->missionId,
            'missionStatus'     => $this->missionStatus,
            'postId'            => $this->postId,
            'siteId'            => $this->siteId,
            'siteName'          => $this->siteName,
            'surgeonId'         => $this->surgeonId,
            'surgeonName'       => $this->surgeonName,
            'instrumentistId'   => $this->instrumentistId,
            'instrumentistName' => $this->instrumentistName,
            'startTime'         => $this->startTime,
            'endTime'           => $this->endTime,
        ];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['absenceId'], (int) $a['absentUserId'], (string) $a['absentRole'], (string) $a['date'],
            AbsenceImpactLevel::from($a['level']), AbsenceImpactConsequence::from($a['consequence']),
            $a['missionId'] ?? null, $a['missionStatus'] ?? null, $a['postId'] ?? null,
            $a['siteId'] ?? null, $a['siteName'] ?? null,
            $a['surgeonId'] ?? null, $a['surgeonName'] ?? null,
            $a['instrumentistId'] ?? null, $a['instrumentistName'] ?? null,
            $a['startTime'] ?? null, $a['endTime'] ?? null,
        );
    }
}
