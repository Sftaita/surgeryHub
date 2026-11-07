<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\SiteGroup;

/** H5b (D-132) — a group of sites (group-scoped generation and visibility, D-115bis). */
final class SiteGroupDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return SiteGroup::class; }

    public function trackedFields(): array
    {
        return ['name' => 'Nom'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var SiteGroup $g */
        $g     = $c->entity;
        $name  = $g->getName() ?? sprintf('Groupe #%d', $c->entityId);
        $actor = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::SITE_GROUP_CREATED, sprintf('%s a créé le groupe de sites %s', $actor, $name)],
            GovernedChange::DELETED => [ActivityEventType::SITE_GROUP_DELETED, sprintf('%s a supprimé le groupe de sites %s', $actor, $name)],
            default => [ActivityEventType::SITE_GROUP_UPDATED, sprintf('%s a modifié le groupe de sites %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'SiteGroup', $name, $summary, ['siteGroupId' => $c->entityId, 'siteGroupName' => $name]);
    }
}
