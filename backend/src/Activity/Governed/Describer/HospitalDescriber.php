<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\Hospital;

/**
 * H5b (D-132) — a site: name, address, time zone and the D-114 block-management contacts
 * (values masked by the payload policy). The photo is cosmetic: not tracked.
 */
final class HospitalDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return Hospital::class; }

    public function trackedFields(): array
    {
        return [
            'name' => 'Nom', 'address' => 'Adresse', 'timezone' => 'Fuseau horaire',
            'blockManagementContactEmail' => 'Contact gestion du bloc', 'blockManagementContactCc' => 'Copies gestion du bloc',
        ];
    }

    public function redactedFields(): array
    {
        return ['blockManagementContactEmail', 'blockManagementContactCc'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var Hospital $h */
        $h     = $c->entity;
        $name  = $h->getName() ?? sprintf('Site #%d', $c->entityId);
        $actor = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::SITE_CREATED, sprintf('%s a créé le site %s', $actor, $name)],
            GovernedChange::DELETED => [ActivityEventType::SITE_DELETED, sprintf('%s a supprimé le site %s', $actor, $name)],
            default => [ActivityEventType::SITE_UPDATED, sprintf('%s a modifié le site %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'Hospital', $name, $summary, ['siteId' => $c->entityId, 'siteName' => $name], siteId: $c->entityId);
    }
}
