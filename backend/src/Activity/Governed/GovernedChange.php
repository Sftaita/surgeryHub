<?php

namespace App\Activity\Governed;

use App\Entity\User;

/**
 * H5b (D-132) — one change of one governed entity in one flush, as the listener observed it:
 * created, updated (with the diff of the tracked fields only) or deleted (with the entity
 * still readable — captured before its row disappears).
 */
final class GovernedChange
{
    public const CREATED = 'CREATED';
    public const UPDATED = 'UPDATED';
    public const DELETED = 'DELETED';

    /**
     * @param list<array{field: string, label: string, from: mixed, to: mixed, fromId: ?int, toId: ?int, redacted: bool}> $changes
     * @param array<string, mixed> $original field => value BEFORE the flush (UPDATED only, tracked fields)
     */
    public function __construct(
        public readonly string $action,
        public readonly object $entity,
        public readonly int $entityId,
        public readonly array $changes,
        public readonly array $original,
        public readonly ?User $actor,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function changed(string $field): bool
    {
        foreach ($this->changes as $change) {
            if ($change['field'] === $field) {
                return true;
            }
        }

        return false;
    }

    /** @return array{field: string, label: string, from: mixed, to: mixed, fromId: ?int, toId: ?int, redacted: bool}|null */
    public function change(string $field): ?array
    {
        foreach ($this->changes as $change) {
            if ($change['field'] === $field) {
                return $change;
            }
        }

        return null;
    }
}
