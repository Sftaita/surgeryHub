<?php

namespace App\Message;

/**
 * Dispatched when a self-service absence (Lot 3, D-097) was created/updated and did NOT
 * raise any PlanningAlert (AbsenceImpactService::onAbsenceCreated()/onAbsenceUpdated()
 * returned an empty 'created' array) — the only case where a manager would otherwise learn
 * nothing at all about it. Never dispatched when at least one alert was raised: PLANNING_ALERT
 * already reaches every manager/admin for that case (see AbsenceImpactService::buildNotification()),
 * and this message must never duplicate it.
 *
 * Self-contained (recipient IDs pre-resolved at dispatch time), mirrors PlanningAlertRaisedMessage.
 */
final class AbsenceSelfDeclaredMessage
{
    public function __construct(
        public readonly int $absenceId,
        public readonly int $absentUserId,
        public readonly string $absentUserName,
        public readonly string $absentUserRole,
        public readonly string $dateStart,
        public readonly string $dateEnd,
        public readonly ?string $reason,
        /** Recipient user IDs (managers/admins) who should be notified in-app. */
        public readonly array $recipientUserIds,
    ) {}
}
