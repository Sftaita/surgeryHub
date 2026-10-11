<?php

namespace App\Activity\Governed;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Enum\ActivityEventType;

/**
 * H5b (D-132) — shared vocabulary of the describers: actor name, readable diff, controlled
 * payload ("changes" list + a few context keys), one record per change.
 */
abstract class AbstractGovernedDescriber implements GovernedEntityDescriber
{
    public function redactedFields(): array
    {
        return [];
    }

    protected static function actorName(GovernedChange $c): string
    {
        return $c->actor !== null ? (string) ActivityJournal::displayName($c->actor) : 'Le système';
    }

    /**
     * "Instrumentiste habituelle : Françoise Detrembleur → Sophie Gillard ; Actif : oui → non"
     *
     * @param list<string>|null $only restrict to these fields
     */
    protected static function diffText(GovernedChange $c, ?array $only = null, array $valueLabels = []): string
    {
        $parts = [];
        foreach ($c->changes as $ch) {
            if ($only !== null && !in_array($ch['field'], $only, true)) {
                continue;
            }
            $parts[] = $ch['redacted']
                ? sprintf('%s modifié(e)', $ch['label'])
                : sprintf('%s : %s → %s', $ch['label'], self::display($ch['from'], $valueLabels[$ch['field']] ?? []), self::display($ch['to'], $valueLabels[$ch['field']] ?? []));
        }

        return implode(' ; ', $parts);
    }

    /** @param array<string, string> $labels raw value => French label */
    protected static function display(mixed $v, array $labels = []): string
    {
        return match (true) {
            $v === null || $v === '' => '—',
            $v === true => 'oui',
            $v === false => 'non',
            is_scalar($v) && isset($labels[(string) $v]) => $labels[(string) $v],
            default => (string) $v,
        };
    }

    /** "Champ : valeur" of the initial state, for a creation. */
    protected static function initialText(GovernedChange $c, array $only, array $valueLabels = []): string
    {
        $parts = [];
        foreach ($c->changes as $ch) {
            if (in_array($ch['field'], $only, true) && !$ch['redacted']) {
                $parts[] = sprintf('%s : %s', $ch['label'], self::display($ch['to'], $valueLabels[$ch['field']] ?? []));
            }
        }

        return implode(' ; ', $parts);
    }

    /**
     * @param array<string, mixed> $context extra payload keys (ids, labels — never free text)
     * @param list<string>         $causeAnchors
     */
    /** context key holding the id of a subject that is not the changed entity itself */
    private const SUBJECT_ID_KEYS = [
        'FirmServiceOffering' => 'offeringId', 'Mission' => 'missionId', 'User' => 'userId',
        'SiteGroup' => 'siteGroupId', 'Hospital' => 'siteId', 'Firm' => 'firmId',
    ];

    /**
     * The subject of the event is the business object a reader looks for (the offering, the
     * mission, the person) — not always the row that changed (a suggested material, a
     * material line, a preference). Its id is then the one carried in the context.
     */
    private static function subjectId(GovernedChange $c, string $subjectType, array $context): int
    {
        $own = substr(strrchr('\\' . $c->entity::class, '\\'), 1);
        if ($subjectType === $own || !isset(self::SUBJECT_ID_KEYS[$subjectType])) {
            return $c->entityId;
        }
        $id = $context[self::SUBJECT_ID_KEYS[$subjectType]] ?? null;

        return is_int($id) ? $id : $c->entityId;
    }

    /** An offering has an optional label of its own; otherwise it is named after its intervention type. */
    protected static function offeringName(?\App\Entity\FirmServiceOffering $o): string
    {
        return $o?->getLabel() ?? $o?->getInterventionType()?->getLabel() ?? '—';
    }

    protected function record(
        ActivityEventType $type,
        GovernedChange $c,
        string $subjectType,
        string $subjectLabel,
        string $summary,
        array $context = [],
        ?int $siteId = null,
        ?int $userId = null,
        ?int $missionId = null,
        array $causeAnchors = [],
        ?int $absenceId = null,
        ?string $anchorKey = null,
    ): ActivityRecord {
        return new ActivityRecord(
            eventType: $type,
            subjectType: $subjectType,
            subjectId: self::subjectId($c, $subjectType, $context),
            subjectLabel: mb_substr($subjectLabel, 0, 255),
            summary: $summary,
            payload: $context + [
                'entityId' => $c->entityId,
                'label'    => $subjectLabel,
                'action'   => $c->action,
                'changes'  => array_map(static fn (array $ch) => array_diff_key($ch, ['label' => true]), $c->changes),
            ],
            actor: $c->actor,
            siteId: $siteId,
            missionId: $missionId,
            absenceId: $absenceId,
            userId: $userId,
            causeAnchors: $causeAnchors,
            anchorKey: $anchorKey,
            occurredAt: $c->occurredAt,
        );
    }
}
