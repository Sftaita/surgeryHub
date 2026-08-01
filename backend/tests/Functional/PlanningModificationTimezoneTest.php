<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
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
 * D-090 — the mandatory proof that Planning V2 Modification mode's "Redéployer" no longer
 * corrupts mission times by a DST offset (D-089). Every assertion here re-fetches the
 * Mission from a CLEARED EntityManager (never the pre-flush in-memory object still held by
 * the test) so `getStartAt()` genuinely round-trips through
 * BusinessDateTimeImmutableType::convertToPHPValue() — the only way to actually prove
 * nothing was corrupted on write.
 */
final class PlanningModificationTimezoneTest extends WebTestCase
{
    private const PASSWORD = 'Timezone90Fix!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdVersionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
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
                    // D-091 — a cross-site conflict alert may have been raised (correctly)
                    // against fixture missions that happen to overlap; must be removed
                    // before the mission itself (FK_PA_MISSION has no cascade).
                    foreach ($this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $m->getId()]) as $alert) {
                        $this->em->remove($alert);
                    }
                    $this->em->remove($m);
                }
                $this->em->flush();
            }
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
        $u->setEmail('tz90-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('TZ90-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeVersion(Hospital $site, User $manager, string $periodStart, string $periodEnd): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable($periodStart));
        $v->setPeriodEnd(new \DateTimeImmutable($periodEnd));
        $v->setVersionNumber(random_int(1000, 999999)); // isolated from other tests' numbering
        $v->setStatus(PlanningVersionStatus::ACTIVE);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    /** @param string $date "Y-m-d", $startTime/$endTime "H:i" — all Europe/Brussels wall-clock. */
    private function makeMission(
        PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy,
        string $date, string $startTime, string $endTime, ?User $instrumentist = null,
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
        $m->setStatus(MissionStatus::ASSIGNED);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    private function lineFor(Mission $m, array $overrides = []): array
    {
        return array_merge([
            'date'                     => $m->getStartAt()->format('Y-m-d'),
            'postId'                   => $m->getId(),
            'surgeonId'                => $m->getSurgeon()?->getId(),
            'surgeonName'              => '',
            'missionType'              => $m->getType()->value,
            'startTime'                => $m->getStartAt()->format('H:i'),
            'endTime'                  => $m->getEndAt()->format('H:i'),
            'siteId'                   => $m->getSite()?->getId(),
            'siteName'                 => '',
            'instrumentistId'          => $m->getInstrumentist()?->getId(),
            'instrumentistName'        => null,
            'status'                   => $m->getInstrumentist() !== null ? 'COVERED' : 'UNCOVERED',
            'existingMissionId'        => $m->getId(),
            'existingInstrumentistId'  => $m->getInstrumentist()?->getId(),
            'existingInstrumentistName'=> null,
            'freedFrom'                => false,
        ], $overrides);
    }

    /** Re-fetches from a cleared EntityManager — forces a real DB round-trip through the type. */
    private function freshMission(int $id): Mission
    {
        $this->em->clear();
        $m = $this->em->find(Mission::class, $id);
        self::assertNotNull($m);
        return $m;
    }

    // ── D-089/D-090: no drift, summer (CEST, UTC+2) ─────────────────────────────

    public function test_unedited_line_in_summer_produces_zero_functional_changes_and_no_drift(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, '2026-07-01', '2026-07-31');
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, '2026-07-15', '08:00', '13:00', $instr);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/apply-modifications", [
            'lines' => [$this->lineFor($mission)], // literally re-sent unchanged, as the real frontend does
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $result['functionalChanges'], 'An unedited line must never register as a functional change.');
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['usersNotified']);
        self::assertSame(0, $result['emailsSent']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame('08:00', $fresh->getStartAt()->format('H:i'), 'Summer wall-clock start time must be unchanged.');
        self::assertSame('13:00', $fresh->getEndAt()->format('H:i'), 'Summer wall-clock end time must be unchanged.');
        self::assertSame('+02:00', $fresh->getStartAt()->format('P'), 'Must remain correctly labeled CEST (+02:00), never +00:00.');
    }

    // ── D-089/D-090: no drift, winter (CET, UTC+1) ──────────────────────────────

    public function test_unedited_line_in_winter_produces_zero_functional_changes_and_no_drift(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, '2027-01-01', '2027-01-31');
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, '2027-01-15', '08:00', '13:00', $instr);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/apply-modifications", [
            'lines' => [$this->lineFor($mission)],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $result['functionalChanges'], 'An unedited line must never register as a functional change.');
        self::assertSame(0, $result['updated']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame('08:00', $fresh->getStartAt()->format('H:i'), 'Winter wall-clock start time must be unchanged.');
        self::assertSame('13:00', $fresh->getEndAt()->format('H:i'), 'Winter wall-clock end time must be unchanged.');
        self::assertSame('+01:00', $fresh->getStartAt()->format('P'), 'Must remain correctly labeled CET (+01:00), never +00:00.');
    }

    // ── A genuine schedule change is detected exactly once and stored exactly as typed ──

    public function test_genuine_schedule_change_is_one_functional_change_and_stores_exact_wall_clock(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, '2026-07-01', '2026-07-31');
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, '2026-07-15', '08:00', '13:00', $instr);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/apply-modifications", [
            'lines' => [$this->lineFor($mission, ['startTime' => '10:00', 'endTime' => '15:00'])],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = json_decode((string) $response->getContent(), true);
        self::assertSame(1, $result['functionalChanges'], 'Exactly one genuine schedule edit must register as exactly one functional change.');

        $fresh = $this->freshMission($mission->getId());
        self::assertSame('10:00', $fresh->getStartAt()->format('H:i'), 'Must store exactly the wall-clock time the manager typed — no DST shift.');
        self::assertSame('15:00', $fresh->getEndAt()->format('H:i'));
        self::assertSame('+02:00', $fresh->getStartAt()->format('P'));
    }

    // ── The exact reported symptom: many unedited lines sent, only the one edited counts ──

    public function test_many_unedited_lines_plus_one_edit_produces_exactly_one_functional_change(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, '2026-07-01', '2026-07-31');

        $instrumentists = [];
        $missions = [];
        // 32 missions, matching the reported symptom's magnitude — every one gets its own
        // instrumentist so the "unchanged" comparison is meaningful (not degenerate on a
        // shared instrumentistId).
        for ($i = 0; $i < 32; $i++) {
            $inst = $this->createUser('ROLE_INSTRUMENTIST');
            $instrumentists[] = $inst;
            $day = str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT);
            $missions[] = $this->makeMission($version, $site, $surgeon, $manager, "2026-07-{$day}", '08:00', '13:00', $inst);
        }

        // The real frontend re-sends every line of the month — only mission #0 is actually edited.
        $lines = array_map(fn (Mission $m) => $this->lineFor($m), $missions);
        $lines[0] = $this->lineFor($missions[0], ['startTime' => '09:00', 'endTime' => '14:00']);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/apply-modifications", [
            'lines' => $lines,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = json_decode((string) $response->getContent(), true);
        self::assertSame(
            1, $result['functionalChanges'],
            "32 missions sent, 1 genuinely edited — functionalChanges must be 1, not ~32. Got: " . json_encode($result),
        );
        // 2, not 1: the instrumentist of the one edited mission, AND the surgeon — every
        // mission in this test shares the same surgeon, so their own schedule genuinely did
        // change for that one mission. Both are legitimately concerned; nobody else is.
        self::assertSame(2, $result['usersNotified'], 'Exactly the instrumentist and surgeon of the one edited mission — nobody else.');
        self::assertSame(2, $result['emailsSent']);

        // And the other 31 must show zero drift too.
        for ($i = 1; $i < 32; $i++) {
            $fresh = $this->freshMission($missions[$i]->getId());
            self::assertSame('08:00', $fresh->getStartAt()->format('H:i'), "Mission #{$i} must be untouched.");
        }
    }
}
