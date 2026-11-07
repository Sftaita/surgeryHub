<?php

namespace App\Service;

use App\Entity\MissionPublication;
use App\Enum\RoomTakeoverPoolReason;

/**
 * D-177 — how the mission of a room take-over was published (RoomTakeoverPriorityService::
 * publishTakeoverMission()): exclusive priority of the usual instrumentist, or the eligible
 * pool at once with the reason.
 */
final class TakeoverPublication
{
    private function __construct(
        public readonly MissionPublication $publication,
        public readonly ?RoomTakeoverPoolReason $poolReason,
    ) {
    }

    public static function priority(MissionPublication $publication): self
    {
        return new self($publication, null);
    }

    public static function pool(MissionPublication $publication, RoomTakeoverPoolReason $reason): self
    {
        return new self($publication, $reason);
    }

    public function isPriority(): bool
    {
        return $this->poolReason === null;
    }

    /** @return array<string, mixed> snapshot carried by ROOM_SLOT_TAKEN_OVER and its lifecycle message */
    public function toArray(): array
    {
        $target = $this->publication->getTargetInstrumentist();

        return [
            'mode'                    => $this->isPriority() ? 'PRIORITY' : 'POOL',
            'publicationId'           => $this->publication->getId(),
            'priorityInstrumentistId' => $this->isPriority() ? $target?->getId() : null,
            'expiresAt'               => $this->publication->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'poolReason'              => $this->poolReason?->value,
        ];
    }
}
