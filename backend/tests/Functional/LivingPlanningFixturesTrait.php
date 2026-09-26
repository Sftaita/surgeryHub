<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteMembership;
use App\Entity\SurgeonMissionRequest;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-125 — shared real-HTTP/real-DB fixtures for the "planning vivant" tests
 * (LivingPlanningOperationalScopeTest, MissionDispatchFunctionalTest). Always "next
 * calendar month" (same reasoning as PlanningV2GenerationControllerTest::year()).
 * Teardown deletes by owner (test users/sites), so rows created indirectly by the code
 * under test (generated missions, audit events, notifications, emails) never leak.
 */
trait LivingPlanningFixturesTrait
{
    private const FIXTURE_PASSWORD = 'LivingPlanning125!';

    private EntityManagerInterface $em;
    /** @var array{users: int[], sites: int[], posts: int[]} */
    private array $fx = ['users' => [], 'sites' => [], 'posts' => []];

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $users = $this->fx['users'] ?: [0];
            $sites = $this->fx['sites'] ?: [0];

            $missionIds = array_map('intval', array_column($this->em->createQuery(
                'SELECT m.id FROM App\Entity\Mission m WHERE m.site IN (:s) OR m.surgeon IN (:u) OR m.instrumentist IN (:u)'
            )->setParameter('s', $sites)->setParameter('u', $users)->getArrayResult(), 'id')) ?: [0];

            $dql = [
                'DELETE FROM App\Entity\OutboundNotificationAttempt a WHERE a.notification IN (SELECT o.id FROM App\Entity\OutboundNotification o WHERE o.recipientUser IN (:u))' => ['u' => $users],
                'DELETE FROM App\Entity\OutboundNotification o WHERE o.recipientUser IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\NotificationEvent n WHERE n.user IN (:u) OR n.mission IN (:m)' => ['u' => $users, 'm' => $missionIds],
                'DELETE FROM App\Entity\AuditEvent a WHERE a.mission IN (:m) OR a.actor IN (:u)' => ['u' => $users, 'm' => $missionIds],
                'DELETE FROM App\Entity\PlanningAlert a WHERE a.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionClaim c WHERE c.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\MissionPublication p WHERE p.mission IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\SurgeonMissionRequest r WHERE r.surgeon IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\Mission m WHERE m.id IN (:m)' => ['m' => $missionIds],
                'DELETE FROM App\Entity\PlanningAlert a WHERE a.absence IN (SELECT ab.id FROM App\Entity\Absence ab WHERE ab.user IN (:u))' => ['u' => $users],
                'DELETE FROM App\Entity\PlanningDeployment d WHERE d.deployedBy IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\PlanningVersion v WHERE v.generatedBy IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\Absence a WHERE a.user IN (:u)' => ['u' => $users],
                'DELETE FROM App\Entity\SiteMembership sm WHERE sm.user IN (:u)' => ['u' => $users],
            ];
            foreach ($dql as $q => $params) {
                $query = $this->em->createQuery($q);
                foreach ($params as $k => $v) {
                    $query->setParameter($k, $v);
                }
                $query->execute();
            }

