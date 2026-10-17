<?php

namespace App\Tests\Architecture;

use App\Activity\ActivityPayloadPolicy;
use App\Enum\ActivityDomain;
use App\Enum\ActivityEventType;
use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Framework\TestCase;

/**
 * H5b (D-132, §32) — « Quelles actions importantes peuvent encore se produire sans trace
 * métier durable ? ». Every Doctrine entity must be in exactly one bucket:
 *
 *  - GOVERNED: a GovernedEntityDescriber (src/Activity/Governed/Describer) turns each create /
 *    update / delete flushed by Doctrine into an activity_event — whatever service or command
 *    made the change (robust: no call site can forget it);
 *  - JOURNALED: a named, specialised mechanism already records it (AuditEvent / UserAuditEvent
 *    mirrored by AuditEventMirrorListener, direct ActivityJournal calls, H2 notification
 *    recorder). The mechanism is spelled out, never a wildcard;
 *  - EXCLUDED: deliberately not historised, with the reason.
 *
 * A NEW entity therefore fails this test until someone decides where its history lives.
 * A stale entry (entity removed, describer removed, describer added for a JOURNALED entity)
 * fails too. Interfaces, traits and embeddables are not entities and are ignored.
 *
 * Reflection on the #[ORM\Entity] attribute rather than a text search: an entity is what
 * Doctrine maps, not what a file name looks like.
 */
