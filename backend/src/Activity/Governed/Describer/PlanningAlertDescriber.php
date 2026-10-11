<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\PlanningAlert;

/**
 * H5b (D-132) — an alert RAISED. Its later transitions (acknowledged, resolved, ignored,
 * reassigned, opened to the pool) are manager actions already written as AuditEvent and
 * mirrored by H1: only the creation was silent.
 */
final class PlanningAlertDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return PlanningAlert::class; }

    public function trackedFields(): array
    {
        return ['type' => 'Type', 'mission' => 'Mission', 'absence' => 'Absence'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action !== GovernedChange::CREATED) {
            return null;
        }
        /** @var PlanningAlert $a */
        $a       = $c->entity;
        $kind    = FrenchLabels::ALERT_TYPES[$a->getType()?->value ?? ''] ?? (string) $a->getType()?->value;
        $mission = $a->getMission();
        $where   = $mission !== null
            ? sprintf(' — mission #%d du %s%s', (int) $mission->getId(), FrenchLabels::date($mission->getStartAt()), $mission->getSite() !== null ? ' à ' . $mission->getSite()->getName() : '')
            : '';
        $anchors = array_values(array_filter([
            $a->getAbsence() !== null ? 'absence:' . $a->getAbsence()->getId() : null,
            $mission !== null ? 'mission:' . $mission->getId() : null,
        ]));

        return $this->record(ActivityEventType::PLANNING_ALERT_RAISED, $c, 'PlanningAlert', sprintf('Alerte #%d — %s', $c->entityId, $kind),
            sprintf('Alerte planning levée : %s%s', $kind, $where), [
                'alertId' => $c->entityId, 'alertType' => $a->getType()?->value, 'missionId' => $mission?->getId(),
                'absenceId' => $a->getAbsence()?->getId(), 'siteId' => $mission?->getSite()?->getId(),
            ], siteId: $mission?->getSite()?->getId(), missionId: $mission?->getId(), causeAnchors: $anchors, absenceId: $a->getAbsence()?->getId());
    }
}
