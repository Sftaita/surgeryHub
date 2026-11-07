<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use App\Service\MissionEncodingWorkflowService;
use App\Tests\Support\DeclaresActualHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-182 — a cancelled mission is a mission that did not take place: it contributes nothing to
 * any financial calculation or statistic (statistics, drilldowns, firm billing worklist, the
 * instrumentist's hours), yet its history stays readable (detail, audit, encoding history,
 * archived encoding).
 *
 * Proven by comparison on a dedicated site / firm: every response with mission A only is
 * exactly the response with A and B once B — validated, valued, approved — is cancelled
 * (after the reopening of its encoding, in the same operation); and B was really counted
 * while it was active.
 */
final class MissionCancellationFinanceExclusionTest extends WebTestCase
{
    use DeclaresActualHours;

    private const PASSWORD = 'Cancel182!';
    private const PERIOD = 'from=2026-05-01&to=2026-06-01';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $manager;
    private User $surgeon;
    private Hospital $site;
    private Firm $firm;
    private InterventionType $type;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        // One kernel for the whole scenario: fixtures are built between requests.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(3));
        $this->site = (new Hospital())->setName('D182F-' . $suffix);
        $this->em->persist($this->site);
        $this->firm = new Firm();
        $this->firm->setName('D182F-' . $suffix);
        $this->em->persist($this->firm);
        $this->type = new InterventionType();
        $this->type->setCode('D182F-' . $suffix);
        $this->type->setLabel('D182F');
        $this->em->persist($this->type);
        $rule = new PricingRule();
        $rule->setFirm($this->firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($this->type);
        $rule->setUnitPrice('120.00');
        $this->em->persist($rule);
        $this->manager = $this->user('ROLE_MANAGER');
        $this->surgeon = $this->user('ROLE_SURGEON');
        $this->em->flush();
    }

