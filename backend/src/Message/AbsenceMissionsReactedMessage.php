<?php

namespace App\Message;

/**
 * Dispatched once per absence create/update processing run by AbsenceMissionReactionService,
 * after ALL affected missions in that run have been mutated and committed — never one message
 * per mission. The handler groups $missions by recipient and sends exactly one recap email per
 * recipient (per §7 "un seul email récapitulatif par destinataire et par traitement d'absence").
 *
 * Never dispatched when $missions is empty (nothing to report) — that is also what makes the
 * whole pipeline naturally idempotent: re-processing an absence whose overlapping missions have
 * already all transitioned out of scope finds nothing new, so nothing is dispatched, so no
 * duplicate email is ever sent (see AbsenceMissionReactionService's class docblock).
 *
 * Names are snapshotted directly on each mission entry (never resolved from a FK at handler
 * read-time) — consistent with MissionLifecycleChangedMessage's own R-12 convention. No patient
 * or financial data anywhere in this payload.
 */
final class AbsenceMissionsReactedMessage
{
    /**
     * @param 'INSTRUMENTIST'|'SURGEON' $absentUserRole
     * @param array<int, array{
     *     missionId: int,
     *     changeType: 'RELEASED'|'CANCELLED',
     *     date: string,
     *     moment: ?string,
     *     horaire: ?string,
     *     siteName: ?string,
     *     surgeonId: ?int,
     *     surgeonName: ?string,
     *     instrumentistId: ?int,
     *     instrumentistName: ?string,
     * }> $missions
     */
    public function __construct(
        public readonly int $absenceId,
        public readonly int $absentUserId,
        public readonly string $absentUserRole,
        public readonly int $actorId,
        public readonly array $missions,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
