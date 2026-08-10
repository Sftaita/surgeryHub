<?php

namespace App\Exception;

use App\Enum\EligibilityReason;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown whenever an assign/reassign/schedule-change would put an ineligible
 * instrumentist (ABSENT, SCHEDULE_CONFLICT, INACTIVE, NO_SITE_MEMBERSHIP — D-101) on a
 * Mission. Mapped to error.code = 'INSTRUMENTIST_INCOMPATIBLE' by ApiExceptionSubscriber;
 * error.violations carries one entry per failing EligibilityReason (raw enum value, e.g.
 * "ABSENT") so the frontend can render the exact reason(s) without re-deriving them.
 */
class InstrumentistIneligibleException extends ConflictHttpException
{
    /** @param EligibilityReason[] $reasons */
    public function __construct(private readonly array $reasons)
    {
        $labels = array_map(static fn (EligibilityReason $r) => $r->label(), $reasons);
        parent::__construct('Instrumentiste non éligible : ' . implode(', ', $labels));
    }

    /** @return EligibilityReason[] */
    public function getReasons(): array
    {
        return $this->reasons;
    }
}
