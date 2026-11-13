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
use App\Service\InstrumentistHoursPdfExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-189 — exports PDF de « Heures instrumentistes » : synthèse de la page et décompte individuel.
 *
 * Preuves : accès MANAGER / ADMIN seulement ; une ligne par prestation VALIDÉE, datée, dans
 * l'ordre chronologique (plusieurs le même jour = plusieurs lignes) ; durées et montants = ceux du
 * tiroir (« Mes heures »), total = Σ lignes = coût estimé validé de la page ; aucune prestation →
 * décompte vide à 0,00 € ; pagination multi-pages ; photo existante embarquée, jamais hors dossier.
 */
final class ManagerInstrumentistHoursExportTest extends WebTestCase
{
    private const PASSWORD = 'HoursPdf1!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private User $surgeon;
    private array $createdMissionIds = [];
    private array $createdUserIds = [];
    private array $createdSiteIds = [];
    private array $createdRateIds = [];
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $ids = $this->createdMissionIds ?: [0];
            $this->em->createQuery('DELETE FROM App\Entity\MissionExecution e WHERE e.mission IN (:m)')->setParameter('m', $ids)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\Mission m WHERE m.id IN (:m)')->setParameter('m', $ids)->execute();
            $this->em->createQuery('DELETE FROM App\Entity\InstrumentistRate r WHERE r.id IN (:r)')->setParameter('r', $this->createdRateIds ?: [0])->execute();
            $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.id IN (:u)')->setParameter('u', $this->createdUserIds ?: [0])->execute();
            $this->em->createQuery('DELETE FROM App\Entity\Hospital h WHERE h.id IN (:h)')->setParameter('h', $this->createdSiteIds ?: [0])->execute();
        }
        foreach ($this->createdFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_only_manager_and_admin_can_export_both_pdfs(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $uris = ['/api/manager/instrumentist-hours/export?month=2026-10', '/api/manager/instrumentists/' . $instrumentist->getId() . '/hours/export?month=2026-10'];

        foreach ($uris as $uri) {
            $client->request('GET', $uri);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode(), $uri);
        }
        foreach (['ROLE_INSTRUMENTIST', 'ROLE_SURGEON'] as $role) {
            $token = $this->login($client, $role === 'ROLE_INSTRUMENTIST' ? $instrumentist : $this->createUser($role));
            foreach ($uris as $uri) {
                self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, $token, $uri)->getStatusCode(), "$role $uri");
            }
        }
        foreach (['ROLE_MANAGER', 'ROLE_ADMIN'] as $role) {
            $token = $this->login($client, $this->createUser($role));
            foreach ($uris as $uri) {
                $response = $this->get($client, $token, $uri);
                self::assertSame(Response::HTTP_OK, $response->getStatusCode(), "$role $uri");
                self::assertSame('application/pdf', $response->headers->get('Content-Type'));
                self::assertStringStartsWith('attachment; filename="', (string) $response->headers->get('Content-Disposition'));
                self::assertStringStartsWith('%PDF-', (string) $response->getContent());
            }
        }

        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, $manager, '/api/manager/instrumentist-hours/export')->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, $manager, '/api/manager/instrumentist-hours/export?month=2026-10&sort=nope')->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, $manager, '/api/manager/instrumentists/' . $instrumentist->getId() . '/hours/export?month=2999-01')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get($client, $manager, '/api/manager/instrumentists/999999999/hours/export?month=2026-10')->getStatusCode());
    }

    public function test_statement_has_one_dated_line_per_validated_prestation_matching_the_drawer(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Brugmann');
        $a = $this->createUser('ROLE_INSTRUMENTIST', 'Élodie', 'Aubry');
        $this->makeRate($a, InstrumentistRateType::HOURLY_RATE, '42.00');
        $this->makeRate($a, InstrumentistRateType::CONSULTATION_FEE, '120.00');

        $late = $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-10-22 13:00', '2026-10-22 17:15');      // 255 · 178,50
        $early = $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-10-22 07:30', '2026-10-22 12:00');     // 270 · 189,00
        $consult = $this->makeMission($site, $a, MissionStatus::CLOSED, '2026-10-05 09:00', '2026-10-05 12:00', MissionType::CONSULTATION); // forfait 120,00
        $night = $this->makeMission($site, $a, MissionStatus::VALIDATED, '2026-10-31 22:00', '2026-11-01 03:00');     // 300 · 210,00
        $this->makeMission($site, $a, MissionStatus::SUBMITTED, '2026-10-29 07:30', '2026-10-29 16:00');               // en attente : exclue
        $this->em->clear();

        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $detail = $this->json($this->get($client, $manager, '/api/manager/instrumentists/' . $a->getId() . '/hours?month=2026-10'));
        $list = $this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?month=2026-10'));
        $row = array_values(array_filter($list['items'], static fn (array $i) => $i['instrumentist']['id'] === $a->getId()))[0];

        $data = $this->exporter()->statementData($this->reload($a), 2026, 10, null, new \DateTimeImmutable('2026-11-10 10:00', new \DateTimeZone(self::TZ)));

        // Une ligne par prestation validée, chronologique ; deux le 22/10 = deux lignes.
        self::assertSame([$consult->getId(), $early->getId(), $late->getId(), $night->getId()], array_column($data['lines'], 'missionId'));
        self::assertSame(['05/10/2026', '22/10/2026', '22/10/2026', '31/10/2026'], array_column($data['lines'], 'date'));
        self::assertSame(['07h30', '12h00'], [$data['lines'][1]['start'], $data['lines'][1]['end']]);
        self::assertSame('03h00 (+1)', $data['lines'][3]['end']);
        self::assertSame(['Consultation', 'Bloc', 'Bloc', 'Bloc'], array_column($data['lines'], 'type'));
        self::assertSame(['Forfait 120,00 €', '42,00 € / h', '42,00 € / h', '42,00 € / h'], array_column($data['lines'], 'rate'));

        // Durées et montants = ceux du tiroir, ligne à ligne.
        $byMission = array_column($detail['items'], null, 'missionId');
        foreach ($data['lines'] as $line) {
            self::assertSame('validated', $byMission[$line['missionId']]['status']);
            self::assertSame($byMission[$line['missionId']]['minutes'], $line['minutes']);
            self::assertSame($byMission[$line['missionId']]['estimate']['amount'], $line['amountRaw']);
        }

        // Total = Σ lignes = heures / coût estimé validés du tiroir et de la ligne de la page.
        self::assertSame(4, $data['summary']['count']);
        self::assertSame($detail['summary']['validatedMinutes'], $data['summary']['minutes']);
        self::assertSame($row['validatedMinutes'], $data['summary']['minutes']);
        self::assertSame('697.50', $data['summary']['totalRaw']);
        self::assertSame($detail['estimate']['validatedAmount'], $data['summary']['totalRaw']);
        self::assertSame($row['estimate']['validatedAmount'], $data['summary']['totalRaw']);
        self::assertSame('697,50 €', $data['summary']['total']);
        self::assertSame(1, $data['summary']['pendingCount']);
        self::assertSame(array_sum(array_map(static fn (array $l) => (int) round((float) $l['amountRaw'] * 100), $data['lines'])), 69750);

        // Numéro stable pour un contenu identique.
        $again = $this->exporter()->statementData($this->reload($a), 2026, 10, null, new \DateTimeImmutable('2026-11-11 10:00', new \DateTimeZone(self::TZ)));
        self::assertSame($data['number'], $again['number']);
        self::assertMatchesRegularExpression('/^DPH-202610-\d{4,}-[0-9A-F]{6}$/', $data['number']);
    }

    public function test_instrumentist_without_validated_prestation_gets_an_empty_statement(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Delta');
        $a = $this->createUser('ROLE_INSTRUMENTIST', 'Chloé', 'Sansvalidation');
        $this->makeRate($a, InstrumentistRateType::HOURLY_RATE, '40.00');
        $this->makeMission($site, $a, MissionStatus::SUBMITTED, '2026-10-12 08:00', '2026-10-12 12:00');
        $this->em->clear();

        $data = $this->exporter()->statementData($this->reload($a), 2026, 10, null, new \DateTimeImmutable('2026-11-10 10:00', new \DateTimeZone(self::TZ)));
        self::assertSame([], $data['lines']);
        self::assertSame(0, $data['summary']['count']);
        self::assertSame('0h00', $data['summary']['hours']);
        self::assertSame('0,00 €', $data['summary']['total']);
        self::assertSame(1, $data['summary']['pendingCount']);

        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $response = $this->get($client, $manager, '/api/manager/instrumentists/' . $a->getId() . '/hours/export?month=2026-10');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_long_statement_spans_several_pages_and_overview_matches_the_list(): void
    {
        $client = $this->boot();
        $site = $this->makeSite('Saint-Pierre');
        $a = $this->createUser('ROLE_INSTRUMENTIST', 'Nadia', 'Longmois');
        $this->makeRate($a, InstrumentistRateType::HOURLY_RATE, '45.00');
        for ($day = 1; $day <= 30; $day++) {
            $this->makeMission($site, $a, MissionStatus::VALIDATED, sprintf('2026-09-%02d 07:00', $day), sprintf('2026-09-%02d 11:00', $day));
            $this->makeMission($site, $a, MissionStatus::VALIDATED, sprintf('2026-09-%02d 12:00', $day), sprintf('2026-09-%02d 15:30', $day));
        }
        $this->em->clear();

        $now = new \DateTimeImmutable('2026-11-10 10:00', new \DateTimeZone(self::TZ));
        $data = $this->exporter()->statementData($this->reload($a), 2026, 9, null, $now);
        self::assertCount(60, $data['lines']);
        self::assertSame('225h00', $data['summary']['hours']);
        self::assertSame('10 125,00 €', $data['summary']['total']);

        $manager = $this->login($client, $this->createUser('ROLE_MANAGER'));
        $pdf = (string) $this->get($client, $manager, '/api/manager/instrumentists/' . $a->getId() . '/hours/export?month=2026-09')->getContent();
        self::assertGreaterThanOrEqual(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Plusieurs pages A4');

        // Synthèse = liste de la page (mêmes filtres), au caractère près pour la ligne et le total.
        $list = $this->json($this->get($client, $manager, '/api/manager/instrumentist-hours?month=2026-09&siteId=' . $site->getId()));
        $overview = $this->exporter()->overviewData(2026, 9, $site->getId(), null, 'validatedMinutes', 'desc', $now);
        self::assertSame($list['totals'], $overview['raw']['totals']);
        self::assertSame(array_map(static fn (array $i) => $i['instrumentist']['id'], $list['items']), array_map(static fn (array $i) => $i['instrumentist']['id'], $overview['raw']['items']));
        self::assertSame('≈ 10 125,00 €', $overview['totals']['validatedAmount']);
        self::assertSame('225h00', $overview['totals']['validated']);
    }

    public function test_variations_are_signed_for_colouring(): void
    {
        $this->boot();
        $site = $this->makeSite('Ixelles');
        $up = $this->createUser('ROLE_INSTRUMENTIST', 'Hausse', 'Plus');
        $down = $this->createUser('ROLE_INSTRUMENTIST', 'Baisse', 'Moins');
        $this->makeMission($site, $up, MissionStatus::VALIDATED, '2026-09-10 08:00', '2026-09-10 10:00');
        $this->makeMission($site, $up, MissionStatus::VALIDATED, '2026-10-10 08:00', '2026-10-10 12:00');
        $this->makeMission($site, $down, MissionStatus::VALIDATED, '2026-09-10 08:00', '2026-09-10 12:00');
        $this->makeMission($site, $down, MissionStatus::VALIDATED, '2026-10-10 08:00', '2026-10-10 10:00');
        $this->em->clear();

        $overview = $this->exporter()->overviewData(2026, 10, $site->getId(), null, 'name', 'asc', new \DateTimeImmutable('2026-11-10 10:00', new \DateTimeZone(self::TZ)));
        $byName = array_column($overview['rows'], null, 'name');
        self::assertSame(['text' => '▲ +100 %', 'tone' => 'up'], $byName['Hausse ' . $up->getLastname()]['previous']);
        self::assertSame(['text' => '▼ −50 %', 'tone' => 'down'], $byName['Baisse ' . $down->getLastname()]['previous']);
    }

    public function test_existing_profile_photo_is_embedded_and_never_read_outside_its_folder(): void
    {
        $this->boot();
        $dir = static::getContainer()->getParameter('app.profile_picture.upload_dir');
        @mkdir($dir, 0775, true);
        $file = $dir . '/user-test-' . bin2hex(random_bytes(4)) . '.png';
        $img = imagecreatetruecolor(8, 8);
        imagepng($img, $file);
        $this->createdFiles[] = $file;

        $a = $this->createUser('ROLE_INSTRUMENTIST', 'Photo', 'Présente');
        $a->setProfilePicturePath('/uploads/profile-pictures/' . basename($file));
        $a->setCompanyName('Aubry SRL');
        $a->setVatNumber('BE0123456789');
        $this->em->flush();

        $now = new \DateTimeImmutable('2026-11-10 10:00', new \DateTimeZone(self::TZ));
        $data = $this->exporter()->statementData($a, 2026, 10, null, $now);
        self::assertStringStartsWith('data:image/png;base64,', (string) $data['instrumentist']['photo']);
        self::assertSame('Aubry SRL', $data['instrumentist']['companyName']);
        self::assertSame('BE0123456789', $data['instrumentist']['vatNumber']);

        foreach (['/uploads/profile-pictures/../../index.php', '/uploads/profile-pictures/absente.png', 'https://example.test/x.png'] as $path) {
            $a->setProfilePicturePath($path);
            self::assertNull($this->exporter()->statementData($a, 2026, 10, null, $now)['instrumentist']['photo'], $path);
        }
        $a->setProfilePicturePath(null);
        $none = $this->exporter()->statementData($a, 2026, 10, null, $now);
        self::assertNull($none['instrumentist']['photo']);
        self::assertSame('PP', $none['instrumentist']['initials']);
        self::assertNull($none['instrumentist']['phone'], 'Jamais de donnée inventée');
    }

    private function exporter(): InstrumentistHoursPdfExporter
    {
        return static::getContainer()->get(InstrumentistHoursPdfExporter::class);
    }

    private function reload(User $u): User
    {
        return $this->em->find(User::class, $u->getId());
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->surgeon = $this->createUser('ROLE_SURGEON', 'Antoine', 'Mertens');
        return $client;
    }

    private function createUser(string $role, string $firstname = 'Martin', string $lastname = 'Dubois'): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('hourspdf-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname($lastname . '-' . bin2hex(random_bytes(2)));
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
        $r->setValidFrom(new \DateTimeImmutable('2025-01-01'));
        $r->setValidTo(null);
        $this->em->persist($r);
        $this->em->flush();
        $this->createdRateIds[] = $r->getId();
    }

    private function makeMission(Hospital $site, User $instrumentist, MissionStatus $status, string $actualStart, string $actualEnd, MissionType $type = MissionType::BLOCK): Mission
    {
        $tz = new \DateTimeZone(self::TZ);
        $s = new \DateTimeImmutable($actualStart, $tz);
        $f = new \DateTimeImmutable($actualEnd, $tz);
        $m = new Mission();
        $m->setType($type);
        $m->setSite($site);
        $m->setSurgeon($this->surgeon);
        $m->setCreatedBy($this->surgeon);
        $m->setStartAt($s);
        $m->setEndAt($f);
        $m->setStatus($status);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();

        $e = new MissionExecution();
        $e->setMission($m);
        $e->setActualStartAt($s);
        $e->setActualEndAt($f);
        $e->setActualDurationMinutes((int) round(($f->getTimestamp() - $s->getTimestamp()) / 60));
        $this->em->persist($e);
        $this->em->flush();

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
