<?php

namespace App\Message;

/**
 * Lot 6 (D-100) — dispatché après qu'un chirurgien signale une anomalie d'encodage.
 * Prévient les managers/admins actifs — même famille que
 * SurgeonMissionRequestCreatedMessage (D-099) : recipientUserIds figé au moment de la
 * création, in-app + push uniquement côté handler, jamais email.
 */
final class EncodingAnomalyReportCreatedMessage
{
    /** @param int[] $recipientUserIds */
    public function __construct(
        public readonly int $reportId,
        public readonly int $missionId,
        public readonly int $surgeonId,
        public readonly string $surgeonName,
        public readonly string $type,
        public readonly array $recipientUserIds,
    ) {
    }
}
