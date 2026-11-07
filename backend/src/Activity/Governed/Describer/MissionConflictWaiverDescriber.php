<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\GovernedChange;
use App\Entity\MissionConflictWaiver;
use App\Enum\ActivityEventType;

/**
 * H5b (D-132) — a manager's explicit authorisation to deploy despite one cross-site conflict
 * (D-091), and its invalidation. A real decision that was silent: who authorised which pair of
 * missions. The free-text reason is never copied (hasReason only).
 */
final class MissionConflictWaiverDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return MissionConflictWaiver::class; }

    public function trackedFields(): array
    {
        return ['invalidatedAt' => 'Invalidée le'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var MissionConflictWaiver $w */
        $w = $c->entity;
        if ($c->action === GovernedChange::DELETED || ($c->action === GovernedChange::UPDATED && $w->getInvalidatedAt() === null)) {
            return null;
        }
        $low   = $w->getMissionLow()?->getId();
        $high  = $w->getMissionHigh()?->getId();
        $pair  = sprintf('missions #%d et #%d', (int) $low, (int) $high);
        $grant = $c->action === GovernedChange::CREATED;

        return $this->record(
            $grant ? ActivityEventType::PLANNING_CONFLICT_WAIVER_GRANTED : ActivityEventType::PLANNING_CONFLICT_WAIVER_INVALIDATED,
            new GovernedChange($c->action, $c->entity, $c->entityId, [], [], $c->actor ?? ($grant ? $w->getAuthorizedBy() : null), $c->occurredAt),
            'MissionConflictWaiver', sprintf('Conflit autorisé — %s', $pair),
            $grant
                ? sprintf('%s a autorisé le déploiement malgré le conflit entre les %s (%s)', ActivityJournal::displayName($w->getAuthorizedBy()) ?? self::actorName($c), $pair, $w->getMissionLowStartAt()->format('d/m/Y'))
                : sprintf('Autorisation de conflit entre les %s invalidée', $pair),
            [
                'missionId' => $low, 'siteId' => $w->getSiteId(), 'surgeonId' => $w->getSurgeonId(), 'instrumentistId' => $w->getInstrumentistId(),
                'reason' => $grant ? ($w->getReason() !== null && trim($w->getReason()) !== '' ? 'HAS_REASON' : null) : 'INVALIDATED',
            ],
            siteId: $w->getSiteId(), missionId: $low,
            causeAnchors: array_values(array_filter([$low !== null ? 'mission:' . $low : null, $high !== null ? 'mission:' . $high : null])),
        );
    }
}
