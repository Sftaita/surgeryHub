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
use App\Tests\Support\DeclaresActualHours;

/**
 * D-142 — régularisation du catalogue matériel : la vue manager
 * (GET /api/material-items/billing-regularisation) reflète les décisions de facturation
 * (non décidé / facturable / non facturable) et les missions que le MOTEUR bloque encore
 * (FinancialCalculationService::evaluate(), lecture seule). Données fictives, vraie base,
 * HTTP. Aucune correction du catalogue ne recalcule quoi que ce soit, un calcul verrouillé
 * ne change jamais, et une mission n'est jamais présentée comme débloquée tant qu'une autre
 * cause demeure.
 */
final class MaterialBillingRegularisationTest extends WebTestCase
{
    use DeclaresActualHours;

    private const PASSWORD = 'Regul123!';

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
        $u->setEmail('regul-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
            $this->site->setName('REG-Delta-' . bin2hex(random_bytes(2)));
            $this->em->persist($this->site); $this->em->flush();
            $this->created['sites'][] = $this->site->getId();
        }
        return $this->site;
    }

    /**
     * Mission VALIDATED 13:00–18:00, instrumentiste avec un tarif horaire actif. Heures réelles
     * déclarées par défaut (D-143 : le seul planifié n'est jamais rémunéré) ; $actualHours=false
     * reproduit une mission historique sans aucune déclaration.
     */
    private function mission(string $day = '2026-09-10', bool $actualHours = true): Mission
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
        if ($actualHours) {
            $this->declareActualHours($m);
        }
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


    /** Vue de régularisation (la base de test est partagée : on lit la ligne de l'article). */
    private function regularisation(KernelBrowser $client, string $token): array
    {
        return $this->json($this->request($client, $token, 'GET', '/api/material-items/billing-regularisation'));
    }

    private function regRow(array $overview, MaterialItem $item): array
    {
        foreach ($overview['items'] as $row) {
            if ($row['id'] === $item->getId()) {
                return $row;
            }
        }
        self::fail('article absent de la vue de régularisation');
    }

    private function regMission(array $row, Mission $m): array
    {
        foreach ($row['missions'] as $mission) {
            if ($mission['id'] === $m->getId()) {
                return $mission;
            }
        }
        self::fail('mission absente de la ligne');
    }

    /** Mission S&N LCA (forfait 135 €) avec une ligne de l'article donné. */
    private function lcaMission(Firm $sn, MaterialItem $item, string $day = '2026-09-10', string $qty = '1.00'): array
    {
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $this->rule($sn, $lca, null, '135.00');
        $m = $this->mission($day);
        $itv = $this->intervention($m, $sn, $lca);
        $ml = $this->material($m, $itv, $item, $qty);
        return [$m, $itv, $ml];
    }

    private function postMaterialRate(KernelBrowser $client, string $token, Firm $firm, MaterialItem $item, string $price, ?string $from = null): void
    {
        $body = ['ruleType' => 'MATERIAL_FEE', 'materialItemId' => $item->getId(), 'unitPrice' => $price];
        if ($from !== null) {
            $body['validFrom'] = $from;
        }
        $this->json($this->request($client, $token, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $body), 201);
    }

    // ── 1. Non décidé → facturable ────────────────────────────────────────

    public function test_1_undecided_item_becomes_billable_with_a_rate_and_the_engine_sees_it(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $qfix = $this->item($sn, 'Q-FIX');
        [$m, , $ml] = $this->lcaMission($sn, $qfix, qty: '2.00');
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        $before = $this->regularisation($client, $token);
        $row = $this->regRow($before, $qfix);
        self::assertSame('UNDECIDED', $row['status']);
        self::assertSame('Non décidé', $row['statusLabel']);
        self::assertSame('UNSPECIFIED', $row['billingStatus'], 'non décidé reste UNSPECIFIED, jamais « non facturable »');
        self::assertTrue($row['toConfigure']);
        self::assertSame(['lineCount' => 1, 'missionCount' => 1, 'calculatedMissionCount' => 0, 'firstDate' => '2026-09-10', 'lastDate' => '2026-09-10', 'blockedMissionCount' => 1, 'onlyBlockerMissionCount' => 1], $row['usage']);
        $mission = $this->regMission($row, $m);
        self::assertSame('PENDING', $mission['calculationState']);
        self::assertTrue($mission['blockedByThisItem']);
        self::assertSame(0, $mission['otherBlockingAnomalyCount']);

        // Décision : facturable, 45 € sans date de début (couvre aussi les prestations passées).
        $this->postMaterialRate($client, $token, $sn, $qfix, '45.00');

        $after = $this->regularisation($client, $token);
        $row = $this->regRow($after, $qfix);
        self::assertSame('BILLABLE', $row['status']);
        self::assertSame('BILLABLE', $row['billingStatus']);
        self::assertFalse($row['toConfigure']);
        self::assertSame(0, $row['usage']['blockedMissionCount']);
        self::assertSame('45.00', $row['currentRate']['unitPrice']);
        self::assertSame($before['summary']['toConfigureCount'] - 1, $after['summary']['toConfigureCount'], 'le compteur « à régulariser » baisse');

        $detail = $this->detail($client, $token, $m);
        self::assertTrue($detail['anomalies'][0]['resolved']);
        self::assertSame('LINE', $detail['anomalies'][0]['currentResolution']['kind']);
        self::assertTrue($detail['recalculation']['wouldSucceed']);
        self::assertSame($ml->getId(), $detail['anomalies'][0]['materialLineId']);
    }

    // ── 2. Non décidé → non facturable ────────────────────────────────────

    public function test_2_undecided_item_becomes_not_billable_explicitly_without_any_zero_rate(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $journey = $this->item($sn, 'JOURNEY II');
        [$m, , $ml] = $this->lcaMission($sn, $journey);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        $this->json($this->request($client, $token, 'PATCH', "/api/material-items/{$journey->getId()}", ['billingStatus' => 'NOT_BILLABLE']));

        $row = $this->regRow($this->regularisation($client, $token), $journey);
        self::assertSame('NOT_BILLABLE', $row['status']);
        self::assertFalse($row['toConfigure']);
        self::assertSame([], $row['rules'], 'aucune règle à 0 € créée');
        self::assertFalse($this->regMission($row, $m)['blockedByThisItem']);
        self::assertSame('MATERIAL_NOT_BILLABLE', $this->detail($client, $token, $m)['anomalies'][0]['currentResolution']['kind']);
        self::assertSame('MATERIAL_NOT_BILLABLE', $this->worklist($client, $token, $m)['rows']['MATERIAL_LINE:' . $ml->getId()]['reasonCode']);

        // Retour possible à « non décidé » : la mission redevient bloquée, rien n'est déduit.
        $this->json($this->request($client, $token, 'PATCH', "/api/material-items/{$journey->getId()}", ['billingStatus' => 'UNSPECIFIED']));
        $row = $this->regRow($this->regularisation($client, $token), $journey);
        self::assertSame('UNDECIDED', $row['status']);
        self::assertTrue($this->regMission($row, $m)['blockedByThisItem']);
    }

    // ── 3. Facturable mais tarif manquant ─────────────────────────────────

    public function test_3_billable_item_whose_rate_period_ended_is_flagged_rate_missing(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $ub = $this->item($sn, 'Ultrabutton', MaterialBillingStatus::BILLABLE);
        $old = $this->rule($sn, null, $ub, '60.00', '2026-01-01', '2026-06-01');
        [$m] = $this->lcaMission($sn, $ub);

        $row = $this->regRow($this->regularisation($client, $token), $ub);
        self::assertSame('BILLABLE_RATE_MISSING', $row['status']);
        self::assertSame('Tarif manquant', $row['statusLabel']);
        self::assertTrue($row['toConfigure']);
        self::assertNull($row['currentRate']);
        self::assertSame([$old->getId()], array_column($row['rules'], 'id'), 'les tarifs existants sont montrés');
        self::assertTrue($this->regMission($row, $m)['blockedByThisItem']);
    }

    // ── 4. Tarif valable pour une mission passée ──────────────────────────

    public function test_4_rate_starting_before_a_past_mission_covers_it(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $ub = $this->item($sn, 'Ultrabutton Tib');
        [$m] = $this->lcaMission($sn, $ub, '2026-09-10');
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $this->postMaterialRate($client, $token, $sn, $ub, '60.00', '2026-09-01');

        $row = $this->regRow($this->regularisation($client, $token), $ub);
        self::assertSame('BILLABLE', $row['status']);
        self::assertFalse($this->regMission($row, $m)['blockedByThisItem']);
        self::assertTrue($this->detail($client, $token, $m)['recalculation']['wouldSucceed']);
    }

    // ── 5. Tarif dont le début est postérieur à la mission ────────────────

    public function test_5_rate_starting_after_the_mission_does_not_unblock_it_and_says_so(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $ub = $this->item($sn, 'Ultrabutton Adj');
        [$m] = $this->lcaMission($sn, $ub, '2026-09-10');
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $this->postMaterialRate($client, $token, $sn, $ub, '60.00', '2026-10-07');

        $row = $this->regRow($this->regularisation($client, $token), $ub);
        self::assertSame('BILLABLE_RATE_MISSING', $row['status'], 'facturable, mais pas à la date de la mission');
        self::assertTrue($row['toConfigure']);
        self::assertTrue($this->regMission($row, $m)['blockedByThisItem']);
        $a = $this->detail($client, $token, $m)['anomalies'][0];
        self::assertFalse($a['resolved']);
        // D-142 : l'explication décrit la cause ACTUELLE (le tarif posé depuis l'échec ne couvre
        // pas la date), plus celle de l'échec audité (« aucune décision de facturation »).
        self::assertStringContainsString("ne s'applique au 10/09/2026", $a['explanation']);
        self::assertStringContainsString('du 07/10/2026', $a['explanation']);
        self::assertStringNotContainsString('Aucune décision de facturation', $a['explanation']);
        self::assertCount(1, $a['targetRules']);
    }

    // ── 6. Matériel d'une autre firme que celle de l'intervention ─────────

    public function test_6_item_of_another_firm_is_attributed_to_its_own_firm(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $arthrex = $this->firm('Arthrex');
        $swivel = $this->item($arthrex, 'SwiveLock');
        [$m] = $this->lcaMission($sn, $swivel);

        $row = $this->regRow($this->regularisation($client, $token), $swivel);
        self::assertSame($arthrex->getId(), $row['firm']['id'], 'SwiveLock reste Arthrex même dans une intervention Smith & Nephew');
        self::assertTrue($this->regMission($row, $m)['blockedByThisItem']);

        $this->postMaterialRate($client, $token, $arthrex, $swivel, '30.00');
        self::assertFalse($this->regMission($this->regRow($this->regularisation($client, $token), $swivel), $m)['blockedByThisItem']);
    }

    // ── 7. Plusieurs matériels bloquant la même mission ───────────────────

    public function test_7_fixing_one_of_several_blocking_items_never_shows_the_mission_unblocked(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $arthrex = $this->firm('Arthrex');
        $qfix = $this->item($sn, 'Q-FIX');
        $agrafe = $this->item($arthrex, 'Agrafe');
        [$m, $itv] = $this->lcaMission($sn, $qfix);
        $this->material($m, $itv, $agrafe);
        $this->material($m, $itv, $agrafe); // deux lignes du même article : une seule mission

        $o = $this->regularisation($client, $token);
        self::assertSame(2, $this->regMission($this->regRow($o, $qfix), $m)['otherBlockingAnomalyCount'], 'Q-FIX : restent les deux lignes d\'agrafe');
        self::assertSame(1, $this->regMission($this->regRow($o, $agrafe), $m)['otherBlockingAnomalyCount'], 'agrafe : reste Q-FIX');
        self::assertSame(2, $this->regRow($o, $agrafe)['usage']['lineCount']);
        self::assertSame(1, $this->regRow($o, $agrafe)['usage']['missionCount'], 'utilisations ≠ missions');
        self::assertSame(0, $this->regRow($o, $qfix)['usage']['onlyBlockerMissionCount']);

        $this->json($this->request($client, $token, 'PATCH', "/api/material-items/{$qfix->getId()}", ['billingStatus' => 'NOT_BILLABLE']));
        $o = $this->regularisation($client, $token);
        $agrafeMission = $this->regMission($this->regRow($o, $agrafe), $m);
        self::assertTrue($agrafeMission['blockedByThisItem']);
        self::assertSame(0, $agrafeMission['otherBlockingAnomalyCount'], 'l\'agrafe est désormais la seule cause');
        self::assertSame(1, $this->regRow($o, $agrafe)['usage']['onlyBlockerMissionCount']);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode(), 'la mission reste bloquée');
        self::assertFalse($this->detail($client, $token, $m)['recalculation']['wouldSucceed']);
    }

    // ── 8. Configuration modifiée sans recalcul automatique ───────────────

    public function test_8_configuring_an_item_never_triggers_a_calculation(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $qfix = $this->item($sn, 'Q-FIX');
        [$m] = $this->lcaMission($sn, $qfix);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());
        $audits = $this->em->getRepository(AuditEvent::class)->count(['mission' => $m->getId()]);

        $this->postMaterialRate($client, $token, $sn, $qfix, '45.00');
        $this->regularisation($client, $token);
        $this->detail($client, $token, $m);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(FinancialCalculation::class)->count(['mission' => $m->getId()]), 'aucun calcul créé');
        self::assertSame($audits, $this->em->getRepository(AuditEvent::class)->count(['mission' => $m->getId()]), 'aucun audit financier ajouté par les lectures');
        self::assertSame('ANOMALY', $this->detail($client, $token, $m)['state'], 'l\'échec reste affiché jusqu\'au recalcul demandé');
    }

    // ── 9. Calcul verrouillé protégé ──────────────────────────────────────

    public function test_9_locked_calculation_is_never_reevaluated_nor_changed(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $fastfix = $this->item($sn, 'Fast-Fix', MaterialBillingStatus::BILLABLE);
        $rate = $this->rule($sn, null, $fastfix, '17.00');
        [$m, , $ml] = $this->lcaMission($sn, $fastfix, (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d'), '3.00');
        $calcId = $this->json($this->calculate($client, $token, $m), 201)['id'];
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/approve"));
        $this->json($this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/lock"));

        $this->replaceRule($client, $token, $sn, $rate, '25.00', (new \DateTimeImmutable('today'))->modify('+10 days'));

        // Mission à calcul actif : comptée dans l'utilisation, jamais réévaluée ni listée.
        $row = $this->regRow($this->regularisation($client, $token), $fastfix);
        self::assertSame([], array_values(array_filter($row['missions'], static fn (array $x) => $x['id'] === $m->getId())));
        self::assertSame(1, $row['usage']['calculatedMissionCount']);
        self::assertSame(1, $row['usage']['missionCount']);
        self::assertSame(1, $row['usage']['lineCount']);
        self::assertSame(0, $row['usage']['blockedMissionCount']);
        self::assertNotSame(201, $this->request($client, $token, 'POST', "/api/financial-calculations/{$calcId}/recalculate")->getStatusCode());
        $line = $this->lineFor($this->firmLines($m), $ml);
        self::assertSame('51.00', $line->getTotalAmount(), 'montant figé : 3 × 17 €');
        self::assertSame(FinancialCalculationStatus::LOCKED, $line->getFinancialCalculation()->getStatus());
    }

    // ── 10. Cohérence catalogue / suivi des encodages / facturation ───────

    public function test_10_regularisation_view_encoding_tracking_and_firm_billing_agree(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $qfix = $this->item($sn, 'Q-FIX');
        [$m, $itv, $ml] = $this->lcaMission($sn, $qfix);
        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        self::assertTrue($this->regMission($this->regRow($this->regularisation($client, $token), $qfix), $m)['blockedByThisItem']);
        self::assertFalse($this->detail($client, $token, $m)['anomalies'][0]['resolved']);
        $wl = $this->worklist($client, $token, $m);
        self::assertSame('MISSING_FIRM_MATERIAL_RATE', $wl['rows']['MATERIAL_LINE:' . $ml->getId()]['reasonCode']);
        self::assertSame('CALCULATION_BLOCKED', $wl['rows']['MISSION_INTERVENTION:' . $itv->getId()]['reasonCode']);

        $this->postMaterialRate($client, $token, $sn, $qfix, '45.00');

        self::assertFalse($this->regMission($this->regRow($this->regularisation($client, $token), $qfix), $m)['blockedByThisItem']);
        $detail = $this->detail($client, $token, $m);
        self::assertTrue($detail['anomalies'][0]['resolved']);
        self::assertTrue($detail['recalculation']['wouldSucceed']);
        $wl = $this->worklist($client, $token, $m);
        self::assertSame('FIXED_PENDING_RECALCULATION', $wl['rows']['MATERIAL_LINE:' . $ml->getId()]['reasonCode']);
        self::assertNull($wl['rows']['MATERIAL_LINE:' . $ml->getId()]['amount'], 'aucun montant avant le recalcul demandé');
    }

    public function test_regularisation_view_is_manager_only(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uri = '/api/material-items/billing-regularisation';
        foreach (['ROLE_INSTRUMENTIST', 'ROLE_SURGEON'] as $role) {
            self::assertSame(403, $this->request($client, $this->login($client, $this->user($role)), 'GET', $uri)->getStatusCode(), $role);
        }
        $client->request('GET', $uri);
        self::assertSame(401, $client->getResponse()->getStatusCode(), 'anonyme');
        $this->json($this->request($client, $this->login($client, $this->user('ROLE_ADMIN')), 'GET', $uri));
    }

    // ── D-142 × D-143 : heures réelles obligatoires ──────────────────────

    public function test_mission_without_actual_hours_is_never_shown_as_unblocked_by_fixing_the_material(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $qfix = $this->item($sn, 'Q-FIX');
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $this->rule($sn, $lca, null, '135.00');
        $m = $this->mission('2026-09-10', actualHours: false);
        $itv = $this->intervention($m, $sn, $lca);
        $this->material($m, $itv, $qfix);

        $r = $this->calculate($client, $token, $m);
        self::assertSame(422, $r->getStatusCode());
        $codes = array_column(json_decode($r->getContent(), true)['error']['violations'], 'code');
        sort($codes);
        self::assertSame(['MISSING_ACTUAL_HOURS', 'MISSING_FIRM_MATERIAL_RATE'], $codes, 'deux causes distinctes, jamais fusionnées');

        // Matériel non décidé : bloquée par lui ET par les heures — jamais « seule cause ».
        $row = $this->regRow($this->regularisation($client, $token), $qfix);
        $mission = $this->regMission($row, $m);
        self::assertTrue($mission['blockedByThisItem']);
        self::assertSame(1, $mission['otherBlockingAnomalyCount'], 'heures réelles manquantes = autre anomalie, pas une anomalie tarifaire');
        self::assertSame(0, $row['usage']['onlyBlockerMissionCount']);

        // Matériel corrigé : plus bloquée PAR LUI, mais toujours pas calculable.
        $this->postMaterialRate($client, $token, $sn, $qfix, '45.00');
        $mission = $this->regMission($this->regRow($this->regularisation($client, $token), $qfix), $m);
        self::assertFalse($mission['blockedByThisItem']);
        self::assertSame(1, $mission['otherBlockingAnomalyCount'], 'jamais présentée « Plus bloquée — à calculer »');
        $detail = $this->detail($client, $token, $m);
        self::assertFalse($detail['recalculation']['wouldSucceed'], 'le pronostic tient compte des heures réelles manquantes');

        // Heures réelles déclarées : la mission devient calculable — et rien n'a été calculé seul.
        $this->declareActualHours($this->managed($m));
        $mission = $this->regMission($this->regRow($this->regularisation($client, $token), $qfix), $m);
        self::assertSame(0, $mission['otherBlockingAnomalyCount']);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(FinancialCalculation::class)->count(['mission' => $m->getId()]), 'aucun calcul automatique');
    }

    // ── Jamais de tarif matériel à 0 € ───────────────────────────────────

    public function test_zero_material_rate_is_refused_and_never_makes_the_item_billable(): void
    {
        [$client, $token] = $this->boot();
        $sn = $this->firm('Smith & Nephew');
        $qfix = $this->item($sn, 'Q-FIX');
        $uri = "/api/firms/{$sn->getId()}/pricing-rules";

        foreach (['0', '0.00'] as $price) {
            $r = $this->request($client, $token, 'POST', $uri, ['ruleType' => 'MATERIAL_FEE', 'materialItemId' => $qfix->getId(), 'unitPrice' => $price]);
            self::assertSame(422, $r->getStatusCode(), $price);
            self::assertSame('MATERIAL_FEE_ZERO_REFUSED', json_decode($r->getContent(), true)['error']['code']);
        }
        // Négatif et non numérique : 422 explicite, jamais un 500 ni le message « 0 € ».
        foreach (['-5', 'abc', '12abc', '', true] as $price) {
            $r = $this->request($client, $token, 'POST', $uri, ['ruleType' => 'MATERIAL_FEE', 'materialItemId' => $qfix->getId(), 'unitPrice' => $price]);
            self::assertSame(422, $r->getStatusCode(), var_export($price, true));
            self::assertSame('VALIDATION_FAILED', json_decode($r->getContent(), true)['error']['code'], var_export($price, true));
        }
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(PricingRule::class)->count(['materialItem' => $qfix->getId()]), 'aucune règle créée');
        $row = $this->regRow($this->regularisation($client, $token), $qfix);
        self::assertSame('UNSPECIFIED', $row['billingStatus'], 'reste non décidé');
        self::assertSame('UNDECIDED', $row['status']);

        // Remplacement versionné et modification d'une règle future : même refus.
        $rate = $this->rule($sn, null, $qfix, '45.00');
        $r = $this->request($client, $token, 'POST', "{$uri}/{$rate->getId()}/replace", ['unitPrice' => '0', 'effectiveFrom' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d')]);
        self::assertSame(422, $r->getStatusCode());
        $future = $this->rule($sn, null, $this->item($sn, 'Ultrabutton'), '30.00', (new \DateTimeImmutable('+10 days'))->format('Y-m-d'));
        self::assertSame(422, $this->request($client, $token, 'PATCH', "{$uri}/{$future->getId()}", ['unitPrice' => '0.00'])->getStatusCode());
        self::assertSame(422, $this->request($client, $token, 'PATCH', "{$uri}/{$future->getId()}", ['unitPrice' => '12abc'])->getStatusCode());
        self::assertSame(422, $this->request($client, $token, 'POST', "{$uri}/{$rate->getId()}/replace", ['unitPrice' => '-1', 'effectiveFrom' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d')])->getStatusCode());
        $this->em->clear();
        self::assertSame('45.00', $this->em->find(PricingRule::class, $rate->getId())->getUnitPrice());
        self::assertSame('30.00', $this->em->find(PricingRule::class, $future->getId())->getUnitPrice());
    }

    // ── Performance : le nombre de requêtes ne dépend ni du catalogue ni de l'historique ──

    public function test_query_count_does_not_grow_with_catalogue_size_nor_calculated_history(): void
    {
        [$client] = $this->boot();
        $conn = $this->em->getConnection();
        $service = static::getContainer()->get(\App\Service\MaterialBillingRegularisationService::class);
        $selects = static fn () => (int) $conn->fetchAssociative("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
        $measure = function () use ($service, $selects): int {
            $this->em->clear();
            $before = $selects();
            $service->overview();
            return $selects() - $before;
        };

        $sn = $this->firm('Smith & Nephew');
        $fastfix = $this->item($sn, 'Fast-Fix', MaterialBillingStatus::BILLABLE);
        $this->rule($sn, null, $fastfix, '17.00');
        $baseline = $measure();

        // 20 articles de plus (dont 10 tarifés) et 6 missions déjà calculées qui les utilisent.
        $lca = $this->type('LCA', 'Plastie du ligament croisé antérieur du genou');
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = $item = $this->item($sn, 'Article ' . $i, $i % 2 ? MaterialBillingStatus::BILLABLE : MaterialBillingStatus::UNSPECIFIED);
            if ($i % 2) {
                $this->rule($sn, null, $item, '10.00');
            }
        }
        for ($k = 0; $k < 6; $k++) {
            $m = $this->mission('2026-09-' . (10 + $k));
            $itv = $this->intervention($m, $sn, $lca);
            for ($l = 0; $l < 4; $l++) {
                $this->material($m, $itv, $items[($k * 3 + $l) % 20]);
            }
            $conn->insert('financial_calculation', ['mission_id' => $m->getId(), 'version' => 1, 'status' => 'LOCKED', 'effective_at' => '2026-09-10',
                'currency_policy' => 'PER_CURRENCY_NO_CONVERSION', 'calculated_at' => '2026-09-20 10:00:00', 'created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00']);
        }

        self::assertSame($baseline, $measure(), 'aucune requête par article ni par mission déjà calculée');
        $row = $this->regRow($this->regularisation($client, $this->login($client, $this->manager)), $items[0]);
        self::assertSame([], $row['missions'], 'missions déjà calculées : comptées, pas listées');
        self::assertSame(1, $row['usage']['calculatedMissionCount']);
        self::assertSame('2026-09-10', $row['usage']['firstDate'], 'même date de référence que le moteur');
    }
}
