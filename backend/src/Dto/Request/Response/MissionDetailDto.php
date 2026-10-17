<?php

namespace App\Dto\Request\Response;

final class MissionDetailDto
{
    /**
     * @param string[] $allowedActions
     */
    public function __construct(
        public readonly int $id,
        public readonly HospitalSlimDto $site,
        public readonly ?string $startAt,
        public readonly ?string $endAt,
        public readonly string $schedulePrecision,
        public readonly string $type,
        public readonly string $status,
        public readonly UserSlimDto $surgeon,
        public readonly ?UserSlimDto $instrumentist,
        public readonly array $allowedActions,
        public readonly ?string $noMaterialComment,
        public readonly ?bool $submittedWithoutMaterial,
        /** Socle chirurgien (Lot 2, D-095) — voir MissionListDto::$covered. */
        public readonly bool $covered,
        /**
         * D-120 — infos de relance d'encodage (cockpit Suivi des encodages), lues via
         * EncodingReminderService, jamais recalculées côté frontend.
         */
        public readonly ?string $automaticReminderSentAt,
        public readonly ?string $nextAutomaticReminderAt,
        public readonly ?string $lastManualReminderAt,
        public readonly ?string $lastManualReminderByName,
        /** D-143 — canal de la dernière relance manuelle ('push'|'email'), pour le retour au manager. */
        public readonly ?string $lastManualReminderChannel,
        /**
         * D-143 — relances automatiques déjà envoyées (manager uniquement, [] sinon) :
         * [{stage: 'D1'|'D2'|'D4', sentAt, channels: ['IN_APP','PUSH'|'EMAIL'…], managersNotified}].
         *
         * @var list<array<string,mixed>>
         */
        public readonly array $automaticReminders,
        /** D-143 — dernière soumission de l'encodage (null si jamais soumis). */
        public readonly ?string $submittedAt,
        /**
         * D-125 — nominative request state, computed server-side (MissionDispatchService::
         * pendingOffer()/lastDeclinedOffer()), never re-derived client-side:
         * {status: 'PENDING'|'DECLINED', instrumentist: {id, name}, offeredAt, declinedAt}.
         * PENDING = OPEN, not covered, awaiting that instrumentist's answer. Null otherwise.
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $targetedOffer = null,
        /**
         * D-148 — pourquoi l'encodage ne peut pas être rouvert (mission CLOSED ou calcul
         * financier LOCKED), servi aux managers seulement ; null quand rien ne s'y oppose ou
         * que la question ne se pose pas (MissionEncodingCorrectionPolicy).
         */
        public readonly ?string $encodingCorrectionBlockedReason = null,
        /**
         * D-151 — the current affectation, for the mission's surgeon and the managers only
         * (null otherwise): {id, instrumentist: {id, name}, source, assignedAt,
         * refusalDeadlineAt (voluntary acceptances only), refusable, refusalBlockedReason,
         * reviewRequestedAt}. Computed by MissionAssignmentRefusalPolicy, never client-side.
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $currentAssignment = null,
        /**
         * D-151 — 'ENDED' when the viewing instrumentist had an affectation to this mission
         * that has ended ("Affectation annulée" — never why). Null otherwise.
         */
        public readonly ?string $myAssignmentStatus = null,
        /**
         * D-153 — managers only: why a correction has financial consequences (an active
         * CALCULATED / APPROVED calculation to recalculate after revalidation), null otherwise.
         */
        public readonly ?string $encodingCorrectionFinancialNotice = null,
    ) {}
}