final class ActivityCoverageTest extends TestCase
{
    /** entity short name => mechanism that already writes its history */
    private const JOURNALED = [
        'Absence'                             => 'ActivityJournal direct — ABSENCE_CREATED / _UPDATED / _DELETED (H1) + AuditEvent ABSENCE_DAY_REMOVED',
        'ActivityEvent'                       => 'the journal itself (append-only, guarded by ActivityJournalIntegrationTest)',
        'AuditEvent'                          => 'mirrored one-to-one by AuditEventMirrorListener (sourceRef audit_event:{id})',
        'UserAuditEvent'                      => 'mirrored one-to-one by AuditEventMirrorListener (sourceRef user_audit_event:{id})',
        'Mission'                             => 'AuditEvent MISSION_* (lifecycle, post-deploy, pool, manual creation and draft edits since H5b)',
        'MissionClaim'                        => 'AuditEvent MISSION_CLAIMED_FROM_POOL / MISSION_RELEASED_TO_POOL',
        'MissionAssignment'                   => 'D-150 — opened/ended only inside a Mission transition journaled by its own AuditEvent MISSION_* (claim, assign, reassign, release, cancel, restore, declare, post-deploy add, direct assignment) or ActivityJournal PLANNING_DEPLOYED (bulk opening at deploy); exception kept as found: PlanningAlertActionService published-status branches write no AuditEvent (D-150 limits)',
        'MissionPublication'                  => 'AuditEvent MISSION_PUBLISHED_TO_POOL / MISSION_OFFERED_TO_INSTRUMENTIST / MISSION_ASSIGNED_DIRECTLY (D-125)',
        'MissionCommunication'                => 'ActivityJournal direct — MISSION_COMMUNICATION_* (H4, D-130)',
        'MissionExecution'                    => 'AuditEvent MISSION_EXECUTION_CREATED / _UPDATED / _DURATION_CHANGED',
        'MissionExecutionDispute'             => 'AuditEvent MISSION_EXECUTION_DISPUTE_OPENED / _RESOLVED / _REJECTED',
        'MissionEncodingComment'              => 'part of the encoding transition it annotates (AuditEvent MISSION_ENCODING_REJECTED / _REOPENED, hasReason only)',
        'MissionInterventionDraft'            => 'AuditEvent MISSION_INTERVENTION_DRAFT_CREATED / _RESOLVED / _IGNORED_AS_HISTORY / _MATERIAL_REASSIGNED',
        'InterventionTypeRequest'             => 'AuditEvent MISSION_INTERVENTION_DRAFT_* (one event per user action, request + draft)',
        'MaterialItemRequest'                 => 'AuditEvent MATERIAL_ITEM_REQUEST_IGNORED + the MaterialLine / MaterialItem it becomes (governed)',
        'EncodingAnomalyReport'               => 'AuditEvent ENCODING_ANOMALY_REPORTED / _RESOLVED',
        'SurgeonMissionRequest'               => 'AuditEvent SURGEON_MISSION_REQUEST_CREATED / _ACCEPTED / _REJECTED',
        'PlanningVersion'                     => 'ActivityJournal direct — PLANNING_GENERATED / _DEPLOYED / _DRAFT_DELETED / _MODIFICATION_APPLIED (H1)',
        'PlanningDeployment'                  => 'ActivityJournal direct — PLANNING_DEPLOYED (H1)',
        'ReleasedOperatingRoomSlot'           => 'AuditEvent ROOM_SLOT_MADE_AVAILABLE / _TAKEN_OVER / _REOPENED (D-124)',
        'SiteMembership'                      => 'UserAuditEvent USER_SITE_ADDED / USER_SITE_REMOVED (UserAuditService, instrumentist & surgeon screens since H5b)',
        'PricingRule'                         => 'AuditEvent PRICING_RULE_* (append-only versions, D-072)',
        'InstrumentistRate'                   => 'AuditEvent INSTRUMENTIST_RATE_* (append-only versions, D-072)',
        'FinancialCalculation'                => 'AuditEvent FINANCIAL_CALCULATION_*',
        'FinancialCalculationLine'            => 'immutable lines of a FinancialCalculation (its own events carry the totals)',
        'FirmInvoice'                         => 'AuditEvent FIRM_INVOICE_* + ActivityJournal FIRM_INVOICE_SENT / _RESENT (H5b)',
        'FirmInvoiceLine'                     => 'immutable lines of a FirmInvoice (snapshot at creation)',
        'InstrumentistStatement'              => 'AuditEvent INSTRUMENTIST_STATEMENT_* + ActivityJournal INSTRUMENTIST_STATEMENT_SENT / _RESENT (H5b)',
        'InstrumentistStatementLine'          => 'immutable lines of an InstrumentistStatement (snapshot at creation)',
        'Payment'                             => 'AuditEvent DOCUMENT_PAYMENT_RECORDED / REFUND_RECORDED (append-only, no update)',
        'NotificationDispatch'                => 'ActivityJournal NOTIFICATION_DISPATCHED (H2 NotificationActivityRecorder)',
        'NotificationEvent'                   => 'in-app channel of a dispatch — NOTIFICATION_CHANNEL_* (H2)',
        'OutboundNotification'                => 'NOTIFICATION_CHANNEL_QUEUED / _SENT / _FAILED (H2, + TransactionalEmailSender since H5b)',
        'OutboundNotificationAttempt'         => 'technical attempt detail of an OutboundNotification (its channel events carry the outcome)',
        'SurgeonAbsenceCommunicationDelivery' => 'linked to its OutboundNotification (H5b) — NOTIFICATION_CHANNEL_* with cause absence / communication',
        // Intégration H1–H8 × origin/main (D-134, D-140) et D-143 — entités postérieures à H5b.
        'FirmBillingLineEvent'                => 'append-only per-line billing journal itself (D-134, immutable, PreUpdate/PreRemove guarded), written with the AuditEvent FIRM_INVOICE_* of the same action',
        'MedVueAccountLink'                   => 'UserAuditEvent MEDVUE_LINKED / MEDVUE_UNLINKED / MEDVUE_LINK_FAILED (D-140), mirrored by AuditEventMirrorListener',
        'MissionEncodingReminder'             => 'AuditEvent MISSION_ENCODING_AUTO_REMINDER_SENT (D-143) + the NotificationDispatch of each recipient — the row itself is the idempotence reservation',
        'GeneratedDocument'                   => 'evidence store referenced by the events that use it (generatedDocumentId + sha256 in *_SENT payloads)',
    ];

