<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\MissionIntervention;

/**
 * H5b (D-132) — an intervention of a mission's encoding (billing basis): added, changed
 * (type, firm, representative, mandatory choice), removed. Labels and ids only — never any
 * patient data (a mission never carries any).
 */
final class MissionInterventionDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return MissionIntervention::class; }

    public function trackedFields(): array
    {
        return [
            'interventionType' => 'Type d\'intervention', 'code' => 'Code', 'label' => 'Libellé', 'primaryFirm' => 'Firme',
            'representativePresent' => 'Représentant présent', 'selectedChoiceOption' => 'Choix obligatoire',
        ];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var MissionIntervention $i */
        $i       = $c->entity;
        $mission = $i->getMission();
        $name    = sprintf('« %s »', $i->getLabel() ?? $i->getInterventionType()?->getLabel() ?? '—');
        $where   = sprintf('mission #%d', (int) $mission?->getId());
        $actor   = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::MISSION_INTERVENTION_ADDED, sprintf('%s a ajouté l\'intervention %s à l\'encodage de la %s', $actor, $name, $where)],
            GovernedChange::DELETED => [ActivityEventType::MISSION_INTERVENTION_REMOVED, sprintf('%s a retiré l\'intervention %s de l\'encodage de la %s', $actor, $name, $where)],
            default => [ActivityEventType::MISSION_INTERVENTION_UPDATED, sprintf('%s a modifié l\'intervention %s de la %s : %s', $actor, $name, $where, self::diffText($c))],
        };

        return $this->record($type, $c, 'Mission', sprintf('Mission #%d', (int) $mission?->getId()), $summary, [
            'missionId' => $mission?->getId(), 'missionInterventionId' => $c->entityId, 'interventionTypeId' => $i->getInterventionType()?->getId(),
            'firmId' => $i->getPrimaryFirm()?->getId(), 'firmName' => $i->getPrimaryFirm()?->getName(),
        ], siteId: $mission?->getSite()?->getId(), missionId: $mission?->getId(), causeAnchors: $mission !== null ? ['mission:' . $mission->getId()] : []);
    }
}
