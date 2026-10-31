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
         * {status: 'PENDING'|'DECLINED', instrumentist: {id, name}, offeredAt, declinedAt,
         * priority, expiresAt, startsBeforeExpiry} (D-177: room take-over priority and its deadline).
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
        /**
         * D-155 — activity executed without instrumentist (null otherwise):
         * {declaredAt, declaredByName}. Hours never exist in this mode; billing is firm-only.
         */
        public readonly ?array $executedWithoutInstrumentist = null,
        /**
         * D-171 — managers only: why « Valider l'encodage » is not offered right now (encoding
         * still open, mission not covered, locked…), shown as-is under the disabled button.
         * Null when validation is offered or moot (MissionEncodingCorrectionPolicy).
         */
        public readonly ?string $encodingValidationBlockedReason = null,
        /**
         * D-171 — managers only, when « Terminer et valider » or « Valider » (SUBMITTED) is offered: fingerprint of the
         * encoding as read now (EncodingTakeoverService::revision()), sent back as
         * `expectedRevision`; a different one at confirmation time means someone changed it.
         */
        public readonly ?string $encodingRevision = null,
        /**
         * D-171 — managers only, when « Terminer et valider » is a takeover (instrumentist
         * assigned, encoding not submitted): {instrumentistName, hoursMissing}. The dialog then
         * requires an explicit confirmation (`takeoverConfirmed`).
         */
        public readonly ?array $encodingTakeover = null,
        /**
         * D-171 — managers and the assigned instrumentist: the last takeover of this encoding
         * {byName, at, hoursMissing} (audit MISSION_ENCODING_TAKEN_OVER), null if none.
         */
        public readonly ?array $lastEncodingTakeover = null,
        /**
         * D-171 — managers and the assigned instrumentist: interventions and material were
         * validated while the worked hours were missing, and they still are — only the hours
         * remain declarable ('edit_hours'). Never invented, never validated automatically.
         */
        public readonly bool $hoursPendingAfterValidation = false,
        /** D-172 — managers only: same block as the list (MissionEncodingOverviewService). */
        public readonly ?array $encoding = null,
    ) {}
}