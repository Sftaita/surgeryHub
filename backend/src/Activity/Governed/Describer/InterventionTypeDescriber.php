<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\InterventionType;

/**
 * H5b (D-132) — an intervention type of the catalogue. A merge (mergedInto set) is already
 * the AuditEvent INTERVENTION_TYPE_MERGED (mirrored): the update it implies is not doubled.
 */
final class InterventionTypeDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return InterventionType::class; }

    public function trackedFields(): array
    {
        return ['code' => 'Code', 'label' => 'Libellé', 'specialty' => 'Spécialité', 'active' => 'Actif', 'allowedWithoutFirm' => 'Autorisé sans firme', 'mergedInto' => 'Fusionné dans'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->changed('mergedInto')) {
            return null;
        }
        /** @var InterventionType $t */
        $t     = $c->entity;
        $name  = sprintf('« %s »%s', $t->getLabel() ?? '—', $t->getCode() ? ' (' . $t->getCode() . ')' : '');
        $actor = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::INTERVENTION_TYPE_CREATED, sprintf('%s a créé le type d\'intervention %s', $actor, $name)],
            GovernedChange::DELETED => [ActivityEventType::INTERVENTION_TYPE_DELETED, sprintf('%s a supprimé le type d\'intervention %s', $actor, $name)],
            default => [ActivityEventType::INTERVENTION_TYPE_UPDATED, sprintf('%s a modifié le type d\'intervention %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'InterventionType', $name, $summary, ['interventionTypeId' => $c->entityId, 'interventionTypeCode' => $t->getCode()]);
    }
}
