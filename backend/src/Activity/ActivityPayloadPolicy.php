<?php

namespace App\Activity;

use App\Enum\ActivityDomain;
use App\Enum\ActivityEventType;
use App\Enum\AuditEventType;
use App\Enum\UserAuditEventType;

/**
 * H1 (D-126) — strict, per-eventType allow-list for ActivityEvent.payload.
 *
 * The journal is not a technical dump: every key not explicitly allowed for the event's
 * type is dropped. On top of that, a key whose NAME looks sensitive (password, token,
 * cookie, JWT, secret, email, patient, diagnosis, IP, stack trace, raw body/HTML, push
 * keys…) is always dropped, even if a whitelist mistakenly lists it — defense in depth.
 *
 * Free-text fields (absence reason, alert note, review comment, manager cancel reason,
 * proposed labels…) are never copied: a person may type health or patient information
 * in them. Recorders expose a boolean like hasReason instead.
 *
 * Financial amounts are only kept for events of a financial domain (PRICING / BILLING /
 * PAYMENTS), which future non-Manager/Admin history views must filter out (D-126).
 *
 * Spec values:
 *   'scalar'          — string|int|float|bool|null (strings capped at 255 chars)
 *   'scalar_list'     — list of scalars (capped at 500 items)
 *   'scalar_map'      — string-keyed map of scalars (e.g. counts by status)
 *   ['object' => spec] — nested object filtered with its own spec
 *   ['list' => spec]   — list of objects, each filtered with spec (capped at 500 items)
 */
final class ActivityPayloadPolicy
{
    private const MAX_STRING = 255;
    private const MAX_ITEMS  = 500;

    private const EMAIL_VALUE_PATTERN = '/[^\s@<>"]+@[^\s@<>"]+\.[A-Za-z]{2,}/u';

    private const FORBIDDEN_KEY_PATTERN = '/(passw|token|jwt|cookie|secret|authoriz|e_?mail|patient|diagnos|medical|^ip$|ip_?addr|stack|trace|^body|html|p256dh|^auth$|endpoint|iban|phone)/i';

    /** Keys an AuditEvent / UserAuditEvent mirror may carry, for any non-financial domain. */
    private const MIRROR_KEYS = [
        'absenceId', 'actorId', 'actorName', 'actualDurationMinutes', 'alertId', 'alertType',
        'causedByAbsenceId', 'choiceOptionId', 'choiceOptionLabel', 'daysUntilMission', 'direction',
        'draftId', 'effectiveDurationSource', 'effectiveFrom', 'endAt', 'endTime', 'finalFirmId',
        'firmId', 'firmName', 'fromEndAt', 'fromInstrumentistId', 'fromInstrumentistName',
        'fromSiteId', 'fromStartAt', 'fromType', 'instrumentistId', 'instrumentistName',
        'instrumentistRateId', 'interventionTypeCode', 'interventionTypeId',
        'interventionTypeRequestId', 'materialItemId', 'materialItemLabel',
        'materialItemRequestsCount', 'materialItemRequestsMovedCount', 'materialLinesCount',
        'materialLinesMovedCount', 'missionId', 'missionInterventionId', 'newInstrumentistRateId',
        'newStatus', 'occurrenceDate', 'orderIndex', 'period', 'postId',
        'previousInstrumentistRateId', 'previousStatus', 'pricingRuleId', 'rateType',
        'requestedFirmId', 'requestedFirmNameSnapshot', 'roomSlotId', 'ruleType', 'siteId',
        'siteName', 'startAt', 'startTime', 'status', 'strategy', 'surgeonId', 'surgeonName',
        'surgeonMissionRequestId', 'toEndAt', 'toInstrumentistId', 'toInstrumentistName',
        'toSiteId', 'toStartAt', 'toType', 'type', 'validFrom', 'validTo', 'originalSurgeonId',
        'originalSurgeonName', 'triggeredById', 'reassignedFromMissionId', 'takenById',
        'takenByName', 'initialInstrumentistId', 'takeoverMissionId', 'originalMissionId',
        'invoiceId', 'invoiceNumber', 'statementId', 'statementNumber', 'documentId',
        'documentType', 'documentNumber', 'paymentId', 'financialCalculationId',
        'calculationVersion', 'assignmentMode', 'targetInstrumentistId', 'targetInstrumentistName',
        'hasReason', 'oldRole', 'newRole', 'count', 'mergedIntoId', 'sourceId', 'targetId',
        'occurrenceCount', 'roles',
        // D-150 / D-151 / D-152 — affectations, surgeon refusal, incompatibilities.
        'assignmentId', 'assignmentSource', 'assignedAt', 'refusalScope', 'refusalBlockedReason',
        'origin', 'incompatibilityId', 'sourceAssignmentId',
        // D-153 — before / after of the real hours (correction history).
        'previousActualStartAt', 'actualStartAt', 'previousActualEndAt', 'actualEndAt',
        'previousActualDurationMinutes', 'previousHoursSource', 'hoursSource',
    ];

