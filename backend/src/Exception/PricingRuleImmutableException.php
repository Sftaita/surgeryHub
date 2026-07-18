<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — thrown by PricingRuleVersioningService
 * when an operation would mutate a PricingRule whose validFrom is already reached
 * (active or past — "dès que validFrom <= now, elle appartient à l'historique").
 * Only future, not-yet-applicable rules may be freely edited/cancelled — see
 * updateFutureRule()/cancelFutureRule() vs replaceCurrentRuleFrom().
 *
 * Mapped to error.code = 'PRICING_RULE_IMMUTABLE' by ApiExceptionSubscriber.
 */
class PricingRuleImmutableException extends ConflictHttpException
{
}
