<?php

namespace App\Tests\Functional;

use App\Entity\FinancialCalculation;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\FinancialCurrencyPolicy;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Diagnostic tarifs instrumentistes (2026-08-05) — GET /api/missions?validatedWithoutCalculation=true
 * (tuile dashboard "Missions validées sans calcul"). Même règle que
 * FinancialStatisticsQueryService::pipeline() (validatedMissionsWithoutCalculation) :
 * statut VALIDATED + aucun FinancialCalculation pour la mission.
 */
final class MissionListValidatedWithoutCalculationTest extends WebTestCase
{
    private const PASSWORD = 'VwcList72!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'calculations' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['calculations'] as $id) {
                $e = $this->em->find(FinancialCalculation::class, $id);
                if ($e !== null) { $this->em->remove($e); }
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
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

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
        $u->setEmail('vwclist-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('VwcList');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
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
        $h->setName('VwcListSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(User $surgeon, User $instr, Hospital $site, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-1 day'));
        $m->setEndAt($now->modify('-1 day +5 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function attachCalculation(Mission $mission): void
    {
        $fc = new FinancialCalculation();
        $fc->setMission($mission);
        $fc->setEffectiveAt(new \DateTimeImmutable('yesterday'));
        $fc->setCurrencyPolicy(FinancialCurrencyPolicy::PER_CURRENCY_NO_CONVERSION);
        $fc->setCalculatedAt(new \DateTimeImmutable());
        $this->em->persist($fc);
        $this->em->flush();
        $this->createdIds['calculations'][] = $fc->getId();
    }

    public function test_validated_mission_without_calculation_is_included(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::VALIDATED);
        $token = $this->login($client, $manager);

        $client->request('GET', '/api/missions?validatedWithoutCalculation=true&limit=200',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $ids = array_column(json_decode($response->getContent(), true)['items'], 'id');
        self::assertContains($mission->getId(), $ids);
    }

    public function test_validated_mission_with_calculation_is_excluded(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::VALIDATED);
        $this->attachCalculation($mission);
        $token = $this->login($client, $manager);

        $client->request('GET', '/api/missions?validatedWithoutCalculation=true&limit=200',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $ids = array_column(json_decode($response->getContent(), true)['items'], 'id');
        self::assertNotContains($mission->getId(), $ids, 'une mission déjà calculée ne doit jamais réapparaître dans la liste "sans calcul"');
    }

    public function test_non_validated_mission_is_excluded_even_without_calculation(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::SUBMITTED);
        $token = $this->login($client, $manager);

        $client->request('GET', '/api/missions?validatedWithoutCalculation=true&limit=200',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $ids = array_column(json_decode($response->getContent(), true)['items'], 'id');
        self::assertNotContains($mission->getId(), $ids, 'seul le statut VALIDATED est concerné par ce filtre');
    }

    public function test_filter_omitted_does_not_restrict_the_list(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::SUBMITTED);
        $token = $this->login($client, $manager);

        // siteId scope la requête à ce test précisément — la table mission n'est pas
        // isolée entre classes de test (chaque classe ne nettoie que ses propres
        // fixtures), donc "être dans les 200 premiers résultats non filtrés" n'est pas
        // une hypothèse fiable à l'échelle de la suite complète.
        $client->request('GET', '/api/missions?limit=200&siteId=' . $site->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $ids = array_column(json_decode($response->getContent(), true)['items'], 'id');
        self::assertContains($mission->getId(), $ids, 'sans le filtre, le comportement existant ne doit pas changer');
    }
}
