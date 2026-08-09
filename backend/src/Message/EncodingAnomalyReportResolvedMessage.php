<?php

namespace App\Message;

/**
 * Lot 6 (D-100) — dispatché après qu'un manager résout un signalement d'anomalie
 * d'encodage. Prévient le chirurgien requester — même famille que
 * SurgeonMissionRequestDecidedMessage (D-099) : push d'abord, repli email.
 */
final class EncodingAnomalyReportResolvedMessage
{
    public function __construct(
        public readonly int $reportId,
        public readonly int $missionId,
        public readonly int $surgeonId,
        public readonly string $resolutionComment,
    ) {
    }
}