    private function user(string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d182f-' . bin2hex(random_bytes(5)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D182F');
        $u->setLastname(substr($role, 5));
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);

        return $u;
    }

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $this->client->getResponse()->getContent());

        return $data['token'];
    }

    /** @return array{0: int, 1: array<mixed>} */
    private function request(string $method, string $uri, string $token, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $body !== null ? json_encode($body) : null);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    /** A validated mission of the dedicated site, valued and approved, with its own instrumentist. */
    private function approvedMission(): Mission
    {
        // Each request resets the services (EntityManager cleared): the fixtures are re-read.
        $this->site = $this->em->find(Hospital::class, $this->site->getId());
        $this->surgeon = $this->em->find(User::class, $this->surgeon->getId());
        $this->manager = $this->em->find(User::class, $this->manager->getId());
        $this->firm = $this->em->find(Firm::class, $this->firm->getId());
        $this->type = $this->em->find(InterventionType::class, $this->type->getId());

        $instrumentist = $this->user('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('35.00');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);

        $start = new \DateTimeImmutable('2026-05-15 09:00:00', new \DateTimeZone('Europe/Brussels'));
        $mission = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($this->site)
            ->setSurgeon($this->surgeon)
            ->setCreatedBy($this->manager)
            ->setStartAt($start)
            ->setEndAt($start->modify('+2 hours'))
            ->setStatus(MissionStatus::VALIDATED)
            ->setInstrumentist($instrumentist);
        $mission->setEncodingLockedAt(new \DateTimeImmutable('2026-05-15 12:00:00'));
        $this->em->persist($mission);
        $this->em->flush();
        $this->declareActualHours($mission);

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($this->type->getCode());
        $intervention->setLabel('D182F');
        $intervention->setInterventionType($this->type);
        $intervention->setPrimaryFirm($this->firm);
        $this->em->persist($intervention);
        $this->em->flush();
        $mission->getInterventions()->add($intervention);

        $calculations = static::getContainer()->get(FinancialCalculationService::class);
        $calculations->approve($calculations->calculate($mission, $this->manager), $this->manager);

        return $mission;
    }

    /**
     * Every financial / statistical view of the dedicated site and firm, as the manager reads it.
     *
     * @return array<string, mixed>
     */
    private function financialViews(string $token): array
    {
        $scope = self::PERIOD . '&siteId=' . $this->site->getId();
        $views = [];
        foreach ([
            'overview', 'timeseries?granularity=DAY', 'pipeline', 'by-firm', 'by-instrumentist', 'by-surgeon',
            'by-intervention', 'top-materials', 'missions', 'calculations', 'documents',
        ] as $endpoint) {
            $uri = '/api/financial-statistics/' . $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $scope;
            [$status, $body] = $this->request('GET', $uri, $token);
            self::assertSame(200, $status, $uri . ' ' . json_encode($body));
            $views[$endpoint] = self::withoutVolatile($body);
        }
        $uri = '/api/firm-billing/worklist?' . self::PERIOD . '&firmIds[]=' . $this->firm->getId();
        [$status, $body] = $this->request('GET', $uri, $token);
        self::assertSame(200, $status, $uri . ' ' . json_encode($body));
        $views['firm-billing/worklist'] = self::withoutVolatile($body);

        return $views;
    }

    /** Generation timestamps only — never an amount, a count or a line. */
    private static function withoutVolatile(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/^(generatedAt|computedAt|asOf|now)$/', $k)) {
                continue;
            }
            $clean[$k] = self::withoutVolatile($v);
        }

        return $clean;
    }

    /** @return list<int> the missions of the instrumentist's « Mes heures » for May 2026 */
    private function myHoursMissionIds(User $instrumentist): array
    {
        [$status, $body] = $this->request('GET', '/api/me/hours?month=2026-05', $this->login($instrumentist));
        self::assertSame(200, $status, json_encode($body));
        $ids = [];
        array_walk_recursive($body, static function ($v, $k) use (&$ids) {
            if ($k === 'missionId') {
                $ids[] = (int) $v;
            }
        });

        return $ids;
    }

    public function test_cancelled_mission_is_excluded_from_every_financial_view_and_keeps_its_history(): void
    {
        $token = $this->login($this->manager);
        $this->approvedMission();
        $withAOnly = $this->financialViews($token);

        $b = $this->approvedMission();
        $bInstrumentist = $b->getInstrumentist();
        $withB = $this->financialViews($token);
        // B is really counted while it took place (else the comparison below would prove nothing).
        foreach (['overview', 'by-surgeon', 'missions', 'calculations', 'firm-billing/worklist'] as $view) {
            self::assertNotEquals($withAOnly[$view], $withB[$view], "$view counts the active mission B");
        }
        self::assertContains($b->getId(), $this->myHoursMissionIds($bInstrumentist));

        // Cancelled by the manager: its validated encoding reopened first, in the same operation.
        [$status, $detail] = $this->request('GET', "/api/missions/{$b->getId()}", $token);
        self::assertSame(200, $status);
        self::assertContains('cancel', $detail['allowedActions']);
        self::assertSame('REOPEN', $detail['cancellation']['correction']['action']);
        [$status, $body] = $this->request('POST', "/api/missions/{$b->getId()}/cancel", $token, ['reason' => "L'opération n'a pas eu lieu", 'correctionConfirmed' => true]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('CANCELLED', $body['status']);

        // Nothing of B remains in any financial or statistical view.
        $afterCancellation = $this->financialViews($token);
        foreach ($withAOnly as $view => $response) {
            self::assertEquals($response, $afterCancellation[$view], "$view excludes the cancelled mission B");
        }
        self::assertNotContains($b->getId(), $this->myHoursMissionIds($bInstrumentist));
        $this->em->clear();
        $b = $this->em->find(Mission::class, $b->getId());
        self::assertFalse(static::getContainer()->get(MissionEncodingWorkflowService::class)->isBillable($b));
        $calculations = $this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $b]);
        self::assertNotEmpty($calculations);
        foreach ($calculations as $calculation) {
            self::assertSame(FinancialCalculationStatus::CANCELLED, $calculation->getStatus());
        }

        // Its history stays: archived encoding, audit trail, encoding history.
        self::assertSame(MissionStatus::CANCELLED, $b->getStatus());
        self::assertCount(1, $b->getInterventions());
        self::assertNotNull($b->getExecution()?->getActualDurationMinutes());
        [$status, $detail] = $this->request('GET', "/api/missions/{$b->getId()}", $token);
        self::assertSame(200, $status);
        self::assertSame('CANCELLED', $detail['status']);
        $types = array_map(static fn (AuditEvent $e) => $e->getEventType(), $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $b->getId()], ['id' => 'ASC']));
        $reopened = array_search(AuditEventType::MISSION_ENCODING_REOPENED, $types, true);
        $cancelled = array_search(AuditEventType::MISSION_CANCELLED_POST_DEPLOY, $types, true);
        self::assertNotFalse($reopened);
        self::assertNotFalse($cancelled);
        self::assertLessThan($cancelled, $reopened, 'reopened, then cancelled');
        self::assertContains(AuditEventType::FINANCIAL_CALCULATION_CANCELLED, $types);
        [$status, $history] = $this->request('GET', "/api/missions/{$b->getId()}/encoding/history", $token);
        self::assertSame(200, $status);
        self::assertContains('MISSION_ENCODING_REOPENED', array_column($history['items'], 'eventType'));
        // The archived encoding stays readable, with the reason of its reopening.
        [$status, $encoding] = $this->request('GET', "/api/missions/{$b->getId()}/encoding", $token);
        self::assertSame(200, $status);
        self::assertStringContainsString("Annulation de la mission : programme annulé. L'opération n'a pas eu lieu", json_encode($encoding['encodingComments'], JSON_UNESCAPED_UNICODE));
    }
}
