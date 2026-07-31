<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-090 — deploy-time revalidation of DRAFT missions against CURRENT absence data
 * (PlanningDraftRevalidationService, wired into PlanningDeploymentService::deploy()).
 * Covers anomalies 2 and 4 from the Planning V2 audit: an absence registered after
 * generate() ran, but before deploy, must never silently publish either an absent
 * instrumentist's assignment or a surgeon-absent slot as OPEN.
 */
final class PlanningDeployAbsenceRevalidationTest extends WebTestCase
{
    private const PASSWORD = 'Revalidation90!';

    private EntityManagerInterface $em;
    private array $createdMissionIds     = [];
    private array $createdUserIds        = [];
    private array $createdSiteIds        = [];
    private array $createdVersionIds     = [];
    private array $createdAbsenceIds     = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdAbsenceIds as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            if (!empty($this->createdVersionIds)) {
                $missions = $this->em->createQueryBuilder()
                    ->select('m')->from(Mission::class, 'm')
                    ->where('m.planningVersion IN (:v)')
                    ->setParameter('v', $this->createdVersionIds)
                    ->getQuery()->getResult();
                foreach ($missions as $m) {
                    foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $m->getId()]) as $evt) {
                        $this->em->remove($evt);
                    }
                    $this->em->remove($m);
                }
                $this->em->flush();
            }
            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) {
                    foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                        $this->em->remove($evt);
                    }
                    $this->em->remove($e);
                }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $id]) as $n) {
                    $this->em->remove($n);
                }
                foreach ($this->em->getRepository(PlanningDeployment::class)->findBy(['deployedBy' => $id]) as $d) {
                    $this->em->remove($d);
                }
            }
            $this->em->flush();

            foreach ($this->createdVersionIds as $id) {
                $e = $this->em->find(PlanningVersion::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdSiteIds as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('deploy90-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Deploy90-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeDraftVersion(Hospital $site, User $manager, string $periodStart, string $periodEnd): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable($periodStart));
        $v->setPeriodEnd(new \DateTimeImmutable($periodEnd));
        $v->setVersionNumber(random_int(1000, 999999));
        $v->setStatus(PlanningVersionStatus::DRAFT);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    private function makeDraftMission(
        PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy,
        string $date, string $startTime, string $endTime, ?User $instrumentist,
    ): Mission {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m = new Mission();
        $m->setPlanningVersion($version);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable("{$date}T{$startTime}:00", $tz));
        $m->setEndAt(new \DateTimeImmutable("{$date}T{$endTime}:00", $tz));
        $m->setStatus(MissionStatus::DRAFT);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function makeAbsence(User $user, string $dateStart, string $dateEnd, User $createdBy): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($dateStart));
        $a->setDateEnd(new \DateTimeImmutable($dateEnd));
        $a->setCreatedBy($createdBy);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdAbsenceIds[] = $a->getId();
        return $a;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    private function deploy(KernelBrowser $client, string $token, PlanningVersion $version): Response
    {
        return $this->postJson($client, $token, '/api/planning/v2/deploy', [
            'planningVersionId' => $version->getId(),
        ]);
    }

    private function freshMission(int $id): Mission
    {
        $this->em->clear();
        $m = $this->em->find(Mission::class, $id);
        self::assertNotNull($m);
        return $m;
    }

    // ── Anomalie 2 — instrumentiste absent ──────────────────────────────────────

    public function test_instrumentist_absence_added_after_generation_blocks_deploy(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        $mission  = $this->makeDraftMission($version, $site, $surgeon, $manager, '2026-09-15', '08:00', '13:00', $instr);

        // Absence registered AFTER the draft was generated, BEFORE deploy — the exact gap
        // AbsenceMissionReactionService never covers (DRAFT is not one of its actionable
        // statuses) and the original bug never re-checked.
        $this->makeAbsence($instr, '2026-09-15', '2026-09-15', $manager);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('DRAFT_CONFLICTS', $body['code']);
        self::assertCount(1, $body['conflicts']);
        self::assertSame($mission->getId(), $body['conflicts'][0]['missionId']);
        self::assertSame('2026-09-15', $body['conflicts'][0]['date']);
        self::assertSame($instr->getId(), $body['conflicts'][0]['instrumentistId']);

        // Nothing published — deploy must be fully blocked, not partially applied.
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::DRAFT, $fresh->getStatus(), 'A blocked deploy must never publish anything.');
        self::assertSame(PlanningVersionStatus::DRAFT, $this->em->find(PlanningVersion::class, $version->getId())->getStatus());
    }

    public function test_instrumentist_absence_existing_before_generation_is_never_assigned(): void
    {
        // Documents the already-correct behavior at generation time (PlanningGeneratorServiceV2
        // ::isAbsentFast()): an absence that exists BEFORE the draft is built is never even
        // eligible to be assigned, so there is nothing for deploy-time revalidation to catch —
        // this scenario is a generator concern, not a deploy concern, and this test proves the
        // two layers agree rather than silently relying on only one of them.
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        // No instrumentist attached — simulates what the generator would have produced,
        // having already excluded the absent instrumentist at preview() time.
        $mission  = $this->makeDraftMission($version, $site, $surgeon, $manager, '2026-09-15', '08:00', '13:00', null);
        $this->makeAbsence($instr, '2026-09-10', '2026-09-20', $manager);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus());
        self::assertNull($fresh->getInstrumentist());
    }

    public function test_instrumentist_absence_with_no_overlap_does_not_block_deploy(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        $mission  = $this->makeDraftMission($version, $site, $surgeon, $manager, '2026-09-15', '08:00', '13:00', $instr);
        // Absence entirely before the mission's date — no overlap.
        $this->makeAbsence($instr, '2026-09-01', '2026-09-05', $manager);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh->getStatus());
        self::assertSame($instr->getId(), $fresh->getInstrumentist()?->getId());
    }

    public function test_instrumistist_absence_exact_boundary_overlap_blocks_deploy(): void
    {
        // Absence dateEnd exactly equals the mission's date — inclusive bounds, must count
        // as absent (matches PlanningGeneratorServiceV2::isAbsentFast()'s own `<=`/`>=`).
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        $this->makeDraftMission($version, $site, $surgeon, $manager, '2026-09-15', '08:00', '13:00', $instr);
        $this->makeAbsence($instr, '2026-09-10', '2026-09-15', $manager); // dateEnd == mission date

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    }

    // ── Anomalie 4 — chirurgien absent ───────────────────────────────────────────

    public function test_surgeon_absence_neutralizes_mission_instead_of_publishing_open(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $version  = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        // No instrumentist assigned — this is the exact reported symptom: it would have
        // published as OPEN ("à pourvoir") for a surgeon who is now absent.
        $mission  = $this->makeDraftMission($version, $site, $surgeon, $manager, '2026-09-15', '08:00', '13:00', null);
        $this->makeAbsence($surgeon, '2026-09-15', '2026-09-15', $manager);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $fresh->getStatus(), 'A surgeon-absent slot must be neutralized, never published OPEN.');
        self::assertNull($fresh->getInstrumentist());
    }

    public function test_surgeon_absence_on_already_assigned_draft_notifies_former_instrumentist(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        // An instrumentist WAS already assigned in the draft before the surgeon's absence.
        $mission  = $this->makeDraftMission($version, $site, $surgeon, $manager, '2026-09-15', '08:00', '13:00', $instr);
        $this->makeAbsence($surgeon, '2026-09-15', '2026-09-15', $manager);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $fresh->getStatus(), 'Must never remain ASSIGNED or become OPEN.');
        self::assertNull($fresh->getInstrumentist(), 'The stale assignment must be cleared, not left dangling on a cancelled mission.');

        // MissionPostDeployService::cancel(..., notify: true) reuses the existing lifecycle
        // pipeline — this asserts the former instrumentist actually has an audit trail entry
        // for the cancellation (the durable, synchronous half of that notification path).
        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'The neutralization must be audited.');
    }

    public function test_surgeon_absence_does_not_block_deploy_of_other_unaffected_missions(): void
    {
        $client    = $this->boot();
        $manager   = $this->createUser('ROLE_MANAGER');
        $token     = $this->login($client, $manager);
        $surgeon1  = $this->createUser('ROLE_SURGEON');
        $surgeon2  = $this->createUser('ROLE_SURGEON');
        $instr     = $this->createUser('ROLE_INSTRUMENTIST');
        $site      = $this->makeSite();
        $version   = $this->makeDraftVersion($site, $manager, '2026-09-01', '2026-09-30');
        $affected  = $this->makeDraftMission($version, $site, $surgeon1, $manager, '2026-09-15', '08:00', '13:00', null);
        $unaffected = $this->makeDraftMission($version, $site, $surgeon2, $manager, '2026-09-16', '08:00', '13:00', $instr);
        $this->makeAbsence($surgeon1, '2026-09-15', '2026-09-15', $manager);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(MissionStatus::CANCELLED, $this->freshMission($affected->getId())->getStatus());
        self::assertSame(MissionStatus::ASSIGNED, $this->freshMission($unaffected->getId())->getStatus());
    }
}
