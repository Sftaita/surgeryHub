<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\MaterialLine;

/**
 * H5b (D-132) — material used on a mission (billing basis): added, quantity / article changed,
 * removed. The free comment is recorded as "changed" only.
 */
final class MaterialLineDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return MaterialLine::class; }

    public function trackedFields(): array
    {
        return ['item' => 'Article', 'quantity' => 'Quantité', 'missionIntervention' => 'Intervention', 'comment' => 'Commentaire'];
    }

    public function redactedFields(): array
    {
        return ['comment'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var MaterialLine $l */
        $l       = $c->entity;
        $mission = $l->getMission();
        $item    = sprintf('« %s »', $l->getItem()?->getLabel() ?? '—');
        $where   = sprintf('mission #%d', (int) $mission?->getId());
        $actor   = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::MISSION_MATERIAL_LINE_ADDED, sprintf('%s a ajouté %s × %s au matériel utilisé de la %s', $actor, (string) $l->getQuantity(), $item, $where)],
            GovernedChange::DELETED => [ActivityEventType::MISSION_MATERIAL_LINE_REMOVED, sprintf('%s a retiré %s du matériel utilisé de la %s', $actor, $item, $where)],
            default => [ActivityEventType::MISSION_MATERIAL_LINE_UPDATED, sprintf('%s a modifié le matériel %s de la %s : %s', $actor, $item, $where, self::diffText($c))],
        };

        return $this->record($type, $c, 'Mission', sprintf('Mission #%d', (int) $mission?->getId()), $summary, [
            'missionId' => $mission?->getId(), 'missionInterventionId' => $l->getMissionIntervention()?->getId(),
            'materialItemId' => $l->getItem()?->getId(), 'materialItemLabel' => $l->getItem()?->getLabel(), 'quantity' => $l->getQuantity(),
        ], siteId: $mission?->getSite()?->getId(), missionId: $mission?->getId(), causeAnchors: $mission !== null ? ['mission:' . $mission->getId()] : []);
    }
}
