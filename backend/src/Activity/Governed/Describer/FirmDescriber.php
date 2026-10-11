<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\Firm;

/**
 * H5b (D-132) — a firm: name, activity, country, representative and billing contact (the
 * recipients of its invoices — addresses and phone recorded as "changed" only). The logo is
 * cosmetic: not tracked.
 */
final class FirmDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return Firm::class; }

    public function trackedFields(): array
    {
        return [
            'name' => 'Nom', 'active' => 'Active', 'country' => 'Pays', 'representative' => 'Représentant',
            'phone' => 'Téléphone', 'billingEmail' => 'Email de facturation', 'billingEmailCc' => 'Copies de facturation',
        ];
    }

    public function redactedFields(): array
    {
        return ['phone', 'billingEmail', 'billingEmailCc'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var Firm $f */
        $f     = $c->entity;
        $name  = $f->getName() ?? sprintf('Firme #%d', $c->entityId);
        $actor = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::FIRM_CREATED, sprintf('%s a créé la firme %s', $actor, $name)],
            GovernedChange::DELETED => [ActivityEventType::FIRM_DELETED, sprintf('%s a supprimé la firme %s', $actor, $name)],
            default => [ActivityEventType::FIRM_UPDATED, sprintf('%s a modifié la firme %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'Firm', $name, $summary, ['firmId' => $c->entityId, 'firmName' => $name]);
    }
}
