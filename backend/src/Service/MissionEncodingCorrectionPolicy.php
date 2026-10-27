<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;

/**
 * D-148 — pourquoi l'encodage d'une mission ne peut PAS être rouvert, lu par
 * MissionActionsService ('reopen' n'est pas proposé) et par MissionMapper
 * (`encodingCorrectionBlockedReason`, affiché tel quel par « Suivi des encodages »).
 * Aucune règle nouvelle : ce sont exactement les deux refus de
 * MissionEncodingWorkflowService::reopen() (D-070 CLOSED terminal, D-073 calcul LOCKED),
 * qui reste l'autorité au moment de la transition (mêmes messages).
 */
final class MissionEncodingCorrectionPolicy
{
    /**
     * D-153 — statuses in which a manager corrects an encoding directly (interventions,
     * material, real hours). VALIDATED is never one of them: a validated encoding is
     * locked (encodingLockedAt) and goes through reopen() first; CLOSED is terminal;
     * DRAFT / OPEN / CANCELLED / REJECTED have nothing to correct.
     */
    public const MANAGER_CORRECTABLE_STATUSES = [
        MissionStatus::DECLARED,
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::SUBMITTED,
    ];

    public const FINANCIAL_RECALCULATION_NOTICE = "Un calcul financier existe pour cette mission et reflète l'encodage tel qu'il était à sa validation. Après correction et revalidation, recalculez-le depuis le détail de la mission (aucun recalcul ni aucune facturation automatiques).";

    public const CLOSED_MESSAGE = "Mission clôturée : son encodage ne peut plus être rouvert.";
    public const FINANCIAL_LOCK_MESSAGE = "Verrou financier : le calcul de cette mission est intégré à un document financier. Son encodage ne peut plus être rouvert.";

    /**
     * D-153 — the manager may correct interventions and material right now (same rule for
     * MissionVoter::EDIT_ENCODING and 'edit_encoding'). The write endpoints also go through
     * MissionEncodingGuard (lock / invoice), unchanged. A LOCKED calculation (part of a
     * financial document) forbids it in any status: its amounts are never changed silently.
     */
    public static function managerCanEditEncoding(Mission $mission): bool
    {
        if (!in_array($mission->getStatus(), self::MANAGER_CORRECTABLE_STATUSES, true) || $mission->isEncodingLocked()) {
            return false;
        }

        foreach ($mission->getFinancialCalculations() as $calculation) {
            if ($calculation->getStatus() === FinancialCalculationStatus::LOCKED) {
                return false;
            }
        }

        return true;
    }

    /**
     * D-153 — the manager may correct the instrumentist's real hours right now (same rule for
     * MissionExecutionVoter::UPDATE and 'edit_hours'): the hours are an instrumentist's,
     * there is none to correct without one.
     */
    public static function managerCanEditHours(Mission $mission): bool
    {
        return self::managerCanEditEncoding($mission) && $mission->getInstrumentist() !== null;
    }

    /**
     * D-153 — a CALCULATED / APPROVED calculation still active while the encoding is open for
     * correction (typically after a reopen, D-148): its amounts reflect the encoding as it was
     * validated. Never recalculated silently; a LOCKED one makes reopen impossible already.
     */
    public static function financialImpactNotice(Mission $mission): ?string
    {
        if (!self::managerCanEditEncoding($mission)) {
            return null;
        }

        foreach ($mission->getFinancialCalculations() as $calculation) {
            if (in_array($calculation->getStatus(), [FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::APPROVED], true)) {
                return self::FINANCIAL_RECALCULATION_NOTICE;
            }
        }

        return null;
    }

    /**
     * D-171 — statuses from which a manager finishes an encoding himself and validates it in
     * one step (« Terminer et valider »): the encoding is still open. SUBMITTED is validated
     * directly; DECLARED goes through approve first.
     */
    public const MANAGER_COMPLETABLE_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
    ];

    /**
     * D-171 — why the manager can NOT finish and validate the encoding himself right now
     * (null = possible). Single rule for MissionVoter::ENCODING_COMPLETE_AND_VALIDATE, the
     * 'complete_and_validate' action and MissionEncodingWorkflowService::completeAndValidate().
     * Content rules (hours, interventions, BLOCK justification) stay checked by the
     * transition itself, exactly as for complete() + validate().
     */
    public static function completeAndValidateBlockedReason(Mission $mission, \DateTimeImmutable $now): ?string
    {
        if (!in_array($mission->getStatus(), self::MANAGER_COMPLETABLE_STATUSES, true)) {
            return "L'encodage n'est pas en cours : il ne peut pas être terminé et validé depuis ce statut.";
        }
        if (!self::managerCanEditEncoding($mission)) {
            return "Encodage verrouillé : il ne peut plus être modifié.";
        }
        if (!$mission->hasEncodableActivity()) {
            return "Aucune instrumentiste n'est affectée : déclarez d'abord l'activité réalisée sans instrumentiste.";
        }
        $startAt = $mission->getStartAt();
        if ($startAt === null || $now < $startAt) {
            return "La mission n'a pas encore commencé.";
        }

        return null;
    }

    /**
     * D-171 — pourquoi « Valider l'encodage » n'est pas proposé au manager, servi tel quel par
     * le détail de mission (`encodingValidationBlockedReason`) : jamais un bouton désactivé
     * sans explication. null quand la validation est proposée ou sans objet (validée, clôturée).
     */
    public static function validationBlockedReason(Mission $mission, \DateTimeImmutable $now): ?string
    {
        $status = $mission->getStatus();
        if (in_array($status, [MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED], true)) {
            return null;
        }
        if (in_array($status, [MissionStatus::DRAFT, MissionStatus::CANCELLED, MissionStatus::REJECTED], true)) {
            return "Aucun encodage à valider pour cette mission.";
        }
        if ($status === MissionStatus::DECLARED) {
            return "Mission déclarée par l'instrumentiste : approuvez-la d'abord.";
        }
        if ($status === MissionStatus::OPEN) {
            return ExecutedWithoutInstrumentistPolicy::declarationBlockedReason($mission, $now) === null
                ? "Aucune instrumentiste n'a couvert cette mission. Si l'activité a eu lieu, déclarez-la réalisée sans instrumentiste pour l'encoder et la valider."
                : "La mission n'a pas encore été couverte ni réalisée.";
        }

        $blocked = self::completeAndValidateBlockedReason($mission, $now);
        if ($blocked !== null) {
            return $blocked;
        }

        return $mission->getInstrumentist() !== null
            ? "L'instrumentiste n'a pas encore soumis son encodage. Vous pouvez le terminer et le valider vous-même."
            : "L'encodage n'a pas encore été soumis. Vous pouvez le terminer et le valider vous-même.";
    }

    public static function reopenBlockedReason(Mission $mission): ?string
    {
        if ($mission->getStatus() === MissionStatus::CLOSED) {
            return self::CLOSED_MESSAGE;
        }

        if ($mission->getStatus() !== MissionStatus::VALIDATED) {
            return null;
        }

        foreach ($mission->getFinancialCalculations() as $calculation) {
            if ($calculation->getStatus() === FinancialCalculationStatus::LOCKED) {
                return self::FINANCIAL_LOCK_MESSAGE;
            }
        }

        return null;
    }
}