    /** entity short name => why it is deliberately not historised */
    private const EXCLUDED = [
        'RefreshToken'                 => 'authentication secret — technical, must never reach the history',
        'InstrumentistRating'          => 'personal appreciation between people, not an operational action (H5b inventory)',
        'SurgeonRatingByInstrumentist' => 'personal appreciation between people, not an operational action (H5b inventory)',
        'ImplantSubMission'            => 'legacy: never created by current code, only read / purged by PlanningDraftService',
        'MissingMaterialReport'        => 'legacy: never created by current code, only read / purged by PlanningDraftService',
        'WeeklyTemplate'               => 'legacy: superseded by SurgeonSchedulePost (Planning V2), no writer left',
    ];

    public function test_every_entity_has_a_decided_history(): void
    {
        $governed = $this->governedEntities();
        $missing  = [];
        foreach ($this->entities() as $short) {
            $buckets = (int) isset($governed[$short]) + (int) isset(self::JOURNALED[$short]) + (int) isset(self::EXCLUDED[$short]);
            if ($buckets === 0) {
                $missing[] = $short;
            }
        }

        self::assertSame([], $missing, 'These entities have no decided history: add a GovernedEntityDescriber, '
            . 'name the mechanism that already journals them (JOURNALED) or exclude them with a reason (EXCLUDED).');
    }

    public function test_classification_is_exclusive_and_not_stale(): void
    {
        $entities = array_flip($this->entities());
        $governed = $this->governedEntities();

        foreach ([self::JOURNALED, self::EXCLUDED, $governed] as $bucket) {
            foreach ($bucket as $short => $reason) {
                self::assertArrayHasKey($short, $entities, "$short is classified but is no longer a Doctrine entity — remove the entry");
                self::assertNotSame('', trim((string) $reason), "$short: a classification carries its reason");
            }
        }
        self::assertSame([], array_keys(array_intersect_key(self::JOURNALED, self::EXCLUDED)), 'journaled and excluded at once');
        // User has a governed describer for its legacy rates only, the rest goes through UserAuditService.
        self::assertSame(['User'], array_keys(array_intersect_key($governed, self::EXCLUDED + self::JOURNALED + ['User' => true])),
            'an entity is governed OR journaled OR excluded — User is the single documented overlap (legacy rates)');
    }

    public function test_every_h5b_event_type_has_a_payload_whitelist_and_a_domain(): void
    {
        $policy = new ActivityPayloadPolicy();
        foreach (ActivityEventType::cases() as $type) {
            $domain = $type->domain();
            self::assertInstanceOf(ActivityDomain::class, $domain);
            $out = $policy->filter($type, $domain, [
                'entityId' => 1, 'changes' => [['field' => 'x', 'from' => 'a', 'to' => 'b']],
                'password' => 'p', 'token' => 't', 'endpoint' => 'https://push', 'p256dh' => 'k', 'auth' => 'a',
                'patientName' => 'X', 'diagnosis' => 'Y', 'body' => '<p>mail</p>', 'stackTrace' => 'trace', 'pdfBytes' => 'JVBERi',
            ]);
            foreach (['password', 'token', 'endpoint', 'p256dh', 'auth', 'patientName', 'diagnosis', 'body', 'stackTrace', 'pdfBytes'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $out, "{$type->value} must never carry $forbidden");
            }
        }
    }

    /** @return list<string> */
    private function entities(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue; // interface or trait
            }
            if ((new \ReflectionClass($class))->getAttributes(Entity::class) !== []) {
                $out[] = basename($file, '.php');
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<string, string> entity short name => describer */
    private function governedEntities(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Activity/Governed/Describer/*.php') as $file) {
            $code = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/function entityClass\(\): string\s*\{\s*return (\w+)::class;/', $code, basename($file) . ' declares its entity');
            preg_match('/function entityClass\(\): string\s*\{\s*return (\w+)::class;/', $code, $m);
            $out[$m[1]] = basename($file, '.php');
        }

        return $out;
    }
}
