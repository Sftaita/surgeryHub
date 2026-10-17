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
