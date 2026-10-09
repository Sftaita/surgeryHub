<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\InstrumentistRateType;
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-138 — Suivi des encodages : toute « Anomalie » FINANCE est justifiée, localisée et
 * compréhensible. Contre une vraie base et via HTTP : la liste (motifs) et
 * GET .../missions/{id}/financial-anomalies (détail du tiroir) doivent dire la même chose,
 * et seule une tentative de calcul réellement échouée produit une anomalie — jamais un
 * dépassement d'heures (cas réel #1040 / #1226).
 *
 * Missions en septembre 2026 sur un site créé par le test : le filtre siteId isole la liste
 * des autres données de la base.
 */
final class EncodingTrackingFinancialAnomaliesTest extends WebTestCase
{
    private const PASSWORD = 'Anomalie123!';
    private const LIST = '/api/billing/encoding-tracking?from=2026-09-01&to=2026-10-01';

    private EntityManagerInterface $em;
    private ?Hospital $site = null;
    private array $created = [
        'missions' => [], 'offerings' => [], 'rules' => [], 'rates' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [], 'executions' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $missionIds = $this->created['missions'];
            if ($missionIds !== []) {
                foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $missionIds]) as $calc) {
                    $calc->setSupersededByCalculation(null);
                    foreach ($calc->getLines() as $l) { $this->em->remove($l); }
                }
                $this->em->flush();
                foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $missionIds]) as $calc) { $this->em->remove($calc); }
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionIds]) as $evt) { $this->em->remove($evt); }
                $this->em->flush();
                foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $missionIds]) as $ml) { $this->em->remove($ml); }
                $this->em->flush();
                foreach ($this->em->getRepository(MissionIntervention::class)->findBy(['mission' => $missionIds]) as $mi) { $this->em->remove($mi); }
                $this->em->flush();
                foreach ($missionIds as $id) {
                    $m = $this->em->find(Mission::class, $id);
                    if ($m) { $m->setExecution(null); }
                }
                $this->em->flush();
                foreach ($this->created['executions'] as $id) { $e = $this->em->find(MissionExecution::class, $id); if ($e) $this->em->remove($e); }
                $this->em->flush();
                foreach ($missionIds as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
                $this->em->flush();
            }
            foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $this->created['users']]) as $evt) { $this->em->remove($evt); }
            $this->em->flush();
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function user(string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('anomalie-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Sophie');
        $u->setLastname('Anomalie');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /**
     * Chaque requête HTTP réinitialise l'EntityManager : une entité créée avant doit être
     * rechargée avant d'être référencée par une nouvelle fixture.
     *
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function managed(object $entity): object
    {
        if ($this->em->contains($entity)) {
            return $entity;
        }
        return $this->em->find($this->em->getClassMetadata($entity::class)->getName(), $entity->getId());
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function request(KernelBrowser $client, ?string $token, string $method, string $uri): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server, content: '{}');
        return $client->getResponse();
    }

    private function json(Response $r, int $expected = 200): array
    {
        self::assertSame($expected, $r->getStatusCode(), (string) $r->getContent());
        return json_decode((string) $r->getContent(), true) ?? [];
    }

    private function firm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . ' ' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function type(string $label): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('ANO-' . bin2hex(random_bytes(3)));
        $t->setLabel($label);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function item(Firm $firm, string $label): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($this->managed($firm));
        $i->setLabel($label);
        $i->setUnit('1');
        $i->setReferenceCode('REF-' . bin2hex(random_bytes(3)));
        $i->setBillingStatus(MaterialBillingStatus::UNSPECIFIED);
        $this->em->persist($i); $this->em->flush();
        $this->created['items'][] = $i->getId();
        return $i;
    }

    private function interventionRule(Firm $firm, InterventionType $type, string $price = '350.00'): PricingRule
    {
        $firm = $this->managed($firm);
        $type = $this->managed($type);
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function materialRule(Firm $firm, MaterialItem $item, string $price = '20.00'): PricingRule
    {
        $firm = $this->managed($firm);
        $item = $this->managed($item);
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function site(): Hospital
    {
        if ($this->site === null) {
            $this->site = new Hospital();
            $this->site->setName('ANO-Delta-' . bin2hex(random_bytes(2)));
            $this->em->persist($this->site); $this->em->flush();
            $this->created['sites'][] = $this->site->getId();
        }
        return $this->site;
    }

    /**
     * Mission VALIDATED 13:00–18:00 (5 h planifiées), une intervention de $firm, et une ligne
     * de matériel par article de $items (rattachée à l'intervention).
     *
     * @param MaterialItem[] $items
     */
    private function mission(Firm $firm, InterventionType $type, array $items = [], ?int $actualMinutes = null, bool $instrumentistRate = true): Mission
    {
        $surgeon = $this->user('ROLE_SURGEON');
        $instrumentist = $this->user('ROLE_INSTRUMENTIST');
        if ($instrumentistRate) {
            $rate = new InstrumentistRate();
            $rate->setInstrumentist($instrumentist);
            $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
            $rate->setAmount('40.00');
            $rate->setCurrency('EUR');
            $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
            $this->em->persist($rate); $this->em->flush();
            $this->created['rates'][] = $rate->getId();
        }

        $start = new \DateTimeImmutable('2026-09-10 13:00:00');
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($this->site());
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($start);
        $m->setEndAt($start->modify('+5 hours'));
        $m->setStatus(MissionStatus::VALIDATED);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $itv = new MissionIntervention();
        $itv->setMission($m);
        $itv->setCode($type->getCode());
        $itv->setLabel($type->getLabel());
        $itv->setInterventionType($type);
        $itv->setPrimaryFirm($firm);
        $this->em->persist($itv); $this->em->flush();
        $m->getInterventions()->add($itv);

        foreach ($items as $item) {
            $this->addMaterial($m, $itv, $item, $surgeon);
        }

        if ($actualMinutes !== null) {
            $execution = new MissionExecution();
            $execution->setMission($m);
            $execution->setActualDurationMinutes($actualMinutes);
            $this->em->persist($execution); $this->em->flush();
            $this->created['executions'][] = $execution->getId();
            $m->setExecution($execution);
            $this->em->flush();
        }

        return $m;
    }

    private function addMaterial(Mission $m, MissionIntervention $itv, MaterialItem $item, User $by): MaterialLine
    {
        $m = $this->managed($m);
        $ml = new MaterialLine();
        $ml->setMission($m);
        $ml->setMissionIntervention($this->managed($itv));
        $ml->setItem($this->managed($item));
        $ml->setQuantity('1.00');
        $ml->setCreatedBy($this->managed($by));
        $this->em->persist($ml); $this->em->flush();
        $m->getMaterialLines()->add($ml);
        return $ml;
    }

    private function listItem(KernelBrowser $client, string $token, Mission $m): array
    {
        $payload = $this->json($this->request($client, $token, 'GET', self::LIST . '&siteId=' . $this->site()->getId()));
        foreach ($payload['items'] as $item) {
            if ($item['missionId'] === $m->getId()) {
                return $item;
            }
        }
        self::fail('Mission absente de la liste du suivi.');
    }

    private function detail(KernelBrowser $client, string $token, Mission $m): array
    {
        return $this->json($this->request($client, $token, 'GET', "/api/billing/encoding-tracking/missions/{$m->getId()}/financial-anomalies"));
    }

    private function calculate(KernelBrowser $client, string $token, Mission $m): Response
    {
        return $this->request($client, $token, 'POST', "/api/missions/{$m->getId()}/financial-calculations");
    }

    /** Liste et tiroir disent la même chose : même état, même nombre d'anomalies, mêmes motifs. */
    private function assertListAndDetailAgree(array $item, array $detail): void
    {
        self::assertSame($item['financial']['state'], $detail['state']);
        self::assertSame($item['financial']['label'], $detail['label']);
        self::assertSame($item['financial']['anomalyCount'], count($detail['anomalies']));
        $fromDetail = array_count_values(array_column($detail['anomalies'], 'code'));
        $fromList = array_column($item['financial']['anomalyReasons'], 'count', 'code');
        ksort($fromDetail);
        ksort($fromList);
        self::assertSame($fromList, $fromDetail);
    }

    // ── 1. Validée, sans anomalie ───────────────────────────────────────

    public function test_validated_mission_without_anomaly_has_no_explanation_to_give(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type('Suture méniscale');
        $item = $this->item($firm, 'FiberTak');
        $this->interventionRule($firm, $type);
        $this->materialRule($firm, $item);
        $m = $this->mission($firm, $type, [$item]);

        $item1 = $this->listItem($client, $token, $m);
        self::assertSame('TO_CALCULATE', $item1['financial']['state']);
        self::assertSame(0, $item1['financial']['anomalyCount']);
        self::assertSame([], $item1['financial']['anomalyReasons']);
        $detail = $this->detail($client, $token, $m);
        self::assertSame([], $detail['anomalies']);
        self::assertNull($detail['retry']);
        $this->assertListAndDetailAgree($item1, $detail);

        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());
        $item2 = $this->listItem($client, $token, $m);
        self::assertSame('CALCULATED', $item2['financial']['state']);
        $this->assertListAndDetailAgree($item2, $this->detail($client, $token, $m));
    }

    // ── 2. Dépassement horaire sans anomalie (cas #1226 : 7 h 45 / 5 h) ──

    public function test_hours_overrun_alone_never_produces_a_financial_anomaly(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Smith & Nephew');
        $type = $this->type('Suture méniscale');
        $this->interventionRule($firm, $type);
        $m = $this->mission($firm, $type, [], actualMinutes: 600); // 10 h pour 5 h planifiées

        $before = $this->listItem($client, $token, $m);
        self::assertSame('OVER_PLAN', $before['hours']['comparison'], 'le dépassement reste visible dans HEURES');
        self::assertSame('TO_CALCULATE', $before['financial']['state'], 'mais FINANCE ne le traite pas comme une anomalie');

        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode(), 'le moteur valorise 10 h sans objection');
        $after = $this->listItem($client, $token, $m);
        self::assertSame('OVER_PLAN', $after['hours']['comparison']);
        self::assertSame('CALCULATED', $after['financial']['state']);
        self::assertSame(0, $after['financial']['anomalyCount']);
    }

    // ── 3 + 5. Anomalie tarifaire réelle, rattachée à une intervention ──

    public function test_missing_intervention_rate_is_explained_and_located_on_its_intervention(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Smith & Nephew');
        $type = $this->type("Suture d'un ménisque de genou");
        $m = $this->mission($firm, $type);
        $itvId = $m->getInterventions()->first()->getId();

        self::assertSame('TO_CALCULATE', $this->listItem($client, $token, $m)['financial']['state'],
            "un tarif manquant n'est une anomalie qu'après une vraie tentative de calcul");
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        $item = $this->listItem($client, $token, $m);
        self::assertSame('ANOMALY', $item['financial']['state']);
        self::assertSame(1, $item['financial']['anomalyCount']);
        self::assertSame([['code' => 'MISSING_FIRM_INTERVENTION_RATE', 'label' => "Tarif d'intervention manquant", 'count' => 1]], $item['financial']['anomalyReasons']);

        $response = $this->request($client, $token, 'GET', "/api/billing/encoding-tracking/missions/{$m->getId()}/financial-anomalies");
        self::assertStringNotContainsString('No active', (string) $response->getContent(), 'le message technique du moteur ne sort jamais');
        self::assertStringNotContainsString('PricingRule', (string) $response->getContent());
        $detail = $this->json($response);
        $this->assertListAndDetailAgree($item, $detail);
        self::assertNotNull($detail['failedAt']);
        self::assertSame('2026-09-10', $detail['effectiveAt']);

        $a = $detail['anomalies'][0];
        self::assertSame('MISSING_FIRM_INTERVENTION_RATE', $a['code']);
        self::assertSame('CONFIGURATION', $a['category']);
        self::assertSame('BLOCKING', $a['severity']);
        self::assertSame("Tarif d'intervention manquant", $a['title']);
        self::assertStringContainsString('chez ' . $firm->getName() . ' au 10/09/2026', $a['explanation']);
        self::assertSame(['id' => $firm->getId(), 'name' => $firm->getName()], $a['firm']);
        self::assertSame("Suture d'un ménisque de genou", $a['element']['label']);
        self::assertSame($itvId, $a['missionInterventionId']);
        self::assertNull($a['materialLineId']);
        self::assertSame(['code' => 'CONFIGURE_INTERVENTION_RATE', 'label' => 'Configurer le tarif'], $a['action']);
        self::assertFalse($a['resolved']);
        self::assertSame(['kind' => 'CALCULATE', 'calculationId' => null], $detail['retry']);
    }

    // ── 4 + 6. Plusieurs anomalies, dont du matériel précis ─────────────

    public function test_every_anomaly_is_listed_and_material_ones_point_to_their_line_and_intervention(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $sn = $this->firm('Smith & Nephew');
        $arthrex = $this->firm('Arthrex');
        $type = $this->type('Suture méniscale');
        $journey = $this->item($sn, 'JOURNEY II');
        $swivel = $this->item($arthrex, 'SwiveLock C Anchor');
        $m = $this->mission($sn, $type, [$journey, $swivel]);
        $itvId = $m->getInterventions()->first()->getId();
        $lineIds = array_map(static fn (MaterialLine $l) => $l->getId(), $m->getMaterialLines()->toArray());

        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        $item = $this->listItem($client, $token, $m);
        self::assertSame(3, $item['financial']['anomalyCount']);
        self::assertSame(
            [['code' => 'MISSING_FIRM_MATERIAL_RATE', 'label' => 'Tarif matériel manquant', 'count' => 2],
             ['code' => 'MISSING_FIRM_INTERVENTION_RATE', 'label' => "Tarif d'intervention manquant", 'count' => 1]],
            $item['financial']['anomalyReasons'],
            'motifs ventilés, le plus fréquent d\'abord',
        );
        $detail = $this->detail($client, $token, $m);
        $this->assertListAndDetailAgree($item, $detail);

        $materials = array_values(array_filter($detail['anomalies'], static fn (array $a) => $a['code'] === 'MISSING_FIRM_MATERIAL_RATE'));
        self::assertCount(2, $materials);
        self::assertSame($lineIds, array_column($materials, 'materialLineId'));
        foreach ($materials as $a) {
            self::assertSame($itvId, $a['missionInterventionId'], "la ligne mène à l'intervention qui la porte");
            self::assertSame('MATERIAL', $a['element']['type']);
            self::assertNotNull($a['element']['reference']);
        }
        self::assertSame(['JOURNEY II', 'SwiveLock C Anchor'], array_column(array_column($materials, 'element'), 'label'));
        self::assertSame([$sn->getName(), $arthrex->getName()], array_column(array_column($materials, 'firm'), 'name'),
            'chaque matériel garde SA firme, pas celle de l\'intervention');
    }

    // ── 7. Échec technique ≠ anomalie métier ────────────────────────────

    public function test_unknown_engine_code_or_empty_payload_is_a_technical_failure_never_a_business_anomaly(): void
    {
        $client = $this->boot();
        $manager = $this->user('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->firm('Arthrex');
        $type = $this->type('Suture méniscale');
        $this->interventionRule($firm, $type);
        $m = $this->mission($firm, $type);

        /** @var AuditService $audit */
        $audit = static::getContainer()->get(AuditService::class);
        $audit->record($this->managed($m), $this->managed($manager), AuditEventType::FINANCIAL_CALCULATION_FAILED, [
            'version' => 1, 'effectiveAt' => '2026-09-10',
            'anomalies' => [['code' => 'SOMETHING_NEW', 'message' => 'SQLSTATE[HY000] internal stack', 'context' => []]],
        ]);
        $this->em->flush();

        $response = $this->request($client, $token, 'GET', "/api/billing/encoding-tracking/missions/{$m->getId()}/financial-anomalies");
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        $detail = $this->json($response);
        self::assertSame('ANOMALY', $detail['state']);
        self::assertCount(1, $detail['anomalies']);
        self::assertSame('CALCULATION_FAILED', $detail['anomalies'][0]['code']);
        self::assertSame('TECHNICAL', $detail['anomalies'][0]['category']);
        self::assertSame('Calcul financier en erreur', $detail['anomalies'][0]['title']);
        $this->assertListAndDetailAgree($this->listItem($client, $token, $m), $detail);

        // Payload vide : toujours une explication, jamais une « Anomalie » muette.
        $audit->record($this->managed($m), $this->managed($manager), AuditEventType::FINANCIAL_CALCULATION_FAILED, ['version' => 1]);
        $this->em->flush();
        $empty = $this->detail($client, $token, $m);
        self::assertCount(1, $empty['anomalies']);
        self::assertSame('TECHNICAL', $empty['anomalies'][0]['category']);
        $this->assertListAndDetailAgree($this->listItem($client, $token, $m), $empty);
    }

    // ── 9. Actualisation après résolution ───────────────────────────────

    public function test_fixing_part_of_the_causes_keeps_the_remaining_ones_then_a_successful_calculation_clears_everything(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Smith & Nephew');
        $type = $this->type('Suture méniscale');
        $anchor = $this->item($firm, 'Q-FIX');
        $m = $this->mission($firm, $type, [$anchor]);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        self::assertSame(2, $this->listItem($client, $token, $m)['financial']['anomalyCount']);

        // Le tarif d'intervention est configuré : l'anomalie est signalée résolue, mais
        // reste affichée tant que le calcul n'a pas été relancé (l'historique ne bouge pas).
        $this->interventionRule($firm, $type);
        $detail = $this->detail($client, $token, $m);
        $byCode = array_column($detail['anomalies'], 'resolved', 'code');
        self::assertTrue($byCode['MISSING_FIRM_INTERVENTION_RATE']);
        self::assertFalse($byCode['MISSING_FIRM_MATERIAL_RATE']);
        self::assertSame('ANOMALY', $detail['state']);

        // Relance : seule l'anomalie restante subsiste.
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $item = $this->listItem($client, $token, $m);
        $detail = $this->detail($client, $token, $m);
        self::assertSame(['MISSING_FIRM_MATERIAL_RATE'], array_column($detail['anomalies'], 'code'));
        $this->assertListAndDetailAgree($item, $detail);

        // Dernière cause corrigée + relance réussie : plus aucune anomalie, nulle part.
        $this->materialRule($firm, $anchor);
        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());
        $item = $this->listItem($client, $token, $m);
        self::assertSame('CALCULATED', $item['financial']['state']);
        self::assertSame(0, $item['financial']['anomalyCount']);
        $detail = $this->detail($client, $token, $m);
        self::assertSame([], $detail['anomalies']);
        self::assertNull($detail['retry']);
        self::assertSame(3, $this->em->getRepository(AuditEvent::class)->count(['mission' => $m->getId(), 'eventType' => [AuditEventType::FINANCIAL_CALCULATION_FAILED, AuditEventType::FINANCIAL_CALCULATION_CREATED]]),
            "les échecs restent dans l'historique : seul l'affichage change");
    }

    public function test_failed_recalculation_offers_a_recalculation_of_the_active_calculation(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type('Suture méniscale');
        $this->interventionRule($firm, $type);
        $m = $this->mission($firm, $type);
        $first = $this->calculate($client, $token, $m);
        self::assertSame(201, $first->getStatusCode());
        $calcId = $this->json($first, 201)['id'];

        // Matériel ajouté après coup, sans tarif : le recalcul échoue, l'ancien calcul reste actif.
        $mission = $this->em->find(Mission::class, $m->getId());
        $this->addMaterial($mission, $mission->getInterventions()->first(), $this->item($firm, 'Agrafe'), $mission->getSurgeon());
        self::assertSame(422, $this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/recalculate")->getStatusCode());

        $detail = $this->detail($client, $token, $m);
        self::assertSame('ANOMALY', $detail['state']);
        self::assertSame(['kind' => 'RECALCULATE', 'calculationId' => $calcId], $detail['retry']);
        $this->assertListAndDetailAgree($this->listItem($client, $token, $m), $detail);
    }

    // ── Permissions ─────────────────────────────────────────────────────

    public function test_detail_is_reserved_to_billing_managers(): void
    {
        $client = $this->boot();
        $firm = $this->firm('Arthrex');
        $m = $this->mission($firm, $this->type('Suture méniscale'));
        $uri = "/api/billing/encoding-tracking/missions/{$m->getId()}/financial-anomalies";

        self::assertSame(401, $this->request($client, null, 'GET', $uri)->getStatusCode());
        self::assertSame(403, $this->request($client, $this->login($client, $this->user('ROLE_INSTRUMENTIST')), 'GET', $uri)->getStatusCode());
        self::assertSame(403, $this->request($client, $this->login($client, $this->user('ROLE_SURGEON')), 'GET', $uri)->getStatusCode());
        self::assertSame(200, $this->request($client, $this->login($client, $this->user('ROLE_ADMIN')), 'GET', $uri)->getStatusCode());
        $manager = $this->login($client, $this->user('ROLE_MANAGER'));
        self::assertSame(200, $this->request($client, $manager, 'GET', $uri)->getStatusCode());
        self::assertSame(404, $this->request($client, $manager, 'GET', '/api/billing/encoding-tracking/missions/999999999/financial-anomalies')->getStatusCode());
    }

    // ── D-138 — règles tarifaires contradictoires ───────────────────────
    // Les fixtures persistent les règles sans PricingRuleWriteService : c'est exactement
    // le cas de données écrites hors application (seule origine possible d'un conflit).

    private function closeRule(PricingRule $rule): void
    {
        $this->em->getConnection()->executeStatement('UPDATE pricing_rule SET active = 0 WHERE id = ?', [$rule->getId()]);
    }

    public function test_contradictory_intervention_rules_become_an_explicit_anomaly_never_a_500(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Smith & Nephew');
        $type = $this->type("Suture d'un ménisque de genou");
        $a = $this->interventionRule($firm, $type, '300.00');
        $b = $this->interventionRule($firm, $type, '350.00');
        $m = $this->mission($firm, $type);
        $itvId = $m->getInterventions()->first()->getId();

        $calc = $this->calculate($client, $token, $m);
        self::assertSame(422, $calc->getStatusCode(), 'anomalie métier, pas une erreur 500 : ' . $calc->getContent());

        $item = $this->listItem($client, $token, $m);
        self::assertSame('ANOMALY', $item['financial']['state']);
        self::assertSame([['code' => 'CONFLICTING_FIRM_INTERVENTION_RATE', 'label' => "Tarifs d'intervention contradictoires", 'count' => 1]], $item['financial']['anomalyReasons']);

        $detail = $this->detail($client, $token, $m);
        $this->assertListAndDetailAgree($item, $detail);
        $anomaly = $detail['anomalies'][0];
        self::assertSame('CONFIGURATION', $anomaly['category']);
        self::assertSame($itvId, $anomaly['missionInterventionId']);
        self::assertSame(['id' => $firm->getId(), 'name' => $firm->getName()], $anomaly['firm']);
        self::assertSame("Suture d'un ménisque de genou", $anomaly['element']['label']);
        self::assertSame([$a->getId(), $b->getId()], array_column($anomaly['conflictingRules'], 'id'));
        self::assertSame(['300.00', '350.00'], array_column($anomaly['conflictingRules'], 'unitPrice'));
        self::assertStringContainsString(sprintf('règle #%d : 300,00 EUR', $a->getId()), $anomaly['explanation']);
        self::assertStringContainsString(sprintf('règle #%d : 350,00 EUR', $b->getId()), $anomaly['explanation']);
        self::assertStringContainsString('ne choisit jamais', $anomaly['explanation']);
        self::assertSame('CONFIGURE_INTERVENTION_RATE', $anomaly['action']['code']);
        self::assertFalse($anomaly['resolved']);

        // Écrans catalogue : le forfait en conflit est signalé, jamais « tarif non configuré ».
        $offering = new FirmServiceOffering();
        $offering->setFirm($this->managed($firm));
        $offering->setInterventionType($this->managed($type));
        $this->em->persist($offering); $this->em->flush();
        $this->created['offerings'][] = $offering->getId();
        $firmOfferings = $this->json($this->request($client, $token, 'GET', '/api/firms/' . $firm->getId() . '/service-offerings'));
        self::assertSame([$a->getId(), $b->getId()], $firmOfferings[0]['pricingConflictRuleIds']);
        $typeOfferings = $this->json($this->request($client, $token, 'GET', '/api/intervention-types/' . $type->getId() . '/offerings'));
        self::assertNull($typeOfferings[0]['forfait']);
        self::assertSame([$a->getId(), $b->getId()], $typeOfferings[0]['pricingConflictRuleIds']);

        // Correction : une règle est clôturée → la cause disparaît, le calcul aboutit au
        // tarif restant, l'anomalie s'efface partout.
        $this->closeRule($a);
        self::assertTrue($this->detail($client, $token, $m)['anomalies'][0]['resolved']);
        $ok = $this->calculate($client, $token, $m);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getContent());
        self::assertSame('CALCULATED', $this->listItem($client, $token, $m)['financial']['state']);
        $line = array_values(array_filter($this->json($ok, 201)['lines'], static fn (array $l) => $l['lineType'] === 'FIRM_INTERVENTION_FEE'))[0];
        self::assertSame('350.00', $line['unitAmount']);
        $resolved = $this->json($this->request($client, $token, 'GET', '/api/firms/' . $firm->getId() . '/service-offerings'));
        self::assertNull($resolved[0]['pricingConflictRuleIds']);
    }

    public function test_contradictory_material_rules_are_located_on_their_material_line(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type('Plastie LCA');
        $this->interventionRule($firm, $type);
        $anchor = $this->item($firm, 'SwiveLock C Anchor');
        $a = $this->materialRule($firm, $anchor, '90.00');
        $b = $this->materialRule($firm, $anchor, '95.00');
        $m = $this->mission($firm, $type, [$anchor]);
        $lineId = $m->getMaterialLines()->first()->getId();

        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $detail = $this->detail($client, $token, $m);
        self::assertCount(1, $detail['anomalies']);
        $anomaly = $detail['anomalies'][0];
        self::assertSame('CONFLICTING_FIRM_MATERIAL_RATE', $anomaly['code']);
        self::assertSame('Tarifs matériel contradictoires', $anomaly['title']);
        self::assertSame($lineId, $anomaly['materialLineId']);
        self::assertSame($m->getInterventions()->first()->getId(), $anomaly['missionInterventionId']);
        self::assertSame('SwiveLock C Anchor', $anomaly['element']['label']);
        self::assertSame([$a->getId(), $b->getId()], array_column($anomaly['conflictingRules'], 'id'));

        // Les écrans catalogue ne tombent plus en 500 : le conflit y est signalé, jamais
        // présenté comme « aucun tarif ».
        $list = $this->json($this->request($client, $token, 'GET', '/api/material-items?firmId=' . $firm->getId()));
        $row = array_values(array_filter($list['items'], static fn (array $i) => $i['id'] === $anchor->getId()))[0];
        self::assertNull($row['currentPrice']);
        self::assertSame([$a->getId(), $b->getId()], $row['pricingConflictRuleIds']);
    }

    public function test_contradictory_instrumentist_rates_block_the_mission_with_an_explicit_anomaly(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type('Plastie LCA');
        $this->interventionRule($firm, $type);
        $m = $this->mission($firm, $type); // un tarif horaire posé par la fixture
        $second = new InstrumentistRate();
        $second->setInstrumentist($this->managed($m)->getInstrumentist());
        $second->setRateType(InstrumentistRateType::HOURLY_RATE);
        $second->setAmount('45.00');
        $second->setCurrency('EUR');
        $second->setValidFrom(new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($second); $this->em->flush();
        $this->created['rates'][] = $second->getId();

        $calc = $this->calculate($client, $token, $m);
        self::assertSame(422, $calc->getStatusCode(), (string) $calc->getContent());
        $anomaly = $this->detail($client, $token, $m)['anomalies'][0];
        self::assertSame('CONFLICTING_INSTRUMENTIST_RATE', $anomaly['code']);
        self::assertSame('INSTRUMENTIST', $anomaly['element']['type']);
        self::assertNull($anomaly['firm']);
        self::assertCount(2, $anomaly['conflictingRules']);
        self::assertStringContainsString('aucune ligne de cette mission', $anomaly['explanation']);
    }

    // ── D-138 — ordre des événements, jamais les horodatages ────────────

    /** Force un même horodatage (et même un horodatage inversé) sur tout l'historique financier. */
    private function forceTimestamps(Mission $m, string $failedAt, string $succeededAt): void
    {
        $c = $this->em->getConnection();
        $c->executeStatement("UPDATE audit_event SET created_at = ? WHERE mission_id = ? AND event_type = 'FINANCIAL_CALCULATION_FAILED'", [$failedAt, $m->getId()]);
        $c->executeStatement("UPDATE audit_event SET created_at = ? WHERE mission_id = ? AND event_type IN ('FINANCIAL_CALCULATION_CREATED','FINANCIAL_CALCULATION_RECALCULATED')", [$succeededAt, $m->getId()]);
        $c->executeStatement('UPDATE financial_calculation SET calculated_at = ? WHERE mission_id = ?', [$succeededAt, $m->getId()]);
    }

    public function test_failure_then_success_is_resolved_whatever_the_timestamps(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type('Plastie LCA');
        $m = $this->mission($firm, $type);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $this->interventionRule($firm, $type);
        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());

        foreach ([['2026-10-08 06:29:04', '2026-10-08 06:29:04'], ['2026-10-08 06:29:05', '2026-10-08 06:29:04']] as [$failed, $succeeded]) {
            $this->forceTimestamps($m, $failed, $succeeded);
            $item = $this->listItem($client, $token, $m);
            self::assertSame('CALCULATED', $item['financial']['state'], "échec puis succès (échec $failed, succès $succeeded)");
            $this->assertListAndDetailAgree($item, $this->detail($client, $token, $m));
        }
    }

    public function test_success_then_failure_stays_an_anomaly_even_in_the_same_second(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type('Plastie LCA');
        $this->interventionRule($firm, $type);
        $m = $this->mission($firm, $type);
        $first = $this->calculate($client, $token, $m);
        self::assertSame(201, $first->getStatusCode());
        $calcId = $this->json($first, 201)['id'];
        $mission = $this->managed($m);
        $this->addMaterial($mission, $mission->getInterventions()->first(), $this->item($firm, 'Agrafe'), $mission->getSurgeon());
        self::assertSame(422, $this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/recalculate")->getStatusCode());

        // Même seconde, puis échec horodaté AVANT le succès (horloges décalées) : l'ordre
        // réel des événements reste « succès puis échec » → toujours ANOMALY.
        foreach ([['2026-10-08 06:29:04', '2026-10-08 06:29:04'], ['2026-10-08 06:29:03', '2026-10-08 06:29:04']] as [$failed, $succeeded]) {
            $this->forceTimestamps($m, $failed, $succeeded);
            $item = $this->listItem($client, $token, $m);
            self::assertSame('ANOMALY', $item['financial']['state'], "succès puis échec (échec $failed, succès $succeeded)");
            $detail = $this->detail($client, $token, $m);
            $this->assertListAndDetailAgree($item, $detail);
            self::assertSame(['MISSING_FIRM_MATERIAL_RATE'], array_column($detail['anomalies'], 'code'));
            self::assertSame(['kind' => 'RECALCULATE', 'calculationId' => $calcId], $detail['retry']);
        }
    }
}

