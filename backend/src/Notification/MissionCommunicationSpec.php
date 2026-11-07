<?php

namespace App\Notification;

use App\Enum\MissionCommunicationSource;

/**
 * H4 (D-130) — carried by a NotificationIntent: "if this notification actually reaches its
 * recipient, these missions count as communicated to her, in this role, by this source".
 * Stored on the NotificationDispatch so the proof can arrive later (an email accepted by the
 * relay after the worker ran).
 */
final class MissionCommunicationSpec
{
    public const ROLE_INSTRUMENTIST = 'INSTRUMENTIST';
    public const ROLE_SURGEON       = 'SURGEON';

    /** @param list<int> $missionIds */
    public function __construct(
        public readonly MissionCommunicationSource $source,
        public readonly string $role,
        public readonly array $missionIds,
    ) {
    }

    /** @return array{source: string, role: string, missionIds: list<int>} */
    public function toArray(): array
    {
        return ['source' => $this->source->value, 'role' => $this->role, 'missionIds' => array_values(array_map('intval', $this->missionIds))];
    }

    /** @param array{source: string, role: string, missionIds: list<int>} $a */
    public static function fromArray(array $a): self
    {
        return new self(MissionCommunicationSource::from($a['source']), $a['role'], $a['missionIds']);
    }
}
