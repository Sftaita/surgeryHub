<?php

namespace App\Tests\Integration\Activity;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\AppendOnly\ActivityEventImmutableException;
use App\Activity\CorrelationContext;
use App\Activity\Recorder\PlanningActivityRecorder;
use App\Activity\UuidV7;
use App\Entity\Absence;
use App\Entity\ActivityEvent;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\ActivityEventType;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningDraftDeletionSource;
use App\Enum\PlanningVersionStatus;
use App\Messenger\CorrelationMiddleware;
use App\Service\AuditService;
use App\Service\PlanningDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineTransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * H1 (D-126) — ActivityJournal against the real test database (no mocked EntityManager:
 * the transaction, the DBAL guard, the Doctrine listeners and the Messenger Doctrine
 * transport are all the real ones).
 *
 * activity_event rows written here are deliberately left in place (append-only — the
 * suite cannot and must not delete them); every assertion scopes its reads to ids /
 * correlation ids created by the test itself.
 */
final class ActivityJournalIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ActivityJournal $journal;
    private CorrelationContext $context;
    /** @var array{users: int[], sites: int[]} */
    private array $fx = ['users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->journal = self::getContainer()->get(ActivityJournal::class);
        $this->context = self::getContainer()->get(CorrelationContext::class);
        $this->context->begin(ActivityActorType::USER);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
            $users = $this->fx['users'] ?: [0];
            $sites = $this->fx['sites'] ?: [0];
            $missionIds = array_map('intval', array_column($this->em->createQuery(
                'SELECT m.id FROM App\Entity\Mission m WHERE m.site IN (:s)'
            )->setParameter('s', $sites)->getArrayResult(), 'id')) ?: [0];
            foreach ([
                'DELETE FROM App\Entity\AuditEvent a WHERE a.mission IN (:m) OR a.actor IN (:u)' => ['m' => $missionIds, 'u' => $users],
                'DELETE FROM App\Entity\Mission m WHERE m.id IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\PlanningVersion v WHERE v.generatedBy IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\Absence a WHERE a.user IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\User u WHERE u.id IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\Hospital h WHERE h.id IN (:s)' => ['s' => $sites],
            ] as $dql => $params) {
                $q = $this->em->createQuery($dql);
                foreach ($params as $k => $v) {
                    $q->setParameter($k, $v);
                }
                $q->execute();
            }
        }
        parent::tearDown();
    }

    // ── 1. creation + transaction rule ───────────────────────────────────────

    public function test_record_writes_one_row_with_the_current_correlation(): void
    {
        $id = $this->em->getConnection()->transactional(fn () => $this->journal->record($this->sampleRecord()));

        $row = $this->row($id);
        self::assertSame('ABSENCE_CREATED', $row['event_type']);
        self::assertSame('ABSENCES', $row['domain']);
        self::assertSame($this->context->correlationId(), $row['correlation_id']);
        self::assertSame('SUCCESS', $row['outcome']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $row['occurred_at'], 'microsecond UTC instant');
    }

    public function test_record_refuses_to_run_outside_a_business_transaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->journal->record($this->sampleRecord());
    }

    // ── 2. payload whitelist on the stored row ───────────────────────────────

    public function test_payload_whitelist_is_applied_before_storage(): void
    {
        $id = $this->em->getConnection()->transactional(fn () => $this->journal->record($this->sampleRecord([
            'absenceId' => 1, 'dateStart' => '2026-09-29', 'reason' => 'Arrêt maladie', 'email' => 'a@b.c',
            'password' => 'x', 'userName' => 'Dr someone@example.org',
        ])));

        $payload = json_decode((string) $this->row($id)['payload'], true);
        self::assertEqualsCanonicalizing(['absenceId', 'dateStart', 'userName'], array_keys($payload));
        self::assertStringNotContainsString('@', $payload['userName']);
    }

    // ── 3 / 4. append-only ───────────────────────────────────────────────────

    public function test_update_is_refused_at_every_level(): void
    {
        $id   = $this->em->getConnection()->transactional(fn () => $this->journal->record($this->sampleRecord()));
        $conn = $this->em->getConnection();

        foreach ([
            fn () => $conn->executeStatement('UPDATE activity_event SET summary = ? WHERE id = ?', ['x', $id]),
            fn () => $conn->update('activity_event', ['summary' => 'x'], ['id' => $id]),
            fn () => $this->em->createQuery('UPDATE App\Entity\ActivityEvent e SET e.summary = :s WHERE e.id = :id')
                ->setParameter('s', 'x')->setParameter('id', $id)->execute(),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('UPDATE must be refused');
            } catch (\Throwable $e) {
                self::assertInstanceOf(ActivityEventImmutableException::class, self::root($e));
            }
        }

        $entity = $this->em->find(ActivityEvent::class, $id);
        (new \ReflectionProperty(ActivityEvent::class, 'summary'))->setValue($entity, 'tampered');
        try {
            $this->em->flush();
            self::fail('ORM update must be refused');
        } catch (ActivityEventImmutableException) {
        }

        self::assertNotSame('x', $this->row($id)['summary']);
        self::assertNotSame('tampered', $this->row($id)['summary']);
    }

    public function test_delete_is_refused_at_every_level(): void
    {
        $id   = $this->em->getConnection()->transactional(fn () => $this->journal->record($this->sampleRecord()));
        $conn = $this->em->getConnection();

        foreach ([
            fn () => $conn->executeStatement('DELETE FROM activity_event WHERE id = ?', [$id]),
            fn () => $conn->delete('activity_event', ['id' => $id]),
            fn () => $conn->executeStatement('TRUNCATE TABLE activity_event'),
            fn () => $this->em->createQuery('DELETE FROM App\Entity\ActivityEvent e WHERE e.id = :id')->setParameter('id', $id)->execute(),
            fn () => $this->em->remove($this->em->find(ActivityEvent::class, $id)),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('DELETE must be refused');
            } catch (\Throwable $e) {
                self::assertInstanceOf(ActivityEventImmutableException::class, self::root($e));
            }
        }

        self::assertNotNull($this->row($id));
    }

    // ── 5. no orphan, no lie ─────────────────────────────────────────────────

    public function test_business_rollback_leaves_no_orphan_event(): void
    {
        $actor = $this->makeUser('ROLE_MANAGER');
        $marker = 'rollback-' . bin2hex(random_bytes(4));

        try {
            $this->em->wrapInTransaction(function () use ($actor, $marker): void {
                self::getContainer()->get(AuditService::class)->recordGlobal($actor, AuditEventType::PRICING_RULE_CREATED, ['pricingRuleId' => 999999, 'firmName' => $marker]);
                $this->em->flush();
                throw new \RuntimeException('business failure after the audit flush');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM activity_event WHERE JSON_EXTRACT(payload, '$.firmName') = ?", [$marker],
        ));
    }

    public function test_a_journal_failure_rolls_the_business_mutation_back(): void
    {
        $siteName = 'H1 rollback site ' . bin2hex(random_bytes(3));

        try {
            $this->em->getConnection()->transactional(function () use ($siteName): void {
                $site = (new Hospital())->setName($siteName);
                $this->em->persist($site);
                $this->em->flush();
                $this->journal->record(new ActivityRecord(
                    eventType: ActivityEventType::ABSENCE_CREATED,
                    subjectType: 'Absence', subjectId: 1, subjectLabel: null,
                    summary: '   ', // invalid → the journal refuses
                ));
            });
            self::fail('journal failure expected');
        } catch (\InvalidArgumentException) {
        }

        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM hospital WHERE name = ?', [$siteName]));
    }

    // ── audit mirror ─────────────────────────────────────────────────────────

    public function test_audit_event_is_mirrored_in_the_same_flush_with_source_ref(): void
    {
        $actor   = $this->makeUser('ROLE_MANAGER', 'Samy', 'Ftaita');
        $mission = $this->makeMission(MissionStatus::ASSIGNED);

        self::getContainer()->get(AuditService::class)->record($mission, $actor, AuditEventType::MISSION_RELEASED_TO_POOL, [
            'previousStatus' => 'ASSIGNED', 'fromInstrumentistId' => 7, 'fromInstrumentistName' => 'Françoise Detrembleur',
            'reason' => null, 'causedByAbsenceId' => null, 'actorId' => $actor->getId(), 'actorName' => 'Samy Ftaita',
        ]);
        $this->em->flush();

        $auditId = (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM audit_event WHERE mission_id = ?', [$mission->getId()]);
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM activity_event WHERE source_ref = ?', ['audit_event:' . $auditId]);

        self::assertNotFalse($row);
        self::assertSame('MISSION_RELEASED_TO_POOL', $row['event_type']);
        self::assertSame('MISSIONS', $row['domain']);
        self::assertSame('audit_event:' . $auditId, $row['idempotency_key']);
        self::assertSame($mission->getId(), (int) $row['mission_id']);
        self::assertSame(7, (int) $row['user_id'], 'the removed instrumentist is the concerned user');
        self::assertSame('USER', $row['actor_type']);
        self::assertSame('Samy Ftaita', $row['actor_name_snapshot']);
        self::assertSame('ROLE_MANAGER', $row['actor_role_snapshot']);
        self::assertStringContainsString(sprintf('Samy Ftaita a libéré la mission #%d — ASSIGNED → OPEN', $mission->getId()), $row['summary']);
        self::assertStringContainsString('Françoise Detrembleur', $row['summary']);
    }

    // ── 6. DRAFT deletion keeps its history ──────────────────────────────────

    public function test_planning_draft_deleted_survives_the_physical_deletion(): void
    {
        $actor   = $this->makeUser('ROLE_MANAGER', 'Samy', 'Ftaita');
        $version = $this->makeDraftVersion($actor);
        $m1 = $this->makeMission(MissionStatus::DRAFT, $version, '2026-10-02 07:45');
        $m2 = $this->makeMission(MissionStatus::DRAFT, $version, '2026-10-30 07:45');
        self::getContainer()->get(AuditService::class)->record($m1, $actor, AuditEventType::MISSION_CREATED_FROM_PLANNING);
        $this->em->flush();
        $auditId   = (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM audit_event WHERE mission_id = ?', [$m1->getId()]);
        $versionId = $version->getId();

        // As the controller does: a freshly loaded version (its missions collection is
        // hydrated from the database, not the stale in-memory one built above).
        $this->em->clear();
        self::getContainer()->get(PlanningDraftService::class)->delete(
            $this->em->find(PlanningVersion::class, $versionId),
            $this->em->find(User::class, $actor->getId()),
        );

        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM mission WHERE id IN (?, ?)', [$m1->getId(), $m2->getId()]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM audit_event WHERE id = ?', [$auditId]), 'AuditEvent contract unchanged: removed with the DRAFT');
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM activity_event WHERE source_ref = ?', ['audit_event:' . $auditId]), 'its timeline mirror survives');

        $row = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'PLANNING_DRAFT_DELETED' AND planning_version_id = ?", [$versionId]);
        self::assertNotFalse($row);
        $payload = json_decode((string) $row['payload'], true);
        self::assertSame('MANUAL', $payload['source']);
        self::assertSame(2, $payload['missionCount']);
        self::assertSame(['DRAFT' => 2], $payload['countsByStatus']);
        self::assertFalse($payload['snapshotTruncated']);
        self::assertSame(['2026-10-02', '2026-10-30'], array_column($payload['occurrences'], 'date'));
        self::assertSame('Phil Schiepers', $payload['occurrences'][0]['surgeonName']);
        self::assertSame('Françoise Detrembleur', $payload['occurrences'][0]['instrumentistName']);
        self::assertSame('USER', $row['actor_type']);
    }

    public function test_snapshot_over_the_limit_is_explicitly_truncated(): void
    {
        $actor   = $this->makeUser('ROLE_MANAGER');
        $version = $this->makeDraftVersion($actor);
        $site    = $this->em->getReference(Hospital::class, $this->fx['sites'][0]);

        $missions = [];
        for ($i = 0; $i < PlanningActivityRecorder::SNAPSHOT_LIMIT + 7; $i++) {
            $missions[] = (new Mission())->setSite($site)->setSurgeon($actor)->setType(MissionType::BLOCK)
                ->setStatus(MissionStatus::DRAFT)->setStartAt(new \DateTimeImmutable('2026-10-02 07:45'))
                ->setEndAt(new \DateTimeImmutable('2026-10-02 13:00'));
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $id = self::getContainer()->get(PlanningActivityRecorder::class)
                ->draftDeleted($version, $missions, PlanningDraftDeletionSource::MANUAL, $actor);
            $payload = json_decode((string) $this->row($id)['payload'], true);
        } finally {
            $conn->rollBack();
        }

        self::assertTrue($payload['snapshotTruncated']);
        self::assertSame(500, $payload['snapshotCount']);
        self::assertSame(507, $payload['totalCount']);
        self::assertSame(507, $payload['missionCount']);
        self::assertCount(500, $payload['occurrences']);
    }

    // ── 7. absences ──────────────────────────────────────────────────────────

    public function test_absence_lifecycle_is_traced_and_survives_deletion_with_causal_anchor(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Phil', 'Schiepers');
        $absence = (new Absence())->setUser($surgeon)->setCreatedBy($surgeon)
            ->setDateStart(new \DateTimeImmutable('2026-09-29'))->setDateEnd(new \DateTimeImmutable('2026-10-04'))
            ->setReason('Congrès / formation');
        $this->em->persist($absence);
        $this->em->flush();
        $absenceId = $absence->getId();

        $created = $this->eventsFor('ABSENCE_CREATED', $absenceId);
        self::assertCount(1, $created);
        self::assertSame('Phil Schiepers a enregistré une absence du 29/09/2026 au 04/10/2026', $created[0]['summary']);
        $createdPayload = json_decode((string) $created[0]['payload'], true);
        self::assertTrue($createdPayload['hasReason']);
        self::assertArrayNotHasKey('reason', $createdPayload);
        self::assertStringNotContainsString('Congrès', (string) $created[0]['payload'], 'free text never copied');

        // A consequence written in the same operation links back to it explicitly.
        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        self::getContainer()->get(AuditService::class)->record($mission, $surgeon, AuditEventType::MISSION_CANCELLED_POST_DEPLOY, [
            'previousStatus' => 'ASSIGNED', 'causedByAbsenceId' => $absenceId,
        ]);
        $this->em->flush();
        $cancel = $this->em->getConnection()->fetchAssociative(
            "SELECT * FROM activity_event WHERE event_type = 'MISSION_CANCELLED_POST_DEPLOY' AND mission_id = ?", [$mission->getId()]);
        self::assertSame((int) $created[0]['id'], (int) $cancel['causation_event_id']);
        self::assertSame($created[0]['correlation_id'], $cancel['correlation_id']);
        self::assertSame($absenceId, (int) $cancel['absence_id']);
        self::assertStringContainsString(sprintf('annulée à la suite de l\'absence #%d', $absenceId), $cancel['summary']);

        // A sibling with no known causal link keeps the correlation but no causation.
        $sibling = $this->em->getConnection()->transactional(fn () => $this->journal->record($this->sampleRecord()));
        self::assertNull($this->row($sibling)['causation_event_id']);
        self::assertSame($created[0]['correlation_id'], $this->row($sibling)['correlation_id']);

        $absence->setDateEnd(new \DateTimeImmutable('2026-10-06'));
        $this->em->flush();
        $updated = $this->eventsFor('ABSENCE_UPDATED', $absenceId);
        self::assertCount(1, $updated);
        $up = json_decode((string) $updated[0]['payload'], true);
        self::assertSame('2026-10-04', $up['before']['dateEnd']);
        self::assertSame('2026-10-06', $up['after']['dateEnd']);
        self::assertSame(['dateEnd'], $up['changedFields']);

        $this->em->remove($absence);
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM absence WHERE id = ?', [$absenceId]));
        $deleted = $this->eventsFor('ABSENCE_DELETED', $absenceId);
        self::assertCount(1, $deleted);
        self::assertStringContainsString('29/09/2026 → 06/10/2026', $deleted[0]['summary']);
        self::assertCount(1, $this->eventsFor('ABSENCE_CREATED', $absenceId), 'creation trace still there after deletion');
    }

    // ── 11. idempotency ──────────────────────────────────────────────────────

    public function test_idempotency_key_prevents_a_duplicate(): void
    {
        $key = 'test:idem:' . bin2hex(random_bytes(6));
        $conn = $this->em->getConnection();

        $first  = $conn->transactional(fn () => $this->journal->record($this->sampleRecord(idempotencyKey: $key)));
        $second = $conn->transactional(fn () => $this->journal->record($this->sampleRecord(idempotencyKey: $key)));

        self::assertSame($first, $second);
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM activity_event WHERE idempotency_key = ?', [$key]));
    }

    // ── 8 / 10. Messenger through a real Doctrine transport ──────────────────

    public function test_correlation_survives_a_real_doctrine_transport_and_never_leaks_between_messages(): void
    {
        $dbal      = $this->em->getConnection();
        $queue     = 'h1_correlation_' . bin2hex(random_bytes(4));
        $transport = new DoctrineTransport(new DoctrineTransportConnection([
            'table_name' => 'messenger_messages', 'queue_name' => $queue, 'redeliver_timeout' => 3600, 'auto_setup' => true,
        ], $dbal), new PhpSerializer());

        $middleware = self::getContainer()->get(CorrelationMiddleware::class);
        $written = [];
        $handler = function (\stdClass $message) use (&$written): void {
            $written[] = $this->em->getConnection()->transactional(fn () => $this->journal->record($this->sampleRecord([
                'absenceId' => $message->n,
            ])));
        };
        // Dispatch side: the same middleware, no handler (the message only goes to the transport).
        $sendBus = new MessageBus([$middleware]);
        $bus     = new MessageBus([$middleware, new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [$handler]]))]);

        // "HTTP request" A dispatches a message, then request B dispatches another one.
        $this->context->begin(ActivityActorType::USER);
        $correlationA = $this->context->correlationId();
        $transport->send($sendBus->dispatch((object) ['n' => 1]));
        $this->context->begin(ActivityActorType::USER);
        $correlationB = $this->context->correlationId();
        $transport->send($sendBus->dispatch((object) ['n' => 2]));
        self::assertNotSame($correlationA, $correlationB, 'two requests, two correlations');
        self::assertSame([], $written, 'nothing handled at dispatch time');

        // Worker: a fresh process-like context, two messages pulled from the DB one after the other.
        $this->context->reset();
        $handled = 0;
        foreach ([1, 2] as $_) {
            foreach ($transport->get() as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('h1')));
                $transport->ack($envelope);
                $handled++;
            }
        }

        self::assertSame(2, $handled);
        self::assertSame($correlationA, $this->row($written[0])['correlation_id']);
        self::assertSame($correlationB, $this->row($written[1])['correlation_id']);
        self::assertNotNull($this->row($written[0])['messenger_message_id']);
        self::assertSame('WORKER', $this->row($written[0])['actor_type']);
        self::assertFalse($this->context->isActive(), 'worker context cleaned after each message');
    }

    // ── 15. index usage / write cost ─────────────────────────────────────────

    public function test_main_queries_use_their_index_and_writes_stay_cheap(): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $start = microtime(true);
            for ($i = 0; $i < 300; $i++) {
                $this->journal->record($this->sampleRecord(['absenceId' => $i]));
            }
            $perEventMs = (microtime(true) - $start) * 1000 / 300;
            // (No ANALYZE TABLE here: it implicitly commits in MySQL.)

            $plans = [
                'idx_activity_correlation'      => ['SELECT * FROM activity_event WHERE correlation_id = ? ORDER BY occurred_at, id', [UuidV7::generate()]],
                'idx_activity_mission'          => ['SELECT * FROM activity_event WHERE mission_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 50', [123]],
                'idx_activity_user'             => ['SELECT * FROM activity_event WHERE user_id = ? ORDER BY occurred_at DESC LIMIT 50', [123]],
                'idx_activity_absence'          => ['SELECT * FROM activity_event WHERE absence_id = ? ORDER BY occurred_at DESC LIMIT 50', [123]],
                'idx_activity_planning_version' => ['SELECT * FROM activity_event WHERE planning_version_id = ? ORDER BY occurred_at DESC LIMIT 50', [123]],
                'idx_activity_subject'          => ['SELECT * FROM activity_event WHERE subject_type = ? AND subject_id = ?', ['Mission', 123]],
                'uniq_activity_idempotency'     => ['SELECT id FROM activity_event WHERE idempotency_key = ?', ['audit_event:1']],
            ];
            foreach ($plans as $index => [$sql, $params]) {
                $plan = $conn->fetchAllAssociative('EXPLAIN ' . $sql, $params);
                // A unique-key lookup on a missing value is resolved by the optimizer
                // through that very index, then reported as "no matching row in const table".
                $resolvedByConstIndex = str_contains((string) ($plan[0]['Extra'] ?? ''), 'no matching row in const table');
                self::assertTrue($plan[0]['key'] === $index || ($index === 'uniq_activity_idempotency' && $resolvedByConstIndex),
                    "$sql should use $index: " . json_encode($plan));
            }
        } finally {
            $conn->rollBack();
        }

        self::assertLessThan(25, $perEventMs, 'a journal write must stay a cheap single INSERT');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function sampleRecord(array $payload = ['absenceId' => 1], ?string $idempotencyKey = null): ActivityRecord
    {
        return new ActivityRecord(
            eventType: ActivityEventType::ABSENCE_CREATED,
            subjectType: 'Absence',
            subjectId: $payload['absenceId'] ?? 1,
            subjectLabel: 'Absence de test',
            summary: 'Absence de test enregistrée',
            payload: $payload,
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @return array<string, mixed>|null */
    private function row(?int $id): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM activity_event WHERE id = ?', [$id]);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function eventsFor(string $type, int $absenceId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM activity_event WHERE event_type = ? AND absence_id = ? ORDER BY id', [$type, $absenceId]);
    }

    private static function root(\Throwable $e): \Throwable
    {
        while ($e->getPrevious() !== null && !$e instanceof ActivityEventImmutableException) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    private function makeUser(string $role, string $first = 'Test', string $last = 'User'): User
    {
        $u = (new User())->setEmail('h1-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')
            ->setRoles([$role])->setFirstname($first)->setLastname($last)->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->fx['users'][] = $u->getId();

        return $u;
    }

    private function site(): Hospital
    {
        if ($this->fx['sites'] !== []) {
            return $this->em->getReference(Hospital::class, $this->fx['sites'][0]);
        }
        $site = (new Hospital())->setName('H1 Delta ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->fx['sites'][] = $site->getId();

        return $site;
    }

    private function makeDraftVersion(User $actor): PlanningVersion
    {
        $v = (new PlanningVersion())->setPeriodStart(new \DateTimeImmutable('2026-10-01'))
            ->setPeriodEnd(new \DateTimeImmutable('2026-10-31'))->setGeneratedBy($actor)
            ->setStatus(PlanningVersionStatus::DRAFT)->setVersionNumber(1)->setSite($this->site());
        $this->em->persist($v);
        $this->em->flush();

        return $v;
    }

    private function makeMission(MissionStatus $status, ?PlanningVersion $version = null, string $start = '2026-12-16 07:45'): Mission
    {
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Phil', 'Schiepers');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST', 'Françoise', 'Detrembleur');
        $m = (new Mission())->setSite($this->site())->setSurgeon($surgeon)->setInstrumentist($instr)
            ->setType(MissionType::BLOCK)->setStatus($status)->setCreatedBy($surgeon)
            ->setStartAt(new \DateTimeImmutable($start, new \DateTimeZone('Europe/Brussels')))
            ->setEndAt((new \DateTimeImmutable($start, new \DateTimeZone('Europe/Brussels')))->modify('+5 hours'))
            ->setPlanningVersion($version);
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }
}
