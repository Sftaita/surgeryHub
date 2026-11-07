<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\AbsenceCommunicationSiteConfig;

/** H5b (D-132) — the D-114 settings of a site: they decide who is told of a surgeon's absence. */
final class AbsenceCommunicationSiteConfigDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return AbsenceCommunicationSiteConfig::class; }

    public function trackedFields(): array
    {
        return [
            'notifyColleaguesEnabled' => 'Informer les collègues', 'notifyBlockManagementEnabled' => 'Informer la gestion du bloc',
            'blockManagementDelayDays' => 'Délai gestion du bloc (jours)',
        ];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action === GovernedChange::DELETED) {
            return null;
        }
        /** @var AbsenceCommunicationSiteConfig $s */
        $s    = $c->entity;
        $site = $s->getSite()?->getName() ?? '—';
        // §28 — AbsenceCommunicationSiteConfigService materialises the default row (everything
        // off) and flushes before applying the manager's change: that row is not an operation.
        // The update that follows carries the real diff (from the defaults), once.
        if ($c->action === GovernedChange::CREATED && !$s->isNotifyColleaguesEnabled() && !$s->isNotifyBlockManagementEnabled() && $s->getBlockManagementDelayDays() === null) {
            return null;
        }

        return $this->record(ActivityEventType::ABSENCE_COMMUNICATION_SETTINGS_UPDATED, $c, 'AbsenceCommunicationSiteConfig',
            sprintf('Communication des absences — %s', $site),
            sprintf('%s a modifié la communication des absences chirurgiens de %s : %s', self::actorName($c), $site,
                $c->action === GovernedChange::CREATED ? self::initialText($c, array_keys($this->trackedFields())) : self::diffText($c)),
            ['siteId' => $s->getSite()?->getId(), 'siteName' => $site], siteId: $s->getSite()?->getId());
    }
}
