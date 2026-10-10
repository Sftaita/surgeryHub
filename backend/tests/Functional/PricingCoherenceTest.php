<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialLineType;
use App\Enum\InstrumentistRateType;
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-141 — cohérence Catalogue (« Prestations ») ↔ moteur financier ↔ « Suivi des
 * encodages » ↔ « Facturation firmes ». Contre une vraie base et via HTTP, données
 * fictives : les dix cas de régression tarifaires, puis le scénario réel de la mission
 * #1040 (01/10/2026, Smith & Nephew + Arthrex, 7 interventions, 12 lignes de matériel).
 *
 * Invariants vérifiés partout : « Pas de forfait » (feeApplicable=false) n'est jamais un
 * tarif manquant ni un 0 € ; un tarif manquant n'est jamais un 0 € ; le tarif d'une firme
 * n'est jamais appliqué à une autre ; une anomalie bloque tout le calcul (aucun montant
 * partiel) ; un calcul verrouillé ne change jamais.
 */
final class PricingCoherenceTest extends WebTestCase
{
    private const PASSWORD = 'Coherence123!';

    private EntityManagerInterface $em;
    private ?Hospital $site = null;
    private ?User $manager = null;
    private array $created = [
        'missions' => [], 'offerings' => [], 'rules' => [], 'rates' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
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
                foreach ($missionIds as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
                $this->em->flush();
            }
            foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $this->created['users']]) as $evt) { $this->em->remove($evt); }
            $this->em->flush();
            // Les règles remplacées pointent les unes vers les autres via l'audit seulement.
            foreach ($this->created['firms'] as $firmId) {
                foreach ($this->em->getRepository(PricingRule::class)->findBy(['firm' => $firmId]) as $r) { $this->em->remove($r); }
            }
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

    private function boot(): array
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->manager = $this->user('ROLE_MANAGER');
        return [$client, $this->login($client, $this->manager)];
    }

    private function user(string $role, string $first = 'Test', string $last = 'Coherence'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('coherence-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($first);
        $u->setLastname($last);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function managed(object $entity): object
    {
        return $this->em->contains($entity) ? $entity : $this->em->find($this->em->getClassMetadata($entity::class)->getName(), $entity->getId());
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function request(KernelBrowser $client, string $token, string $method, string $uri, array $body = []): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: json_encode($body ?: new \stdClass()));
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

    private function type(string $code, string $label): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(3)));
        $t->setLabel($label);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function offering(Firm $firm, InterventionType $type, bool $feeApplicable = true, bool $delegate = false): FirmServiceOffering
    {
        $o = new FirmServiceOffering();
        $o->setFirm($this->managed($firm));
        $o->setInterventionType($this->managed($type));
        $o->setFeeApplicable($feeApplicable);
        $o->setRepresentativePresenceRelevant($delegate);
        $o->setRepresentativeSuppressesInterventionFee($delegate);
        $o->setRepresentativeSuppressesOwnMaterialFees($delegate);
        $this->em->persist($o); $this->em->flush();
        $this->created['offerings'][] = $o->getId();
        return $o;
    }

    private function item(Firm $firm, string $label, MaterialBillingStatus $status = MaterialBillingStatus::UNSPECIFIED): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($this->managed($firm));
        $i->setLabel($label);
        $i->setUnit('1');
        $i->setReferenceCode('REF-' . bin2hex(random_bytes(3)));
        $i->setBillingStatus($status);
        $this->em->persist($i); $this->em->flush();
        $this->created['items'][] = $i->getId();
        return $i;
    }

    private function rule(Firm $firm, ?InterventionType $type, ?MaterialItem $item, string $price, ?string $from = null, ?string $to = null): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($this->managed($firm));
        $r->setRuleType($type !== null ? PricingRuleType::INTERVENTION_FEE : PricingRuleType::MATERIAL_FEE);
        $r->setInterventionType($type !== null ? $this->managed($type) : null);
        $r->setMaterialItem($item !== null ? $this->managed($item) : null);
        $r->setUnitPrice($price);
        $r->setValidFrom($from !== null ? new \DateTimeImmutable($from) : null);
        $r->setValidTo($to !== null ? new \DateTimeImmutable($to) : null);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function site(): Hospital
    {
        if ($this->site === null) {
            $this->site = new Hospital();
            $this->site->setName('COH-Delta-' . bin2hex(random_bytes(2)));
            $this->em->persist($this->site); $this->em->flush();
            $this->created['sites'][] = $this->site->getId();
        }
        return $this->site;
    }

    /** Mission VALIDATED 13:00–18:00, instrumentiste avec un tarif horaire actif. */
    private function mission(string $day = '2026-09-10'): Mission
    {
        $surgeon = $this->user('ROLE_SURGEON', 'Arnaud', 'Deltour-Test');
        $instrumentist = $this->user('ROLE_INSTRUMENTIST', 'Sophie', 'Collette-Test');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $start = new \DateTimeImmutable($day . ' 13:00:00');
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($this->managed($this->site()));
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($start);
        $m->setEndAt($start->modify('+5 hours'));
        $m->setStatus(MissionStatus::VALIDATED);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function intervention(Mission $m, Firm $firm, InterventionType $type, ?bool $delegatePresent = null): MissionIntervention
    {
        $m = $this->managed($m);
        $type = $this->managed($type);
        $itv = new MissionIntervention();
        $itv->setMission($m);
        $itv->setCode($type->getCode());
        $itv->setLabel($type->getLabel());
        $itv->setInterventionType($type);
        $itv->setPrimaryFirm($this->managed($firm));
        $itv->setRepresentativePresent($delegatePresent);
        $this->em->persist($itv); $this->em->flush();
        $m->getInterventions()->add($itv);
        return $itv;
    }

    private function material(Mission $m, MissionIntervention $itv, MaterialItem $item, string $qty = '1.00'): MaterialLine
    {
        $m = $this->managed($m);
        $ml = new MaterialLine();
        $ml->setMission($m);
        $ml->setMissionIntervention($this->managed($itv));
        $ml->setItem($this->managed($item));
        $ml->setQuantity($qty);
        $ml->setCreatedBy($this->managed($m->getSurgeon()));
        $this->em->persist($ml); $this->em->flush();
        $m->getMaterialLines()->add($ml);
        return $ml;
    }

    /** Remplacement versionné d'un tarif (D-072) via l'API du catalogue ; retourne l'id de la nouvelle règle. */
    private function replaceRule(KernelBrowser $client, string $token, Firm $firm, PricingRule $rule, string $price, \DateTimeImmutable $from): int
    {
        $data = $this->json($this->request($client, $token, 'POST', "/api/firms/{$firm->getId()}/pricing-rules/{$rule->getId()}/replace",
            ['unitPrice' => $price, 'effectiveFrom' => $from->format('Y-m-d')]), 201);
        $id = $data['newRule']['id'] ?? $data['id'] ?? null;
        self::assertIsInt($id, json_encode($data));
        return $id;
    }

    private function calculate(KernelBrowser $client, string $token, Mission $m): Response
    {
        return $this->request($client, $token, 'POST', "/api/missions/{$m->getId()}/financial-calculations");
    }

    private function detail(KernelBrowser $client, string $token, Mission $m): array
    {
        return $this->json($this->request($client, $token, 'GET', "/api/billing/encoding-tracking/missions/{$m->getId()}/financial-anomalies"));
    }

    /** @return array{rows: array<string, array>, anomalies: list<array>, bulkActions: array} lignes de CETTE mission, par clé */
    private function worklist(KernelBrowser $client, string $token, Mission $m): array
    {
        $day = $this->managed($m)->getStartAt()->format('Y-m-d');
        $data = $this->json($this->request($client, $token, 'GET', "/api/firm-billing/worklist?from={$day}&to={$day}"));
        $rows = [];
        foreach ($data['rows'] as $row) {
            if ($row['mission']['id'] === $m->getId()) {
                $rows[$row['key']] = $row;
            }
        }
        return [
            'rows' => $rows,
            'anomalies' => array_values(array_filter($data['anomalies'], static fn (array $a) => $a['mission']['id'] === $m->getId())),
            'bulkActions' => $data['bulkActions'],
        ];
    }

    /** Tuiles de la worklist pour le jour de la mission, restreintes aux firmes du test. */
    private function worklistSummary(KernelBrowser $client, string $token, Mission $m, Firm ...$firms): array
    {
        $day = $this->managed($m)->getStartAt()->format('Y-m-d');
        $query = implode('', array_map(static fn (Firm $f) => '&firmIds[]=' . $f->getId(), $firms));
        return $this->json($this->request($client, $token, 'GET', "/api/firm-billing/worklist?from={$day}&to={$day}{$query}"))['summary'];
    }

    /** @return FinancialCalculationLine[] lignes FIRM du calcul actif */
    private function firmLines(Mission $m): array
    {
        $this->em->clear();
        $calc = $this->em->getRepository(FinancialCalculation::class)->findOneBy(
            ['mission' => $m->getId(), 'status' => [FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED]],
            ['version' => 'DESC'],
        );
        self::assertNotNull($calc, 'aucun calcul actif');
        return array_values(array_filter($calc->getLines()->toArray(), static fn (FinancialCalculationLine $l) => $l->getBeneficiaryFirm() !== null));
    }

    private function lineFor(array $lines, MissionIntervention|MaterialLine $source): ?FinancialCalculationLine
    {
        foreach ($lines as $l) {
            if ($source instanceof MissionIntervention && $l->getMissionIntervention()?->getId() === $source->getId()) return $l;
            if ($source instanceof MaterialLine && $l->getMaterialLine()?->getId() === $source->getId()) return $l;
        }
        return null;
    }

    // ── 1. Forfait positif valide ──────────────────────────────────────────

    public function test_1_positive_fee_is_resolved_and_billed(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $this->offering($sn, $lca);
        $rule = $this->rule($sn, $lca, null, '135.00');
        $m = $this->mission();
        $itv = $this->intervention($m, $sn, $lca);

        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());
        $line = $this->lineFor($this->firmLines($m), $itv);
        self::assertNotNull($line);
        self::assertSame('135.00', $line->getTotalAmount());
        self::assertSame($rule->getId(), $line->getPricingRule()->getId());
    }

    // ── 2. Absence volontaire de forfait (« Pas de forfait ») ──────────────

    public function test_2_voluntary_no_fee_is_never_a_missing_rate_nor_a_zero_line(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $men = $this->type('SUT-MEN', "Suture d'un ménisque de genou");
        $this->offering($sn, $men, feeApplicable: false);
        $m = $this->mission();
        $itv = $this->intervention($m, $sn, $men);

        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode(), 'aucune anomalie');
        self::assertNull($this->lineFor($this->firmLines($m), $itv), 'aucune ligne, pas même à 0 €');
        $row = $this->worklist($client, $token, $m)['rows']['MISSION_INTERVENTION:' . $itv->getId()];
        self::assertSame('FEE_NOT_APPLICABLE', $row['reasonCode']);
        self::assertSame('NOT_BILLABLE', $row['billingStatus']);
        self::assertNull($row['amount']);
    }

    // ── 3. Tarif réellement absent ────────────────────────────────────────

    public function test_3_really_missing_rate_blocks_and_says_why_and_is_never_zero(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $men = $this->type('SUT-MEN', "Suture d'un ménisque de genou");
        $this->offering($sn, $men, feeApplicable: true);
        $m = $this->mission();
        $itv = $this->intervention($m, $sn, $men);

        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        self::assertSame(0, $this->em->getRepository(FinancialCalculation::class)->count(['mission' => $m->getId()]), 'aucun montant enregistré');
        $a = $this->detail($client, $token, $m)['anomalies'][0];
        self::assertSame('MISSING_FIRM_INTERVENTION_RATE', $a['code']);
        self::assertSame('CONFIGURATION', $a['category']);
        self::assertStringContainsString('configurée « avec forfait »', $a['explanation']);
        self::assertStringContainsString('« Pas de forfait »', $a['explanation'], "l'action propose les deux issues légitimes");
        self::assertSame([], $a['targetRules']);
        self::assertFalse($a['resolved']);
        self::assertSame('ANOMALY', $a['currentResolution']['kind']);
        self::assertSame($itv->getId(), $a['missionInterventionId']);
    }

    // ── 4. Tarif applicable seulement à certaines dates ───────────────────

    public function test_4_rate_valid_only_on_some_dates_is_used_inside_its_period_and_explained_outside(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $ptg = $this->type('PTG', 'Prothèse totale de genou');
        $rule = $this->rule($sn, $ptg, null, '191.00', '2026-01-01', '2026-09-01'); // validTo exclusif

        $inside = $this->mission('2026-08-31');
        $itvInside = $this->intervention($inside, $sn, $ptg);
        self::assertSame(201, $this->calculate($client, $token, $inside)->getStatusCode(), 'dernier jour couvert');
        self::assertSame('191.00', $this->lineFor($this->firmLines($inside), $itvInside)->getTotalAmount());

        $outside = $this->mission('2026-09-10');
        $this->intervention($outside, $sn, $ptg);
        self::assertSame(422, $this->calculate($client, $token, $outside)->getStatusCode());
        $a = $this->detail($client, $token, $outside)['anomalies'][0];
        self::assertSame('MISSING_FIRM_INTERVENTION_RATE', $a['code']);
        self::assertCount(1, $a['targetRules']);
        self::assertSame($rule->getId(), $a['targetRules'][0]['id']);
        self::assertStringContainsString("aucun de ses tarifs ne s'applique au 10/09/2026", $a['explanation']);
        self::assertStringContainsString('du 01/01/2026 au 31/08/2026', $a['explanation'], 'validTo exclusif affiché comme dernier jour couvert');
    }

    // ── 5. Même intervention, deux firmes, deux tarifs ────────────────────

    public function test_5_same_intervention_at_two_firms_never_borrows_the_other_firm_rate(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $arthrex = $this->firm('Arthrex');
        $multi = $this->type('MULTI-LIG-GEN', 'Plastie du LCA associée à un ou plusieurs autres ligaments');
        $snRule = $this->rule($sn, $multi, null, '135.00');

        $m = $this->mission();
        $atArthrex = $this->intervention($m, $arthrex, $multi);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode(), 'le tarif Smith & Nephew ne vaut jamais pour Arthrex');
        $a = $this->detail($client, $token, $m)['anomalies'][0];
        self::assertSame('MISSING_FIRM_INTERVENTION_RATE', $a['code']);
        self::assertSame($arthrex->getId(), $a['firm']['id']);
        self::assertNotContains($snRule->getId(), array_column($a['targetRules'], 'id'));

        $arthrexRule = $this->rule($arthrex, $multi, null, '150.00');
        $atSn = $this->intervention($m, $sn, $multi);
        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());
        $lines = $this->firmLines($m);
        self::assertSame($arthrexRule->getId(), $this->lineFor($lines, $atArthrex)->getPricingRule()->getId());
        self::assertSame('150.00', $this->lineFor($lines, $atArthrex)->getTotalAmount());
        self::assertSame($snRule->getId(), $this->lineFor($lines, $atSn)->getPricingRule()->getId());
        self::assertSame('135.00', $this->lineFor($lines, $atSn)->getTotalAmount());
    }

    // ── 6. Intervention déléguée avec neutralisation ──────────────────────

    public function test_6_delegate_present_neutralises_fee_and_own_material_but_not_other_firm_material(): void
    {
        [$client, $token] = $this->boot();
        $arthrex = $this->firm('Arthrex');
        $sn = $this->firm('Smith & Nephew');
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $this->offering($arthrex, $lca, delegate: true);
        $this->rule($arthrex, $lca, null, '135.00');
        $swivel = $this->item($arthrex, 'SwiveLock C Anchor');
        $this->rule($arthrex, null, $swivel, '30.00');
        $fastfix = $this->item($sn, 'Fast-Fix');
        $this->rule($sn, null, $fastfix, '17.00');

        $m = $this->mission();
        $itv = $this->intervention($m, $arthrex, $lca, delegatePresent: true);
        $own = $this->material($m, $itv, $swivel);
        $other = $this->material($m, $itv, $fastfix, '2.00');

        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());
        $lines = $this->firmLines($m);
        $fee = $this->lineFor($lines, $itv);
        self::assertSame('135.00', $fee->getGrossAmount(), 'le brut reste le tarif résolu');
        self::assertSame('0.00', $fee->getTotalAmount());
        self::assertNotNull($fee->getSnapshot()['adjustmentReasonSnapshot']);
        self::assertSame('0.00', $this->lineFor($lines, $own)->getTotalAmount());
        self::assertSame('34.00', $this->lineFor($lines, $other)->getTotalAmount(), "aucune contamination inter-firme");
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$fee->getFinancialCalculation()->getId()}/approve"));
        self::assertSame('REPRESENTATIVE_PRESENT', $this->worklist($client, $token, $m)['rows']['MISSION_INTERVENTION:' . $itv->getId()]['reasonCode']);
    }

    // ── 7. Matériel facturable ────────────────────────────────────────────

    public function test_7_billable_material_is_valued_quantity_times_rate(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $men = $this->type('SUT-MEN', "Suture d'un ménisque de genou");
        $this->offering($sn, $men, feeApplicable: false);
        $fastfix = $this->item($sn, 'Fast-Fix', MaterialBillingStatus::BILLABLE);
        $this->rule($sn, null, $fastfix, '17.00');
        $m = $this->mission();
        $itv = $this->intervention($m, $sn, $men);
        $ml = $this->material($m, $itv, $fastfix, '7.00');

        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode(), 'pas de forfait, mais le matériel reste facturé');
        $lines = $this->firmLines($m);
        self::assertSame(FinancialLineType::FIRM_MATERIAL_FEE, $this->lineFor($lines, $ml)->getLineType());
        self::assertSame('119.00', $this->lineFor($lines, $ml)->getTotalAmount());
        self::assertNull($this->lineFor($lines, $itv));
    }

    // ── 8. Modification de tarif après un premier calcul ──────────────────

    public function test_8_rate_change_after_a_calculation_never_rewrites_it_and_applies_only_from_its_effective_date(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $current = $this->rule($sn, $lca, null, '135.00');

        $past = $this->mission((new \DateTimeImmutable('today'))->modify('-5 days')->format('Y-m-d'));
        $pastItv = $this->intervention($past, $sn, $lca);
        $future = $this->mission((new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d'));
        $futureItv = $this->intervention($future, $sn, $lca);
        $pastCalcId = $this->json($this->calculate($client, $token, $past), 201)['id'];
        $futureCalcId = $this->json($this->calculate($client, $token, $future), 201)['id'];

        $new = $this->replaceRule($client, $token, $sn, $current, '150.00', (new \DateTimeImmutable('today'))->modify('+10 days'));

        // Les calculs existants ne bougent pas (append-only, D-073).
        self::assertSame('135.00', $this->lineFor($this->firmLines($past), $pastItv)->getTotalAmount());
        self::assertSame('135.00', $this->lineFor($this->firmLines($future), $futureItv)->getTotalAmount());

        // Recalcul explicite : la date de la prestation décide du tarif.
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$pastCalcId}/recalculate"), 201);
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$futureCalcId}/recalculate"), 201);
        self::assertSame('135.00', $this->lineFor($this->firmLines($past), $pastItv)->getTotalAmount(), 'avant la date d\'effet : ancien tarif');
        $futureLine = $this->lineFor($this->firmLines($future), $futureItv);
        self::assertSame('150.00', $futureLine->getTotalAmount());
        self::assertSame($new, $futureLine->getPricingRule()->getId());
        $this->em->clear();
        $old = $this->em->find(FinancialCalculation::class, $futureCalcId);
        self::assertSame(FinancialCalculationStatus::SUPERSEDED, $old->getStatus());
        self::assertSame('135.00', array_values(array_filter($old->getLines()->toArray(), static fn ($l) => $l->getBeneficiaryFirm() !== null))[0]->getTotalAmount(), "l'ancienne version est conservée telle quelle");
    }

    // ── 9. Calcul bloqué puis corrigé ─────────────────────────────────────

    public function test_9_blocked_calculation_then_fixed_is_announced_then_recalculated_with_traceability(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $lcaRule = $this->rule($sn, $lca, null, '135.00');
        $qfix = $this->item($sn, 'Q-FIX');
        $m = $this->mission();
        $itv = $this->intervention($m, $sn, $lca);
        $ml = $this->material($m, $itv, $qfix);

        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $wl = $this->worklist($client, $token, $m);
        $lcaRow = $wl['rows']['MISSION_INTERVENTION:' . $itv->getId()];
        self::assertSame('CALCULATION_BLOCKED', $lcaRow['reasonCode']);
        self::assertStringContainsString('135,00 EUR (règle #' . $lcaRule->getId(), $lcaRow['reasonDetail'], 'le tarif résolu est dit');
        self::assertStringContainsString('tarif matériel manquant', $lcaRow['reasonDetail'], 'et ce qui bloque');
        self::assertNull($lcaRow['amount'], 'jamais un montant non calculé');
        $matRow = $wl['rows']['MATERIAL_LINE:' . $ml->getId()];
        self::assertSame('MISSING_FIRM_MATERIAL_RATE', $matRow['reasonCode']);
        self::assertStringContainsString('Aucune décision de facturation', $matRow['reasonDetail']);
        self::assertNotContains($m->getId(), $wl['bulkActions']['recalculateFixed']);

        // Correction catalogue (HTTP) : matériel compris dans le forfait → « non facturable ».
        $this->json($this->request($client, $token, 'PATCH', "/api/material-items/{$qfix->getId()}", ['billingStatus' => 'NOT_BILLABLE']));
        $detail = $this->detail($client, $token, $m);
        self::assertTrue($detail['anomalies'][0]['resolved']);
        self::assertSame('MATERIAL_NOT_BILLABLE', $detail['anomalies'][0]['currentResolution']['kind']);
        self::assertTrue($detail['recalculation']['wouldSucceed']);
        $wl = $this->worklist($client, $token, $m);
        self::assertSame('FIXED_PENDING_RECALCULATION', $wl['rows']['MISSION_INTERVENTION:' . $itv->getId()]['reasonCode']);
        self::assertSame('MATERIAL_NOT_BILLABLE', $wl['rows']['MATERIAL_LINE:' . $ml->getId()]['reasonCode']);
        self::assertContains($m->getId(), $wl['bulkActions']['recalculateFixed']);
        $summary = $this->worklistSummary($client, $token, $m, $sn);
        self::assertSame(0, $summary['anomalyCount'], '« À corriger » ne compte plus une cause corrigée');
        self::assertSame(1, $summary['resolvedAnomalyCount'], 'elle est annoncée à recalculer');

        // Relance contrôlée (action groupée existante) : calcul complet, échec conservé.
        $run = $this->json($this->request($client, $token, 'POST', '/api/firm-billing/calculations', ['missionIds' => [$m->getId()]]));
        self::assertSame(1, $run['calculated']);
        self::assertSame('135.00', $this->lineFor($this->firmLines($m), $itv)->getTotalAmount());
        self::assertSame('CALCULATED', $this->detail($client, $token, $m)['state']);
        self::assertSame(1, $this->em->getRepository(AuditEvent::class)->count(['mission' => $m->getId(), 'eventType' => \App\Enum\AuditEventType::FINANCIAL_CALCULATION_FAILED]));
    }

    // ── 10. Calcul verrouillé (facture émise) inchangé ────────────────────

    public function test_10_locked_calculation_never_changes_after_a_rate_change(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $current = $this->rule($sn, $lca, null, '135.00');
        $m = $this->mission((new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d'));
        $itv = $this->intervention($m, $sn, $lca);
        $calcId = $this->json($this->calculate($client, $token, $m), 201)['id'];
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/approve"));
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/lock"));

        $this->replaceRule($client, $token, $sn, $current, '150.00', (new \DateTimeImmutable('today'))->modify('+10 days'));

        $recalc = $this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/recalculate");
        self::assertNotSame(201, $recalc->getStatusCode(), (string) $recalc->getContent());
        $line = $this->lineFor($this->firmLines($m), $itv);
        self::assertSame('135.00', $line->getTotalAmount());
        self::assertSame(FinancialCalculationStatus::LOCKED, $line->getFinancialCalculation()->getStatus());
        $row = $this->worklist($client, $token, $m)['rows']['MISSION_INTERVENTION:' . $itv->getId()];
        self::assertSame('135.00', $row['amount'], 'la worklist montre le montant figé, jamais le nouveau tarif');
    }

    // ── Scénario #1040 (données fictives) ─────────────────────────────────

    /**
     * Reproduction de la mission #1040 : 7 interventions (2 sutures méniscales S&N, LCA S&N,
     * LCA multiligamentaire Arthrex avec délégué absent, 2 PTG S&N, MPFL Arthrex) et 12 lignes
     * de matériel, dont 8 articles sans décision de facturation. Chronologie réelle : échec
     * (11 anomalies), puis « Pas de forfait » posé sur la suture méniscale, puis la
     * prestation MPFL Arthrex devient « délégué pertinent » après la validation de
     * l'encodage — une cause que l'échec audité ne pouvait pas contenir.
     */
    public function test_mission_1040_scenario_end_to_end(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $arthrex = $this->firm('Arthrex');
        $men = $this->type('SUT-MEN', "Suture d'un ménisque de genou");
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $multi = $this->type('MULTI-LIG-GEN', 'Plastie du LCA associée à un ou plusieurs autres ligaments');
        $ptg = $this->type('PTG', 'Prothèse totale de genou');
        $mpfl = $this->type('MPFL', 'Plastie du ligament fémoro-patellaire médian du genou');

        $menOffering = $this->offering($sn, $men, feeApplicable: true);
        $this->offering($sn, $lca);
        $this->offering($sn, $ptg);
        $this->offering($arthrex, $multi, delegate: true);
        $lcaRule = $this->rule($sn, $lca, null, '135.00');
        $snMultiRule = $this->rule($sn, $multi, null, '135.00');
        $arthrexMultiRule = $this->rule($arthrex, $multi, null, '135.00');
        $this->rule($sn, $ptg, null, '191.00');
        $this->rule($arthrex, $mpfl, null, '128.00');

        $fastfix = $this->item($sn, 'Fast-Fix', MaterialBillingStatus::BILLABLE);
        $this->rule($sn, null, $fastfix, '17.00');
        $journey = $this->item($sn, 'JOURNEY II Total Knee Arthroplasty');
        $ubAdj = $this->item($sn, 'Ultrabutton Adjustable Button');
        $ubTib = $this->item($sn, 'Ultrabutton Tib ajustable fixation');
        $qfix = $this->item($sn, 'Q-FIX');
        $swivel = $this->item($arthrex, 'SwiveLock C Anchor');
        $agrafe = $this->item($arthrex, 'Agrafe ligamentaire');
        $fibertak = $this->item($arthrex, 'FiberTak Soft Anchor');
        $vis = $this->item($arthrex, 'Vis Bio-Compression');

        $m = $this->mission('2026-10-01');
        $men1 = $this->intervention($m, $sn, $men);
        $men2 = $this->intervention($m, $sn, $men);
        $lcaItv = $this->intervention($m, $sn, $lca);
        $multiItv = $this->intervention($m, $arthrex, $multi, delegatePresent: false);
        $ptg1 = $this->intervention($m, $sn, $ptg);
        $ptg2 = $this->intervention($m, $sn, $ptg);
        $mpflItv = $this->intervention($m, $arthrex, $mpfl);
        $lines = [
            $this->material($m, $men1, $fastfix, '4.00'),
            $this->material($m, $ptg1, $journey),
            $this->material($m, $ptg2, $journey),
            $this->material($m, $men2, $fastfix, '7.00'),
            $this->material($m, $lcaItv, $fastfix, '2.00'),
            $ubAdjLine = $this->material($m, $lcaItv, $ubAdj),
            $ubTibLine = $this->material($m, $lcaItv, $ubTib),
            $swivelLine = $this->material($m, $lcaItv, $swivel),
            $this->material($m, $multiItv, $agrafe),
            $qfixLine = $this->material($m, $lcaItv, $qfix),
            $this->material($m, $mpflItv, $fibertak, '3.00'),
            $this->material($m, $mpflItv, $vis),
        ];
        self::assertCount(12, $lines);

        // ── Échec : 2 forfaits manquants + 9 matériels sans décision = 11 anomalies.
        $fail = $this->json($this->calculate($client, $token, $m), 422);
        self::assertCount(11, $fail['error']['violations']);
        self::assertSame(0, $this->em->getRepository(FinancialCalculation::class)->count(['mission' => $m->getId()]), 'une anomalie bloque tout : aucun montant partiel');

        $detail = $this->detail($client, $token, $m);
        self::assertSame('ANOMALY', $detail['state']);
        self::assertCount(11, $detail['anomalies']);
        self::assertSame([], $detail['newAnomalies']);
        self::assertSame(['wouldSucceed' => false, 'remainingAnomalyCount' => 11, 'referenceDate' => '2026-10-01'], $detail['recalculation']);
        $byLine = [];
        foreach ($detail['anomalies'] as $a) {
            $byLine[$a['materialLineId'] ?? 'itv:' . $a['missionInterventionId']] = $a;
        }
        foreach ([$ubAdjLine, $ubTibLine, $qfixLine] as $l) {
            $a = $byLine[$l->getId()];
            self::assertSame('MISSING_FIRM_MATERIAL_RATE', $a['code']);
            self::assertSame($sn->getId(), $a['firm']['id']);
            self::assertSame($lcaItv->getId(), $a['missionInterventionId'], 'situé dans l\'intervention 3/7');
            self::assertStringContainsString('Aucune décision de facturation', $a['explanation']);
        }
        self::assertSame($arthrex->getId(), $byLine[$swivelLine->getId()]['firm']['id'], 'SwiveLock reste Arthrex, même dans une intervention Smith & Nephew');
        self::assertStringContainsString('configurée « avec forfait »', $byLine['itv:' . $men1->getId()]['explanation']);

        // Facturation firmes : LCA S&N et multiligamentaire Arthrex sont corrects, bloqués par les autres.
        $wl = $this->worklist($client, $token, $m);
        $lcaRow = $wl['rows']['MISSION_INTERVENTION:' . $lcaItv->getId()];
        self::assertSame('CALCULATION_BLOCKED', $lcaRow['reasonCode']);
        self::assertStringContainsString('règle #' . $lcaRule->getId(), $lcaRow['reasonDetail']);
        self::assertStringContainsString('bloqué par 11 autres anomalies', $lcaRow['reasonDetail']);
        $multiRow = $wl['rows']['MISSION_INTERVENTION:' . $multiItv->getId()];
        self::assertSame('CALCULATION_BLOCKED', $multiRow['reasonCode']);
        self::assertStringContainsString('règle #' . $arthrexMultiRule->getId(), $multiRow['reasonDetail'], 'la règle Arthrex');
        self::assertStringNotContainsString('règle #' . $snMultiRule->getId() . ',', $multiRow['reasonDetail'], 'jamais celle de Smith & Nephew');
        self::assertSame('MISSING_FIRM_INTERVENTION_RATE', $wl['rows']['MISSION_INTERVENTION:' . $men1->getId()]['reasonCode']);

        // ── Le manager pose « Pas de forfait » sur la suture méniscale (Catalogue, HTTP).
        $this->json($this->request($client, $token, 'PATCH', "/api/firms/{$sn->getId()}/service-offerings/{$menOffering->getId()}", ['feeApplicable' => false]));
        // … et la prestation MPFL Arthrex devient « délégué pertinent » après validation.
        $this->offering($arthrex, $mpfl, delegate: true);

        $detail = $this->detail($client, $token, $m);
        self::assertCount(11, $detail['anomalies'], "l'échec audité reste affiché tel quel");
        foreach ([$men1, $men2] as $itv) {
            $a = array_values(array_filter($detail['anomalies'], static fn ($x) => $x['missionInterventionId'] === $itv->getId() && $x['materialLineId'] === null))[0];
            self::assertTrue($a['resolved'], '« Pas de forfait » corrige le tarif manquant');
            self::assertSame('FEE_NOT_APPLICABLE', $a['currentResolution']['kind']);
            self::assertStringContainsString('Pas de forfait', $a['currentResolution']['label']);
        }
        self::assertCount(1, $detail['newAnomalies']);
        self::assertSame('MISSING_REPRESENTATIVE_PRESENCE_ANSWER', $detail['newAnomalies'][0]['code']);
        self::assertSame($mpflItv->getId(), $detail['newAnomalies'][0]['missionInterventionId']);
        self::assertTrue($detail['newAnomalies'][0]['detectedAfterFailure']);
        self::assertSame(['wouldSucceed' => false, 'remainingAnomalyCount' => 10, 'referenceDate' => '2026-10-01'], $detail['recalculation']);

        $wl = $this->worklist($client, $token, $m);
        $menRow = $wl['rows']['MISSION_INTERVENTION:' . $men1->getId()];
        self::assertSame('FEE_NOT_APPLICABLE', $menRow['reasonCode'], 'Factures Firmes = Catalogue : « Aucun forfait prévu »');
        self::assertStringContainsString('la configuration a changé depuis', $menRow['reasonDetail']);
        self::assertSame('MISSING_REPRESENTATIVE_PRESENCE_ANSWER', $wl['rows']['MISSION_INTERVENTION:' . $mpflItv->getId()]['reasonCode'], 'la cause réelle, pas « Calcul bloqué »');
        self::assertContains('MISSING_REPRESENTATIVE_PRESENCE_ANSWER', array_column($wl['anomalies'], 'code'));
        // « À corriger » = ce qui bloque ENCORE, comme le pronostic du Suivi (10) ; les 2 corrigées à part.
        $summary = $this->worklistSummary($client, $token, $m, $sn, $arthrex);
        self::assertSame(10, $summary['anomalyCount']);
        self::assertSame(2, $summary['resolvedAnomalyCount']);

        // ── Correction des causes restantes.
        foreach ([$journey, $ubAdj, $ubTib, $qfix] as $included) {
            $this->json($this->request($client, $token, 'PATCH', "/api/material-items/{$included->getId()}", ['billingStatus' => 'NOT_BILLABLE']));
        }
        foreach ([[$swivel, '30.00'], [$agrafe, '12.00'], [$fibertak, '25.00'], [$vis, '40.00']] as [$item, $price]) {
            $this->json($this->request($client, $token, 'POST', "/api/firms/{$arthrex->getId()}/pricing-rules", ['ruleType' => 'MATERIAL_FEE', 'materialItemId' => $item->getId(), 'unitPrice' => $price]), 201);
        }
        $this->managed($mpflItv)->setRepresentativePresent(false);
        $this->em->flush();

        $detail = $this->detail($client, $token, $m);
        self::assertTrue($detail['recalculation']['wouldSucceed']);
        self::assertSame([], $detail['newAnomalies']);
        self::assertSame([true], array_values(array_unique(array_column($detail['anomalies'], 'resolved'))));
        $wl = $this->worklist($client, $token, $m);
        self::assertSame('FIXED_PENDING_RECALCULATION', $wl['rows']['MISSION_INTERVENTION:' . $lcaItv->getId()]['reasonCode']);
        self::assertContains($m->getId(), $wl['bulkActions']['recalculateFixed']);
        $summary = $this->worklistSummary($client, $token, $m, $sn, $arthrex);
        self::assertSame(0, $summary['anomalyCount'], 'plus rien ne bloque');
        self::assertSame(11, $summary['resolvedAnomalyCount'], 'les 11 causes auditées sont corrigées, à recalculer');

        // ── Recalcul : état cohérent et traçable, mêmes informations sur les trois écrans.
        self::assertSame(201, $this->calculate($client, $token, $m)->getStatusCode());
        $firmLines = $this->firmLines($m);
        self::assertNull($this->lineFor($firmLines, $men1));
        self::assertNull($this->lineFor($firmLines, $men2));
        self::assertSame('135.00', $this->lineFor($firmLines, $lcaItv)->getTotalAmount());
        self::assertSame($arthrexMultiRule->getId(), $this->lineFor($firmLines, $multiItv)->getPricingRule()->getId());
        self::assertSame('128.00', $this->lineFor($firmLines, $mpflItv)->getTotalAmount());
        self::assertSame('119.00', $this->lineFor($firmLines, $lines[3])->getTotalAmount());
        self::assertNull($this->lineFor($firmLines, $qfixLine), 'non facturable : aucune ligne');
        self::assertSame('75.00', $this->lineFor($firmLines, $lines[10])->getTotalAmount());

        $detail = $this->detail($client, $token, $m);
        self::assertSame('CALCULATED', $detail['state']);
        self::assertSame([], $detail['anomalies']);
        $wl = $this->worklist($client, $token, $m);
        self::assertSame('FEE_NOT_APPLICABLE', $wl['rows']['MISSION_INTERVENTION:' . $men1->getId()]['reasonCode']);
        self::assertSame('CALCULATION_PENDING_APPROVAL', $wl['rows']['MISSION_INTERVENTION:' . $lcaItv->getId()]['reasonCode']);
        self::assertSame('135.00', $wl['rows']['MISSION_INTERVENTION:' . $lcaItv->getId()]['amount']);
        self::assertSame([], array_values(array_filter($wl['anomalies'], static fn ($a) => \App\Enum\FirmBillingReason::from($a['code'])->isEngineAnomaly())));
    }
}
