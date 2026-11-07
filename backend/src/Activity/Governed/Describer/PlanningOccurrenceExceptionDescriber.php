<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\PlanningOccurrenceException;
use App\Enum\OccurrenceExceptionSource;

/**
 * H5b (D-132) — a MANUAL exception on one occurrence of a habitual post (cancel, move,
 * replacement instrumentist, other hours). The exceptions created automatically by a surgeon
 * absence (source SURGEON_ABSENCE) are already told by their AuditEvent PLANNING_OCCURRENCE_*
 * and by H4 (USER_PLANNING_IMPACTED): never doubled here.
 */
final class PlanningOccurrenceExceptionDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return PlanningOccurrenceException::class; }

    public function trackedFields(): array
    {
        return [
            'occurrenceDate' => 'Occurrence', 'type' => 'Type', 'overrideDate' => 'Nouvelle date',
            'overrideInstrumentist' => 'Instrumentiste remplaçante', 'overrideStartTime' => 'Début', 'overrideEndTime' => 'Fin',
        ];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var PlanningOccurrenceException $e */
        $e = $c->entity;
        if ($e->getSource() !== OccurrenceExceptionSource::MANAGER) {
            return null;
        }
        $post  = $e->getPost();
        $dr    = $post?->getSurgeon()?->getDrName() ?? '—';
        $date  = FrenchLabels::date($e->getOccurrenceDate());
        $kind  = FrenchLabels::EXCEPTION_TYPES[$e->getType()?->value ?? ''] ?? (string) $e->getType()?->value;
        $actor = self::actorName($c);
        $detail = match ($e->getType()?->value) {
            'MOVED' => ' au ' . FrenchLabels::date($e->getOverrideDate()),
            'INSTRUMENTIST_OVERRIDE' => ' — ' . (ActivityJournal::displayName($e->getOverrideInstrumentist()) ?? '—'),
            'TIME_OVERRIDE' => sprintf(' — %s–%s', $e->getOverrideStartTime()?->format('H:i') ?? '—', $e->getOverrideEndTime()?->format('H:i') ?? '—'),
            default => '',
        };

        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::PLANNING_OCCURRENCE_EXCEPTION_CREATED,
                sprintf('%s a créé une exception sur le poste habituel du %s le %s : %s%s', $actor, $dr, $date, $kind, $detail)],
            GovernedChange::DELETED => [ActivityEventType::PLANNING_OCCURRENCE_EXCEPTION_DELETED,
                sprintf('%s a supprimé l\'exception (%s) du poste habituel du %s le %s', $actor, $kind, $dr, $date)],
            default => [ActivityEventType::PLANNING_OCCURRENCE_EXCEPTION_UPDATED,
                sprintf('%s a modifié l\'exception du poste habituel du %s le %s : %s', $actor, $dr, $date, self::diffText($c, null, ['type' => FrenchLabels::EXCEPTION_TYPES]))],
        };

        return $this->record($type, $c, 'PlanningOccurrenceException', sprintf('Exception #%d — %s, %s', $c->entityId, $dr, $date), $summary, [
            'postId' => $post?->getId(), 'surgeonId' => $post?->getSurgeon()?->getId(), 'surgeonName' => ActivityJournal::displayName($post?->getSurgeon()),
            'occurrenceDate' => $e->getOccurrenceDate()?->format('Y-m-d'), 'exceptionType' => $e->getType()?->value, 'source' => $e->getSource()->value,
            'instrumentistId' => $e->getOverrideInstrumentist()?->getId(), 'instrumentistName' => ActivityJournal::displayName($e->getOverrideInstrumentist()),
            'siteId' => $post?->getSite()?->getId(),
        ], siteId: $post?->getSite()?->getId(), userId: $post?->getSurgeon()?->getId());
    }
}