    /** Additionally allowed for PRICING / BILLING / PAYMENTS mirrors only. */
    private const FINANCIAL_KEYS = [
        'amount', 'currency', 'oldAmount', 'newAmount', 'oldCurrency', 'newCurrency',
        'totalAmount', 'paidAmount', 'remainingAmount', 'netBalance',
    ];

    /** Free-text keys of audit payloads, exposed only as a presence flag. */
    public const FREE_TEXT_KEYS = ['reason', 'note', 'reviewComment', 'ignoreComment', 'ignoreReason', 'comment', 'description', 'label'];

    private const PERSON_ROLE = 'scalar';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function filter(\BackedEnum $type, ActivityDomain $domain, array $payload): array
    {
        return $this->apply($this->specFor($type, $domain), $payload);
    }

    /** @return array<string, mixed> */
    private function specFor(\BackedEnum $type, ActivityDomain $domain): array
    {
        if ($type instanceof AuditEventType || $type instanceof UserAuditEventType) {
            $keys = self::MIRROR_KEYS;
            if ($domain->isFinancial()) {
                $keys = array_merge($keys, self::FINANCIAL_KEYS);
            }
            $spec = array_fill_keys($keys, 'scalar');
            $spec['roles'] = 'scalar_list';

            return $spec;
        }

        $occurrenceSnapshot = [
            'missionId' => 'scalar', 'date' => 'scalar', 'startTime' => 'scalar', 'endTime' => 'scalar',
            'siteId' => 'scalar', 'siteName' => 'scalar', 'surgeonId' => 'scalar', 'surgeonName' => 'scalar',
            'instrumentistId' => 'scalar', 'instrumentistName' => 'scalar', 'status' => 'scalar',
        ];
        $absenceDates = ['dateStart' => 'scalar', 'dateEnd' => 'scalar', 'hasReason' => 'scalar'];
        $person       = ['absenceId' => 'scalar', 'userId' => 'scalar', 'userName' => 'scalar', 'userRole' => self::PERSON_ROLE];

        return match ($type) {
            ActivityEventType::ABSENCE_CREATED => $person + $absenceDates + [
                'createdById' => 'scalar', 'selfDeclared' => 'scalar',
            ],
            ActivityEventType::ABSENCE_UPDATED => $person + [
                'before' => ['object' => $absenceDates],
                'after' => ['object' => $absenceDates],
                'changedFields' => 'scalar_list',
            ],
            ActivityEventType::ABSENCE_DELETED => $person + $absenceDates + [
                'createdById' => 'scalar', 'createdAt' => 'scalar',
            ],
            ActivityEventType::PLANNING_GENERATED => [
                'planningVersionId' => 'scalar', 'versionNumber' => 'scalar', 'periodStart' => 'scalar',
                'periodEnd' => 'scalar', 'siteIds' => 'scalar_list', 'siteGroupId' => 'scalar', 'mode' => 'scalar',
                'lineCount' => 'scalar', 'created' => 'scalar', 'updated' => 'scalar', 'skipped' => 'scalar',
                'rejectedAssignmentCount' => 'scalar', 'lineStatusCounts' => 'scalar_map',
                'reasonCounts' => 'scalar_map',
                'occurrences' => ['list' => [
                    'date' => 'scalar', 'postId' => 'scalar', 'startTime' => 'scalar', 'endTime' => 'scalar',
                    'siteId' => 'scalar', 'surgeonId' => 'scalar', 'surgeonName' => 'scalar',
                    'habitualInstrumentistId' => 'scalar', 'habitualInstrumentistName' => 'scalar',
                    'status' => 'scalar', 'reason' => 'scalar',
                ]],
                'snapshotTruncated' => 'scalar', 'snapshotCount' => 'scalar', 'totalCount' => 'scalar',
            ],
            ActivityEventType::PLANNING_DEPLOYED => [
                'deploymentId' => 'scalar', 'planningVersionId' => 'scalar', 'periodFrom' => 'scalar',
                'periodTo' => 'scalar', 'siteId' => 'scalar', 'missionCount' => 'scalar',
                'assignedCount' => 'scalar', 'openPoolCount' => 'scalar', 'archivedVersionId' => 'scalar',
                'neutralizedCount' => 'scalar', 'legacy' => 'scalar',
            ],
            ActivityEventType::PLANNING_DRAFT_DELETED => [
                'planningVersionId' => 'scalar', 'versionNumber' => 'scalar', 'periodStart' => 'scalar',
                'periodEnd' => 'scalar', 'siteIds' => 'scalar_list', 'siteGroupId' => 'scalar',
                'source' => 'scalar', 'generatedAt' => 'scalar', 'missionCount' => 'scalar',
                'countsByStatus' => 'scalar_map', 'occurrences' => ['list' => $occurrenceSnapshot],
                'snapshotTruncated' => 'scalar', 'snapshotCount' => 'scalar', 'totalCount' => 'scalar',
            ],
            ActivityEventType::PLANNING_MODIFICATION_APPLIED => [
                'planningVersionId' => 'scalar', 'periodStart' => 'scalar', 'periodEnd' => 'scalar',
                'requestedLines' => 'scalar', 'modifiedMissions' => 'scalar', 'created' => 'scalar',
                'assigned' => 'scalar', 'reassigned' => 'scalar', 'released' => 'scalar',
                'cancelled' => 'scalar', 'scheduleChanged' => 'scalar', 'unchanged' => 'scalar',
                'skippedForeign' => 'scalar', 'absenceReconciled' => 'scalar',
                'functionalChanges' => 'scalar', 'usersNotified' => 'scalar', 'emailsSent' => 'scalar',
                'errorType' => 'scalar', 'errorStatus' => 'scalar',
                'rolledBack' => 'scalar', 'openedCount' => 'scalar',
            ],
            ActivityEventType::NOTIFICATION_DISPATCHED => [
                'dispatchId' => 'scalar', 'notificationType' => 'scalar', 'recipientUserId' => 'scalar',
                'recipientName' => 'scalar', 'missionId' => 'scalar', 'mode' => 'scalar',
                'episodeRef' => 'scalar', 'channelsSource' => 'scalar',
                'channels' => ['object' => [
                    'IN_APP' => ['object' => ['status' => 'scalar', 'skipReason' => 'scalar', 'outboundNotificationId' => 'scalar', 'grouped' => 'scalar']],
                    'EMAIL' => ['object' => ['status' => 'scalar', 'skipReason' => 'scalar', 'outboundNotificationId' => 'scalar', 'grouped' => 'scalar']],
                    'PUSH' => ['object' => ['status' => 'scalar', 'skipReason' => 'scalar', 'outboundNotificationId' => 'scalar', 'grouped' => 'scalar']],
                ]],
            ],
            ActivityEventType::OPEN_POOL_EVALUATED => [
                'missionId' => 'scalar', 'episodeRef' => 'scalar', 'cause' => 'scalar', 'mode' => 'scalar',
                'missionOpen' => 'scalar', 'missionDate' => 'scalar', 'startTime' => 'scalar', 'endTime' => 'scalar',
                'siteId' => 'scalar', 'siteName' => 'scalar', 'surgeonName' => 'scalar',
                'eligibleCount' => 'scalar', 'excludedCount' => 'scalar', 'notifiedCount' => 'scalar',
                'noEligibleCandidates' => 'scalar', 'batchSize' => 'scalar',
                'excluded' => ['list' => ['userId' => 'scalar', 'userName' => 'scalar', 'reason' => 'scalar', 'wasEligible' => 'scalar']],
            ],
            ActivityEventType::ABSENCE_IMPACT_EVALUATED => [
                'action' => 'scalar', 'absentRole' => 'scalar', 'absenceStart' => 'scalar', 'absenceEnd' => 'scalar',
                'counts' => 'scalar_map', 'recipientsInformed' => 'scalar', 'stillUncovered' => 'scalar',
                'restoredCount' => 'scalar', 'impactCount' => 'scalar', 'snapshotTruncated' => 'scalar',
                'impacts' => ['list' => [
                    'date' => 'scalar', 'level' => 'scalar', 'consequence' => 'scalar', 'missionId' => 'scalar',
                    'postId' => 'scalar', 'siteName' => 'scalar', 'surgeonName' => 'scalar',
                    'instrumentistName' => 'scalar', 'recipientName' => 'scalar', 'startTime' => 'scalar', 'endTime' => 'scalar',
                ]],
            ],
            ActivityEventType::USER_PLANNING_IMPACTED => [
                'absenceId' => 'scalar', 'date' => 'scalar', 'level' => 'scalar', 'consequence' => 'scalar',
                'missionId' => 'scalar', 'postId' => 'scalar', 'siteName' => 'scalar', 'surgeonName' => 'scalar',
                'instrumentistName' => 'scalar', 'recipientId' => 'scalar', 'recipientName' => 'scalar',
                'startTime' => 'scalar', 'endTime' => 'scalar',
            ],
            ActivityEventType::NOTIFICATION_CHANNEL_QUEUED,
            ActivityEventType::NOTIFICATION_CHANNEL_SENT,
            ActivityEventType::NOTIFICATION_CHANNEL_FAILED => [
                'outboundNotificationId' => 'scalar', 'dispatchId' => 'scalar', 'notificationType' => 'scalar',
                'channel' => 'scalar', 'recipientUserId' => 'scalar', 'recipientName' => 'scalar',
                'missionId' => 'scalar', 'attemptCount' => 'scalar', 'failureReason' => 'scalar',
                'grouped' => 'scalar', 'final' => 'scalar',
            ],
            default => self::isH5bType($type)
                ? self::GOVERNED_SPEC + ($domain->isFinancial() ? array_fill_keys(self::FINANCIAL_KEYS, 'scalar') : [])
                : throw new \InvalidArgumentException(sprintf('No payload policy for %s.', $type->value)),
        };
    }