            foreach ($this->fx['posts'] as $id) {
                $p = $this->em->find(SurgeonSchedulePost::class, $id);
                if ($p !== null) { $this->em->remove($p); }
            }
            $this->em->flush();
            $this->em->createQuery('DELETE FROM App\Entity\ShiftPeriodConfig c WHERE c.site IN (:s)')->setParameter('s', $sites)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.id IN (:u)')->setParameter('u', $users)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\Hospital h WHERE h.id IN (:s)')->setParameter('s', $sites)->execute();
        }
        parent::tearDown();
    }

    private static function testYear(): int
    {
        return (int) (new \DateTimeImmutable('first day of next month'))->format('Y');
    }

    private static function testMonth(): int
    {
        return (int) (new \DateTimeImmutable('first day of next month'))->format('n');
    }

    private function firstMonday(): \DateTimeImmutable
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', self::testYear(), self::testMonth()));
        $iso   = (int) $first->format('N');
        return $iso === 1 ? $first : $first->modify('+' . (8 - $iso) . ' days');
    }

    /** ISO 8601 instant for a Brussels wall-clock time on $day — what the frontend sends. */
    private function at(\DateTimeImmutable $day, string $hhmm): string
    {
        return (new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $hhmm, new \DateTimeZone('Europe/Brussels')))
            ->format(\DateTimeInterface::ATOM);
    }

    private function boot(): KernelBrowser
    {
        $client   = static::createClient();
        // Fixtures are created between requests — keep ONE kernel/EntityManager so they stay
        // managed (tests clear() the EM before reading back what the API wrote).
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function makeUser(string $role, string $firstname = 'Test', string $lastname = 'User', ?EmploymentType $employment = null): User
    {
        $u = new User();
        $u->setEmail('d125-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $u->setActive(true);
        if ($employment !== null) {
            $u->setEmploymentType($employment);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u->setPassword($hasher->hashPassword($u, self::FIXTURE_PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->fx['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::FIXTURE_PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D125 Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->fx['sites'][] = $h->getId();

        $c = new ShiftPeriodConfig();
        $c->setSite($h);
        $c->setPeriod(ShiftPeriod::MATIN);
        $c->setStartTime(new \DateTimeImmutable('08:00'));
        $c->setEndTime(new \DateTimeImmutable('13:00'));
        $this->em->persist($c);
        $this->em->flush();

        return $h;
    }

    /** Site-affiliated EMPLOYEE instrumentist — "instrumentiste du site". */
    private function makeSiteInstrumentist(Hospital $site, string $firstname, string $lastname): User
    {
        $u = $this->makeUser('ROLE_INSTRUMENTIST', $firstname, $lastname, EmploymentType::EMPLOYEE);
        $m = new SiteMembership();
        $m->setUser($u)->setSite($this->ref($site))->setSiteRole('INSTRUMENTIST');
        $this->em->persist($m);
        $this->em->flush();
        return $u;
    }

    /** Weekly Monday MATIN post for the test month. */
    private function makeMondayPost(User $surgeon, Hospital $site, ?User $instrumentist): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([1]);
        $rule->setAnchorDate($this->firstMonday());

        $p = new SurgeonSchedulePost();
        $p->setSurgeon($this->ref($surgeon));
        $p->setSite($this->ref($site));
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($rule);
        $p->setInstrumentist($instrumentist !== null ? $this->ref($instrumentist) : null);
        $p->setStartDate(new \DateTimeImmutable(sprintf('%04d-%02d-01', self::testYear(), self::testMonth())));
        $p->setCreatedBy($this->ref($surgeon));
        $this->em->persist($p);
        $this->em->flush();
        $this->fx['posts'][] = $p->getId();
        return $p;
    }

    private function makeAbsence(User $user, \DateTimeImmutable $day): Absence
    {
        $a = new Absence();
        $a->setUser($this->ref($user));
        $a->setDateStart($day);
        $a->setDateEnd($day);
        $a->setCreatedBy($this->ref($user));
        $this->em->persist($a);
        $this->em->flush();
        return $a;
    }

    private function makePendingRequest(User $surgeon, Hospital $site, \DateTimeImmutable $day): SurgeonMissionRequest
    {
        $r = new SurgeonMissionRequest();
        $r->setSurgeon($this->ref($surgeon));
        $r->setSite($this->ref($site));
        $r->setType(MissionType::BLOCK);
        $r->setStartAt(new \DateTimeImmutable($this->at($day, '14:00')));
        $r->setEndAt(new \DateTimeImmutable($this->at($day, '17:00')));
        $r->setStatus(SurgeonMissionRequest::STATUS_PENDING);
        $this->em->persist($r);
        $this->em->flush();
        return $r;
    }

    /**
     * The kernel resets (clears) the EntityManager after every request, so fixture entities
     * created before a request are detached afterwards — always attach relations by reference.
     *
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function ref(object $entity): object
    {
        return $this->em->getReference($this->em->getClassMetadata($entity::class)->getName(), $entity->getId());
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private function api(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null): Response
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        return $client->getResponse();
    }

    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** Manual creation exactly as the manager wizard does it (always DRAFT, R-04). */
    private function createMission(KernelBrowser $client, string $token, Hospital $site, User $surgeon, \DateTimeImmutable $day, string $from = '08:00', string $to = '13:00'): int
    {
        $res = $this->api($client, $token, 'POST', '/api/missions', [
            'siteId' => $site->getId(), 'type' => 'BLOCK', 'schedulePrecision' => 'EXACT',
            'startAt' => $this->at($day, $from), 'endAt' => $this->at($day, $to),
            'surgeonUserId' => $surgeon->getId(),
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        return (int) $this->body($res)['id'];
    }

    private function generateAndDeploy(KernelBrowser $client, string $token, Hospital $site): int
    {
        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $versionId = (int) $this->body($gen)['versionId'];

        $dep = $this->api($client, $token, 'POST', '/api/planning/v2/deploy', ['planningVersionId' => $versionId]);
        self::assertSame(200, $dep->getStatusCode(), (string) $dep->getContent());

        return $versionId;
    }

    /** @return array<int, array<string,mixed>> the manager calendar of the version, keyed by mission id */
    private function calendar(KernelBrowser $client, string $token, int $versionId): array
    {
        $res = $this->api($client, $token, 'GET', "/api/missions?planningScopeOf={$versionId}&limit=100");
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $byId = [];
        foreach ($this->body($res)['items'] as $item) {
            $byId[(int) $item['id']] = $item;
        }
        return $byId;
    }
}
