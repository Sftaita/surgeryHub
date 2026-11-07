<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-172 — la période tarifaire modifiée contient des calculs financiers verrouillés : leurs
 * montants sont définitifs (facture ou relevé émis), le changement est refusé en entier.
 *
 * Mapped to error.code = 'INSTRUMENTIST_RATE_LOCKED_PERIOD' by ApiExceptionSubscriber.
 */
class InstrumentistRateLockedPeriodException extends ConflictHttpException
{
}
