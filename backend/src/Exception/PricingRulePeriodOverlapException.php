<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown by PricingRuleWriteService when a create/update would produce two active
 * PricingRule on the same cible (firm + ruleType + interventionType|materialItem) with
 * overlapping validity periods — checked under a pessimistic lock on the cible so
 * concurrent writers cannot both pass the check (see D-067, PricingRuleWriteService).
 *
 * Mapped to error.code = 'PRICING_RULE_PERIOD_OVERLAP' by ApiExceptionSubscriber so API
 * consumers can show a precise message instead of a generic 409 CONFLICT.
 */
class PricingRulePeriodOverlapException extends ConflictHttpException
{
}
