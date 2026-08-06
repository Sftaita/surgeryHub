<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Message\AbsenceSelfDeclaredMessage;
use App\MessageHandler\AbsenceSelfDeclaredMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Self-service absence CRUD (Lot 3, D-097) — SelfAbsenceController, AbsenceVoter,
 * AbsenceImpactService::previewOverlappingMissions(), AbsenceSelfDeclaredMessage(Handler).
 *
 * §17 security is the highest-priority coverage here: the client never chooses the owner,
 * and ownership/editability are enforced server-side regardless of what a malicious or
 * confused client sends.
 */
final class SelfAbsenceControllerTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'missions' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['absences'] as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $mission = $this->em->find(Mission::class, $id);
                if ($mission === null) { continue; }
                $alerts = $this->em->createQueryBuilder()
                    ->select('a')->from(PlanningAlert::class, 'a')
                    ->where('a.mission = :m')->setParameter('m', $mission)
                    ->getQuery()->getResult();
                foreach ($alerts as $alert) { $this->em->remove($alert); }
            }
            $this->em->flush();
            // AuditEvent.mission and NotificationEvent.user/mission have no ON DELETE SET NULL —
            // AbsenceMissionReactionService's release()/cancel() calls (via MissionPostDeployService)
            // create rows referencing the test's missions/users, which must go before them.
            foreach ($this->createdIds['missions'] as $id) {
                $mission = $this->em->find(Mission::class, $id);
                if ($mission === null) { continue; }
                $events = $this->em->createQueryBuilder()
                    ->select('e')->from(AuditEvent::class, 'e')
                    ->where('e.mission = :m')->setParameter('m', $mission)
                    ->getQuery()->getResult();
                foreach ($events as $event) { $this->em->remove($event); }
            }
            foreach ($this->createdIds['users'] as $id) {
                $user = $this->em->find(User::class, $id);
                if ($user === null) { continue; }
                $notifications = $this->em->createQueryBuilder()
                    ->select('n')->from(NotificationEvent::class, 'n')
                    ->where('n.user = :u')->setParameter('u', $user)
                    ->getQuery()->getResult();
                foreach ($notifications as $notification) { $this->em->remove($notification); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** @return array{user: User, token: string} */
    private function authenticate(KernelBrowser $client, string $role): array
    {
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('lot3-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, 'Lot3Test123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => 'Lot3Test123!']));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Lot3 Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        return $h;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day = '2026-09-10'): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("{$day} 08:00:00"));
        $m->setEndAt(new \DateTimeImmutable("{$day} 13:00:00"));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── §17 Sécurité — ownership ─────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_surgeon_can_create_view_update_delete_their_own_absence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-09-20', 'dateEnd' => '2026-09-20', 'reason' => 'Congé',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];
        self::assertSame('2026-09-20', $created['dateStart']);
        self::assertSame(0, $created['missionsImpactedCount']);

        $client->request('GET', '/api/absences/mine', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = $this->json($client->getResponse());
        self::assertCount(1, $list);

        $client->request('PATCH', '/api/absences/mine/' . $created['id'], server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['reason' => 'Congé modifié']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('Congé modifié', $this->json($client->getResponse())['reason']);

        $client->request('DELETE', '/api/absences/mine/' . $created['id'], server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/absences/mine', server: $this->auth($token));
        self::assertCount(0, $this->json($client->getResponse()));
        $this->createdIds['absences'] = [];

        unset($surgeon);
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_can_create_view_update_delete_their_own_absence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-09-21', 'dateEnd' => '2026-09-25',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];

        $client->request('PATCH', '/api/absences/mine/' . $created['id'], server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateEnd' => '2026-09-26']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('2026-09-26', $this->json($client->getResponse())['dateEnd']);

        $client->request('DELETE', '/api/absences/mine/' . $created['id'], server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'] = [];
    }

    #[WithoutErrorHandler]
    public function test_surgeon_cannot_view_update_or_delete_another_surgeons_absence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $ownerToken, 'user' => $owner] = $this->authenticate($client, 'ROLE_SURGEON');

        $absence = new Absence();
        $absence->setUser($owner)->setDateStart(new \DateTimeImmutable('2026-09-22'))->setDateEnd(new \DateTimeImmutable('2026-09-22'))->setCreatedBy($owner);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        ['token' => $attackerToken] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('PATCH', '/api/absences/mine/' . $absence->getId(), server: $this->auth($attackerToken, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['reason' => 'hacked']));
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/absences/mine/' . $absence->getId(), server: $this->auth($attackerToken));
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // The attacker's own list must never include another user's absence.
        $client->request('GET', '/api/absences/mine', server: $this->auth($attackerToken));
        self::assertCount(0, $this->json($client->getResponse()));

        unset($ownerToken);
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_cannot_touch_a_surgeons_absence_and_vice_versa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');

        $absence = new Absence();
        $absence->setUser($surgeon)->setDateStart(new \DateTimeImmutable('2026-09-23'))->setDateEnd(new \DateTimeImmutable('2026-09-23'))->setCreatedBy($surgeon);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        ['token' => $instrToken] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('DELETE', '/api/absences/mine/' . $absence->getId(), server: $this->auth($instrToken));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_malicious_userId_in_payload_is_ignored_absence_always_belongs_to_the_authenticated_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $me] = $this->authenticate($client, 'ROLE_SURGEON');
        $victim = new User();
        $victim->setEmail('lot3-victim-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $victim->setRoles(['ROLE_SURGEON']);
        $victim->setActive(true);
        $this->em->persist($victim);
        $this->em->flush();
        $this->createdIds['users'][] = $victim->getId();

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $victim->getId(), 'dateStart' => '2026-09-24', 'dateEnd' => '2026-09-24',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];

        $this->em->clear();
        $persisted = $this->em->find(Absence::class, $created['id']);
        self::assertSame($me->getId(), $persisted->getUser()->getId(), 'Absence must belong to the authenticated user, never the userId supplied in the payload');
        self::assertNotSame($victim->getId(), $persisted->getUser()->getId());
    }

    #[WithoutErrorHandler]
    public function test_manager_is_rejected_by_self_service_endpoints_manager_workflow_untouched(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $managerToken] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('GET', '/api/absences/mine', server: $this->auth($managerToken));
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/absences/mine', server: $this->auth($managerToken, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateStart' => '2026-09-20', 'dateEnd' => '2026-09-20']));
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // The pre-existing manager-facing endpoint must remain fully functional (unchanged).
        $surgeon = new User();
        $surgeon->setEmail('lot3-mgr-target-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $surgeon->setRoles(['ROLE_SURGEON']);
        $surgeon->setActive(true);
        $this->em->persist($surgeon);
        $this->em->flush();
        $this->createdIds['users'][] = $surgeon->getId();

        $client->request('POST', '/api/absences', server: $this->auth($managerToken, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-09-20', 'dateEnd' => '2026-09-20',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
    }

    // ── §18 Règles métier dates ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_single_day_absence_dateStart_equals_dateEnd(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateStart' => '2026-09-12', 'dateEnd' => '2026-09-12']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];
        self::assertSame($created['dateStart'], $created['dateEnd']);
    }

    #[WithoutErrorHandler]
    public function test_dateStart_after_dateEnd_is_rejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateStart' => '2026-09-15', 'dateEnd' => '2026-09-10']));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_past_absence_is_read_only_edit_and_delete_both_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $me] = $this->authenticate($client, 'ROLE_SURGEON');

        $absence = new Absence();
        $absence->setUser($me)->setDateStart(new \DateTimeImmutable('2020-01-01'))->setDateEnd(new \DateTimeImmutable('2020-01-02'))->setCreatedBy($me);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        $client->request('GET', '/api/absences/mine', server: $this->auth($token));
        $list = $this->json($client->getResponse());
        self::assertFalse($list[0]['editable']);

        $client->request('PATCH', '/api/absences/mine/' . $absence->getId(), server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['reason' => 'nope']));
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/absences/mine/' . $absence->getId(), server: $this->auth($token));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_future_absence_remains_editable_and_deletable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $me] = $this->authenticate($client, 'ROLE_SURGEON');

        $absence = new Absence();
        $absence->setUser($me)->setDateStart(new \DateTimeImmutable('+30 days'))->setDateEnd(new \DateTimeImmutable('+31 days'))->setCreatedBy($me);
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        $client->request('PATCH', '/api/absences/mine/' . $absence->getId(), server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['reason' => 'ok']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_modification_from_period_to_single_day_and_back(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateStart' => '2026-10-01', 'dateEnd' => '2026-10-05']));
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];

        $client->request('PATCH', '/api/absences/mine/' . $created['id'], server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateStart' => '2026-10-01', 'dateEnd' => '2026-10-01']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $updated = $this->json($client->getResponse());
        self::assertSame($updated['dateStart'], $updated['dateEnd']);

        $client->request('PATCH', '/api/absences/mine/' . $created['id'], server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['dateEnd' => '2026-10-03']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $updated2 = $this->json($client->getResponse());
        self::assertNotSame($updated2['dateStart'], $updated2['dateEnd']);
    }

    // ── §19 Impact Planning V2 ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_impact_preview_reflects_overlapping_missions_without_persisting_anything(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');
        $instr = new User();
        $instr->setEmail('lot3-instr-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $instr->setRoles(['ROLE_INSTRUMENTIST']);
        $instr->setActive(true);
        $this->em->persist($instr);
        $this->em->flush();
        $this->createdIds['users'][] = $instr->getId();
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-09-30');

        $client->request('GET', '/api/absences/mine/impact-preview?dateStart=2026-09-30&dateEnd=2026-09-30', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $impact = $this->json($client->getResponse());
        self::assertCount(1, $impact);
        self::assertSame($mission->getId(), $impact[0]['missionId']);
        self::assertSame($instr->getId(), $impact[0]['counterpart']['id']);

        // Read-only: no Absence row, no PlanningAlert was created by the preview call.
        $this->em->clear();
        $alertCount = count($this->em->createQueryBuilder()->select('a')->from(PlanningAlert::class, 'a')->where('a.mission = :m')->setParameter('m', $mission)->getQuery()->getResult());
        self::assertSame(0, $alertCount);
    }

    #[WithoutErrorHandler]
    public function test_surgeon_absence_overlapping_open_mission_raises_alert_and_reports_impacted_count(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN, '2026-10-10');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-10-10', 'dateEnd' => '2026-10-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];
        // Surgeon absence on an OPEN mission is handled by AbsenceMissionReactionService
        // (auto-cancel), never left as a manager alert — see AbsenceMissionReactionService
        // docblock. Confirmed via mission status below.
        self::assertSame(1, $created['missionsImpactedCount']);

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_absence_overlapping_declared_mission_raises_manager_alert_mission_unchanged(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $instr] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $surgeon = new User();
        $surgeon->setEmail('lot3-surg-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $surgeon->setRoles(['ROLE_SURGEON']);
        $surgeon->setActive(true);
        $this->em->persist($surgeon);
        $this->em->flush();
        $this->createdIds['users'][] = $surgeon->getId();
        $site = $this->makeSite();
        // VALIDATED is alertable but not in AbsenceMissionReactionService's actionable
        // statuses — the mission must stay untouched while a PlanningAlert is raised.
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::VALIDATED, '2026-10-11');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-10-11', 'dateEnd' => '2026-10-11',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];
        self::assertSame(1, $created['missionsImpactedCount']);

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::VALIDATED, $mission->getStatus(), 'AbsenceImpactService never mutates a Mission');

        $alerts = $this->em->createQueryBuilder()->select('a')->from(PlanningAlert::class, 'a')->where('a.mission = :m')->setParameter('m', $mission)->getQuery()->getResult();
        self::assertCount(1, $alerts);
        self::assertSame(\App\Enum\PlanningAlertType::INSTRUMENTIST_ABSENCE, $alerts[0]->getType());
    }

    #[WithoutErrorHandler]
    public function test_no_overlap_dispatches_absence_self_declared_notification_to_managers(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-11-01', 'dateEnd' => '2026-11-05',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];
        self::assertSame(0, $created['missionsImpactedCount']);

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof AbsenceSelfDeclaredMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope, 'AbsenceSelfDeclaredMessage must have been dispatched when zero missions are impacted');
        self::assertContains($manager->getId(), $envelope->getMessage()->recipientUserIds);

        static::getContainer()->get(AbsenceSelfDeclaredMessageHandler::class)->__invoke($envelope->getMessage());
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $notifications = $this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $manager)->getQuery()->getResult();
        self::assertCount(1, $notifications);
        self::assertSame('ABSENCE_SELF_DECLARED', $notifications[0]->getEventType());
    }

    #[WithoutErrorHandler]
    public function test_overlap_never_duplicates_notification_absence_self_declared_not_dispatched_when_alert_already_raised(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT, '2026-11-12');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-11-12', 'dateEnd' => '2026-11-12',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $created['id'];
        self::assertSame(1, $created['missionsImpactedCount']);

        foreach ($transport->getSent() as $e) {
            self::assertNotInstanceOf(AbsenceSelfDeclaredMessage::class, $e->getMessage(), 'Must never duplicate PLANNING_ALERT with ABSENCE_SELF_DECLARED for the same event');
        }
    }

    #[WithoutErrorHandler]
    public function test_deleting_a_self_service_absence_resolves_its_alert(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT, '2026-11-20');

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-11-20', 'dateEnd' => '2026-11-20',
        ]));
        $created = $this->json($client->getResponse());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts = $this->em->createQueryBuilder()->select('a')->from(PlanningAlert::class, 'a')->where('a.mission = :m')->setParameter('m', $mission)->getQuery()->getResult();
        self::assertCount(1, $alerts);
        self::assertSame(\App\Enum\PlanningAlertStatus::OPEN, $alerts[0]->getStatus());

        $client->request('DELETE', '/api/absences/mine/' . $created['id'], server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $alert = $this->em->find(PlanningAlert::class, $alerts[0]->getId());
        self::assertSame(\App\Enum\PlanningAlertStatus::RESOLVED, $alert->getStatus());
    }
}
