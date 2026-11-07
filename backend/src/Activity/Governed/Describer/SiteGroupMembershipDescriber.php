<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\SiteGroupMembership;

/** H5b (D-132) — a site added to / removed from a group of sites. */
final class SiteGroupMembershipDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return SiteGroupMembership::class; }

    public function trackedFields(): array
    {
        return ['group' => 'Groupe', 'site' => 'Site'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action === GovernedChange::UPDATED) {
            return null;
        }
        /** @var SiteGroupMembership $m */
        $m     = $c->entity;
        $group = $m->getGroup()?->getName() ?? '—';
        $site  = $m->getSite()?->getName() ?? '—';
        $added = $c->action === GovernedChange::CREATED;

        return $this->record($added ? ActivityEventType::SITE_GROUP_SITE_ADDED : ActivityEventType::SITE_GROUP_SITE_REMOVED, $c, 'SiteGroup',
            $group, sprintf('%s a %s le site %s %s le groupe %s', self::actorName($c), $added ? 'ajouté' : 'retiré', $site, $added ? 'dans' : 'du', $group),
            ['siteGroupId' => $m->getGroup()?->getId(), 'siteGroupName' => $group, 'siteId' => $m->getSite()?->getId(), 'siteName' => $site],
            siteId: $m->getSite()?->getId());
    }
}
