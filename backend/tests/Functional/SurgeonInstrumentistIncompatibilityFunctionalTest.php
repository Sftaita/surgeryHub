<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\SiteMembership;
use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\IncompatibilityOrigin;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningVersionStatus;
use App\Enum\ShiftPeriod;
use App\Exception\PlanningDraftConflictException;
use App\Message\PlanningAlertRaisedMessage;
use App\Service\MissionEligibilityService;
use App\Service\PlanningDeploymentService;
use App\Service\SurgeonSchedulePostService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-152 — incompatibilités chirurgien–instrumentiste, de bout en bout (HTTP + vraie base) :
 * blocage depuis le profil du chirurgien (périmètre : ses établissements + freelances),
 * exclusion sur toutes les voies (claim direct, offres, sollicitation du pool, demande
 * nominative, attribution directe, réaffectation, sélecteurs, roster de planning, postes
 * récurrents, déploiement), refus durable, conflits sur les missions futures (alerte manager,
 * jamais ignorable, résolution effective seulement), levée et retour à l'éligibilité, droits,
 * historique, confidentialité côté instrumentiste.
 */
final class SurgeonInstrumentistIncompatibilityFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'Incompat152!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private array $userIds = [];
    private array $siteIds = [];
    private array $versionIds = [];

    private Hospital $site;
    private Hospital $otherSite;
    private User $surgeon;
    private User $colleague;
    private User $iris;
    private User $jade;
    private User $stranger;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->transport()->reset();

        $this->site = $this->makeSite();
        $this->otherSite = $this->makeSite();
        $this->surgeon = $this->makeUser('ROLE_SURGEON', 'Simon', 'Chirurgien');
        $this->colleague = $this->makeUser('ROLE_SURGEON', 'Claude', 'Confrere');
        $this->iris = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris', 'Bloquee');
        $this->jade = $this->makeUser('ROLE_INSTRUMENTIST', 'Jade', 'Autre');
        $this->stranger = $this->makeUser('ROLE_INSTRUMENTIST', 'Sacha', 'Ailleurs');
        $this->manager = $this->makeUser('ROLE_MANAGER', 'Manon', 'Manager');
        foreach ([$this->surgeon, $this->colleague, $this->iris, $this->jade] as $person) {
            $this->affiliate($person, $this->site);
        }
        $this->affiliate($this->stranger, $this->otherSite);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $ids = implode(',', $this->userIds ?: [0]);
        $missions = implode(',', array_map('intval', $conn->fetchFirstColumn("SELECT id FROM mission WHERE surgeon_id IN ($ids) OR instrumentist_id IN ($ids)")) ?: [0]);
        $conn->executeStatement("DELETE FROM surgeon_instrumentist_incompatibility WHERE surgeon_id IN ($ids) OR instrumentist_id IN ($ids)");
        foreach (['planning_alert', 'mission_assignment', 'mission_claim', 'mission_publication', 'notification_event', 'audit_event', 'mission_communication'] as $table) {
            $conn->executeStatement("DELETE FROM {$table} WHERE mission_id IN ($missions)");
        }
        $conn->executeStatement("DELETE d FROM notification_dispatch_delivery d JOIN notification_dispatch n ON n.id = d.dispatch_id WHERE n.recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($ids) OR mission_id IN ($missions)");
        $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($ids) OR mission_id IN ($missions)");
        $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
        $conn->executeStatement("DELETE FROM surgeon_schedule_post WHERE surgeon_id IN ($ids)");
        $conn->executeStatement("DELETE FROM planning_deployment WHERE deployed_by_id IN ($ids)");
        foreach ($this->versionIds as $vid) {
            $conn->executeStatement('DELETE FROM planning_version WHERE id = ?', [$vid]);
        }
        $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($ids)");
        $conn->executeStatement("DELETE FROM site_membership WHERE user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM `user` WHERE id IN ($ids)");
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM shift_period_config WHERE site_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    // ── Fixtures and helpers ─────────────────────────────────────────────────

    private function makeUser(string $role, string $firstname, string $lastname): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d152-' . bin2hex(random_bytes(4)) . '@example.org');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        if ($role === 'ROLE_INSTRUMENTIST') {
            $u->setEmploymentType(EmploymentType::EMPLOYEE);
        }
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Clinique D152 ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->siteIds[] = $h->getId();

        return $h;
    }

    private function affiliate(User $user, Hospital $site): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole(in_array('ROLE_SURGEON', $user->getRoles(), true) ? 'SURGEON' : 'INSTRUMENTIST');
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function mission(User $surgeon, MissionStatus $status, string $startsIn, ?User $instrumentist = null, ?PlanningVersion $version = null): Mission
    {
        // Re-read by id: client requests reboot the kernel, the fixtures may be detached by now.
        $this->em->clear();
        $start = (new \DateTimeImmutable('now', new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)))->modify($startsIn)->setTime(8, 0);
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($this->em->find(Hospital::class, $this->site->getId()));
        $m->setSurgeon($this->em->find(User::class, $surgeon->getId()));
        $m->setCreatedBy($this->em->find(User::class, $this->manager->getId()));
        $m->setStartAt($start);
        $m->setEndAt($start->modify('+4 hours'));
        $m->setStatus($status);
        $m->setInstrumentist($instrumentist !== null ? $this->em->find(User::class, $instrumentist->getId()) : null);
        if ($version !== null) {
            $m->setPlanningVersion($this->em->find(PlanningVersion::class, $version->getId()));
        }
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    private function token(User $user): string
    {
        $this->client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function post(User $as, string $uri, array $body = []): Response
    {
        $this->client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as)],
            content: json_encode($body),
        );

        return $this->client->getResponse();
    }

    private function get(User $as, string $uri): Response
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as)]);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function blockAsSurgeon(User $instrumentist): int
    {
        $response = $this->post($this->surgeon, '/api/me/blocked-instrumentists', ['instrumentistId' => $instrumentist->getId()]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return (int) $this->json($response)['id'];
    }

    private function alertOn(Mission $mission): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT id, status FROM planning_alert WHERE mission_id = ? AND type = 'SURGEON_INCOMPATIBILITY' ORDER BY id DESC LIMIT 1",
            [$mission->getId()],
        );

        return $row === false ? null : $row;
    }

    // ── B — blocking from the surgeon's profile ──────────────────────────────

    public function test_a_surgeon_blocks_an_instrumentist_of_his_sites_but_not_someone_else(): void
    {
        $blockable = array_column($this->json($this->get($this->surgeon, '/api/me/blockable-instrumentists'))['items'], 'id');
        self::assertContains($this->iris->getId(), $blockable);
        self::assertNotContains($this->stranger->getId(), $blockable, 'not on his sites and not a freelancer');

        $this->blockAsSurgeon($this->iris);
        $mine = $this->json($this->get($this->surgeon, '/api/me/blocked-instrumentists'))['items'];
        self::assertSame([$this->iris->getId()], array_column(array_column($mine, 'instrumentist'), 'id'));
        self::assertSame('SURGEON', $mine[0]['origin']);
        self::assertTrue($mine[0]['canLift']);
        self::assertNotContains($this->iris->getId(), array_column($this->json($this->get($this->surgeon, '/api/me/blockable-instrumentists'))['items'], 'id'));

        $refused = $this->post($this->surgeon, '/api/me/blocked-instrumentists', ['instrumentistId' => $this->stranger->getId()]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('INSTRUMENTIST_NOT_BLOCKABLE', $this->json($refused)['error']['code']);

        $twice = $this->post($this->surgeon, '/api/me/blocked-instrumentists', ['instrumentistId' => $this->iris->getId()]);
        self::assertSame(409, $twice->getStatusCode());
        self::assertSame('INCOMPATIBILITY_ALREADY_ACTIVE', $this->json($twice)['error']['code']);
    }

    // ── B / application — never offered, notified, claimable, assignable ─────

    public function test_an_active_incompatibility_excludes_the_instrumentist_from_every_path(): void
    {
        $this->blockAsSurgeon($this->iris);
        $open = $this->mission($this->surgeon, MissionStatus::OPEN, '+6 days');
        $colleagues = $this->mission($this->colleague, MissionStatus::OPEN, '+7 days');

        // Claim directly through the API — refused, neutrally.
        $claim = $this->post($this->iris, "/api/missions/{$open->getId()}/claim");
        self::assertSame(409, $claim->getStatusCode());
        self::assertSame('MISSION_UNAVAILABLE', $this->json($claim)['error']['reason']);
        self::assertStringNotContainsStringIgnoringCase('incompat', (string) $claim->getContent());
        self::assertStringNotContainsStringIgnoringCase('bloqu', (string) $claim->getContent());

        // Offers — never this surgeon's mission; a colleague's mission still is.
        $offers = array_column($this->json($this->get($this->iris, '/api/missions?eligibleToMe=true'))['items'], 'id');
        self::assertNotContains($open->getId(), $offers);
        self::assertContains($colleagues->getId(), $offers);

        // Pool solicitation (notifications) — never her for this surgeon's mission.
        $eligible = static::getContainer()->get(MissionEligibilityService::class)->findEligibleByMission([$open, $colleagues]);
        self::assertNotContains($this->iris->getId(), array_map(static fn (User $u) => $u->getId(), $eligible[$open->getId()]));
        self::assertContains($this->iris->getId(), array_map(static fn (User $u) => $u->getId(), $eligible[$colleagues->getId()]));

        // Nominative offer, direct assignment — refused.
        $offer = $this->post($this->manager, "/api/missions/{$open->getId()}/publish", ['scope' => 'TARGETED', 'targetUserId' => $this->iris->getId()]);
        self::assertGreaterThanOrEqual(400, $offer->getStatusCode(), (string) $offer->getContent());
        $direct = $this->post($this->manager, "/api/missions/{$open->getId()}/assign-directly", ['instrumentistId' => $this->iris->getId()]);
        self::assertGreaterThanOrEqual(400, $direct->getStatusCode());

        // Reassignment of an assigned mission — refused.
        $assigned = $this->mission($this->surgeon, MissionStatus::OPEN, '+8 days');
        self::assertSame(200, $this->post($this->jade, "/api/missions/{$assigned->getId()}/claim")->getStatusCode());
        $reassign = $this->post($this->manager, "/api/missions/{$assigned->getId()}/reassign", ['instrumentistId' => $this->iris->getId()]);
        self::assertGreaterThanOrEqual(400, $reassign->getStatusCode());

        // Manager pickers — not selectable, explicit reason.
        $candidates = $this->json($this->get($this->manager, "/api/missions/{$open->getId()}/eligible-instrumentists"))['candidates'];
        $iris = array_values(array_filter($candidates, fn ($c) => $c['id'] === $this->iris->getId()))[0];
        self::assertFalse($iris['selectable']);
        self::assertContains('SURGEON_INCOMPATIBILITY', $iris['reasons']);

        // Planning roster for a line of this surgeon — same.
        $day = (new \DateTimeImmutable('+9 days'))->format('Y-m-d');
        $roster = $this->json($this->get($this->manager, "/api/planning/v2/eligible-instrumentists?siteId={$this->site->getId()}&date={$day}&startTime=08:00&endTime=12:00&surgeonId={$this->surgeon->getId()}"))['candidates'];
        $iris = array_values(array_filter($roster, fn ($c) => $c['id'] === $this->iris->getId()))[0];
        self::assertContains('SURGEON_INCOMPATIBILITY', $iris['reasons']);
        self::assertFalse($iris['selectable']);

        // Recurring post — never configured with the pair (the site has a MATIN period, so the
        // only possible refusal left is the incompatibility).
        $period = new \App\Entity\ShiftPeriodConfig();
        $period->setSite($this->em->find(Hospital::class, $this->site->getId()));
        $period->setPeriod(ShiftPeriod::MATIN);
        $period->setStartTime(new \DateTimeImmutable('08:00'));
        $period->setEndTime(new \DateTimeImmutable('12:00'));
        $period->setActive(true);
        $this->em->persist($period);
        $this->em->flush();
        try {
            static::getContainer()->get(SurgeonSchedulePostService::class)->create([
                'surgeonId' => $this->surgeon->getId(), 'siteId' => $this->site->getId(), 'type' => MissionType::BLOCK,
                'period' => ShiftPeriod::MATIN, 'instrumentistId' => $this->iris->getId(),
                'startDate' => new \DateTimeImmutable('+10 days'),
                'recurrence' => ['frequency' => \App\Enum\RecurrenceFrequency::WEEKLY, 'weekdays' => [1], 'anchorDate' => new \DateTimeImmutable('+10 days')],
            ], $this->em->find(User::class, $this->manager->getId()));
            self::fail('A recurring post with an incompatible pair must be refused.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('incompatible', $e->getMessage());
        }

        // Her own notifications: nothing about it, ever.
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ?', [$this->iris->getId()]));
    }

    // ── A3 durable refusal + B conflicts on future missions ──────────────────

    public function test_a_durable_refusal_blocks_and_turns_her_other_future_missions_into_conflicts(): void
    {
        // Iris already holds another future mission of this surgeon (assigned by the manager).
        $later = $this->mission($this->surgeon, MissionStatus::OPEN, '+12 days');
        self::assertSame(200, $this->post($this->manager, "/api/missions/{$later->getId()}/assign-directly", ['instrumentistId' => $this->iris->getId()])->getStatusCode());

        $mission = $this->mission($this->surgeon, MissionStatus::OPEN, '+5 days');
        self::assertSame(200, $this->post($this->iris, "/api/missions/{$mission->getId()}/claim")->getStatusCode());
        $assignmentId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM mission_assignment WHERE mission_id = ? AND ended_at IS NULL', [$mission->getId()]);
        $this->transport()->reset();

        $response = $this->post($this->surgeon, "/api/missions/{$mission->getId()}/assignments/{$assignmentId}/refuse", ['scope' => 'DURABLE']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        $incompatibility = $this->em->getRepository(SurgeonInstrumentistIncompatibility::class)->findOneBy(['surgeon' => $this->surgeon->getId(), 'instrumentist' => $this->iris->getId()]);
        self::assertNotNull($incompatibility);
        self::assertSame(IncompatibilityOrigin::SURGEON_REFUSAL, $incompatibility->getOrigin());
        self::assertSame($assignmentId, $incompatibility->getSourceAssignment()?->getId());
        self::assertSame('DURABLE', $this->em->getConnection()->fetchOne('SELECT refusal_scope FROM mission_assignment WHERE id = ?', [$assignmentId]));

        // The other future mission is NOT touched — it becomes an open conflict for the managers.
        $laterNow = $this->em->find(Mission::class, $later->getId());
        self::assertSame(MissionStatus::ASSIGNED, $laterNow->getStatus());
        self::assertSame($this->iris->getId(), $laterNow->getInstrumentist()?->getId());
        self::assertSame('OPEN', $this->alertOn($later)['status']);
        $raised = array_values(array_filter(
            array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()),
            static fn (object $m) => $m instanceof PlanningAlertRaisedMessage && $m->alertType === 'SURGEON_INCOMPATIBILITY',
        ));
        self::assertCount(1, $raised);
        self::assertContains($this->manager->getId(), $raised[0]->recipientUserIds);

        // She can take neither mission back.
        self::assertSame(409, $this->post($this->iris, "/api/missions/{$mission->getId()}/claim")->getStatusCode());
    }

    // ── Conflicts: never ignored, resolved only effectively ──────────────────

    public function test_a_conflict_can_be_neither_ignored_nor_closed_by_hand_and_is_resolved_by_a_real_action(): void
    {
        $first = $this->mission($this->surgeon, MissionStatus::OPEN, '+10 days');
        $second = $this->mission($this->surgeon, MissionStatus::OPEN, '+11 days');
        foreach ([$first, $second] as $m) {
            self::assertSame(200, $this->post($this->iris, "/api/missions/{$m->getId()}/claim")->getStatusCode());
        }

        $created = $this->post($this->manager, '/api/incompatibilities', ['surgeonId' => $this->surgeon->getId(), 'instrumentistId' => $this->iris->getId()]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $incompatibilityId = $this->json($created)['id'];
        self::assertCount(2, $this->json($created)['conflicts']);

        $alertId = (int) $this->alertOn($first)['id'];
        $alert = $this->json($this->get($this->manager, "/api/planning/alerts/{$alertId}"));
        self::assertFalse($alert['actions']['canIgnore']);
        self::assertFalse($alert['actions']['canResolve']);
        self::assertTrue($alert['actions']['canReassign']);
        self::assertSame(409, $this->post($this->manager, "/api/planning/alerts/{$alertId}/ignore", ['note' => 'Maintenir'])->getStatusCode(), 'no « maintenir l\'affectation »');
        self::assertSame(409, $this->post($this->manager, "/api/planning/alerts/{$alertId}/resolve", ['note' => 'OK'])->getStatusCode());
        self::assertSame('OPEN', $this->alertOn($first)['status'], 'stays visible until effectively resolved');

        // Effective resolution #1: reassignment from the alert.
        $reassign = $this->post($this->manager, "/api/planning/alerts/{$alertId}/reassign", ['instrumentistId' => $this->jade->getId()]);
        self::assertSame(200, $reassign->getStatusCode(), (string) $reassign->getContent());
        self::assertSame('RESOLVED', $this->alertOn($first)['status']);

        // Effective resolution #2: lifting the incompatibility closes the remaining conflict.
        self::assertSame('OPEN', $this->alertOn($second)['status']);
        self::assertSame(200, $this->post($this->manager, "/api/incompatibilities/{$incompatibilityId}/lift")->getStatusCode());
        self::assertSame('RESOLVED', $this->alertOn($second)['status']);
        self::assertSame(PlanningAlertStatus::RESOLVED->value, $this->alertOn($second)['status']);
        self::assertSame($this->iris->getId(), (int) $this->em->getConnection()->fetchOne('SELECT instrumentist_id FROM mission WHERE id = ?', [$second->getId()]), 'never unassigned silently');
    }

    // ── Lifting: rights, history, eligibility restored ───────────────────────

    public function test_lifting_restores_eligibility_keeps_the_history_and_respects_who_may_lift(): void
    {
        $own = $this->blockAsSurgeon($this->iris);
        $byManager = $this->json($this->post($this->manager, '/api/incompatibilities', ['surgeonId' => $this->surgeon->getId(), 'instrumentistId' => $this->jade->getId()]))['id'];

        self::assertSame(403, $this->post($this->surgeon, "/api/incompatibilities/{$byManager}/lift")->getStatusCode(), "a manager's block is not his to lift");
        self::assertSame(403, $this->post($this->colleague, "/api/incompatibilities/{$own}/lift")->getStatusCode());
        self::assertSame(403, $this->post($this->iris, "/api/incompatibilities/{$own}/lift")->getStatusCode());

        self::assertSame(200, $this->post($this->surgeon, "/api/incompatibilities/{$own}/lift")->getStatusCode());
        $again = $this->post($this->surgeon, "/api/incompatibilities/{$own}/lift");
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('INCOMPATIBILITY_ALREADY_LIFTED', $this->json($again)['error']['code']);
        self::assertSame(200, $this->post($this->manager, "/api/incompatibilities/{$byManager}/lift")->getStatusCode());

        // Eligible again.
        $open = $this->mission($this->surgeon, MissionStatus::OPEN, '+6 days');
        self::assertSame(200, $this->post($this->iris, "/api/missions/{$open->getId()}/claim")->getStatusCode());

        // History kept on both files, with origin, date, author and lifting.
        $history = $this->json($this->get($this->manager, "/api/instrumentists/{$this->iris->getId()}/incompatibilities"))['items'];
        self::assertCount(1, $history);
        self::assertFalse($history[0]['active']);
        self::assertSame('SURGEON', $history[0]['origin']);
        self::assertSame('Simon Chirurgien', $history[0]['createdByName']);
        self::assertSame('Simon Chirurgien', $history[0]['liftedByName']);
        self::assertNotNull($history[0]['liftedAt']);
        self::assertCount(2, $this->json($this->get($this->manager, "/api/surgeons/{$this->surgeon->getId()}/incompatibilities"))['items']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_event WHERE event_type = 'SURGEON_INSTRUMENTIST_INCOMPATIBILITY_LIFTED' AND CAST(JSON_EXTRACT(payload, '$.incompatibilityId') AS UNSIGNED) = ?", [$own]));
    }

    public function test_only_managers_read_the_files_and_the_instrumentist_never_sees_anything(): void
    {
        $this->blockAsSurgeon($this->iris);

        self::assertSame(403, $this->get($this->iris, "/api/instrumentists/{$this->iris->getId()}/incompatibilities")->getStatusCode());
        self::assertSame(403, $this->get($this->iris, '/api/me/blocked-instrumentists')->getStatusCode());
        self::assertSame(403, $this->get($this->surgeon, "/api/surgeons/{$this->surgeon->getId()}/incompatibilities")->getStatusCode(), 'his own profile endpoint only');
        self::assertSame(403, $this->post($this->iris, '/api/incompatibilities', ['surgeonId' => $this->surgeon->getId(), 'instrumentistId' => $this->jade->getId()])->getStatusCode());
        self::assertSame(200, $this->get($this->manager, "/api/surgeons/{$this->surgeon->getId()}/incompatibilities")->getStatusCode());
    }

    // ── Planning: a DRAFT prepared before the block never deploys as is ──────

    public function test_a_draft_affectation_that_became_incompatible_blocks_the_deploy(): void
    {
        $from = (new \DateTimeImmutable('today'))->modify('+30 days');
        $version = new PlanningVersion();
        $version->setSite($this->site);
        $version->setPeriodStart($from);
        $version->setPeriodEnd($from->modify('+6 days'));
        $version->setVersionNumber(random_int(1000, 999999));
        $version->setStatus(PlanningVersionStatus::DRAFT);
        $version->setGeneratedBy($this->manager);
        $this->em->persist($version);
        $this->em->flush();
        $this->versionIds[] = $version->getId();
        $draft = $this->mission($this->surgeon, MissionStatus::DRAFT, '+31 days', $this->iris, $version);

        $this->blockAsSurgeon($this->iris);

        try {
            static::getContainer()->get(PlanningDeploymentService::class)->deploy(
                $from->format('Y-m-d'), $from->modify('+6 days')->format('Y-m-d'), $this->site->getId(),
                $this->em->find(User::class, $this->manager->getId()), $version->getId(),
            );
            self::fail('Deploying an incompatible DRAFT affectation must be blocked.');
        } catch (PlanningDraftConflictException $e) {
            self::assertSame('INCOMPATIBILITY', $e->getConflicts()[0]['type'] ?? null);
        }
        self::assertSame('DRAFT', $this->em->getConnection()->fetchOne('SELECT status FROM mission WHERE id = ?', [$draft->getId()]));
    }
}
