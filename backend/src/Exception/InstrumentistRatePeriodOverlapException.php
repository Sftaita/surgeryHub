<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir de
 * PricingRulePeriodOverlapException pour InstrumentistRate : deux périodes de validité
 * ne peuvent jamais se chevaucher pour un même (instrumentist, rateType).
 *
 * Mapped to error.code = 'INSTRUMENTIST_RATE_PERIOD_OVERLAP' by ApiExceptionSubscriber.
 */
class InstrumentistRatePeriodOverlapException extends ConflictHttpException
{
}
