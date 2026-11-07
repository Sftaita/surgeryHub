<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionPublication;
use App\Entity\NotificationEvent;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SiteMembership;
use App\Entity\SurgeonMissionRequest;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EmploymentType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\PublicationCloseReason;
use App\Enum\PublicationScope;
use App\Enum\RecurrenceFrequency;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\RoomTakeoverPriorityExpiryMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\MessageHandler\RoomTakeoverPriorityExpiryMessageHandler;
use App\Service\RoomTakeoverPriorityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-177 — reprise de salle : publication immédiate, priorité exclusive de 48 heures de
 * l'instrumentiste habituelle, expiration automatique, historique manager, et demande
 * chirurgien acceptée comme vraie reprise (incident du 4 novembre). De bout en bout sur HTTP
 * réel + base réelle, à partir du VRAI flux d'absence (POST /api/absences) ; les
 * notifications sont vérifiées en exécutant les VRAIS handlers sur les messages dispatchés.
 */
final class RoomTakeoverPriorityFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'RoomPriority176!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $siteIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->transport = static::getContainer()->get('messenger.transport.async');
        $this->transport->reset();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $sql = static function (string $q, array $p = []) use ($conn): void {
            try { $conn->executeStatement($q, $p); } catch (\Throwable) { /* best effort */ }
        };

        foreach ($this->siteIds as $siteId) {
            $sql('DELETE FROM released_operating_room_slot WHERE site_id = ?', [$siteId]);
        }
        $missionIds = [];
        foreach ($this->userIds as $uid) {
            foreach ($conn->fetchFirstColumn('SELECT id FROM mission WHERE surgeon_id = ? OR instrumentist_id = ? OR created_by_id = ?', [$uid, $uid, $uid]) as $mid) {
                $missionIds[(int) $mid] = true;
            }
            $sql('DELETE FROM surgeon_mission_request WHERE surgeon_id = ?', [$uid]);
        }
        foreach (array_keys($missionIds) as $mid) {
            foreach (['notification_event', 'outbound_notification', 'audit_event', 'mission_claim', 'planning_alert', 'mission_publication', 'mission_assignment', 'mission_communication'] as $table) {
                $sql("DELETE FROM {$table} WHERE mission_id = ?", [$mid]);
            }
            $sql('DELETE FROM mission WHERE id = ?', [$mid]);
        }
        foreach ($this->userIds as $uid) {
            $sql('DELETE FROM notification_event WHERE user_id = ?', [$uid]);
            $sql('DELETE FROM outbound_notification WHERE recipient_user_id = ?', [$uid]);
            $sql('DELETE FROM audit_event WHERE actor_id = ?', [$uid]);
            $sql('DELETE e FROM planning_occurrence_exception e JOIN surgeon_schedule_post p ON p.id = e.post_id WHERE p.surgeon_id = ?', [$uid]);
            $sql('DELETE FROM surgeon_absence_communication WHERE surgeon_id = ?', [$uid]);
            $sql('DELETE FROM absence WHERE user_id = ?', [$uid]);
        }
        foreach ($this->userIds as $uid) {
            $sql('DELETE FROM surgeon_schedule_post WHERE surgeon_id = ?', [$uid]);
            $sql('DELETE FROM site_membership WHERE user_id = ?', [$uid]);
            $sql('DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM user WHERE id = ?)', [$uid]);
        }
        foreach ($this->userIds as $uid) {
            $sql('DELETE FROM user WHERE id = ?', [$uid]);
        }
        foreach ($this->siteIds as $siteId) {
            $sql('DELETE FROM shift_period_config WHERE site_id = ?', [$siteId]);
            $sql('DELETE FROM hospital WHERE id = ?', [$siteId]);
        }
        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function ref(object $entity): object
    {
        return $this->em->find($entity::class, $entity->getId());
    }

    /** @return array{0: User, 1: string} */
    private function loggedUser(string $role, string $firstname, ?EmploymentType $employment = null): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d176-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Test');
        $u->setEmploymentType($employment);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $u->getEmail(), 'password' => self::PASSWORD]));

        return [$u, $this->json($this->client->getResponse())['token'] ?? ''];
    }

    private function makeSite(string $name): Hospital
    {
        $h = new Hospital();
        $h->setName($name . ' ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->siteIds[] = $h->getId();

        return $h;
    }

    private function affiliate(User $user, Hospital $site, string $role): void
    {
        $sm = new SiteMembership();
        $sm->setUser($this->ref($user));
        $sm->setSite($this->ref($site));
        $sm->setSiteRole($role);
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function makeBlockPost(User $surgeon, Hospital $site, \DateTimeImmutable $day, ?User $instrumentist): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([(int) $day->format('N')]);
        $rule->setAnchorDate($day);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($this->ref($surgeon));
        $post->setSite($this->ref($site));
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate($day);
        $post->setEndDate($day);
        $post->setInstrumentist($instrumentist !== null ? $this->ref($instrumentist) : null);
        $post->setCreatedBy($this->ref($surgeon));
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day, string $start = '08:00', string $end = '13:00'): Mission
    {
        $tz = new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($this->ref($surgeon));
        $m->setInstrumentist($instrumentist !== null ? $this->ref($instrumentist) : null);
        $m->setSite($this->ref($site));
        $m->setStartAt(new \DateTimeImmutable("{$day} {$start}:00", $tz));
        $m->setEndAt(new \DateTimeImmutable("{$day} {$end}:00", $tz));
        $m->setCreatedBy($this->ref($surgeon));
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    private function request(string $method, string $uri, string $token, ?array $body = null): Response
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);

        return $this->client->getResponse();
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** Runs the REAL lifecycle handler on every dispatched message of this type, then clears the queue. */
    private function runLifecycleHandler(MissionChangeType $type): int
    {
        $handler = static::getContainer()->get(MissionLifecycleChangedMessageHandler::class);
        $ran = 0;
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof MissionLifecycleChangedMessage && $message->changeType === $type) {
                $handler($message);
                $ran++;
            }
        }
        $this->transport->reset();

        return $ran;
    }

    /** @return list<MissionLifecycleChangedMessage> */
    private function sentLifecycle(MissionChangeType $type): array
    {
        $out = [];
        foreach ($this->transport->getSent() as $envelope) {
            $m = $envelope->getMessage();
            if ($m instanceof MissionLifecycleChangedMessage && $m->changeType === $type) {
                $out[] = $m;
            }
        }

        return $out;
    }

    private function notificationsFor(User $user, NotificationType $type, ?int $missionId = null): array
    {
        $criteria = ['user' => $user->getId(), 'eventType' => $type->value];
        if ($missionId !== null) {
            $criteria['mission'] = $missionId;
        }

        return $this->em->getRepository(NotificationEvent::class)->findBy($criteria);
    }

    /** @return list<AuditEvent> */
    private function auditEvents(AuditEventType $type, int $missionId): array
    {
        return $this->em->getRepository(AuditEvent::class)->findBy(['eventType' => $type, 'mission' => $missionId], ['id' => 'ASC']);
    }

    /** @return list<MissionPublication> */
    private function publications(int $missionId): array
    {
        $this->em->clear();

        return $this->em->getRepository(MissionPublication::class)->findBy(['mission' => $missionId], ['id' => 'ASC']);
    }

    private function slotOf(Hospital $site): ReleasedOperatingRoomSlot
    {
        $this->em->clear();
        $slots = $this->em->getRepository(ReleasedOperatingRoomSlot::class)->findBy(['site' => $site->getId()]);
        self::assertCount(1, $slots, 'exactly one released slot expected for this site');

        return $slots[0];
    }

    /** @return list<Mission> */
    private function missionsOf(User $surgeon): array
    {
        $this->em->clear();

        return $this->em->getRepository(Mission::class)->findBy(['surgeon' => $surgeon->getId()], ['id' => 'ASC']);
    }

    private function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }

    private function backdatePriority(int $publicationId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE mission_publication SET expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $publicationId],
        );
        $this->em->clear();
    }

    /**
     * Delta: Dr A has a BLOCK post (usual instrumentist Marie) and a deployed Mission ASSIGNED to
     * Marie on $day 08:00–13:00; A's absence is declared through the real API (unless
     * $declareAbsence is false — the 4 Nov order: request first, absence later).
     *
     * @return array<string, mixed>
     */
    private function scene(int $daysAhead = 28, bool $declareAbsence = true, bool $usualInstrumentist = true): array
    {
        [$manager, $managerToken] = $this->loggedUser('ROLE_MANAGER', 'Manon');
        [$a, $aToken] = $this->loggedUser('ROLE_SURGEON', 'Alain');
        [$b, $bToken] = $this->loggedUser('ROLE_SURGEON', 'Bruno');
        [$c, $cToken] = $this->loggedUser('ROLE_SURGEON', 'Claire');
        [$marie, $marieToken] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Marie', EmploymentType::EMPLOYEE);
        [$paul, $paulToken] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Paul', EmploymentType::EMPLOYEE);

        $site = $this->makeSite('Delta');
        foreach ([$a, $b, $c] as $s) {
            $this->affiliate($s, $site, 'SURGEON');
        }
        $this->affiliate($marie, $site, 'INSTRUMENTIST');
        $this->affiliate($paul, $site, 'INSTRUMENTIST');

        $day = (new \DateTimeImmutable('today'))->modify("+{$daysAhead} days");
        $dayYmd = $day->format('Y-m-d');
        $post = $this->makeBlockPost($a, $site, $day, $usualInstrumentist ? $marie : null);
        $original = $this->makeMission($a, $usualInstrumentist ? $marie : null, $site, $usualInstrumentist ? MissionStatus::ASSIGNED : MissionStatus::OPEN, $dayYmd);

        $s = compact('manager', 'managerToken', 'a', 'aToken', 'b', 'bToken', 'c', 'cToken', 'marie', 'marieToken', 'paul', 'paulToken', 'site', 'day', 'dayYmd', 'post', 'original');
        if ($declareAbsence) {
            $this->declareAbsenceOfA($s);
        }

        return $s;
    }

    private function declareAbsenceOfA(array $s): void
    {
        $res = $this->request('POST', '/api/absences', $s['managerToken'], ['userId' => $s['a']->getId(), 'dateStart' => $s['dayYmd'], 'dateEnd' => $s['dayYmd']]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $this->transport->reset();
    }

    /** B takes over A's room; returns [slot, mission id, priority publication]. */
    private function takeOverByB(array $s): array
    {
        $slot = $this->slotOf($s['site']);
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $missionId = $this->missionsOf($s['b'])[0]->getId();
        $pubs = $this->publications($missionId);

        return [$slot, $missionId, $pubs[0] ?? null];
    }

    // ── 1. Reprise = publication immédiate + priorité exclusive de 48 h (Cas A) ─────────

    #[WithoutErrorHandler]
    public function test_take_over_publishes_at_once_with_an_exclusive_48h_priority_invisible_to_the_pool(): void
    {
        $s = $this->scene();
        [$slot, $missionId, $priority] = $this->takeOverByB($s);

        // Mission operational at once (never DRAFT), without PlanningVersion requirement.
        $mission = $this->em->find(Mission::class, $missionId);
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());

        // Exactly one publication: TARGETED to Marie, 48 real hours.
        $pubs = $this->publications($missionId);
        self::assertCount(1, $pubs);
        self::assertSame(PublicationScope::TARGETED, $pubs[0]->getScope());
        self::assertSame($s['marie']->getId(), $pubs[0]->getTargetInstrumentist()->getId());
        self::assertSame(48 * 3600, $pubs[0]->getExpiresAt()->getTimestamp() - $pubs[0]->getPublishedAt()->getTimestamp());

        // The expiry is scheduled (delayed message at the deadline), independent of any user.
        $delays = [];
        foreach ($this->transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof RoomTakeoverPriorityExpiryMessage) {
                $delays[] = $envelope->last(DelayStamp::class)?->getDelay();
            }
        }
        self::assertCount(1, $delays);
        self::assertEqualsWithDelta(48 * 3600 * 1000, $delays[0], 10_000);

        // Notification: only Marie (offer with deadline), never the pool. Drained right away: the
        // in-memory transport is a resettable service, emptied by the next request.
        $takenOver = $this->sentLifecycle(MissionChangeType::ROOM_TAKEN_OVER);
        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::ROOM_TAKEN_OVER));
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($s['marie'], NotificationType::ROOM_TAKEOVER_MISSION_OFFER, $missionId));
        self::assertCount(0, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId));

        // Channels (D-177): in-app + email by default, traced per channel; the email goes to her
        // only. A Messenger redelivery of the same message sends nothing more.
        $channels = fn (User $u) => $this->em->getConnection()->fetchAllKeyValue(
            "SELECT channel, COUNT(*) FROM outbound_notification WHERE recipient_user_id = ? AND mission_id = ? AND notification_type = 'ROOM_TAKEOVER_MISSION_OFFER' GROUP BY channel",
            [$u->getId(), $missionId],
        );
        $before = $channels($s['marie']);
        self::assertSame(1, (int) ($before['EMAIL'] ?? 0), 'one email for the priority offer');
        self::assertSame(1, (int) ($before['IN_APP'] ?? 0));
        self::assertSame([], $channels($s['paul']), 'nothing to the pool');
        static::getContainer()->get(MissionLifecycleChangedMessageHandler::class)($takenOver[0]);
        self::assertSame($before, $channels($s['marie']), 'replayed message: no duplicate on any channel');

        $offered = $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_OFFERED, $missionId);
        self::assertCount(1, $offered);
        self::assertSame('Marie Test', $offered[0]->getPayload()['instrumentistName']);
        self::assertSame($slot->getId(), $offered[0]->getPayload()['roomSlotId']);
        self::assertFalse($offered[0]->getPayload()['missionStartsBeforeExpiry']);

        // Visibility — instrumentists: only Marie sees it, Paul can neither list, view nor claim it.
        $paulOffers = $this->json($this->request('GET', '/api/missions?eligibleToMe=true', $s['paulToken']));
        self::assertEmpty(array_filter($paulOffers['items'], fn ($i) => $i['id'] === $missionId));
        self::assertSame(403, $this->request('GET', "/api/missions/{$missionId}", $s['paulToken'])->getStatusCode());
        self::assertSame(403, $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode());
        $marieOffers = $this->json($this->request('GET', '/api/missions?eligibleToMe=true', $s['marieToken']));
        $row = current(array_filter($marieOffers['items'], fn ($i) => $i['id'] === $missionId));
        self::assertNotFalse($row);
        self::assertSame('PENDING', $row['targetedOffer']['status']);
        self::assertTrue($row['targetedOffer']['priority']);
        self::assertNotNull($row['targetedOffer']['expiresAt']);

        // Surgeon: B sees his mission in his planning.
        $bList = $this->json($this->request('GET', '/api/missions', $s['bToken']));
        self::assertNotEmpty(array_filter($bList['items'], fn ($i) => $i['id'] === $missionId));

        // Manager: state, recipient and deadline; manager mission view offers lift_priority.
        $mgr = $this->json($this->request('GET', '/api/planning/available-rooms?siteId=' . $s['site']->getId(), $s['managerToken']));
        self::assertSame('PRIORITY_PENDING', $mgr['items'][0]['operational']['state']);
        self::assertSame('Marie Test', $mgr['items'][0]['operational']['priority']['instrumentist']['name']);
        self::assertNotNull($mgr['items'][0]['operational']['priority']['expiresAt']);
        self::assertSame('PRIORITY_EXPIRY', $mgr['items'][0]['operational']['nextAutomaticAction']['type']);

        // A manager cannot publish it to the pool behind the priority's back.
        self::assertSame(409, $this->request('POST', "/api/missions/{$missionId}/publish", $s['managerToken'], ['scope' => 'POOL'])->getStatusCode());
    }

    // ── 2. Refus → pool immédiat, même mission ─────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_refusal_ends_the_priority_at_once_and_publishes_the_same_mission_to_the_pool(): void
    {
        $s = $this->scene();
        [, $missionId] = $this->takeOverByB($s);
        $this->transport->reset();

        $res = $this->request('POST', "/api/missions/{$missionId}/decline-offer", $s['marieToken'], ['reason' => 'Pas disponible']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $pubs = $this->publications($missionId);
        self::assertCount(2, $pubs);
        self::assertNotNull($pubs[0]->getDeclinedAt(), 'the refusal is a refusal');
        self::assertNull($pubs[0]->getClosedAt());
        self::assertSame(PublicationScope::POOL, $pubs[1]->getScope());
        self::assertTrue($pubs[1]->isActive());

        $ended = $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_ENDED, $missionId);
        self::assertSame('DECLINED', $ended[0]->getPayload()['endReason']);
        self::assertSame('Pas disponible', $ended[0]->getPayload()['reason']);
        $pool = $this->auditEvents(AuditEventType::MISSION_PUBLISHED_TO_POOL, $missionId);
        self::assertSame('PRIORITY_DECLINED', $pool[0]->getPayload()['poolOpenedReason']);
        self::assertSame([], $this->sentLifecycle(MissionChangeType::OFFER_DECLINED), 'no manager fan-out: the history shows it');

        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::PRIORITY_ENDED));
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId));
        self::assertCount(0, $this->notificationsFor($s['marie'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId), 'the one who declined is not solicited');

        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode());
        $this->em->clear();
        self::assertSame($s['paul']->getId(), $this->em->find(Mission::class, $missionId)->getInstrumentist()->getId());
        self::assertCount(1, $this->missionsOf($s['b']), 'never a second mission');
    }

    // ── 3. Expiration sans réponse → pool, idempotente, jamais un refus ─────────────────

    #[WithoutErrorHandler]
    public function test_expiry_without_answer_opens_the_pool_once_and_is_not_a_refusal(): void
    {
        $s = $this->scene();
        [, $missionId, $priority] = $this->takeOverByB($s);
        $this->transport->reset();

        // Not due yet: the sweep changes nothing, the early message is re-scheduled.
        self::assertStringContainsString('Priorities due: 0', $this->runCommand('app:room-takeover:expire-priorities')->getDisplay());
        static::getContainer()->get(RoomTakeoverPriorityExpiryMessageHandler::class)(new RoomTakeoverPriorityExpiryMessage($priority->getId()));
        self::assertCount(1, $this->publications($missionId));

        $this->backdatePriority($priority->getId());
        $out = $this->runCommand('app:room-takeover:expire-priorities')->getDisplay();
        self::assertStringContainsString('Expired: 1', $out);

        $pubs = $this->publications($missionId);
        self::assertCount(2, $pubs);
        self::assertSame(PublicationCloseReason::EXPIRED, $pubs[0]->getCloseReason());
        self::assertNull($pubs[0]->getDeclinedAt(), 'an expiry is never recorded as a refusal');
        self::assertSame(PublicationScope::POOL, $pubs[1]->getScope());
        $ended = $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_ENDED, $missionId);
        self::assertCount(1, $ended);
        self::assertSame('EXPIRED', $ended[0]->getPayload()['endReason']);
        self::assertSame($priority->getId(), $ended[0]->getPayload()['publicationId']);
        self::assertSame($s['marie']->getId(), $ended[0]->getPayload()['instrumentistId']);
        self::assertNotNull($ended[0]->getPayload()['offeredAt']);
        self::assertNotNull($ended[0]->getPayload()['expiresAt']);
        self::assertTrue($ended[0]->getActor()->getEmail() === 'system@surgicalhub.internal', 'automatic action');
        self::assertSame(MissionStatus::OPEN, $this->em->find(Mission::class, $missionId)->getStatus());
        self::assertNull($this->em->find(Mission::class, $missionId)->getInstrumentist(), 'never an automatic assignment');

        // Re-runs (cron + delayed message + retry): nothing more.
        $messagesAfterFirst = count($this->sentLifecycle(MissionChangeType::PRIORITY_ENDED));
        self::assertSame(1, $messagesAfterFirst);
        self::assertStringContainsString('Priorities due: 0', $this->runCommand('app:room-takeover:expire-priorities')->getDisplay());
        static::getContainer()->get(RoomTakeoverPriorityExpiryMessageHandler::class)(new RoomTakeoverPriorityExpiryMessage($priority->getId()));
        self::assertCount(2, $this->publications($missionId), 'never a second pool publication');
        self::assertCount(1, $this->sentLifecycle(MissionChangeType::PRIORITY_ENDED), 'never a second solicitation');

        // Pool solicited — Marie included (an expiry is not a refusal).
        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::PRIORITY_ENDED));
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId));
        self::assertCount(1, $this->notificationsFor($s['marie'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId));
    }

    // ── 4. Course : acceptation à la dernière seconde contre expiration ───────────────

    #[WithoutErrorHandler]
    public function test_last_second_acceptance_wins_over_the_expiry_and_nothing_is_published_to_the_pool(): void
    {
        $s = $this->scene();
        [, $missionId, $priority] = $this->takeOverByB($s);

        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['marieToken'])->getStatusCode());
        $this->backdatePriority($priority->getId()); // the deadline passes right after
        $this->transport->reset();

        self::assertStringContainsString('Priorities due: 0', $this->runCommand('app:room-takeover:expire-priorities')->getDisplay());
        self::assertFalse(static::getContainer()->get(RoomTakeoverPriorityService::class)->expire($priority->getId()));

        $pubs = $this->publications($missionId);
        self::assertCount(1, $pubs, 'no pool publication');
        self::assertSame(PublicationCloseReason::ACCEPTED, $pubs[0]->getCloseReason());
        self::assertSame([], $this->sentLifecycle(MissionChangeType::PRIORITY_ENDED), 'no contradictory notification');
        self::assertSame($s['marie']->getId(), $this->em->find(Mission::class, $missionId)->getInstrumentist()->getId());
    }

    #[WithoutErrorHandler]
    public function test_after_expiry_the_first_valid_claim_wins_and_the_former_recipient_may_still_take_it(): void
    {
        $s = $this->scene();
        [, $missionId, $priority] = $this->takeOverByB($s);
        $this->backdatePriority($priority->getId());
        $this->runCommand('app:room-takeover:expire-priorities');

        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['marieToken'])->getStatusCode());
        self::assertContains($this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode(), [403, 409], 'one assignment only');
        $this->em->clear();
        self::assertSame($s['marie']->getId(), $this->em->find(Mission::class, $missionId)->getInstrumentist()->getId());
    }

    // ── 5. Attribution manager pendant la priorité ─────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_a_manager_direct_assignment_during_the_priority_supersedes_it_without_pool(): void
    {
        $s = $this->scene();
        [, $missionId] = $this->takeOverByB($s);

        $res = $this->request('POST', "/api/missions/{$missionId}/assign-directly", $s['managerToken'], ['instrumentistId' => $s['paul']->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $pubs = $this->publications($missionId);
        self::assertCount(1, $pubs);
        self::assertSame(PublicationCloseReason::SUPERSEDED, $pubs[0]->getCloseReason());
        self::assertSame('SUPERSEDED', $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_ENDED, $missionId)[0]->getPayload()['endReason']);
        self::assertContains($this->request('POST', "/api/missions/{$missionId}/claim", $s['marieToken'])->getStatusCode(), [403, 409], 'the former recipient can no longer take it');
        self::assertSame([], $this->sentLifecycle(MissionChangeType::PRIORITY_ENDED), 'no pool solicitation');
    }

    // ── 6. Cas B : pas d'instrumentiste habituelle identifiable ─────────────────────────

    #[WithoutErrorHandler]
    public function test_no_usual_instrumentist_publishes_to_the_pool_at_once_with_the_reason(): void
    {
        $s = $this->scene(usualInstrumentist: false);
        [, $missionId] = $this->takeOverByB($s);

        $pubs = $this->publications($missionId);
        self::assertCount(1, $pubs);
        self::assertSame(PublicationScope::POOL, $pubs[0]->getScope());
        self::assertSame('NO_USUAL_INSTRUMENTIST', $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_BYPASSED, $missionId)[0]->getPayload()['reason']);
        self::assertSame('NO_USUAL_INSTRUMENTIST', $this->auditEvents(AuditEventType::MISSION_PUBLISHED_TO_POOL, $missionId)[0]->getPayload()['poolOpenedReason']);
        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::ROOM_TAKEN_OVER));
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId));
        self::assertCount(1, $this->notificationsFor($s['marie'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId));
    }

    // ── 7. Cas C : congé déclaré pendant la priorité ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_an_absence_declared_during_the_priority_ends_it_at_once_and_opens_the_pool(): void
    {
        $s = $this->scene();
        [, $missionId] = $this->takeOverByB($s);
        $this->transport->reset();

        $res = $this->request('POST', '/api/absences', $s['managerToken'], ['userId' => $s['marie']->getId(), 'dateStart' => $s['dayYmd'], 'dateEnd' => $s['dayYmd']]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        $pubs = $this->publications($missionId);
        self::assertSame(PublicationCloseReason::RECIPIENT_UNAVAILABLE, $pubs[0]->getCloseReason());
        self::assertSame(PublicationScope::POOL, $pubs[1]->getScope());
        $ended = $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_ENDED, $missionId);
        self::assertSame('RECIPIENT_UNAVAILABLE', $ended[0]->getPayload()['endReason']);
        self::assertNotNull($ended[0]->getPayload()['absenceId']);

        // Idempotent: the sweep finds nothing more to do.
        self::assertStringContainsString('recipient absent: 0', $this->runCommand('app:room-takeover:expire-priorities')->getDisplay());
        self::assertCount(2, $this->publications($missionId));
    }

    // ── 8. Cas D : intervention imminente, levée manager justifiée ──────────────────────

    #[WithoutErrorHandler]
    public function test_imminent_mission_is_flagged_and_a_manager_can_lift_the_priority_with_a_justification(): void
    {
        $s = $this->scene(daysAhead: 1);
        [$slot, $missionId] = $this->takeOverByB($s);

        $mgr = $this->json($this->request('GET', '/api/planning/available-rooms?siteId=' . $s['site']->getId(), $s['managerToken']));
        self::assertTrue($mgr['items'][0]['operational']['priority']['startsBeforeExpiry'], 'manager alert: the mission starts before the 48 h end');
        $detail = $this->json($this->request('GET', "/api/missions/{$missionId}", $s['managerToken']));
        self::assertTrue($detail['targetedOffer']['startsBeforeExpiry']);
        self::assertContains('lift_priority', $detail['allowedActions']);

        self::assertSame(403, $this->request('POST', "/api/missions/{$missionId}/lift-priority", $s['bToken'], ['justification' => 'x'])->getStatusCode());
        self::assertSame(422, $this->request('POST', "/api/missions/{$missionId}/lift-priority", $s['managerToken'], ['justification' => '  '])->getStatusCode());
        $this->transport->reset();
        $res = $this->request('POST', "/api/missions/{$missionId}/lift-priority", $s['managerToken'], ['justification' => 'Intervention demain matin']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $ended = $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_ENDED, $missionId);
        self::assertSame('LIFTED_BY_MANAGER', $ended[0]->getPayload()['endReason']);
        self::assertSame('Intervention demain matin', $ended[0]->getPayload()['justification']);
        self::assertSame($s['manager']->getId(), $ended[0]->getActor()->getId());
        self::assertSame('LIFTED_BY_MANAGER', $this->auditEvents(AuditEventType::MISSION_PUBLISHED_TO_POOL, $missionId)[0]->getPayload()['poolOpenedReason']);
        self::assertCount(1, $this->sentLifecycle(MissionChangeType::PRIORITY_ENDED));
        self::assertSame(403, $this->request('POST', "/api/missions/{$missionId}/lift-priority", $s['managerToken'], ['justification' => 'again'])->getStatusCode(), 'nothing left to lift');

        // History: the whole chain, chronological, permanent.
        $history = $this->json($this->request('GET', "/api/planning/available-rooms/{$slot->getId()}/history", $s['managerToken']));
        $types = array_column($history['events'], 'type');
        foreach (['ABSENCE_DECLARED', 'ROOM_SLOT_MADE_AVAILABLE', 'ROOM_SLOT_TAKEN_OVER', 'MISSION_ADDED_POST_DEPLOY', 'ROOM_TAKEOVER_PRIORITY_OFFERED', 'ROOM_TAKEOVER_PRIORITY_ENDED', 'MISSION_PUBLISHED_TO_POOL'] as $expected) {
            self::assertContains($expected, $types, $expected);
        }
        self::assertLessThan(array_search('ROOM_TAKEOVER_PRIORITY_OFFERED', $types, true), array_search('ROOM_SLOT_TAKEN_OVER', $types, true));
        self::assertSame('POOL_OPEN', $history['current']['state']);
        self::assertSame(403, $this->request('GET', "/api/planning/available-rooms/{$slot->getId()}/history", $s['bToken'])->getStatusCode(), 'managers only');
    }

    // ── 9. Désistement du repreneur pendant la priorité ─────────────────────────────────

    #[WithoutErrorHandler]
    public function test_taker_release_during_the_priority_closes_it_and_the_history_survives(): void
    {
        $s = $this->scene();
        [$slot, $missionId, $priority] = $this->takeOverByB($s);

        self::assertSame(200, $this->request('POST', "/api/available-rooms/{$slot->getId()}/release", $s['bToken'])->getStatusCode());
        $pubs = $this->publications($missionId);
        self::assertSame(PublicationCloseReason::MISSION_CLOSED, $pubs[0]->getCloseReason());
        $this->backdatePriority($priority->getId());
        self::assertStringContainsString('Priorities due: 0', $this->runCommand('app:room-takeover:expire-priorities')->getDisplay(), 'a closed priority never expires into a pool');

        // C takes the room again: the history keeps both take-overs.
        self::assertSame(200, $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['cToken'])->getStatusCode());
        $history = $this->json($this->request('GET', "/api/planning/available-rooms/{$slot->getId()}/history", $s['managerToken']));
        self::assertCount(2, array_filter($history['events'], fn ($e) => $e['type'] === 'ROOM_SLOT_TAKEN_OVER'));
        self::assertContains('ROOM_SLOT_REOPENED', array_column($history['events'], 'type'));
        self::assertContains($missionId, array_column($history['events'], 'missionId'), 'the cancelled mission stays in the follow-up');
        self::assertSame('PRIORITY_PENDING', $history['current']['state']);
    }

    // ── 9b. Mission de reprise rouverte : toujours atteignable (audit PR #58) ───────────

    public function test_accepted_priority_released_by_a_manager_goes_back_to_the_pool(): void
    {
        $s = $this->scene();
        [, $missionId] = $this->takeOverByB($s);
        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['marieToken'])->getStatusCode());
        self::assertSame(PublicationCloseReason::ACCEPTED, $this->publications($missionId)[0]->getCloseReason());

        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/release", $s['managerToken'])->getStatusCode());
        $pubs = $this->publications($missionId);
        self::assertCount(2, $pubs, 'one POOL publication added, nothing else');
        self::assertSame(PublicationScope::POOL, $pubs[1]->getScope());
        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode());
        self::assertCount(1, $this->missionsOf($s['b']), 'never a second mission');
    }

    public function test_cancelled_then_restored_take_over_mission_is_reachable_through_the_pool(): void
    {
        $s = $this->scene();
        [, $missionId] = $this->takeOverByB($s);

        $service = static::getContainer()->get(\App\Service\MissionPostDeployService::class);
        $service->cancel($this->em->find(Mission::class, $missionId), $this->ref($s['manager']), 'Bloc fermé', false);
        self::assertSame(PublicationCloseReason::MISSION_CLOSED, $this->publications($missionId)[0]->getCloseReason());
        $service->restoreAfterCancellation($this->em->find(Mission::class, $missionId), $this->ref($s['manager']), null, 0);

        $pubs = $this->publications($missionId);
        self::assertCount(2, $pubs);
        self::assertSame(PublicationScope::POOL, $pubs[1]->getScope());
        self::assertTrue($pubs[1]->isActive());
        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode());
    }

    public function test_opening_a_started_take_over_mission_from_an_alert_keeps_it_reachable(): void
    {
        $s = $this->scene();
        [, $missionId] = $this->takeOverByB($s);
        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['marieToken'])->getStatusCode());
        // Started (D-064 start-due), then a planning alert asks to put it back in the pool.
        $this->em->getConnection()->executeStatement("UPDATE mission SET status = 'IN_PROGRESS' WHERE id = ?", [$missionId]);
        $this->em->clear();

        $alert = new \App\Entity\PlanningAlert();
        $alert->setMission($this->em->find(Mission::class, $missionId));
        $alert->setType(\App\Enum\PlanningAlertType::REASSIGNMENT_REQUIRED);
        $alert->setSnapshotJson(['test' => true]);
        $this->em->persist($alert);
        $this->em->flush();

        static::getContainer()->get(\App\Service\PlanningAlertActionService::class)
            ->openAsAvailable($alert, $this->ref($s['manager']), null);

        $pubs = $this->publications($missionId);
        self::assertSame('OPEN', $this->em->find(Mission::class, $missionId)->getStatus()->value);
        self::assertCount(2, $pubs);
        self::assertSame(PublicationScope::POOL, $pubs[1]->getScope());
        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode());
    }

    // ── 10. Publication au pool idempotente (double clic du 29/10) ──────────────────────

    #[WithoutErrorHandler]
    public function test_publishing_twice_to_the_pool_creates_one_publication_and_one_solicitation(): void
    {
        $s = $this->scene(declareAbsence: false);
        $draft = $this->makeMission($s['c'], null, $s['site'], MissionStatus::DRAFT, $s['dayYmd'], '14:00', '17:00');

        $published = fn () => count(array_filter($this->transport->getSent(), fn ($e) => $e->getMessage() instanceof \App\Message\MissionPublishedMessage));

        self::assertSame(204, $this->request('POST', "/api/missions/{$draft->getId()}/publish", $s['managerToken'], ['scope' => 'POOL'])->getStatusCode());
        self::assertSame(1, $published(), 'first click: one pool solicitation');
        self::assertSame(204, $this->request('POST', "/api/missions/{$draft->getId()}/publish", $s['managerToken'], ['scope' => 'POOL'])->getStatusCode());
        self::assertSame(0, $published(), 'second click: nothing new');

        self::assertCount(1, $this->publications($draft->getId()));
        self::assertCount(1, $this->auditEvents(AuditEventType::MISSION_PUBLISHED_TO_POOL, $draft->getId()));
    }

    // ── 11. Demande chirurgien = vraie reprise (incident du 4 novembre) ─────────────────

    #[WithoutErrorHandler]
    public function test_4_nov_request_filed_before_the_absence_is_accepted_as_a_real_take_over(): void
    {
        $s = $this->scene(declareAbsence: false);

        // The incident's order of events: the request first…
        $created = $this->request('POST', '/api/surgeon/mission-requests', $s['cToken'], [
            'siteId' => $s['site']->getId(), 'type' => 'BLOCK',
            'startAt' => "{$s['dayYmd']}T08:00:00+01:00", 'endAt' => "{$s['dayYmd']}T13:00:00+01:00",
            'comment' => 'reprise de la salle du Dr Alain',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $requestId = $this->json($created)['id'];
        self::assertSame('NONE', $this->json($created)['releasedRoomMatch']['status'], 'no released room yet');

        // …then the absence releases the room.
        $this->declareAbsenceOfA($s);
        $slot = $this->slotOf($s['site']);

        $mine = $this->json($this->request('GET', '/api/surgeon/mission-requests', $s['cToken']));
        $row = current(array_filter($mine, fn ($r) => $r['id'] === $requestId));
        self::assertSame('CERTAIN', $row['releasedRoomMatch']['status'], 'the surgeon is told a released room matches');
        self::assertSame($slot->getId(), $row['releasedRoomMatch']['certainSlotId']);

        $list = $this->json($this->request('GET', '/api/manager/surgeon-mission-requests?status=PENDING', $s['managerToken']));
        $mrow = current(array_filter($list['items'] ?? $list, fn ($r) => $r['id'] === $requestId));
        self::assertSame('CERTAIN', $mrow['releasedRoomMatch']['status']);
        self::assertSame('Dr Alain Test', $mrow['releasedRoomMatch']['candidates'][0]['originalSurgeon']['name']);

        // A dispatch mode is refused for a take-over; plain acceptance takes the room over.
        $res = $this->request('POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $s['managerToken'], ['dispatch' => ['mode' => 'POOL']]);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('ROOM_SLOT_DISPATCH_NOT_ALLOWED', $this->json($res)['error']['code']);
        $res = $this->request('POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $s['managerToken'], ['roomSlot' => ['decision' => 'NONE', 'justification' => 'x']]);
        self::assertSame('ROOM_SLOT_TAKEOVER_REQUIRED', $this->json($res)['error']['code']);

        $res = $this->request('POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $s['managerToken'], []);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $slot = $this->slotOf($s['site']);
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $slot->getStatus(), 'the room is no longer offered');
        self::assertSame($s['c']->getId(), $slot->getClaimedBy()->getId());
        $missions = $this->missionsOf($s['c']);
        self::assertCount(1, $missions, 'one mission only');
        self::assertSame(MissionStatus::OPEN, $missions[0]->getStatus(), 'published, never DRAFT');
        self::assertSame($slot->getTakeoverMission()->getId(), $missions[0]->getId());
        $request = $this->em->find(SurgeonMissionRequest::class, $requestId);
        self::assertSame(SurgeonMissionRequest::STATUS_ACCEPTED, $request->getStatus());
        self::assertSame($missions[0]->getId(), $request->getCreatedMission()->getId());
        $taken = $this->auditEvents(AuditEventType::ROOM_SLOT_TAKEN_OVER, $missions[0]->getId());
        self::assertSame($requestId, $taken[0]->getPayload()['surgeonMissionRequestId']);
        self::assertSame($s['manager']->getId(), $taken[0]->getActor()->getId());
        self::assertCount(1, $this->auditEvents(AuditEventType::ROOM_TAKEOVER_PRIORITY_OFFERED, $missions[0]->getId()), 'priority of the usual instrumentist');

        // Nobody can take the same room again.
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        self::assertSame('ROOM_SLOT_ALREADY_TAKEN', $this->json($res)['error']['code']);

        // History links the request to the room.
        $history = $this->json($this->request('GET', "/api/planning/available-rooms/{$slot->getId()}/history", $s['managerToken']));
        $types = array_column($history['events'], 'type');
        self::assertContains('SURGEON_MISSION_REQUEST_CREATED', $types);
        self::assertContains('SURGEON_MISSION_REQUEST_ACCEPTED', $types);
    }

    #[WithoutErrorHandler]
    public function test_ambiguous_match_requires_an_explicit_manager_choice(): void
    {
        $s = $this->scene();
        $slot = $this->slotOf($s['site']);

        // 08:00–14:00 exceeds the released room (08:00–13:00): partial → AMBIGUOUS.
        $mk = fn (string $end) => $this->json($this->request('POST', '/api/surgeon/mission-requests', $s['cToken'], [
            'siteId' => $s['site']->getId(), 'type' => 'BLOCK',
            'startAt' => "{$s['dayYmd']}T08:00:00+01:00", 'endAt' => "{$s['dayYmd']}T{$end}:00+01:00",
        ]));
        $r1 = $mk('14:00');
        self::assertSame('AMBIGUOUS', $r1['releasedRoomMatch']['status']);
        self::assertFalse($r1['releasedRoomMatch']['candidates'][0]['coversRequest']);

        $accept = fn (int $id, array $body) => $this->request('POST', "/api/manager/surgeon-mission-requests/{$id}/accept", $s['managerToken'], $body);
        self::assertSame('ROOM_SLOT_DECISION_REQUIRED', $this->json($accept($r1['id'], []))['error']['code']);
        self::assertSame(422, $accept($r1['id'], ['roomSlot' => ['decision' => 'NONE']])->getStatusCode(), 'justification required');
        self::assertSame('ROOM_SLOT_NOT_MATCHING', $this->json($accept($r1['id'], ['roomSlot' => ['decision' => 'TAKE_OVER', 'slotId' => 999999999]]))['error']['code']);

        // Explicit choice of the room → take-over with the surgeon's own hours.
        $res = $accept($r1['id'], ['roomSlot' => ['decision' => 'TAKE_OVER', 'slotId' => $slot->getId()]]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $mission = $this->missionsOf($s['c'])[0];
        self::assertSame('14:00', $mission->getEndAt()->format('H:i'), 'the request\'s hours are kept');
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $this->slotOf($s['site'])->getStatus());

        // The same surgeon files another overlapping request → would duplicate: refused.
        $r2 = $mk('12:00');
        self::assertSame('ALREADY_TAKEN_OVER', $r2['releasedRoomMatch']['status']);
        self::assertSame('ROOM_SLOT_REQUEST_ALREADY_TAKEN_OVER', $this->json($accept($r2['id'], []))['error']['code']);
        self::assertCount(1, $this->missionsOf($s['c']));
    }

    #[WithoutErrorHandler]
    public function test_explicit_none_keeps_the_ordinary_request_flow_and_is_traced(): void
    {
        $s = $this->scene();
        $r = $this->json($this->request('POST', '/api/surgeon/mission-requests', $s['cToken'], [
            'siteId' => $s['site']->getId(), 'type' => 'BLOCK',
            'startAt' => "{$s['dayYmd']}T12:00:00+01:00", 'endAt' => "{$s['dayYmd']}T16:00:00+01:00",
        ]));
        self::assertSame('AMBIGUOUS', $r['releasedRoomMatch']['status']);

        $res = $this->request('POST', "/api/manager/surgeon-mission-requests/{$r['id']}/accept", $s['managerToken'], [
            'roomSlot' => ['decision' => 'NONE', 'justification' => 'Autre salle, après-midi'],
            'dispatch' => ['mode' => 'POOL'],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $this->slotOf($s['site'])->getStatus());
        $mission = $this->missionsOf($s['c'])[0];
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
        $accepted = $this->auditEvents(AuditEventType::SURGEON_MISSION_REQUEST_ACCEPTED, $mission->getId());
        self::assertSame('Autre salle, après-midi', $accepted[0]->getPayload()['roomSlotDecision']['justification']);

        // No released room on another day → untouched ordinary flow.
        $other = $this->json($this->request('POST', '/api/surgeon/mission-requests', $s['cToken'], [
            'siteId' => $s['site']->getId(), 'type' => 'BLOCK',
            'startAt' => $s['day']->modify('+1 day')->format('Y-m-d') . 'T08:00:00+01:00', 'endAt' => $s['day']->modify('+1 day')->format('Y-m-d') . 'T12:00:00+01:00',
        ]));
        self::assertSame('NONE', $other['releasedRoomMatch']['status']);
    }

    // ── 12. Régularisation administrative (cas réel #626 / créneaux #14–16) ─────────────

    #[WithoutErrorHandler]
    public function test_regularization_links_an_existing_mission_without_touching_it_and_can_be_rolled_back(): void
    {
        $s = $this->scene();
        $slot = $this->slotOf($s['site']);
        // The 4 Nov state: C's mission (from a request accepted before D-177) already ASSIGNED.
        $mission = $this->makeMission($s['c'], $s['paul'], $s['site'], MissionStatus::ASSIGNED, $s['dayYmd'], '07:45', '13:00');
        $this->transport->reset();
        $args = ['--slot' => $slot->getId(), '--mission' => $mission->getId()];

        $dry = $this->runCommand('app:released-rooms:regularize-takeover', $args);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('Dry-run', $dry->getDisplay());
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $this->slotOf($s['site'])->getStatus(), 'dry-run writes nothing');

        $run = $this->runCommand('app:released-rooms:regularize-takeover', $args + ['--execute' => true]);
        self::assertSame(0, $run->getStatusCode(), $run->getDisplay());
        $slot = $this->slotOf($s['site']);
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $slot->getStatus());
        self::assertSame($mission->getId(), $slot->getTakeoverMission()->getId());
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $reloaded->getStatus(), 'mission untouched');
        self::assertSame($s['paul']->getId(), $reloaded->getInstrumentist()->getId());
        self::assertSame('07:45', $reloaded->getStartAt()->format('H:i'));
        self::assertCount(0, $this->publications($mission->getId()), 'no priority, no publication');
        self::assertCount(0, $this->transport->getSent(), 'no notification');
        $reg = $this->auditEvents(AuditEventType::ROOM_SLOT_TAKEOVER_REGULARIZED, $mission->getId());
        self::assertCount(1, $reg);
        self::assertSame('Régularisation administrative d\'une reprise antérieure', $reg[0]->getPayload()['reason']);

        // Idempotent / safe: a second run refuses; nobody can take the room over any more.
        self::assertSame(1, $this->runCommand('app:released-rooms:regularize-takeover', $args + ['--execute' => true])->getStatusCode());
        self::assertSame('ROOM_SLOT_ALREADY_TAKEN', $this->json($this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']))['error']['code']);

        // Rollback restores exactly the previous state.
        $back = $this->runCommand('app:released-rooms:regularize-takeover', $args + ['--rollback' => true, '--execute' => true]);
        self::assertSame(0, $back->getStatusCode(), $back->getDisplay());
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $this->slotOf($s['site'])->getStatus());
        self::assertCount(1, $this->auditEvents(AuditEventType::ROOM_SLOT_TAKEOVER_REGULARIZATION_REVERTED, $mission->getId()));
        self::assertSame(MissionStatus::ASSIGNED, $this->em->find(Mission::class, $mission->getId())->getStatus());
    }
}
