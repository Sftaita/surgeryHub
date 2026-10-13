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
    public const CLOSED_MESSAGE = "Mission clôturée : son encodage ne peut plus être rouvert.";
    public const FINANCIAL_LOCK_MESSAGE = "Verrou financier : le calcul de cette mission est intégré à un document financier. Son encodage ne peut plus être rouvert.";

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
