<?php

namespace App\Service;

use App\Activity\Governed\Describer\MaterialLineDescriber;
use App\Activity\Governed\Describer\MissionInterventionDescriber;
use App\Entity\ActivityEvent;
use App\Entity\Mission;
use App\Enum\ActivityEventType;
use App\Enum\AuditEventType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-153 — the correction history of a mission's encoding, for the manager: a read of the
 * activity journal (H1–H5b) already fed by the governed listener (interventions, material
 * lines: from → to per field) and by the AuditEvent mirror (real hours: previous → new values,
 * encoding workflow). No event is produced here and none is duplicated.
 */
final class MissionEncodingHistoryService
{
    public const LIMIT = 200;

    private const CATEGORIES = [
        ActivityEventType::MISSION_INTERVENTION_ADDED->value   => 'INTERVENTION',
        ActivityEventType::MISSION_INTERVENTION_UPDATED->value => 'INTERVENTION',
        ActivityEventType::MISSION_INTERVENTION_REMOVED->value => 'INTERVENTION',
        ActivityEventType::MISSION_MATERIAL_LINE_ADDED->value   => 'MATERIAL',
        ActivityEventType::MISSION_MATERIAL_LINE_UPDATED->value => 'MATERIAL',
        ActivityEventType::MISSION_MATERIAL_LINE_REMOVED->value => 'MATERIAL',
        AuditEventType::MISSION_EXECUTION_CREATED->value          => 'HOURS',
        AuditEventType::MISSION_EXECUTION_UPDATED->value          => 'HOURS',
        AuditEventType::MISSION_EXECUTION_DURATION_CHANGED->value => 'HOURS',
        AuditEventType::MISSION_ENCODING_STARTED->value   => 'WORKFLOW',
        AuditEventType::MISSION_ENCODING_COMPLETED->value => 'WORKFLOW',
        AuditEventType::MISSION_ENCODING_REOPENED->value  => 'WORKFLOW',
        AuditEventType::MISSION_ENCODING_VALIDATED->value => 'WORKFLOW',
        AuditEventType::MISSION_ENCODING_REJECTED->value  => 'WORKFLOW',
    ];

    /** Hours fields: label, previous key, new key. */
    private const HOURS_FIELDS = [
        ['actualStartAt', 'Début réel', 'previousActualStartAt', 'actualStartAt'],
        ['actualEndAt', 'Fin réelle', 'previousActualEndAt', 'actualEndAt'],
        ['actualDurationMinutes', 'Durée (min)', 'previousActualDurationMinutes', 'actualDurationMinutes'],
        ['hoursSource', 'Source des heures', 'previousHoursSource', 'hoursSource'],
    ];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @return list<array{id: int, occurredAt: string, category: string, eventType: string,
     *     actorName: ?string, summary: string, changes: list<array{field: string, from: mixed, to: mixed, redacted: bool}>}>
     */
    public function historyOf(Mission $mission): array
    {
        /** @var ActivityEvent[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(ActivityEvent::class, 'e')
            ->where('e.missionId = :mission')
            ->andWhere('e.eventType IN (:types)')
            ->setParameter('mission', $mission->getId())
            ->setParameter('types', array_keys(self::CATEGORIES))
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        return array_map(fn (ActivityEvent $e) => [
            'id'         => (int) $e->getId(),
            'occurredAt' => $e->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'category'   => self::CATEGORIES[$e->getEventType()],
            'eventType'  => $e->getEventType(),
            'actorName'  => $e->getActorNameSnapshot(),
            'summary'    => $e->getSummary(),
            'changes'    => self::changes($e),
        ], $events);
    }

    /** @return list<array{field: string, from: mixed, to: mixed, redacted: bool}> */
    private static function changes(ActivityEvent $e): array
    {
        $p = $e->getPayload() ?? [];

        if (self::CATEGORIES[$e->getEventType()] === 'HOURS') {
            if (!array_key_exists('previousActualStartAt', $p)) {
                return [];
            }
            $changes = [];
            foreach (self::HOURS_FIELDS as [, $label, $fromKey, $toKey]) {
                $from = $p[$fromKey] ?? null;
                $to   = $p[$toKey] ?? null;
                if ($from !== $to) {
                    $changes[] = ['field' => $label, 'from' => $from, 'to' => $to, 'redacted' => false];
                }
            }

            return $changes;
        }

        if (!is_array($p['changes'] ?? null)) {
            return [];
        }

        $labels = self::governedLabels($e->getEventType());

        return array_values(array_map(static fn (array $c) => [
            'field'    => $labels[$c['field'] ?? ''] ?? (string) ($c['field'] ?? '?'),
            'from'     => ($c['redacted'] ?? false) ? null : ($c['from'] ?? null),
            'to'       => ($c['redacted'] ?? false) ? null : ($c['to'] ?? null),
            'redacted' => (bool) ($c['redacted'] ?? false),
        ], array_filter($p['changes'], 'is_array')));
    }

    /** @return array<string, string> the describers' own field labels (the payload drops them). */
    private static function governedLabels(string $eventType): array
    {
        return str_starts_with($eventType, 'MISSION_MATERIAL_LINE_')
            ? (new MaterialLineDescriber())->trackedFields()
            : (new MissionInterventionDescriber())->trackedFields();
    }
}
