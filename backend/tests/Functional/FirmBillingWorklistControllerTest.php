<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
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
use App\Enum\InstrumentistRateType;
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-133 — worklist « Facturation firmes » (projection depuis l'activité validée) + contrat
 * de génération/cycle de vie des factures (repris de D-123), contre une vraie base et via
 * HTTP. Missions en septembre 2026 ; chaque appel worklist est filtré sur des firmes créées
 * par le test, jamais dépendant des autres données de la base.
 */
final class FirmBillingWorklistControllerTest extends WebTestCase
{
    private const PASSWORD = 'Worklist123!';
    private const FROM = '2026-09-01';
    private const TO = '2026-09-30';

    private EntityManagerInterface $em;
    private array $created = [
        'missions' => [], 'rules' => [], 'rates' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [], 'offerings' => [],
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
                foreach ($this->em->getRepository(FirmInvoiceLine::class)->findBy(['mission' => $missionIds]) as $fil) {
                    $this->em->remove($fil->getInvoice());
                }
                $this->em->flush();
                foreach ($this->em->getRepository(FirmInvoice::class)->findBy(['firm' => $this->created['firms']]) as $inv) {
                    $this->em->remove($inv);
                }
                $this->em->flush();
                foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $missionIds]) as $calc) {
                    $calc->setSupersededByCalculation(null);
                    foreach ($calc->getLines() as $l) { $this->em->remove($l); }
                }
                $this->em->flush();
                foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $missionIds]) as $calc) {
                    $this->em->remove($calc);
                }
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
            foreach ($this->created['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
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
        $u->setEmail('worklist-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('Worklist');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function get(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function post(KernelBrowser $client, string $token, string $uri, array $body = []): Response
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: json_encode($body));
        return $client->getResponse();
    }

    private function json(Response $r): array
    {
        return json_decode((string) $r->getContent(), true) ?? [];
    }

    private function firm(string $name): Firm
    {
        $f = new Firm();
        $f->setName('WKL-' . $name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function type(string $label = 'Ligamentoplastie'): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('WKL-' . bin2hex(random_bytes(3)));
        $t->setLabel($label);
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function item(Firm $firm, MaterialBillingStatus $billing = MaterialBillingStatus::UNSPECIFIED): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($firm);
        $i->setLabel('Ancre ' . bin2hex(random_bytes(2)));
        $i->setUnit('pièce');
        $i->setReferenceCode('REF-' . bin2hex(random_bytes(3)));
        $i->setBillingStatus($billing);
        $this->em->persist($i); $this->em->flush();
        $this->created['items'][] = $i->getId();
        return $i;
    }

    private function offering(Firm $firm, InterventionType $type, bool $presenceRelevant = false, bool $suppressesFee = false, bool $feeApplicable = true): FirmServiceOffering
    {
        $o = new FirmServiceOffering();
        $o->setFirm($firm);
        $o->setInterventionType($type);
        $o->setRepresentativePresenceRelevant($presenceRelevant);
        $o->setRepresentativeSuppressesInterventionFee($suppressesFee);
        $o->setFeeApplicable($feeApplicable);
        $this->em->persist($o); $this->em->flush();
        $this->created['offerings'][] = $o->getId();
        return $o;
    }

    private function interventionRule(Firm $firm, InterventionType $type, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $r->setInterventionType($type);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function materialRule(Firm $firm, MaterialItem $item, string $price): PricingRule
    {
        $r = new PricingRule();
        $r->setFirm($firm);
        $r->setRuleType(PricingRuleType::MATERIAL_FEE);
        $r->setMaterialItem($item);
        $r->setUnitPrice($price);
        $this->em->persist($r); $this->em->flush();
        $this->created['rules'][] = $r->getId();
        return $r;
    }

    private function hourlyRate(User $instrumentist): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount('40.00');
        $r->setCurrency('EUR');
        $r->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($r); $this->em->flush();
        $this->created['rates'][] = $r->getId();
        return $r;
    }

    private function instrumentist(bool $withRate = true): User
    {
        $u = $this->user('ROLE_INSTRUMENTIST');
        if ($withRate) {
            $this->hourlyRate($u);
        }
        return $u;
    }

    /**
     * Mission dont l'intervention a pour firme principale $interventionFirm, avec
     * optionnellement du matériel ($materialItem, quantité $qty) — éventuellement d'une
     * autre firme (cas multi-firmes).
     */
    private function mission(
        MissionStatus $status,
        Firm $interventionFirm,
        InterventionType $type,
        ?MaterialItem $materialItem = null,
        string $qty = '2.00',
        string $date = '2026-09-10 08:00:00',
        ?bool $representativePresent = null,
        bool $instrumentistRate = true,
    ): Mission {
        $site = new Hospital();
        $site->setName('WKL-Delta-' . bin2hex(random_bytes(2)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->user('ROLE_SURGEON');

        $start = new \DateTimeImmutable($date);
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($start);
        $m->setEndAt($start->modify('+2 hours'));
        $m->setStatus($status);
        $m->setInstrumentist($this->instrumentist($instrumentistRate));
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $itv = new MissionIntervention();
        $itv->setMission($m);
        $itv->setCode($type->getCode());
        $itv->setLabel('Ligamentoplastie LCA');
        $itv->setInterventionType($type);
        $itv->setPrimaryFirm($interventionFirm);
        $itv->setRepresentativePresent($representativePresent);
        $this->em->persist($itv); $this->em->flush();
        $m->getInterventions()->add($itv);

        if ($materialItem !== null) {
            $ml = new MaterialLine();
            $ml->setMission($m);
            $ml->setMissionIntervention($itv);
            $ml->setItem($materialItem);
            $ml->setQuantity($qty);
            $ml->setCreatedBy($surgeon);
            $this->em->persist($ml); $this->em->flush();
            $m->getMaterialLines()->add($ml);
        }

        return $m;
    }

    /** @param Firm[] $firms */
    private function worklist(KernelBrowser $client, string $token, array $firms, string $extra = ''): array
    {
        $query = implode('', array_map(static fn (Firm $f) => '&firmIds[]=' . $f->getId(), $firms));
        $r = $this->get($client, $token, sprintf('/api/firm-billing/worklist?from=%s&to=%s%s%s', self::FROM, self::TO, $query, $extra));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        return $this->json($r);
    }

    /** @return array<string, array> lignes indexées par type (INTERVENTION|MATERIAL) — une mission par test */
    private function rowsByType(array $worklist): array
    {
        $out = [];
        foreach ($worklist['rows'] as $row) {
            self::assertArrayNotHasKey($row['sourceType'], $out, 'une seule ligne par élément source');
            $out[$row['sourceType']] = $row;
        }
        return $out;
    }

    private function calculate(KernelBrowser $client, string $token, Mission $m): Response
    {
        return $this->post($client, $token, "/api/missions/{$m->getId()}/financial-calculations");
    }

    private function calculateAndApprove(KernelBrowser $client, string $token, Mission $m): int
    {
        $r = $this->calculate($client, $token, $m);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $calcId = $this->json($r)['id'];
        $a = $this->post($client, $token, "/api/financial-calculations/{$calcId}/approve");
        self::assertSame(200, $a->getStatusCode(), (string) $a->getContent());
        return $calcId;
    }

    /** @return int[] identifiants de FinancialCalculationLine facturables, dans l'ordre de la worklist */
    private function billableLineIds(array $worklist): array
    {
        $ids = [];
        foreach ($worklist['rows'] as $row) {
            if ($row['canInvoice']) {
                $ids[] = $row['financialLineId'];
            }
        }
        return $ids;
    }

    private function generate(KernelBrowser $client, string $token, Firm $firm, array $lineIds): Response
    {
        return $this->post($client, $token, '/api/firm-invoices/from-financial-calculations', [
            'firmId' => $firm->getId(), 'currency' => 'EUR', 'periodStart' => self::FROM, 'periodEnd' => self::TO,
            'selectedFinancialCalculationLineIds' => $lineIds,
        ]);
    }

    private function export(KernelBrowser $client, string $token, array $firms, array $keys, string $format): Response
    {
        return $this->post($client, $token, '/api/firm-billing/worklist/export', [
            'from' => self::FROM, 'to' => self::TO, 'firmIds' => array_map(static fn (Firm $f) => $f->getId(), $firms),
            'keys' => $keys, 'format' => $format,
        ]);
    }

    /** Lit la feuille d'un .xlsx (zip OOXML) en tableau de lignes de chaînes. */
    private function readXlsx(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($tmp, $content);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp) === true, 'archive xlsx valide');
        self::assertNotFalse($zip->locateName('[Content_Types].xml'));
        self::assertNotFalse($zip->locateName('xl/workbook.xml'));
        $sheet = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($tmp);
        self::assertNotFalse($sheet, 'feuille XML bien formée');

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                // Position réelle depuis la référence (« K7 » → colonne 10) : cellules vides absentes.
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[1]) as $letter) {
                    $col = $col * 26 + (ord($letter) - 64);
                }
                $cells[$col - 1] = isset($c->is) ? (string) $c->is->t : (string) $c->v;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    // ── L'activité validée est visible AVANT tout calcul ─────────────────

    public function test_validated_activity_without_calculation_is_listed_and_never_disappears(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $item = $this->item($firm);
        $this->interventionRule($firm, $type, '350.00');
        $this->materialRule($firm, $item, '25.00');
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type, $item, '2.00');

        $w = $this->worklist($client, $token, [$firm]);
        $rows = $this->rowsByType($w);

        self::assertCount(2, $w['rows'], 'intervention ET matériel validés visibles sans calcul financier');
        foreach ($rows as $row) {
            self::assertSame('TO_REVIEW', $row['billingStatus']);
            self::assertSame('CALCULATION_REQUIRED', $row['reasonCode']);
            self::assertSame('Calcul financier à effectuer', $row['reasonLabel']);
            self::assertNull($row['amount'], 'aucun montant avant le calcul — jamais estimé');
            self::assertFalse($row['canInvoice']);
            self::assertSame($m->getId(), $row['mission']['id']);
            self::assertSame('2026-09-10', $row['mission']['date']);
        }
        self::assertSame('Ligamentoplastie LCA', $rows['INTERVENTION']['label']);
        self::assertSame($item->getReferenceCode(), $rows['MATERIAL']['reference']);
        self::assertSame('2', $rows['MATERIAL']['quantity']);

        self::assertSame(2, $w['summary']['lineCount']);
        self::assertSame(0, $w['summary']['billable']['lineCount']);
        self::assertSame(2, $w['summary']['toReview']['lineCount']);
        self::assertSame(1, $w['summary']['anomalyCount']);
        self::assertSame('CALCULATION_REQUIRED', $w['anomalies'][0]['code']);
        self::assertSame('CALCULATE', $w['anomalies'][0]['action']['code']);
        self::assertSame([$m->getId()], $w['bulkActions']['calculatePending']);
    }

    // ── Facturable ───────────────────────────────────────────────────────

    public function test_validated_intervention_and_material_become_billable_after_approval(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $item = $this->item($firm);
        $this->interventionRule($firm, $type, '350.00');
        $this->materialRule($firm, $item, '25.00');
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type, $item, '2.00');

        $calc = $this->calculate($client, $token, $m);
        self::assertSame(201, $calc->getStatusCode(), (string) $calc->getContent());
        $pending = $this->rowsByType($this->worklist($client, $token, [$firm]));
        self::assertSame('CALCULATION_PENDING_APPROVAL', $pending['INTERVENTION']['reasonCode']);
        self::assertSame('350.00', $pending['INTERVENTION']['amount'], 'montant calculé visible, pas encore facturable');
        self::assertFalse($pending['INTERVENTION']['canInvoice']);

        $this->post($client, $token, "/api/financial-calculations/{$this->json($calc)['id']}/approve");
        $w = $this->worklist($client, $token, [$firm]);
        $rows = $this->rowsByType($w);

        self::assertSame('BILLABLE', $rows['INTERVENTION']['billingStatus']);
        self::assertSame('350.00', $rows['INTERVENTION']['amount']);
        self::assertTrue($rows['INTERVENTION']['canInvoice']);
        self::assertSame('BILLABLE', $rows['MATERIAL']['billingStatus']);
        self::assertSame('50.00', $rows['MATERIAL']['amount']);
        self::assertSame(2, $w['summary']['billable']['lineCount']);
        self::assertSame([['currency' => 'EUR', 'amount' => '400.00']], $w['summary']['billable']['amounts']);
        self::assertSame(0, $w['summary']['anomalyCount']);
    }

    // ── Non facturable : exclusions métier, avec motif ───────────────────

    public function test_representative_presence_makes_the_intervention_not_billable_with_its_reason(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Smith');
        $type = $this->type();
        $this->offering($firm, $type, presenceRelevant: true, suppressesFee: true);
        $this->interventionRule($firm, $type, '350.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firm, $type, representativePresent: true));

        $w = $this->worklist($client, $token, [$firm]);
        $row = $this->rowsByType($w)['INTERVENTION'];

        self::assertSame('NOT_BILLABLE', $row['billingStatus']);
        self::assertSame('REPRESENTATIVE_PRESENT', $row['reasonCode']);
        self::assertSame('Délégué présent', $row['reasonLabel']);
        self::assertStringContainsString('neutralisé en présence du délégué', $row['reasonDetail']);
        self::assertSame('0.00', $row['amount']);
        self::assertFalse($row['canInvoice'], 'une exclusion n\'est jamais proposée à la facturation');
        self::assertSame(1, $w['summary']['notBillable']['lineCount']);
        self::assertSame(0, $w['summary']['anomalyCount'], 'une exclusion n\'est jamais une anomalie');
    }

    public function test_commercial_exclusions_are_not_billable_even_before_any_calculation(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Zimmer');
        $type = $this->type('Arthroscopie diagnostique');
        $this->offering($firm, $type, feeApplicable: false);
        $item = $this->item($firm, MaterialBillingStatus::NOT_BILLABLE);
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type, $item);

        $before = $this->rowsByType($this->worklist($client, $token, [$firm]));
        self::assertSame('FEE_NOT_APPLICABLE', $before['INTERVENTION']['reasonCode']);
        self::assertSame('NOT_BILLABLE', $before['INTERVENTION']['billingStatus']);
        self::assertStringContainsString('Arthroscopie diagnostique', $before['INTERVENTION']['reasonDetail']);
        self::assertSame('MATERIAL_NOT_BILLABLE', $before['MATERIAL']['reasonCode']);

        // Le moteur confirme la même classification après calcul (aucune ligne FIRM).
        $this->calculateAndApprove($client, $token, $m);
        $after = $this->rowsByType($this->worklist($client, $token, [$firm]));
        self::assertSame('FEE_NOT_APPLICABLE', $after['INTERVENTION']['reasonCode']);
        self::assertSame('MATERIAL_NOT_BILLABLE', $after['MATERIAL']['reasonCode']);
        self::assertNull($after['INTERVENTION']['financialLineId']);
    }

    // ── À corriger : jamais de message technique brut ────────────────────

    public function test_missing_rates_become_french_anomalies_and_never_expose_the_engine_message(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Stryker');
        $type = $this->type('Arthrodèse 2 niveaux');
        $item = $this->item($firm);
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type, $item); // aucun tarif

        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        $response = $this->get($client, $token, sprintf('/api/firm-billing/worklist?from=%s&to=%s&firmIds[]=%d', self::FROM, self::TO, $firm->getId()));
        self::assertStringNotContainsString('No active', (string) $response->getContent(), 'le message technique du moteur ne sort jamais');
        self::assertStringNotContainsString('PricingRule', (string) $response->getContent());
        $w = $this->json($response);
        $rows = $this->rowsByType($w);

        self::assertSame('MISSING_FIRM_INTERVENTION_RATE', $rows['INTERVENTION']['reasonCode']);
        self::assertSame("Tarif d'intervention manquant", $rows['INTERVENTION']['reasonLabel']);
        self::assertSame('MISSING_FIRM_MATERIAL_RATE', $rows['MATERIAL']['reasonCode']);

        $byCode = array_column($w['anomalies'], null, 'code');
        $itv = $byCode['MISSING_FIRM_INTERVENTION_RATE'];
        self::assertSame("Tarif d'intervention manquant", $itv['title']);
        self::assertSame('Arthrodèse 2 niveaux', $itv['element']['label']);
        self::assertSame($firm->getName(), $itv['firm']['name']);
        self::assertStringContainsString('au 10/09/2026', $itv['explanation']);
        self::assertSame(['code' => 'CONFIGURE_INTERVENTION_RATE', 'label' => 'Configurer le tarif'], $itv['action']);
        self::assertFalse($itv['resolved']);
        self::assertSame('Tarif matériel manquant', $byCode['MISSING_FIRM_MATERIAL_RATE']['title']);
        self::assertSame([], $w['bulkActions']['recalculateFixed'], 'rien n\'est corrigé : pas de relance proposée');
    }

    public function test_missing_instrumentist_rate_blocks_firm_lines_then_fixed_missions_can_be_recalculated_in_bulk(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->interventionRule($firm, $type, '350.00');
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type, instrumentistRate: false);

        self::assertSame(422, $this->calculate($client, $token, $m)->getStatusCode());

        $w = $this->worklist($client, $token, [$firm]);
        $row = $this->rowsByType($w)['INTERVENTION'];
        self::assertSame('CALCULATION_BLOCKED', $row['reasonCode'], 'élément correct mais calcul de la mission bloqué');
        self::assertStringContainsString('tarif instrumentiste manquant', $row['reasonDetail']);
        $anomaly = array_column($w['anomalies'], null, 'code')['MISSING_INSTRUMENTIST_RATE'];
        self::assertNull($anomaly['firm'], 'anomalie de mission : elle bloque toutes les firmes');
        self::assertSame('CONFIGURE_INSTRUMENTIST_RATE', $anomaly['action']['code']);
        self::assertFalse($anomaly['resolved']);

        // Correction de la cause, puis relance groupée.
        $this->em->clear();
        $this->hourlyRate($this->em->find(User::class, $m->getInstrumentist()->getId()));
        $fixed = $this->worklist($client, $token, [$firm]);
        self::assertTrue(array_column($fixed['anomalies'], null, 'code')['MISSING_INSTRUMENTIST_RATE']['resolved']);
        self::assertSame([$m->getId()], $fixed['bulkActions']['recalculateFixed']);

        $bulk = $this->post($client, $token, '/api/firm-billing/calculations', ['missionIds' => $fixed['bulkActions']['recalculateFixed']]);
        self::assertSame(200, $bulk->getStatusCode(), (string) $bulk->getContent());
        self::assertSame(1, $this->json($bulk)['calculated']);
        self::assertSame('CALCULATION_PENDING_APPROVAL', $this->rowsByType($this->worklist($client, $token, [$firm]))['INTERVENTION']['reasonCode']);
    }

    // ── Calcul actif uniquement (jamais SUPERSEDED / CANCELLED) ─────────

    public function test_only_the_active_calculation_is_used_never_superseded_or_cancelled(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $rule = $this->interventionRule($firm, $type, '350.00');
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type);
        $first = $this->json($this->calculate($client, $token, $m))['id'];

        // Nouveau tarif (même règle modifiée pour le test) puis recalcul : v1 → SUPERSEDED.
        $this->em->clear();
        $this->em->find(PricingRule::class, $rule->getId())->setUnitPrice('400.00');
        $this->em->flush();
        $recalc = $this->post($client, $token, "/api/financial-calculations/{$first}/recalculate");
        self::assertSame(201, $recalc->getStatusCode(), (string) $recalc->getContent());
        $second = $this->json($recalc)['id'];
        $this->post($client, $token, "/api/financial-calculations/{$second}/approve");

        $w = $this->worklist($client, $token, [$firm]);
        self::assertCount(1, $w['rows'], 'la version SUPERSEDED ne crée aucun doublon');
        self::assertSame('400.00', $w['rows'][0]['amount']);
        self::assertSame($second, $w['rows'][0]['calculationId']);

        // Annulation du calcul actif : plus aucune valeur, l'activité reste visible.
        // (aucun endpoint HTTP d'annulation : service du conteneur courant, avec SON EntityManager)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $calc = $em->find(FinancialCalculation::class, $second);
        static::getContainer()->get(\App\Service\FinancialCalculationService::class)->cancel($calc, $em->find(User::class, $this->created['users'][0]), 'test');
        $afterCancel = $this->worklist($client, $token, [$firm]);
        self::assertCount(1, $afterCancel['rows']);
        self::assertSame('CALCULATION_REQUIRED', $afterCancel['rows'][0]['reasonCode']);
        self::assertNull($afterCancel['rows'][0]['amount']);
    }

    // ── Facturé ──────────────────────────────────────────────────────────

    public function test_partial_selection_invoices_only_the_selected_lines_and_marks_them_invoiced(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $item = $this->item($firm);
        $this->interventionRule($firm, $type, '350.00');
        $this->materialRule($firm, $item, '25.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firm, $type, $item, '2.00'));

        $ids = $this->billableLineIds($this->worklist($client, $token, [$firm]));
        self::assertCount(2, $ids);

        $r = $this->generate($client, $token, $firm, [$ids[0]]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $invoice = $this->json($r);
        self::assertCount(1, $invoice['lines']);
        self::assertSame($ids[0], $invoice['lines'][0]['financialCalculationLineId']);
        self::assertSame('2026-09-01', $invoice['periodStart']);
        self::assertSame('GENERATED', $invoice['status']);
        self::assertSame(['send', 'cancel'], $invoice['allowedActions']);

        $after = $this->worklist($client, $token, [$firm]);
        self::assertSame([$ids[1]], $this->billableLineIds($after), 'la ligne non sélectionnée reste facturable');
        $invoiced = array_values(array_filter($after['rows'], static fn (array $r) => $r['billingStatus'] === 'INVOICED'));
        self::assertCount(1, $invoiced);
        self::assertSame($ids[0], $invoiced[0]['financialLineId']);
        self::assertSame('INVOICED', $invoiced[0]['reasonCode']);
        self::assertSame($invoice['number'], $invoiced[0]['invoice']['number']);
        self::assertSame('GENERATED', $invoiced[0]['invoice']['status']);
        self::assertStringContainsString('Calcul verrouillé', $this->rowsByType($after)['MATERIAL']['reasonDetail']);
        self::assertSame(1, $after['summary']['invoiced']['lineCount']);
        self::assertSame(1, $after['summary']['invoices']['generated']);

        $onlyInvoiced = $this->worklist($client, $token, [$firm], '&status=INVOICED');
        self::assertCount(1, $onlyInvoiced['rows']);
        self::assertSame(2, $onlyInvoiced['summary']['lineCount'], 'les tuiles ignorent le filtre de statut');
    }

    // ── Filtres firme(s) / type ──────────────────────────────────────────

    public function test_firm_filter_is_an_or_and_type_filter_narrows_rows(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firmA = $this->firm('A');
        $firmB = $this->firm('B');
        $firmC = $this->firm('C');
        $type = $this->type();
        $itemB = $this->item($firmB);
        $this->mission(MissionStatus::VALIDATED, $firmA, $type, $itemB, '3.00'); // A : intervention, B : matériel
        $this->mission(MissionStatus::VALIDATED, $firmC, $type);

        $onlyA = $this->worklist($client, $token, [$firmA]);
        self::assertSame([$firmA->getId()], array_unique(array_column(array_column($onlyA['rows'], 'firm'), 'id')));
        self::assertCount(1, $onlyA['rows']);

        $aOrB = $this->worklist($client, $token, [$firmA, $firmB]);
        self::assertCount(2, $aOrB['rows'], 'A OU B : intervention de A + matériel de B');
        self::assertEqualsCanonicalizing([$firmA->getId(), $firmB->getId()], array_column(array_column($aOrB['rows'], 'firm'), 'id'));

        $all = $this->worklist($client, $token, [$firmA, $firmB, $firmC]);
        self::assertCount(3, $all['rows']);
        self::assertCount(3, array_unique(array_column($all['rows'], 'key')), 'aucun doublon');

        $materialOnly = $this->worklist($client, $token, [$firmA, $firmB, $firmC], '&type=MATERIAL');
        self::assertCount(1, $materialOnly['rows']);
        self::assertSame('MATERIAL', $materialOnly['rows'][0]['sourceType']);
        self::assertSame($firmB->getId(), $materialOnly['rows'][0]['firm']['id']);
    }

    // ── Export : exactement la sélection ─────────────────────────────────

    public function test_export_contains_exactly_the_selected_rows_without_duplicates_and_the_billable_total(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $item = $this->item($firm);
        $this->interventionRule($firm, $type, '350.00');
        $this->materialRule($firm, $item, '25.00');
        $m1 = $this->mission(MissionStatus::VALIDATED, $firm, $type, $item, '2.00');
        $m2 = $this->mission(MissionStatus::VALIDATED, $firm, $type, date: '2026-09-12 08:00:00');
        $this->calculateAndApprove($client, $token, $m1);

        $w = $this->worklist($client, $token, [$firm]);
        self::assertCount(3, $w['rows']);
        $byMission = [];
        foreach ($w['rows'] as $row) {
            $byMission[$row['mission']['id']][$row['sourceType']] = $row;
        }
        $selected = [$byMission[$m1->getId()]['MATERIAL']['key'], $byMission[$m2->getId()]['INTERVENTION']['key']];

        // Doublon volontaire dans la demande : jamais répété dans l'export.
        $xlsx = $this->export($client, $token, [$firm], [...$selected, $selected[0]], 'xlsx');
        self::assertSame(200, $xlsx->getStatusCode(), (string) $xlsx->getContent());
        self::assertStringContainsString('spreadsheetml.sheet', (string) $xlsx->headers->get('Content-Type'));
        $sheet = $this->readXlsx((string) $xlsx->getContent());

        $flat = implode("\n", array_map(static fn (array $r) => implode('|', $r), $sheet));
        self::assertStringContainsString('du 01/09/2026 au 30/09/2026', $flat);
        self::assertStringContainsString($firm->getName(), $flat);
        $headerIndex = array_key_first(array_filter($sheet, static fn (array $r) => ($r[0] ?? null) === 'Date'));
        self::assertSame(['Date', 'Site', 'Chirurgien', 'Type', 'Prestation', 'Référence', 'Firme', 'Quantité', 'Facturation', 'Motif', 'Montant', 'Devise', 'Facture'], $sheet[$headerIndex]);
        $dataRows = array_values(array_filter(array_slice($sheet, $headerIndex + 1), static fn (array $r) => in_array($r[3] ?? null, ['Intervention', 'Matériel'], true)));
        self::assertCount(2, $dataRows, 'exactement les 2 lignes sélectionnées, sans doublon');
        self::assertSame(['Matériel', 'Intervention'], array_column($dataRows, 3));
        self::assertSame('Facturable', $dataRows[0][8]);
        self::assertSame('50', $dataRows[0][10]);
        self::assertSame('À vérifier', $dataRows[1][8]);
        $total = array_values(array_filter($sheet, static fn (array $r) => ($r[0] ?? null) === 'Total facturable'));
        self::assertCount(1, $total);
        self::assertSame('50', $total[0][10], 'seul le facturable est additionné');

        $pdf = $this->export($client, $token, [$firm], $selected, 'pdf');
        self::assertSame(200, $pdf->getStatusCode());
        self::assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $unknown = $this->export($client, $token, [$firm], ['MATERIAL_LINE:999999999'], 'xlsx');
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame('EXPORT_SELECTION_INVALID', $this->json($unknown)['error']['code']);
    }

    // ── Génération / cycle de vie (contrat D-123 conservé) ───────────────

    public function test_line_of_another_firm_is_rejected(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firmA = $this->firm('A');
        $firmB = $this->firm('B');
        $type = $this->type();
        $this->interventionRule($firmA, $type, '100.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firmA, $type));
        $lineA = $this->billableLineIds($this->worklist($client, $token, [$firmA]))[0];

        $r = $this->generate($client, $token, $firmB, [$lineA]);

        self::assertSame(422, $r->getStatusCode());
        self::assertSame('DOCUMENT_LINE_SELECTION_FAILED', $this->json($r)['error']['code']);
        self::assertSame('FINANCIAL_LINE_BENEFICIARY_MISMATCH', $this->json($r)['error']['violations'][0]['code']);
    }

    public function test_the_same_line_can_never_be_invoiced_twice(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->interventionRule($firm, $type, '100.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firm, $type));
        $lineId = $this->billableLineIds($this->worklist($client, $token, [$firm]))[0];

        self::assertSame(201, $this->generate($client, $token, $firm, [$lineId])->getStatusCode());
        $second = $this->generate($client, $token, $firm, [$lineId]);

        self::assertSame(422, $second->getStatusCode());
        self::assertSame('FINANCIAL_LINE_ALREADY_ASSIGNED', $this->json($second)['error']['violations'][0]['code']);

        // Garantie serveur, indépendante du service : la contrainte UNIQUE refuse une
        // seconde FirmInvoiceLine sur la même FinancialCalculationLine.
        $this->em->clear();
        $fcl = $this->em->find(FinancialCalculationLine::class, $lineId);
        $first = $fcl->getFirmInvoiceLine();
        $dup = new FirmInvoiceLine();
        $dup->setInvoice($first->getInvoice());
        $dup->setMission($first->getMission());
        $dup->setFinancialCalculationLine($fcl);
        $dup->setLineType(PricingRuleType::INTERVENTION_FEE);
        $dup->setDescriptionSnapshot('doublon');
        $dup->setFirmNameSnapshot('x');
        $dup->setUnitPrice('1.00');
        $dup->setTotalAmount('1.00');
        $this->em->persist($dup);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_invoice_snapshot_is_immutable_when_the_tariff_changes_later(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $rule = $this->interventionRule($firm, $type, '350.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firm, $type));
        $invoiceId = $this->json($this->generate($client, $token, $firm, $this->billableLineIds($this->worklist($client, $token, [$firm]))))['id'];

        $this->em->clear();
        $fresh = $this->em->find(PricingRule::class, $rule->getId());
        $fresh->setUnitPrice('999.00');
        $this->em->flush();

        $detail = $this->json($this->get($client, $token, "/api/firm-invoices/{$invoiceId}"));
        self::assertSame('350.00', $detail['totalAmount']);
        self::assertSame('350.00', $detail['lines'][0]['unitPrice']);
        self::assertSame('350.00', $detail['lines'][0]['totalAmount']);
        self::assertSame('350.00', $this->worklist($client, $token, [$firm])['rows'][0]['amount'], 'le montant facturé reste celui de la facture');

        $pdf = $this->get($client, $token, "/api/firm-invoices/{$invoiceId}/pdf");
        self::assertSame(200, $pdf->getStatusCode());
        self::assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
    }

    public function test_multi_firm_mission_invoices_firm_a_then_firm_b_separately(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firmA = $this->firm('A');
        $firmB = $this->firm('B');
        $type = $this->type();
        $itemB = $this->item($firmB);
        $this->interventionRule($firmA, $type, '300.00');
        $this->materialRule($firmB, $itemB, '40.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firmA, $type, $itemB, '3.00'));

        $linesA = $this->billableLineIds($this->worklist($client, $token, [$firmA]));
        $linesB = $this->billableLineIds($this->worklist($client, $token, [$firmB]));
        self::assertCount(1, $linesA);
        self::assertCount(1, $linesB);

        $invoiceA = $this->json($this->generate($client, $token, $firmA, $linesA));
        self::assertSame($linesA[0], $invoiceA['lines'][0]['financialCalculationLineId']);

        // B n'est pas considérée facturée : toujours facturable, avec la contrainte de
        // verrouillage rendue visible (jamais masquée).
        $worklistB = $this->worklist($client, $token, [$firmB]);
        self::assertSame($linesB, $this->billableLineIds($worklistB));
        self::assertStringContainsString('Calcul verrouillé', $worklistB['rows'][0]['reasonDetail']);
        self::assertSame(0, $worklistB['summary']['invoiced']['lineCount']);

        $invoiceB = $this->generate($client, $token, $firmB, $linesB);
        self::assertSame(201, $invoiceB->getStatusCode(), (string) $invoiceB->getContent());
        self::assertSame('120.00', $this->json($invoiceB)['totalAmount']);
        self::assertNotSame($invoiceA['id'], $this->json($invoiceB)['id']);
        self::assertSame([], $this->billableLineIds($this->worklist($client, $token, [$firmB])));
    }

    public function test_mark_paid_is_only_allowed_from_sent(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->interventionRule($firm, $type, '100.00');
        // Les deux missions AVANT toute requête HTTP : le kernel redémarre entre deux
        // requêtes, les entités de fixture appartiendraient sinon à un EntityManager périmé.
        $m1 = $this->mission(MissionStatus::VALIDATED, $firm, $type);
        $m2 = $this->mission(MissionStatus::VALIDATED, $firm, $type);
        $this->calculateAndApprove($client, $token, $m1);
        $this->calculateAndApprove($client, $token, $m2);
        [$l1, $l2] = $this->billableLineIds($this->worklist($client, $token, [$firm]));

        $invoiceId = $this->json($this->generate($client, $token, $firm, [$l1]))['id'];

        $early = $this->post($client, $token, "/api/firm-invoices/{$invoiceId}/mark-paid");
        self::assertSame(409, $early->getStatusCode(), 'GENERATED → PAID refusé');
        self::assertSame('INVOICE_STATUS_TRANSITION_INVALID', $this->json($early)['error']['code']);

        $sent = $this->post($client, $token, "/api/firm-invoices/{$invoiceId}/issue");
        self::assertSame(200, $sent->getStatusCode(), (string) $sent->getContent());
        self::assertSame(['markPaid'], $this->json($sent)['allowedActions']);

        $paid = $this->post($client, $token, "/api/firm-invoices/{$invoiceId}/mark-paid");
        self::assertSame(200, $paid->getStatusCode(), (string) $paid->getContent());
        self::assertSame('PAID', $this->json($paid)['status']);
        self::assertSame([], $this->json($paid)['allowedActions']);

        $again = $this->post($client, $token, "/api/firm-invoices/{$invoiceId}/mark-paid");
        self::assertSame(409, $again->getStatusCode(), 'PAID → PAID refusé proprement');

        $cancelledId = $this->json($this->generate($client, $token, $firm, [$l2]))['id'];
        self::assertSame(200, $this->post($client, $token, "/api/firm-invoices/{$cancelledId}/cancel", ['reason' => 'test'])->getStatusCode());
        $cancelledPaid = $this->post($client, $token, "/api/firm-invoices/{$cancelledId}/mark-paid");
        self::assertSame(409, $cancelledPaid->getStatusCode(), 'CANCELLED → PAID refusé');
        self::assertSame('INVOICE_STATUS_TRANSITION_INVALID', $this->json($cancelledPaid)['error']['code']);

        // Liste des factures filtrée sur plusieurs firmes (OU).
        $list = $this->json($this->get($client, $token, sprintf('/api/firm-invoices?from=%s&to=%s&firmIds[]=%d&firmIds[]=999999999', self::FROM, self::TO, $firm->getId())));
        self::assertCount(2, $list);
    }

    // ── Période : dates métier, jamais décalées par le fuseau ────────────

    public function test_iso_period_is_read_as_brussels_business_dates_for_period_and_number(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->interventionRule($firm, $type, '100.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firm, $type, date: '2027-01-05 08:00:00'));

        $w = $this->json($this->get($client, $token, sprintf('/api/firm-billing/worklist?from=2027-01-01&to=2027-01-31&firmIds[]=%d', $firm->getId())));
        $lineId = $this->billableLineIds($w)[0];

        // Ancien envoi du frontend : minuit Bruxelles exprimé en UTC (veille, 23:00Z).
        $r = $this->post($client, $token, '/api/firm-invoices/from-financial-calculations', [
            'firmId' => $firm->getId(), 'currency' => 'EUR',
            'periodStart' => '2026-12-31T23:00:00.000Z', 'periodEnd' => '2027-01-31T22:59:59.000Z',
            'selectedFinancialCalculationLineIds' => [$lineId],
        ]);

        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('2027-01-01', $this->json($r)['periodStart']);
        self::assertSame('2027-01-31', $this->json($r)['periodEnd']);
        self::assertStringStartsWith('FIRM-2027-', $this->json($r)['number']);
    }

    public function test_worklist_requires_business_dates_valid_filters_and_manager_role(): void
    {
        $client = $this->boot();
        $manager = $this->login($client, $this->user('ROLE_MANAGER'));
        self::assertSame(422, $this->get($client, $manager, '/api/firm-billing/worklist?from=2026-09-31&to=2026-09-30')->getStatusCode());
        self::assertSame(422, $this->get($client, $manager, '/api/firm-billing/worklist?from=2026-10-01&to=2026-09-30')->getStatusCode());
        self::assertSame(422, $this->get($client, $manager, '/api/firm-billing/worklist?from=2026-09-01&to=2026-09-30&type=OTHER')->getStatusCode());
        self::assertSame(422, $this->get($client, $manager, '/api/firm-billing/worklist?from=2026-09-01&to=2026-09-30&status=PAID')->getStatusCode());
        self::assertSame(404, $this->get($client, $manager, '/api/firm-billing/worklist?from=2026-09-01&to=2026-09-30&firmIds[]=999999999')->getStatusCode());
        self::assertSame(422, $this->post($client, $manager, '/api/firm-billing/worklist/export', ['from' => self::FROM, 'to' => self::TO, 'keys' => [], 'format' => 'xlsx'])->getStatusCode());
        self::assertSame(422, $this->post($client, $manager, '/api/firm-billing/calculations', ['missionIds' => []])->getStatusCode());

        $instr = $this->login($client, $this->user('ROLE_INSTRUMENTIST'));
        self::assertSame(403, $this->get($client, $instr, '/api/firm-billing/worklist?from=2026-09-01&to=2026-09-30')->getStatusCode());
        self::assertSame(403, $this->post($client, $instr, '/api/firm-billing/worklist/export', ['from' => self::FROM, 'to' => self::TO, 'keys' => ['x'], 'format' => 'pdf'])->getStatusCode());
        self::assertSame(403, $this->post($client, $instr, '/api/firm-billing/calculations', ['missionIds' => [1]])->getStatusCode());
    }
}
