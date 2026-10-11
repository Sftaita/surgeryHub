<?php

namespace App\Activity\AppendOnly;

/**
 * H1 (D-126) — the single rule behind activity_event's append-only guarantee, shared by
 * the DBAL middleware (SQL level) and the ORM guard (entity level).
 */
final class AppendOnlyGuard
{
    /**
     * Statements mutating or destroying existing rows of activity_event. Matches
     * `UPDATE activity_event`, `UPDATE x, activity_event`, `DELETE FROM activity_event`,
     * `DELETE a FROM activity_event a`, `TRUNCATE [TABLE] activity_event`,
     * `REPLACE INTO activity_event`, with or without backticks/schema prefix.
     */
    private const FORBIDDEN = '/^\s*(?:UPDATE\b[^;]*?|DELETE\b[^;]*?\bFROM\s+|TRUNCATE\s+(?:TABLE\s+)?|REPLACE\s+(?:INTO\s+)?)(?:`?\w+`?\.)?`?activity_event`?(?:\s|$|,|;)/i';

    private static int $maintenanceDepth = 0;

    public static function assertAllowed(string $sql): void
    {
        if (self::$maintenanceDepth > 0) {
            return;
        }

        if (stripos($sql, 'activity_event') === false) {
            return; // fast path for the overwhelming majority of statements
        }

        if (preg_match(self::FORBIDDEN, $sql)) {
            throw new ActivityEventImmutableException(
                'activity_event is append-only: UPDATE/DELETE/TRUNCATE/REPLACE are refused by the application.',
            );
        }
    }

    /**
     * Explicit, exceptional maintenance outside the normal business flow (never called by
     * a controller or a business service). Lifts the guard for exactly this callback.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function runMaintenance(callable $operation): mixed
    {
        self::$maintenanceDepth++;
        try {
            return $operation();
        } finally {
            self::$maintenanceDepth--;
        }
    }
}
