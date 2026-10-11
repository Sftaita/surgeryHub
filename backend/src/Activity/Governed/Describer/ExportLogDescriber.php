<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\ExportLog;

/**
 * H5b (D-132) — mirror of an ExportLog (the specialised journal keeps the proof): who exported
 * what kind of data, with which non-sensitive parameters. Never the exported content.
 */
final class ExportLogDescriber extends AbstractGovernedDescriber
{
    private const FILTER_KEYS = ['periodStart', 'periodEnd', 'status', 'type'];

    public function entityClass(): string { return ExportLog::class; }

    public function trackedFields(): array
    {
        return ['outputType' => 'Format', 'success' => 'Réussi'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action !== GovernedChange::CREATED) {
            return null;
        }
        /** @var ExportLog $e */
        $e       = $c->entity;
        $filters = array_intersect_key($e->getFilters() ?? [], array_flip(self::FILTER_KEYS));
        $period  = isset($filters['periodStart'], $filters['periodEnd']) ? sprintf(' du %s au %s', FrenchLabels::isoDate($filters['periodStart']), FrenchLabels::isoDate($filters['periodEnd'])) : '';

        return new ActivityRecord(
            eventType: ActivityEventType::EXPORT_GENERATED,
            subjectType: 'ExportLog',
            subjectId: $c->entityId,
            subjectLabel: sprintf('Export #%d', $c->entityId),
            summary: sprintf('%s a %s un export d\'activité (%s)%s', ActivityJournal::displayName($e->getUser()) ?? self::actorName($c),
                $e->isSuccess() ? 'généré' : 'tenté', $e->getOutputType() ?? '—', $period),
            payload: [
                'entityId' => $c->entityId, 'action' => $c->action, 'outputType' => $e->getOutputType(), 'exportType' => $e->getEventType(),
                'success' => $e->isSuccess(), 'filters' => array_filter($filters, static fn ($v) => is_scalar($v) || $v === null),
                'userId' => $e->getUser()?->getId(), 'userName' => ActivityJournal::displayName($e->getUser()),
            ],
            actor: $c->actor ?? $e->getUser(),
            userId: $e->getUser()?->getId(),
            sourceRef: 'export_log:' . $c->entityId,
            occurredAt: $c->occurredAt,
        );
    }
}
