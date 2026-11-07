<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-179 — GET /api/me/hours : relevé mensuel personnel « Mes heures ».
 */
final class MyHoursControllerTest extends WebTestCase
{
    private const PASSWORD = 'MyHours1!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds = [];
    private array $createdSiteIds = [];
    private array $createdRateIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            foreach ($this->createdMissionIds as $missionId) {
                foreach ($this->em->getRepository(MissionExecution::class)->findBy(['mission' => $missionId]) as $e) {
                    $this->em->remove($e);
                }
            }
            $this->em->flush();
            foreach ($this->createdMissionIds as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    $this->em->remove($m);
                }
            }
            foreach ($this->createdRateIds as $id) {
                $r = $this->em->find(InstrumentistRate::class, $id);
                if ($r !== null) {
                    $this->em->remove($r);
                }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $u = $this->em->find(User::class, $id);
                if ($u !== null) {
                    $this->em->remove($u);
                }
            }
            foreach ($this->createdSiteIds as $id) {
                $h = $this->em->find(Hospital::class, $id);
                if ($h !== null) {
                    $this->em->remove($h);
                }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Autorisations ───────────────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $client = $this->boot();
        $client->request('GET', '/api/me/hours?month=2026-10');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function test_manager_and_surgeon_are_denied(): void
    {
        $client = $this->boot();
        foreach (['ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SURGEON'] as $role) {
            $token = $this->login($client, $this->createUser($role));
            self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, $token, '/api/me/hours?month=2026-10')->getStatusCode(), $role);
        }
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->createUser('ROLE_INSTRUMENTIST'));
        foreach (['', '?month=2026-13', '?month=26-10', '?month=2026-10&siteId=abc', '?month=2026-10&siteId=-1'] as $q) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, $token, '/api/me/hours' . $q)->getStatusCode(), $q);
        }
    }

    public function test_only_own_submitted_and_validated_missions_of_the_month_are_listed(): void
    {
        $client = $this->boot();
        $me = $this->createUser('ROLE_INSTRUMENTIST');
        $other = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite('Brugmann');

        $submitted = $this->makeMission($site, $surgeon, $me, MissionStatus::SUBMITTED, '2026-10-29 07:30', '2026-10-29 16:00');
        $validated = $this->makeMission($site, $surgeon, $me, MissionStatus::VALIDATED, '2026-10-22 07:30', '2026-10-22 15:45');
        $closed = $this->makeMission($site, $surgeon, $me, MissionStatus::CLOSED, '2026-10-09 07:30', '2026-10-09 16:30');
        foreach ([MissionStatus::ASSIGNED, MissionStatus::ENCODING_IN_PROGRESS, MissionStatus::CANCELLED, MissionStatus::REJECTED, MissionStatus::DECLARED, MissionStatus::IN_PROGRESS] as $excluded) {
            $this->makeMission($site, $surgeon, $me, $excluded, '2026-10-15 08:00', '2026-10-15 16:00');
        }
        $this->makeMission($site, $surgeon, $other, MissionStatus::VALIDATED, '2026-10-20 08:00', '2026-10-20 16:00');
        $this->makeMission($site, $surgeon, $me, MissionStatus::VALIDATED, '2026-09-30 08:00', '2026-09-30 16:00', '2026-09-30 08:00', '2026-09-30 16:00');
        $this->makeMission($site, $surgeon, $me, MissionStatus::VALIDATED, '2026-11-01 08:00', '2026-11-01 16:00', '2026-11-01 08:00', '2026-11-01 16:00');
        $this->em->clear();

        $data = $this->json($this->get($client, $this->login($client, $me), '/api/me/hours?month=2026-10'));

        self::assertSame('2026-10', $data['month']);
        self::assertSame([$submitted->getId(), $validated->getId(), $closed->getId()], array_column($data['items'], 'missionId'), 'Plus récente en premier, statuts SUBMITTED/VALIDATED/CLOSED seulement');
        self::assertSame(['pending', 'validated', 'validated'], array_column($data['items'], 'status'));
        // Heures encodées = en attente + validées ; CLOSED compte comme validé.
        self::assertSame(510 + 495 + 540, $data['summary']['encodedMinutes']);
        self::assertSame(495 + 540, $data['summary']['validatedMinutes']);
        self::assertSame(510, $data['summary']['pendingMinutes']);
        self::assertSame(67, $data['summary']['validatedPct']);
        self::assertNull($data['estimate'], 'Aucun tarif : aucune section financière');
    }

    public function test_real_hours_only_overnight_duration_only_and_missing_hours(): void
    {
        $client = $this->boot();
        $me = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite('Erasme');

        // Planifié 08h00–18h00, réel 22h00 → 03h00 le lendemain : 5h00, jamais le planifié.
        $night = $this->makeMission($site, $surgeon, $me, MissionStatus::VALIDATED, '2026-10-30 22:00', '2026-10-31 03:00', '2026-10-30 08:00', '2026-10-30 18:00');
        // Durée déclarée seule (ACTUAL_EXPLICIT) : comptée, sans début ni fin.
        $durationOnly = $this->makeMission($site, $surgeon, $me, MissionStatus::SUBMITTED, null, null, '2026-10-20 08:00', '2026-10-20 16:00', 510);
        // Validée sans heures (D-171) : 0 h, signalée, jamais le planifié.
        $noHours = $this->makeMission($site, $surgeon, $me, MissionStatus::VALIDATED, null, null, '2026-10-10 08:00', '2026-10-10 16:00');
        $this->em->clear();

        $data = $this->json($this->get($client, $this->login($client, $me), '/api/me/hours?month=2026-10'));
        $items = array_column($data['items'], null, 'missionId');

        $n = $items[$night->getId()];
        self::assertSame(300, $n['minutes']);
        self::assertSame('ACTUAL_TIMES', $n['durationSource']);
        self::assertTrue($n['endsNextDay']);
        self::assertSame('2026-10-30T22:00:00+01:00', $n['start']);
        self::assertSame('2026-10-31T03:00:00+01:00', $n['end']);
        self::assertSame('2026-10-30', $n['date']);

        $d = $items[$durationOnly->getId()];
        self::assertSame(510, $d['minutes']);
        self::assertSame('ACTUAL_EXPLICIT', $d['durationSource']);
        self::assertNull($d['start']);
        self::assertNull($d['end']);
        self::assertFalse($d['endsNextDay']);

        $z = $items[$noHours->getId()];
        self::assertNull($z['minutes']);
        self::assertNull($z['durationSource']);
        self::assertNull($z['start']);

        self::assertSame(300 + 510, $data['summary']['encodedMinutes']);
        self::assertSame(300, $data['summary']['validatedMinutes']);
        self::assertSame(1, $data['summary']['missingHoursCount']);
    }

    public function test_estimate_uses_own_rate_per_mission_type_and_site_filter_applies_everywhere(): void
    {
        $client = $this->boot();
        $me = $this->createUser('ROLE_INSTRUMENTIST');
        $other = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $brug = $this->makeSite('Brugmann');
        $delta = $this->makeSite('Delta');
        $this->makeRate($me, InstrumentistRateType::HOURLY_RATE, '42.00');
        $this->makeRate($me, InstrumentistRateType::CONSULTATION_FEE, '120.00');
        $this->makeRate($other, InstrumentistRateType::HOURLY_RATE, '99.00');

        $this->makeMission($brug, $surgeon, $me, MissionStatus::SUBMITTED, '2026-10-29 07:30', '2026-10-29 16:00');   // 8h30 × 42 = 357,00
        $this->makeMission($brug, $surgeon, $me, MissionStatus::VALIDATED, '2026-10-22 07:30', '2026-10-22 15:45');   // 8h15 × 42 = 346,50
        $this->makeMission($delta, $surgeon, $me, MissionStatus::VALIDATED, '2026-10-16 08:00', '2026-10-16 18:00');  // 10h × 42 = 420,00
        $this->makeMission($delta, $surgeon, $me, MissionStatus::VALIDATED, '2026-10-12 09:00', '2026-10-12 12:00', null, null, null, MissionType::CONSULTATION); // forfait 120,00
        $this->em->clear();
        $token = $this->login($client, $me);

        $all = $this->json($this->get($client, $token, '/api/me/hours?month=2026-10'));
        self::assertSame('1243.50', $all['estimate']['encodedAmount']);
        self::assertSame('886.50', $all['estimate']['validatedAmount']);
        self::assertSame('EUR', $all['estimate']['currency']);
        self::assertSame('42.00', $all['estimate']['hourlyRateMin']);
        self::assertSame(0, $all['estimate']['unratedCount']);
        $byKey = [];
        foreach ($all['estimate']['lines'] as $l) {
            $byKey[$l['siteName'] . '|' . $l['rateType']] = $l;
        }
        self::assertSame('703.50', $byKey[$brug->getName() . '|HOURLY_RATE']['amount']);
        self::assertSame(1005, $byKey[$brug->getName() . '|HOURLY_RATE']['minutes']);
        self::assertSame('120.00', $byKey[$delta->getName() . '|CONSULTATION_FEE']['amount']);
        self::assertNull($byKey[$delta->getName() . '|CONSULTATION_FEE']['minutes']);
        self::assertSame(['HOURLY_RATE', '42.00', '357.00'], [$all['items'][0]['estimate']['rateType'], $all['items'][0]['estimate']['unitAmount'], $all['items'][0]['estimate']['amount']]);

        // Filtre : liste, heures ET montants ; les puces restent celles du mois entier.
        $f = $this->json($this->get($client, $token, '/api/me/hours?month=2026-10&siteId=' . $brug->getId()));
        self::assertSame($brug->getId(), $f['siteId']);
        self::assertCount(2, $f['items']);
        self::assertSame(1005, $f['summary']['encodedMinutes']);
        self::assertSame(495, $f['summary']['validatedMinutes']);
        self::assertSame('703.50', $f['estimate']['encodedAmount']);
        self::assertSame('346.50', $f['estimate']['validatedAmount']);
        self::assertCount(1, $f['estimate']['lines']);
        // Égalité de compte → tri par nom (« Brugmann-… » avant « Delta-… »).
        self::assertSame([[$brug->getId(), 2], [$delta->getId(), 2]], array_map(static fn (array $s) => [$s['id'], $s['count']], $f['sites']));
    }

    public function test_never_exposes_free_text_or_planned_hours(): void
    {
        $client = $this->boot();
        $me = $this->createUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite('Saint-Jean');
        $m = $this->makeMission($site, $surgeon, $me, MissionStatus::SUBMITTED, '2026-10-06 08:00', '2026-10-06 16:00', '2026-10-06 07:00', '2026-10-06 19:00');
        $m->setDeclaredComment('TEXTE-LIBRE-SECRET');
        $this->em->flush();
        $this->em->clear();

        $response = $this->get($client, $this->login($client, $me), '/api/me/hours?month=2026-10');
        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('TEXTE-LIBRE-SECRET', $raw);
        self::assertStringNotContainsString('07:00', $raw, 'Horaire planifié jamais exposé');
        self::assertStringNotContainsString('19:00', $raw, 'Horaire planifié jamais exposé');
        $item = $this->json($response)['items'][0];
        self::assertSame(
            ['missionId', 'date', 'start', 'end', 'endsNextDay', 'minutes', 'durationSource', 'missionType', 'status', 'surgeonName', 'site', 'estimate'],
            array_keys($item),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('myhours-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Martin');
        $u->setLastname('Dubois');
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

    private function makeSite(string $name): Hospital
    {
        $h = new Hospital();
        $h->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeRate(User $instrumentist, InstrumentistRateType $type, string $amount): void
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType($type);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom(new \DateTimeImmutable('2026-01-01'));
        $r->setValidTo(null);
        $this->em->persist($r);
        $this->em->flush();
        $this->createdRateIds[] = $r->getId();
    }

    /**
     * Crée une mission ; $actualStart/$actualEnd = heures réellement encodées (null = aucune),
     * $plannedStart/$plannedEnd = planifié (défaut : identique au réel, ou 08h–16h).
     */
    private function makeMission(
        Hospital $site,
        User $surgeon,
        User $instrumentist,
        MissionStatus $status,
        ?string $actualStart,
        ?string $actualEnd,
        ?string $plannedStart = null,
        ?string $plannedEnd = null,
        ?int $durationOnly = null,
        MissionType $type = MissionType::BLOCK,
    ): Mission {
        $tz = new \DateTimeZone(self::TZ);
        $m = new Mission();
        $m->setType($type);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable($plannedStart ?? $actualStart ?? '2026-10-01 08:00', $tz));
        $m->setEndAt(new \DateTimeImmutable($plannedEnd ?? $actualEnd ?? '2026-10-01 16:00', $tz));
        $m->setStatus($status);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();

        if ($actualStart !== null || $durationOnly !== null) {
            $e = new MissionExecution();
            $e->setMission($m);
            if ($actualStart !== null && $actualEnd !== null) {
                $s = new \DateTimeImmutable($actualStart, $tz);
                $f = new \DateTimeImmutable($actualEnd, $tz);
                $e->setActualStartAt($s);
                $e->setActualEndAt($f);
                $e->setActualDurationMinutes((int) round(($f->getTimestamp() - $s->getTimestamp()) / 60));
            } else {
                $e->setActualDurationMinutes($durationOnly);
            }
            $this->em->persist($e);
            $this->em->flush();
        }

        return $m;
    }

    private function get(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }
}
