<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\ShiftPeriodConfig;

/** H5b (D-132) — the hours of a site's shift period: they set the N1 occurrence hours (D-103). */
final class ShiftPeriodConfigDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return ShiftPeriodConfig::class; }

    public function trackedFields(): array
    {
        return ['site' => 'Site', 'period' => 'Période', 'startTime' => 'Début', 'endTime' => 'Fin', 'active' => 'Active'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var ShiftPeriodConfig $p */
        $p      = $c->entity;
        $site   = $p->getSite()?->getName() ?? '—';
        $period = FrenchLabels::PERIODS[$p->getPeriod()?->value ?? ''] ?? (string) $p->getPeriod()?->value;
        $actor  = self::actorName($c);
        $label  = sprintf('Période %s — %s', $period, $site);

        [$type, $summary] = match (true) {
            $c->action === GovernedChange::CREATED => [ActivityEventType::SHIFT_PERIOD_CREATED,
                sprintf('%s a créé la période %s de %s (%s–%s)', $actor, $period, $site, $p->getStartTime()?->format('H:i') ?? '—', $p->getEndTime()?->format('H:i') ?? '—')],
            $c->action === GovernedChange::DELETED || ($c->changed('active') && !$p->isActive()) => [ActivityEventType::SHIFT_PERIOD_DEACTIVATED,
                sprintf('%s a désactivé la période %s de %s', $actor, $period, $site)],
            default => [ActivityEventType::SHIFT_PERIOD_UPDATED,
                sprintf('%s a modifié la période %s de %s : %s', $actor, $period, $site, self::diffText($c, null, ['period' => FrenchLabels::PERIODS]))],
        };

        return $this->record($type, $c, 'ShiftPeriodConfig', $label, $summary,
            ['siteId' => $p->getSite()?->getId(), 'siteName' => $p->getSite()?->getName(), 'period' => $p->getPeriod()?->value],
            siteId: $p->getSite()?->getId());
    }
}