    /**
     * H5b (D-132) — every event of the governed entities and of the document / D-114 /
     * export recorders: the entity id + label, the action, the structured diff of the
     * TRACKED fields (free-text fields arrive with redacted = true and no value), and a
     * closed set of context keys (ids and display names — never free text, never secrets).
     */
    private const GOVERNED_SPEC = [
        'entityId' => 'scalar', 'label' => 'scalar', 'action' => 'scalar',
        'changes' => ['list' => [
            'field' => 'scalar', 'from' => 'scalar', 'to' => 'scalar', 'fromId' => 'scalar', 'toId' => 'scalar', 'redacted' => 'scalar',
        ]],
        // context
        'postId' => 'scalar', 'surgeonId' => 'scalar', 'surgeonName' => 'scalar', 'instrumentistId' => 'scalar',
        'instrumentistName' => 'scalar', 'siteId' => 'scalar', 'siteName' => 'scalar', 'period' => 'scalar',
        'weekdays' => 'scalar', 'occurrenceDate' => 'scalar', 'exceptionType' => 'scalar', 'source' => 'scalar',
        'siteGroupId' => 'scalar', 'siteGroupName' => 'scalar', 'firmId' => 'scalar', 'firmName' => 'scalar',
        'offeringId' => 'scalar', 'offeringLabel' => 'scalar', 'interventionTypeId' => 'scalar',
        'interventionTypeCode' => 'scalar', 'materialItemId' => 'scalar', 'materialItemLabel' => 'scalar',
        'choiceGroupId' => 'scalar', 'missionId' => 'scalar', 'missionInterventionId' => 'scalar',
        'quantity' => 'scalar', 'alertId' => 'scalar', 'alertType' => 'scalar', 'absenceId' => 'scalar',
        'userId' => 'scalar', 'userName' => 'scalar', 'notificationType' => 'scalar', 'provider' => 'scalar',
        'reason' => 'scalar', 'reassignedFromUserId' => 'scalar', 'outputType' => 'scalar', 'exportType' => 'scalar',
        'success' => 'scalar', 'filters' => 'scalar_map', 'documentType' => 'scalar', 'documentNumber' => 'scalar',
        'invoiceId' => 'scalar', 'statementId' => 'scalar', 'recipientKind' => 'scalar', 'ccCount' => 'scalar',
        'outboundNotificationId' => 'scalar', 'generatedDocumentId' => 'scalar', 'sha256' => 'scalar',
        'communicationId' => 'scalar', 'communicationType' => 'scalar', 'revision' => 'scalar',
        'deliveryCount' => 'scalar', 'occurrenceCount' => 'scalar', 'sourceRef' => 'scalar',
    ];

