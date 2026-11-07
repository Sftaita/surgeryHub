<?php

namespace App\Service;

/**
 * D-177 — result of ReleasedRoomRequestMatcher for one surgeon request. Serialized as-is in
 * the request payloads (`releasedRoomMatch`): the frontend never recomputes a match.
 */
final class RoomRequestMatch
{
    /** @param list<RoomMatchCandidate> $candidates */
    public function __construct(
        public readonly string $status,
        public readonly array $candidates,
        public readonly ?int $certainSlotId,
        public readonly ?RoomMatchCandidate $takenOver = null,
    ) {
    }

    public function isCandidate(int $slotId): bool
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->slot->getId() === $slotId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public function candidateIds(): array
    {
        return array_map(static fn (RoomMatchCandidate $c) => (int) $c->slot->getId(), $this->candidates);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status'        => $this->status,
            'certainSlotId' => $this->certainSlotId,
            'candidates'    => array_map(static fn (RoomMatchCandidate $c) => $c->toArray(), $this->candidates),
            'takenOver'     => $this->takenOver?->toArray(),
        ];
    }
}
