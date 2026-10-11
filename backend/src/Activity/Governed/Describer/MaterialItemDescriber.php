<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\MaterialItem;

/** H5b (D-132) — an article of the material catalogue (firm, reference, implant, billing status). */
final class MaterialItemDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return MaterialItem::class; }

    public function trackedFields(): array
    {
        return [
            'firm' => 'Firme', 'referenceCode' => 'Référence', 'label' => 'Libellé', 'unit' => 'Unité', 'isImplant' => 'Implant',
            'active' => 'Actif', 'implantType' => 'Type d\'implant', 'material' => 'Matériau', 'description' => 'Description',
            'billingStatus' => 'Statut de facturation',
        ];
    }

    public function redactedFields(): array
    {
        return ['description'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var MaterialItem $m */
        $m     = $c->entity;
        $name  = sprintf('« %s »%s', $m->getLabel() ?? '—', $m->getReferenceCode() ? ' (' . $m->getReferenceCode() . ')' : '');
        $actor = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::MATERIAL_ITEM_CREATED, sprintf('%s a créé l\'article %s%s', $actor, $name, $m->getFirm() ? ' (' . $m->getFirm()->getName() . ')' : '')],
            GovernedChange::DELETED => [ActivityEventType::MATERIAL_ITEM_DELETED, sprintf('%s a supprimé l\'article %s', $actor, $name)],
            default => [ActivityEventType::MATERIAL_ITEM_UPDATED, sprintf('%s a modifié l\'article %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'MaterialItem', $name, $summary, [
            'materialItemId' => $c->entityId, 'materialItemLabel' => $m->getLabel(), 'firmId' => $m->getFirm()?->getId(), 'firmName' => $m->getFirm()?->getName(),
        ]);
    }
}
