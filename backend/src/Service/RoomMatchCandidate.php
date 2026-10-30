<?php

namespace App\Service;

use App\Entity\ReleasedOperatingRoomSlot;

/** D-177 — one released room overlapping a surgeon request (ReleasedRoomRequestMatcher). */
final class RoomMatchCandidate
{
    /** @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable} $interval */
    public function __construct(
        public readonly ReleasedOperatingRoomSlot $slot,
        public readonly array $interval,
        public readonly bool $coversRequest,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $surgeon = $this->slot->getSurgeon();

        return [
            'slotId'          => $this->slot->getId(),
            'status'          => $this->slot->getStatus()->value,
            'occurrenceDate'  => $this->slot->getOccurrenceDate()->format('Y-m-d'),
            'period'          => $this->slot->getPeriod()->value,
            'startTime'       => $this->interval[0]->format('H:i'),
            'endTime'         => $this->interval[1]->format('H:i'),
            'originalSurgeon' => $surgeon !== null ? ['id' => $surgeon->getId(), 'name' => $surgeon->getDrName()] : null,
            'coversRequest'   => $this->coversRequest,
        ];
    }
}
