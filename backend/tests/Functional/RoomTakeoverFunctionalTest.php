<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EmploymentType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-124 — « Reprendre une salle libérée », de bout en bout sur HTTP réel + base réelle.
 *
 * Le scénario principal part du VRAI flux d'absence (POST /api/absences) : la Mission ASSIGNED
 * de Dr A est annulée par AbsenceMissionReactionService et le créneau est créé par
 * ReleasedOperatingRoomSlotService — jamais une fixture qui court-circuiterait ce chemin. Les
 * notifications sont vérifiées en exécutant le VRAI MissionLifecycleChangedMessageHandler sur
 * les messages effectivement dispatchés (transport in-memory).
 */
final class RoomTakeoverFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'RoomTakeover124!';

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
        }
        foreach (array_keys($missionIds) as $mid) {
            foreach (['notification_event', 'audit_event', 'mission_claim', 'planning_alert'] as $table) {
                $sql("DELETE FROM {$table} WHERE mission_id = ?", [$mid]);
            }
            $sql('DELETE FROM mission WHERE id = ?', [$mid]);
        }
        foreach ($this->userIds as $uid) {
            $sql('DELETE FROM notification_event WHERE user_id = ?', [$uid]);
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

    /** Re-fetches a managed instance by id — HTTP requests through the test client detach entities. */
    private function ref(object $entity): object
    {
        return $this->em->find($entity::class, $entity->getId());
    }

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        return $this->json($this->client->getResponse())['token'] ?? '';
    }

    /** @return array{0: User, 1: string} */
    private function loggedUser(string $role, string $firstname, ?EmploymentType $employment = null): array
    {
        $u = $this->makeUser($role, $firstname, $employment);
        return [$u, $this->login($u)];
    }

    private function makeUser(string $role, string $firstname, ?EmploymentType $employment = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d124-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Test');
        $u->setEmploymentType($employment);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    private function makeSite(string $name = 'Delta'): Hospital
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

    private function makeBlockPost(User $surgeon, Hospital $site, \DateTimeImmutable $day, ?User $instrumentist = null): SurgeonSchedulePost
    {
        $surgeon = $this->ref($surgeon);
        $site = $this->ref($site);
        $instrumentist = $instrumentist !== null ? $this->ref($instrumentist) : null;
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([(int) $day->format('N')]);
        $rule->setAnchorDate($day);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate($day);
        $post->setEndDate($day);
        $post->setInstrumentist($instrumentist);
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        return $post;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day, string $start = '08:00', string $end = '13:00'): Mission
    {
        $surgeon = $this->ref($surgeon);
        $site = $this->ref($site);
        $instrumentist = $instrumentist !== null ? $this->ref($instrumentist) : null;
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        // D-066 — Brussels-labeled, like every production path (a naive container-UTC value
        // would be shifted by the DST offset on write by business_datetime_immutable).
        $tz = new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m->setStartAt(new \DateTimeImmutable("{$day} {$start}:00", $tz));
        $m->setEndAt(new \DateTimeImmutable("{$day} {$end}:00", $tz));
        $m->setCreatedBy($surgeon);
        $this->em->persist($m);
        $this->em->flush();
        return $m;
    }

    private function makeAbsence(User $user, string $day): Absence
    {
        $user = $this->ref($user);
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($day));
        $a->setDateEnd(new \DateTimeImmutable($day));
        $a->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        return $a;
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

    /** Runs the REAL async handler on every dispatched lifecycle message of this type, then clears the queue. */
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

    private function notificationsFor(User $user, NotificationType $type, ?int $missionId = null): array
    {
        $criteria = ['user' => $user->getId(), 'eventType' => $type->value];
        if ($missionId !== null) {
            $criteria['mission'] = $missionId;
        }
        return $this->em->getRepository(NotificationEvent::class)->findBy($criteria);
    }

    private function auditEvents(AuditEventType $type, ?int $missionId = null): array
    {
        $criteria = ['eventType' => $type];
        if ($missionId !== null) {
            $criteria['mission'] = $missionId;
        }
        return $this->em->getRepository(AuditEvent::class)->findBy($criteria, ['id' => 'ASC']);
    }

    private function slotOf(Hospital $site): ReleasedOperatingRoomSlot
    {
        $this->em->clear();
        $slots = $this->em->getRepository(ReleasedOperatingRoomSlot::class)->findBy(['site' => $site->getId()]);
        self::assertCount(1, $slots, 'exactly one released slot expected for this site');
        return $slots[0];
    }

    private function missionsOf(User $surgeon): array
    {
        return $this->em->getRepository(Mission::class)->findBy(['surgeon' => $surgeon->getId()]);
    }

    /**
     * Common scene: Delta, Dr A has a deployed BLOCK Mission ASSIGNED to Marie on $day
     * 08:00–13:00; A's absence is then declared through the real API.
     *
     * @return array<string, mixed>
     */
    private function sceneWithRealAbsence(): array
    {
        [$manager, $managerToken] = $this->loggedUser('ROLE_MANAGER', 'Manon');
        [$a, $aToken] = $this->loggedUser('ROLE_SURGEON', 'Alain');
        [$b, $bToken] = $this->loggedUser('ROLE_SURGEON', 'Bruno');
        [$c, $cToken] = $this->loggedUser('ROLE_SURGEON', 'Claire');
        [$marie, $marieToken] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Marie', EmploymentType::EMPLOYEE);
        [$paul, $paulToken] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Paul', EmploymentType::EMPLOYEE);
        [$zoe] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Zoe', EmploymentType::EMPLOYEE); // not affiliated

        $site = $this->makeSite('Delta');
        foreach ([$a, $b, $c] as $s) {
            $this->affiliate($s, $site, 'SURGEON');
        }
        $this->affiliate($marie, $site, 'INSTRUMENTIST');
        $this->affiliate($paul, $site, 'INSTRUMENTIST');

        $day = (new \DateTimeImmutable('today'))->modify('+28 days');
        $dayYmd = $day->format('Y-m-d');
        $post = $this->makeBlockPost($a, $site, $day, $marie);
        $original = $this->makeMission($a, $marie, $site, MissionStatus::ASSIGNED, $dayYmd);

        $res = $this->request('POST', '/api/absences', $managerToken, ['userId' => $a->getId(), 'dateStart' => $dayYmd, 'dateEnd' => $dayYmd]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $this->transport->reset();

        return compact('manager', 'managerToken', 'a', 'aToken', 'b', 'bToken', 'c', 'cToken', 'marie', 'marieToken', 'paul', 'paulToken', 'zoe', 'site', 'day', 'dayYmd', 'post', 'original');
    }

    // ── 1. Reprise normale + double reprise + visibilité + audit ────────────────

    #[WithoutErrorHandler]
    public function test_take_over_creates_one_open_mission_and_a_second_surgeon_gets_409_room_slot_already_taken(): void
    {
        $s = $this->sceneWithRealAbsence();

        $this->em->clear();
        $original = $this->em->find(Mission::class, $s['original']->getId());
        self::assertSame(MissionStatus::CANCELLED, $original->getStatus(), 'precondition: A\'s mission cancelled by the real absence flow');

        $slot = $this->slotOf($s['site']);
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $slot->getStatus());
        $madeAvailable = array_filter($this->auditEvents(AuditEventType::ROOM_SLOT_MADE_AVAILABLE), fn (AuditEvent $e) => ($e->getPayload()['roomSlotId'] ?? null) === $slot->getId());
        self::assertCount(1, $madeAvailable, 'audit: salle rendue disponible');
        self::assertSame('Dr Alain Test', array_values($madeAvailable)[0]->getPayload()['originalSurgeonName']);

        // B sees it with an explicit, server-decided CTA.
        $list = $this->json($this->request('GET', '/api/me/available-rooms', $s['bToken']));
        $row = current(array_filter($list['items'], fn ($i) => $i['id'] === $slot->getId()));
        self::assertNotFalse($row);
        self::assertTrue($row['allowedActions']['takeOver']);
        self::assertFalse($row['allowedActions']['release']);
        self::assertSame('Dr Alain Test', $row['surgeon']['name']);

        // A never gets a "take over" CTA on their own room.
        $listA = $this->json($this->request('GET', '/api/me/available-rooms', $s['aToken']));
        $rowA = current(array_filter($listA['items'], fn ($i) => $i['id'] === $slot->getId()));
        self::assertFalse($rowA['allowedActions']['takeOver']);

        // ── B takes over ──
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $body = $this->json($res);
        self::assertSame('CLAIMED', $body['status']);
        self::assertTrue($body['claimedByMe']);
        self::assertSame('Dr Bruno Test', $body['claimedBy']['name']);
        self::assertSame('OPEN', $body['takeoverMission']['status']);
        self::assertNull($body['takeoverMission']['instrumentist']);
        self::assertTrue($body['allowedActions']['release']);
        self::assertFalse($body['allowedActions']['takeOver']);

        $this->em->clear();
        $bMissions = $this->missionsOf($s['b']);
        self::assertCount(1, $bMissions);
        $new = $bMissions[0];
        self::assertSame(MissionStatus::OPEN, $new->getStatus());
        self::assertNull($new->getInstrumentist());
        self::assertSame($s['site']->getId(), $new->getSite()->getId());
        self::assertSame($s['dayYmd'] . ' 08:00', $new->getStartAt()->format('Y-m-d H:i'), 'same date/times as the released room (from A\'s original mission)');
        self::assertSame($s['dayYmd'] . ' 13:00', $new->getEndAt()->format('Y-m-d H:i'));

        // Planning V2 invariant: A's recurring post and cancelled mission are untouched.
        $post = $this->em->find(SurgeonSchedulePost::class, $s['post']->getId());
        self::assertSame($s['a']->getId(), $post->getSurgeon()->getId());
        self::assertSame($s['marie']->getId(), $post->getInstrumentist()->getId());
        $original = $this->em->find(Mission::class, $s['original']->getId());
        self::assertSame(MissionStatus::CANCELLED, $original->getStatus());
        self::assertSame($s['a']->getId(), $original->getSurgeon()->getId());

        // Audit with actor + name snapshots, never patient data.
        $taken = $this->auditEvents(AuditEventType::ROOM_SLOT_TAKEN_OVER, $new->getId());
        self::assertCount(1, $taken);
        $p = $taken[0]->getPayload();
        self::assertSame($s['b']->getId(), $taken[0]->getActor()->getId());
        self::assertSame('Dr Bruno Test', $p['takenByName']);
        self::assertSame('Dr Alain Test', $p['originalSurgeonName']);
        self::assertSame($original->getId(), $p['originalMissionId']);
        self::assertSame($s['marie']->getId(), $p['initialInstrumentistId']);
        self::assertSame('Marie Test', $p['initialInstrumentistName']);
        self::assertCount(1, $this->auditEvents(AuditEventType::MISSION_ADDED_POST_DEPLOY, $new->getId()), 'Mission creation audited by MissionPostDeployService');

        // ── C tries the same room → explicit 409 ──
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['cToken']);
        self::assertSame(409, $res->getStatusCode());
        $err = $this->json($res)['error'];
        self::assertSame('ROOM_SLOT_ALREADY_TAKEN', $err['code']);
        self::assertSame('Dr Bruno Test', $err['takenBy']['name']);
        self::assertSame("Cette salle vient d'être reprise par Dr Bruno Test.", $err['message']);
        $this->em->clear();
        self::assertCount(0, $this->missionsOf($s['c']), 'never a Mission for the loser');
        self::assertCount(1, $this->missionsOf($s['b']));

        // ── visibility: gone for C, still visible for B as "mine" ──
        $listC = $this->json($this->request('GET', '/api/me/available-rooms', $s['cToken']));
        self::assertEmpty(array_filter($listC['items'], fn ($i) => $i['id'] === $slot->getId()));
        self::assertSame(0, $this->json($this->request('GET', '/api/me/available-rooms/count', $s['cToken']))['count']);
        self::assertSame(0, $this->json($this->request('GET', '/api/me/available-rooms/count', $s['bToken']))['count'], 'badge counts only rooms still available');

        // ── manager sees the full picture ("A absent → repris par B") ──
        $mgr = $this->json($this->request('GET', '/api/planning/available-rooms?siteId=' . $s['site']->getId(), $s['managerToken']));
        self::assertSame('CLAIMED', $mgr['items'][0]['status']);
        self::assertSame('Dr Alain Test', $mgr['items'][0]['surgeon']['name']);
        self::assertSame('Dr Bruno Test', $mgr['items'][0]['claimedBy']['name']);
    }

    // ── 2. Notifications : instrumentiste initiale éligible + pool + claim ──────

    #[WithoutErrorHandler]
    public function test_eligible_initial_instrumentist_gets_the_contextual_offer_pool_gets_the_normal_one_and_first_claim_wins(): void
    {
        $s = $this->sceneWithRealAbsence();
        $slot = $this->slotOf($s['site']);

        self::assertSame(200, $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken'])->getStatusCode());
        $this->em->clear();
        $missionId = $this->missionsOf($s['b'])[0]->getId();

        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::ROOM_TAKEN_OVER), 'exactly one async ROOM_TAKEN_OVER message');
        $this->em->clear();

        $offer = $this->notificationsFor($s['marie'], NotificationType::ROOM_TAKEOVER_MISSION_OFFER, $missionId);
        self::assertCount(1, $offer, 'initial instrumentist gets the contextual notice');
        $payload = $offer[0]->getPayload();
        self::assertSame('Dr Bruno Test', $payload['takenByName']);
        self::assertSame('Dr Alain Test', $payload['originalSurgeonName']);
        self::assertSame('08:00', $payload['startTime']);
        self::assertArrayNotHasKey('patient', $payload);
        self::assertCount(0, $this->notificationsFor($s['marie'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId), 'never both notices');

        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE, $missionId), 'the rest of the eligible pool is notified simultaneously');
        self::assertCount(0, $this->notificationsFor($s['paul'], NotificationType::ROOM_TAKEOVER_MISSION_OFFER));
        self::assertCount(0, $this->notificationsFor($s['zoe'], NotificationType::OPEN_MISSION_AVAILABLE), 'non-affiliated instrumentist is never targeted');

        // The mission is genuinely OPEN for everyone — Paul (not the initial one) claims first.
        $res = $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        // Existing CLAIMED pipeline → the surgeon who took over the room is told. Drained right
        // away: the in-memory transport is a resettable service, emptied by the next request.
        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::CLAIMED));
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($s['b'], NotificationType::SURGEON_POST_COVERED, $missionId));

        $late = $this->request('POST', "/api/missions/{$missionId}/claim", $s['marieToken']);
        self::assertContains($late->getStatusCode(), [403, 409], 'a stale offer can never claim an ASSIGNED mission');

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $missionId);
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        self::assertSame($s['paul']->getId(), $mission->getInstrumentist()->getId());

        // B's view now shows the assigned instrumentist.
        $list = $this->json($this->request('GET', '/api/me/available-rooms', $s['bToken']));
        $row = current(array_filter($list['items'], fn ($i) => $i['id'] === $slot->getId()));
        self::assertSame('ASSIGNED', $row['takeoverMission']['status']);
        self::assertSame('Paul Test', $row['takeoverMission']['instrumentist']['name']);
    }

    // ── 3. Instrumentiste initiale absente / en conflit → aucune invitation ────

    #[WithoutErrorHandler]
    public function test_absent_initial_instrumentist_receives_no_claim_invitation(): void
    {
        $s = $this->sceneWithRealAbsence();
        $this->makeAbsence($s['marie'], $s['dayYmd']); // Marie on leave that day (declared after A's cancellation)
        $slot = $this->slotOf($s['site']);

        self::assertSame(200, $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken'])->getStatusCode());
        $this->runLifecycleHandler(MissionChangeType::ROOM_TAKEN_OVER);
        $this->em->clear();

        self::assertCount(0, $this->notificationsFor($s['marie'], NotificationType::ROOM_TAKEOVER_MISSION_OFFER));
        self::assertCount(0, $this->notificationsFor($s['marie'], NotificationType::OPEN_MISSION_AVAILABLE), 'no generic invitation either');
        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE));
    }

    #[WithoutErrorHandler]
    public function test_initial_instrumentist_with_a_schedule_conflict_receives_no_claim_invitation(): void
    {
        $s = $this->sceneWithRealAbsence();
        $elsewhere = $this->makeSite('Epsilon');
        [$d] = $this->loggedUser('ROLE_SURGEON', 'Denis');
        $this->makeMission($d, $s['marie'], $elsewhere, MissionStatus::ASSIGNED, $s['dayYmd'], '09:00', '12:00');
        $slot = $this->slotOf($s['site']);

        self::assertSame(200, $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken'])->getStatusCode());
        $this->runLifecycleHandler(MissionChangeType::ROOM_TAKEN_OVER);
        $this->em->clear();

        self::assertCount(0, $this->notificationsFor($s['marie'], NotificationType::ROOM_TAKEOVER_MISSION_OFFER));
        self::assertCount(0, $this->notificationsFor($s['marie'], NotificationType::OPEN_MISSION_AVAILABLE));
        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::OPEN_MISSION_AVAILABLE));
    }

    // ── 4. Libération : Mission OPEN puis ASSIGNED ────────────────────────────────

    #[WithoutErrorHandler]
    public function test_release_cancels_the_open_mission_and_the_room_is_available_again_for_others(): void
    {
        $s = $this->sceneWithRealAbsence();
        $slot = $this->slotOf($s['site']);
        $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        $this->em->clear();
        $missionId = $this->missionsOf($s['b'])[0]->getId();
        $this->transport->reset();

        // C can't release B's room.
        self::assertSame(403, $this->request('POST', "/api/available-rooms/{$slot->getId()}/release", $s['cToken'])->getStatusCode());

        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/release", $s['bToken']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $body = $this->json($res);
        self::assertSame('AVAILABLE', $body['status']);
        self::assertNull($body['claimedBy']);
        self::assertNull($body['takeoverMission']);

        $this->em->clear();
        self::assertSame(MissionStatus::CANCELLED, $this->em->find(Mission::class, $missionId)->getStatus());
        $reopened = $this->auditEvents(AuditEventType::ROOM_SLOT_REOPENED, $missionId);
        self::assertCount(1, $reopened);
        self::assertSame('TAKER_RELEASED', $reopened[0]->getPayload()['reason']);
        self::assertSame('Dr Bruno Test', $reopened[0]->getPayload()['previousTakenByName']);
        self::assertCount(1, $this->auditEvents(AuditEventType::MISSION_CANCELLED_POST_DEPLOY, $missionId));

        // B is not notified of their own action.
        $this->runLifecycleHandler(MissionChangeType::CANCELLED);
        $this->em->clear();
        self::assertCount(0, $this->notificationsFor($s['b'], NotificationType::PLANNING_MISSION_CANCELLED));

        // Back in C's list with a take-over CTA; C can now take it.
        $listC = $this->json($this->request('GET', '/api/me/available-rooms', $s['cToken']));
        $row = current(array_filter($listC['items'], fn ($i) => $i['id'] === $slot->getId()));
        self::assertNotFalse($row, 'room reappears for other surgeons');
        self::assertTrue($row['allowedActions']['takeOver']);
        self::assertSame(200, $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['cToken'])->getStatusCode());
        $this->em->clear();
        self::assertCount(1, $this->missionsOf($s['c']));
    }

    #[WithoutErrorHandler]
    public function test_release_of_an_assigned_mission_notifies_the_disengaged_instrumentist(): void
    {
        $s = $this->sceneWithRealAbsence();
        $slot = $this->slotOf($s['site']);
        $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        $this->em->clear();
        $missionId = $this->missionsOf($s['b'])[0]->getId();
        self::assertSame(200, $this->request('POST', "/api/missions/{$missionId}/claim", $s['paulToken'])->getStatusCode());
        $this->transport->reset();

        // A manager may also release it (same endpoint, same voter).
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/release", $s['managerToken']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $missionId);
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());
        self::assertNull($mission->getInstrumentist());

        self::assertSame(1, $this->runLifecycleHandler(MissionChangeType::CANCELLED));
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($s['paul'], NotificationType::PLANNING_MISSION_CANCELLED, $missionId), 'disengaged instrumentist is told');
        self::assertCount(1, $this->notificationsFor($s['b'], NotificationType::PLANNING_MISSION_CANCELLED, $missionId), 'surgeon told when someone else released it');
    }

    // ── 5. RBAC ─────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_unauthorized_users_can_neither_take_over_nor_release(): void
    {
        $s = $this->sceneWithRealAbsence();
        [, $outsiderToken] = $this->loggedUser('ROLE_SURGEON', 'Outsider'); // no SiteMembership on Delta
        $slot = $this->slotOf($s['site']);
        $url = "/api/available-rooms/{$slot->getId()}";

        self::assertSame(401, (function () use ($url) { $this->client->request('POST', "{$url}/take-over"); return $this->client->getResponse()->getStatusCode(); })());
        self::assertSame(403, $this->request('POST', "{$url}/take-over", $s['paulToken'])->getStatusCode(), 'instrumentist');
        self::assertSame(403, $this->request('POST', "{$url}/take-over", $s['managerToken'])->getStatusCode(), 'manager never takes a room himself');
        self::assertSame(403, $this->request('POST', "{$url}/take-over", $outsiderToken)->getStatusCode(), 'non-affiliated surgeon');
        self::assertSame(403, $this->request('POST', "{$url}/take-over", $s['aToken'])->getStatusCode(), 'absent surgeon on their own room');
        self::assertSame(403, $this->request('POST', "{$url}/release", $s['bToken'])->getStatusCode(), 'nothing to release yet');
        self::assertSame(404, $this->request('POST', '/api/available-rooms/999999999/take-over', $s['bToken'])->getStatusCode());

        $this->em->clear();
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $this->slotOf($s['site'])->getStatus());
        self::assertCount(0, $this->missionsOf($s['b']));
    }

    // ── 6. Préconditions revérifiées côté serveur ────────────────────────────────

    #[WithoutErrorHandler]
    public function test_take_over_is_refused_when_the_absent_surgeon_is_back_or_the_taker_is_busy(): void
    {
        $s = $this->sceneWithRealAbsence();
        $slot = $this->slotOf($s['site']);

        // B already operates elsewhere at that time → 409 SURGEON_CONFLICT (never double-booked).
        $elsewhere = $this->makeSite('Epsilon');
        $this->affiliate($this->em->find(User::class, $s['b']->getId()), $elsewhere, 'SURGEON');
        $this->makeMission($this->em->find(User::class, $s['b']->getId()), null, $elsewhere, MissionStatus::OPEN, $s['dayYmd'], '10:00', '12:00');
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('ROOM_SLOT_SURGEON_CONFLICT', $this->json($res)['error']['code']);

        // A's absence is removed: the slot row stays (Lot D non-retraction) but is no longer takeable.
        $this->em->getConnection()->executeStatement('DELETE FROM absence WHERE user_id = ?', [$s['a']->getId()]);
        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['cToken']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('ROOM_SLOT_NOT_AVAILABLE', $this->json($res)['error']['code']);
        $this->em->clear();
        self::assertCount(0, $this->missionsOf($s['c']));
    }

    // ── 7. Cohérence avec la vie des absences ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_deleting_the_absence_never_restores_the_absent_surgeons_mission_while_the_room_is_taken_over(): void
    {
        $s = $this->sceneWithRealAbsence();
        $slot = $this->slotOf($s['site']);
        $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);

        $absenceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM absence WHERE user_id = ?', [$s['a']->getId()]);
        self::assertSame(204, $this->request('DELETE', "/api/absences/{$absenceId}", $s['managerToken'])->getStatusCode());

        $this->em->clear();
        self::assertSame(MissionStatus::CANCELLED, $this->em->find(Mission::class, $s['original']->getId())->getStatus(), 'D-104 restoration blocked: never two surgeons in the same room');
        self::assertSame(MissionStatus::OPEN, $this->missionsOf($s['b'])[0]->getStatus(), 'the take-over stands');
    }

    #[WithoutErrorHandler]
    public function test_a_later_absence_of_the_taker_cancels_their_mission_and_gives_the_room_back(): void
    {
        $s = $this->sceneWithRealAbsence();
        $slot = $this->slotOf($s['site']);
        $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $s['bToken']);
        $this->em->clear();
        $missionId = $this->missionsOf($s['b'])[0]->getId();

        $res = $this->request('POST', '/api/absences', $s['managerToken'], ['userId' => $s['b']->getId(), 'dateStart' => $s['dayYmd'], 'dateEnd' => $s['dayYmd']]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        $this->em->clear();
        self::assertSame(MissionStatus::CANCELLED, $this->em->find(Mission::class, $missionId)->getStatus());
        $slot = $this->slotOf($s['site']);
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $slot->getStatus(), 'never left blocked on a cancelled mission');
        $reopened = $this->auditEvents(AuditEventType::ROOM_SLOT_REOPENED, $missionId);
        self::assertCount(1, $reopened);
        self::assertSame('TAKER_ABSENT', $reopened[0]->getPayload()['reason']);
    }

    // ── 8. Salle libérée avant génération (aucune Mission d'origine) ─────────────

    #[WithoutErrorHandler]
    public function test_room_released_before_generation_uses_the_posts_theoretical_partner_and_needs_no_planning_version(): void
    {
        [$a] = $this->loggedUser('ROLE_SURGEON', 'Alain');
        [$b, $bToken] = $this->loggedUser('ROLE_SURGEON', 'Bruno');
        [$marie] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Marie', EmploymentType::EMPLOYEE);
        $site = $this->makeSite('Delta');
        $this->affiliate($b, $site, 'SURGEON');
        $this->affiliate($marie, $site, 'INSTRUMENTIST');
        $day = (new \DateTimeImmutable('today'))->modify('+35 days');
        $post = $this->makeBlockPost($a, $site, $day, $marie);
        $this->makeAbsence($a, $day->format('Y-m-d'));

        $slot = new ReleasedOperatingRoomSlot();
        $slot->setSite($this->ref($site))->setPostId($post->getId())->setSchedulePost($this->ref($post))->setOccurrenceDate($day)
            ->setPeriod(ShiftPeriod::MATIN)->setStartTime(new \DateTimeImmutable('07:30'))->setEndTime(new \DateTimeImmutable('12:30'))
            ->setSurgeon($this->ref($a));
        $this->em->persist($slot);
        $this->em->flush();

        $res = $this->request('POST', "/api/available-rooms/{$slot->getId()}/take-over", $bToken);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        $this->em->clear();
        $mission = $this->missionsOf($b)[0];
        self::assertNull($mission->getPlanningVersion());
        self::assertSame('07:30', $mission->getStartAt()->format('H:i'), 'times from the slot snapshot');
        self::assertSame($marie->getId(), $this->auditEvents(AuditEventType::ROOM_SLOT_TAKEN_OVER, $mission->getId())[0]->getPayload()['initialInstrumentistId']);

        $this->runLifecycleHandler(MissionChangeType::ROOM_TAKEN_OVER);
        $this->em->clear();
        self::assertCount(1, $this->notificationsFor($marie, NotificationType::ROOM_TAKEOVER_MISSION_OFFER));
    }
}
