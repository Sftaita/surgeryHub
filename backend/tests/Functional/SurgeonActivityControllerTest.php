<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 4 (D-098) — GET /api/surgeon/activity : agrégats d'activité personnelle chirurgien,
 * jamais un classement inter-chirurgiens (voir SurgeonActivityService/SurgeonActivityVoter).
 */
final class SurgeonActivityControllerTest extends WebTestCase
{
    private const PASSWORD = 'Lot4Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'types' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getInterventions() as $i) {
                        $this->em->remove($i);
                    }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
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
        $u->setEmail('lot4-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Lot4');
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

    private function request(KernelBrowser $client, string $method, string $uri, ?string $token = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server);
        return $client->getResponse();
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Lot4Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeType(string $label): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('LOT4-' . bin2hex(random_bytes(4)));
        $t->setLabel($label);
        $t->setActive(true);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeMission(User $surgeon, MissionStatus $status, \DateTimeImmutable $startAt, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($startAt);
        $m->setEndAt($startAt->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function addIntervention(Mission $mission, ?InterventionType $type, string $code, string $label, int $orderIndex = 0): MissionIntervention
    {
        $mi = new MissionIntervention();
        $mi->setMission($mission);
        $mi->setInterventionType($type);
        $mi->setCode($code);
        $mi->setLabel($label);
        $mi->setOrderIndex($orderIndex);
        $this->em->persist($mi);
        $this->em->flush();
        return $mi;
    }

    private function todayAt(int $hour): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today ' . $hour . ':00', new \DateTimeZone(self::TZ));
    }

    private function yearRange(): array
    {
        $year = (int) (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y');
        return [
            'from' => sprintf('%d-01-01', $year),
            'to' => sprintf('%d-01-01', $year + 1),
        ];
    }

    // ── §3 Statuts comptabilisés (un test par statut) ────────────────────────

    /** @dataProvider missionStatusProvider */
    public function test_status_counting_matches_rule(MissionStatus $status, bool
    $shouldCount): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $status, $this->todayAt(9), $site);
        $type = $this->makeType('Type Statut');
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel());
        $token = $this->login($client, $surgeon);

        $range = $this->yearRange();
        $response = $this->request($client, 'GET', "/api/surgeon/activity?from={$range['from']}&to={$range['to']}", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);

        self::assertSame($shouldCount ? 1 : 0, $body['missionCount'], $status->value);
        self::assertSame($shouldCount ? 1 : 0, $body['interventionCount'], $status->value);
    }

    public static function missionStatusProvider(): array
    {
        return [
            'DRAFT ne compte pas' => [MissionStatus::DRAFT, false],
            'OPEN ne compte pas' => [MissionStatus::OPEN, false],
            'DECLARED ne compte pas' => [MissionStatus::DECLARED, false],
            'ASSIGNED ne compte pas' => [MissionStatus::ASSIGNED, false],
            'REJECTED ne compte pas' => [MissionStatus::REJECTED, false],
            'SUBMITTED ne compte pas' => [MissionStatus::SUBMITTED, false],
            'VALIDATED compte' => [MissionStatus::VALIDATED, true],
            'CLOSED compte' => [MissionStatus::CLOSED, true],
            'IN_PROGRESS ne compte pas' => [MissionStatus::IN_PROGRESS, false],
            'CANCELLED ne compte pas' => [MissionStatus::CANCELLED, false],
            'ENCODING_IN_PROGRESS ne compte pas' => [MissionStatus::ENCODING_IN_PROGRESS, false],
        ];
    }

    // ── §6/§7 Agrégation ──────────────────────────────────────────────────────

    public function test_multiple_interventions_same_type_are_summed(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $type = $this->makeType('PTG');
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel(), 0);
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel(), 1);
        $token = $this->login($client, $surgeon);

        $range = $this->yearRange();
        $body = $this->getActivity($client, $token, $range);

        self::assertSame(1, $body['missionCount']);
        self::assertSame(2, $body['interventionCount']);
        self::assertCount(1, $body['interventions']);
        self::assertSame(2, $body['interventions'][0]['count']);
        self::assertSame($type->getId(), $body['interventions'][0]['interventionTypeId']);
    }

    public function test_multiple_types_are_grouped_separately(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $typeA = $this->makeType('PTG');
        $typeB = $this->makeType('LCA');
        $this->addIntervention($mission, $typeA, $typeA->getCode(), $typeA->getLabel(), 0);
        $this->addIntervention($mission, $typeB, $typeB->getCode(), $typeB->getLabel(), 1);
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertCount(2, $body['interventions']);
        $labels = array_column($body['interventions'], 'label');
        self::assertContains('PTG', $labels);
        self::assertContains('LCA', $labels);
    }

    /** 1 mission / 3 interventions — missionCount != interventionCount (§7). */
    public function test_mission_count_differs_from_intervention_count(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $type = $this->makeType('PTG');
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel(), 0);
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel(), 1);
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel(), 2);
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertSame(1, $body['missionCount']);
        self::assertSame(3, $body['interventionCount']);
    }

    public function test_multiple_missions_sum_into_total(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $type = $this->makeType('PTG');
        $m1 = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $m2 = $this->makeMission($surgeon, MissionStatus::CLOSED, $this->todayAt(10), $site);
        $this->addIntervention($m1, $type, $type->getCode(), $type->getLabel());
        $this->addIntervention($m2, $type, $type->getCode(), $type->getLabel());
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertSame(2, $body['missionCount']);
        self::assertSame(2, $body['interventionCount']);
        self::assertSame(2, $body['interventions'][0]['count']);
    }

    public function test_missions_outside_period_are_excluded(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $type = $this->makeType('PTG');
        $lastYear = (new \DateTimeImmutable('today', new \DateTimeZone(self::TZ)))->modify('-1 year');
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $lastYear, $site);
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel());
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertSame(0, $body['missionCount']);
        self::assertSame(0, $body['interventionCount']);
        self::assertSame([], $body['interventions']);
    }

    public function test_other_surgeon_missions_are_excluded(): void
    {
        $client = $this->boot();
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $type = $this->makeType('PTG');
        $missionB = $this->makeMission($surgeonB, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $this->addIntervention($missionB, $type, $type->getCode(), $type->getLabel());
        $tokenA = $this->login($client, $surgeonA);

        $body = $this->getActivity($client, $tokenA, $this->yearRange());

        self::assertSame(0, $body['missionCount']);
        self::assertSame(0, $body['interventionCount']);
    }

    /** Ligne historique pré-Lot 5 (interventionType = null) — groupée par code, jamais fusionnée avec un type réel au label proche (§6). */
    public function test_legacy_row_without_intervention_type_is_grouped_separately(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $this->addIntervention($mission, null, 'LCA-LEGACY', 'Ligamentoplastie (historique)');
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertSame(1, $body['interventionCount']);
        self::assertCount(1, $body['interventions']);
        self::assertNull($body['interventions'][0]['interventionTypeId']);
        self::assertSame('Ligamentoplastie (historique)', $body['interventions'][0]['label']);
    }

    // ── §15 Sécurité ──────────────────────────────────────────────────────────

    public function test_surgeon_can_view_own_activity(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $token = $this->login($client, $surgeon);

        $range = $this->yearRange();
        $response = $this->request($client, 'GET', "/api/surgeon/activity?from={$range['from']}&to={$range['to']}", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
    }

    public function test_instrumentist_is_forbidden(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $range = $this->yearRange();
        $response = $this->request($client, 'GET', "/api/surgeon/activity?from={$range['from']}&to={$range['to']}", $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_manager_is_forbidden(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $range = $this->yearRange();
        $response = $this->request($client, 'GET', "/api/surgeon/activity?from={$range['from']}&to={$range['to']}", $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** Aucun ?surgeonId= n'est jamais lu par ce endpoint — toujours self-scopé (§15). */
    public function test_surgeon_id_query_param_is_ignored(): void
    {
        $client = $this->boot();
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $type = $this->makeType('PTG');
        $missionB = $this->makeMission($surgeonB, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $this->addIntervention($missionB, $type, $type->getCode(), $type->getLabel());
        $tokenA = $this->login($client, $surgeonA);

        $range = $this->yearRange();
        $response = $this->request(
            $client,
            'GET',
            "/api/surgeon/activity?from={$range['from']}&to={$range['to']}&surgeonId={$surgeonB->getId()}",
            $tokenA,
        );
        $body = json_decode($response->getContent(), true);

        self::assertSame(0, $body['missionCount'], 'surgeonId ne doit jamais élargir le scope au-delà de l\'utilisateur authentifié');
    }

    // ── §6 Tri ────────────────────────────────────────────────────────────────

    public function test_interventions_are_sorted_by_count_desc(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $typeLow = $this->makeType('AAA-Faible');
        $typeHigh = $this->makeType('ZZZ-Fort');
        $this->addIntervention($mission, $typeLow, $typeLow->getCode(), $typeLow->getLabel(), 0);
        $this->addIntervention($mission, $typeHigh, $typeHigh->getCode(), $typeHigh->getLabel(), 1);
        $this->addIntervention($mission, $typeHigh, $typeHigh->getCode(), $typeHigh->getLabel(), 2);
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertSame('ZZZ-Fort', $body['interventions'][0]['label']);
        self::assertSame(2, $body['interventions'][0]['count']);
        self::assertSame('AAA-Faible', $body['interventions'][1]['label']);
        self::assertSame(1, $body['interventions'][1]['count']);
    }

    public function test_tie_break_is_label_asc(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $typeB = $this->makeType('Bravo');
        $typeA = $this->makeType('Alpha');
        $this->addIntervention($mission, $typeB, $typeB->getCode(), $typeB->getLabel(), 0);
        $this->addIntervention($mission, $typeA, $typeA->getCode(), $typeA->getLabel(), 1);
        $token = $this->login($client, $surgeon);

        $body = $this->getActivity($client, $token, $this->yearRange());

        self::assertSame(1, $body['interventions'][0]['count']);
        self::assertSame(1, $body['interventions'][1]['count']);
        self::assertSame('Alpha', $body['interventions'][0]['label']);
        self::assertSame('Bravo', $body['interventions'][1]['label']);
    }

    // ── §16 Aucune donnée interdite ───────────────────────────────────────────

    public function test_response_never_contains_financial_or_patient_fields(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, MissionStatus::VALIDATED, $this->todayAt(9), $site);
        $type = $this->makeType('PTG');
        $this->addIntervention($mission, $type, $type->getCode(), $type->getLabel());
        $token = $this->login($client, $surgeon);

        $range = $this->yearRange();
        $response = $this->request($client, 'GET', "/api/surgeon/activity?from={$range['from']}&to={$range['to']}", $token);
        $raw = (string) $response->getContent();

        foreach (['amount', 'tarif', 'price', 'rate', 'patient', 'compensation', 'salary'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $raw, "champ interdit détecté: {$forbidden}");
        }
    }

    private function getActivity(KernelBrowser $client, string $token, array $range): array
    {
        $response = $this->request($client, 'GET', "/api/surgeon/activity?from={$range['from']}&to={$range['to']}", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        return json_decode($response->getContent(), true);
    }
}
