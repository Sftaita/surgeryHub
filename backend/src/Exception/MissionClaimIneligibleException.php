<?php

namespace App\Exception;

use App\Entity\Absence;
use App\Enum\EligibilityReason;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * BUG A (2026-09-09) — thrown by MissionPostDeployService::claim() when
 * MissionEligibilityService::evaluate() refuses the candidate. Replaces a generic
 * `ConflictHttpException('Not eligible to claim this mission: ' . labels)`, whose message
 * the frontend had no reliable way to act on short of parsing free text (explicitly
 * forbidden — see docs/decisions.md). Mapped to error.code = 'MISSION_CLAIM_INELIGIBLE' by
 * ApiExceptionSubscriber; error.reason carries the primary EligibilityReason (raw enum
 * value, e.g. "ABSENT") and error.violations carries one entry per failing reason, same
 * convention as InstrumentistIneligibleException.
 *
 * When the primary reason is ABSENT, error.absenceId/error.date are populated (from
 * MissionEligibilityService::findBlockingAbsence(), never re-derived here) so the frontend
 * can offer "Retirer mon absence pour ce jour" without a second round trip just to find
 * out which Absence row is blocking. Both stay null for every other reason.
 */
class MissionClaimIneligibleException extends ConflictHttpException
{
    /** @param EligibilityReason[] $reasons */
    public function __construct(
        private readonly array $reasons,
        private readonly ?\DateTimeImmutable $date = null,
        private readonly ?Absence $blockingAbsence = null,
    ) {
        $labels = array_map(static fn (EligibilityReason $r) => $r->label(), $reasons);
        parent::__construct('Not eligible to claim this mission: ' . implode(', ', $labels));
    }

    /** @return EligibilityReason[] */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function getPrimaryReason(): ?EligibilityReason
    {
        return $this->reasons[0] ?? null;
    }

    /** The mission's own date (day granularity) — always set, regardless of primary reason. */
    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function getBlockingAbsence(): ?Absence
    {
        return $this->blockingAbsence;
    }
}
