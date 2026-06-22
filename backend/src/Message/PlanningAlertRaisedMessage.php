<?php

namespace App\Message;

/**
 * Payload dispatched via the Messenger bus when a new PlanningAlert is raised.
 * PlanningAlertRaisedMessageHandler (Batch 7) consumes this to fan out notifications,
 * respecting each recipient's NotificationPreferenceResolver result.
 *
 * Generic by design — mission/site/date/type only, never patient data (Mission has none
 * to begin with, but this is the contract going forward for any future field added here).
 *
 * Anti-spam rule: only ever construct one of these per newly-created PlanningAlert
 * (never for idempotent no-ops, never for every absence edit when nothing changed).
 */
final class PlanningAlertRaisedMessage
{
    public function __construct(
        public readonly int    $alertId,
        public readonly string $alertType,
        public readonly int    $missionId,
        public readonly ?int   $siteId,
        public readonly ?string $siteName,
        public readonly string $missionDate,
        public readonly ?int   $absenceId,
        public readonly int    $surgeonId,
        public readonly ?int   $instrumentistId,
        /** Recipient user IDs who should be notified about this alert. */
        public readonly array  $recipientUserIds,
        public readonly string $detectedAt,
    ) {}
}
