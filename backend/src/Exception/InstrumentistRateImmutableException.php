<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir de PricingRuleImmutableException
 * pour InstrumentistRate.
 *
 * Mapped to error.code = 'INSTRUMENTIST_RATE_IMMUTABLE' by ApiExceptionSubscriber.
 */
class InstrumentistRateImmutableException extends ConflictHttpException
{
}