    private static function isH5bType(\BackedEnum $type): bool
    {
        if (!$type instanceof ActivityEventType) {
            return false;
        }
        static $legacy = null;
        $legacy ??= [
            ActivityEventType::ABSENCE_CREATED, ActivityEventType::ABSENCE_UPDATED, ActivityEventType::ABSENCE_DELETED,
            ActivityEventType::PLANNING_GENERATED, ActivityEventType::PLANNING_DEPLOYED, ActivityEventType::PLANNING_DRAFT_DELETED,
            ActivityEventType::PLANNING_MODIFICATION_APPLIED, ActivityEventType::NOTIFICATION_DISPATCHED,
            ActivityEventType::NOTIFICATION_CHANNEL_QUEUED, ActivityEventType::NOTIFICATION_CHANNEL_SENT,
            ActivityEventType::NOTIFICATION_CHANNEL_FAILED, ActivityEventType::OPEN_POOL_EVALUATED,
            ActivityEventType::ABSENCE_IMPACT_EVALUATED, ActivityEventType::USER_PLANNING_IMPACTED,
        ];

        return !in_array($type, $legacy, true);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function apply(array $spec, array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !isset($spec[$key]) || self::isForbiddenKey($key)) {
                continue;
            }

            $rule = $spec[$key];
            if ($rule === 'scalar') {
                if (is_scalar($value) || $value === null) {
                    $out[$key] = $this->scalar($value);
                }
            } elseif ($rule === 'scalar_list') {
                if (is_array($value)) {
                    $out[$key] = array_values(array_map(
                        fn ($v) => $this->scalar($v),
                        array_filter(array_slice(array_values($value), 0, self::MAX_ITEMS), static fn ($v) => is_scalar($v) || $v === null),
                    ));
                }
            } elseif ($rule === 'scalar_map') {
                if (is_array($value)) {
                    $map = [];
                    foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $k => $v) {
                        if (is_string($k) && !self::isForbiddenKey($k) && (is_scalar($v) || $v === null)) {
                            $map[$k] = $this->scalar($v);
                        }
                    }
                    $out[$key] = $map;
                }
            } elseif (is_array($rule) && isset($rule['object'])) {
                if (is_array($value)) {
                    $out[$key] = $this->apply($rule['object'], $value);
                }
            } elseif (is_array($rule) && isset($rule['list'])) {
                if (is_array($value)) {
                    $out[$key] = array_values(array_map(
                        fn ($item) => $this->apply($rule['list'], $item),
                        array_filter(array_slice(array_values($value), 0, self::MAX_ITEMS), 'is_array'),
                    ));
                }
            }
        }

        return $out;
    }

    /**
     * Channel names (OutboundNotificationChannel cases) are structural keys, not personal
     * data: "EMAIL" must not be mistaken for an email-address field.
     */
    private const STRUCTURAL_KEYS = ['IN_APP', 'EMAIL', 'PUSH'];

    private static function isForbiddenKey(string $key): bool
    {
        return !in_array($key, self::STRUCTURAL_KEYS, true) && preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1;
    }

    public static function maskEmails(string $value): string
    {
        return preg_replace(self::EMAIL_VALUE_PATTERN, '[email masqué]', $value) ?? $value;
    }

    private function scalar(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // Value-level defense in depth: a display name built with an email fallback
        // (e.g. User::getDrName() for a user without a name) must not leak the address.
        $value = self::maskEmails($value);

        return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) : $value;
    }
}
