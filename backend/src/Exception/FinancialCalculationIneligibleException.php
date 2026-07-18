<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — la mission elle-même n'est pas dans un
 * état permettant un calcul (statut, instrumentiste manquant, calcul déjà actif...).
 * Distincte de FinancialCalculationAnomaliesException : ici, on ne peut même pas
 * commencer à résoudre les tarifs.
 *
 * Mapped to error.code = 'FINANCIAL_CALCULATION_INELIGIBLE' by ApiExceptionSubscriber.
 */
class FinancialCalculationIneligibleException extends ConflictHttpException
{
}
