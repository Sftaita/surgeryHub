<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
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
 * D-123 — cockpit « Facturation firmes » + contrat de génération/cycle de vie, contre une
 * vraie base et via HTTP (le SQL de classement, les verrous et la contrainte UNIQUE ne sont
 * vérifiables qu'ainsi). Missions en septembre 2026 ; chaque appel cockpit est filtré sur
 * une firme créée par le test, jamais dépendant des autres données de la base.
 */
final class FirmBillingCockpitControllerTest extends WebTestCase
{
    private const PASSWORD = 'Cockpit123!';
    private const FROM = '2026-09-01';
    private const TO = '2026-09-30';

    private EntityManagerInterface $em;
    private array $created = [
        'missions' => [], 'rules' => [], 'rates' => [], 'items' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
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
                    $invoice = $fil->getInvoice();
                    $this->em->remove($invoice);
                }
                $this->em->flush();
                foreach ($this->em->getRepository(FirmInvoice::class)->findBy(['firm' => $this->created['firms']]) as $inv) {
                    $this->em->remove($inv);
                }
                $this->em->flush();
                foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $missionIds]) as $calc) {
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
        $u->setEmail('cockpit-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('Cockpit');
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
        $f->setName('CKP-' . $name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->created['firms'][] = $f->getId();
        return $f;
    }

    private function type(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('CKP-' . bin2hex(random_bytes(3)));
        $t->setLabel('Ligamentoplastie');
        $this->em->persist($t); $this->em->flush();
        $this->created['types'][] = $t->getId();
        return $t;
    }

    private function item(Firm $firm): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($firm);
        $i->setLabel('Ancre ' . bin2hex(random_bytes(2)));
        $i->setUnit('pièce');
        $i->setReferenceCode('REF-' . bin2hex(random_bytes(3)));
        $this->em->persist($i); $this->em->flush();
        $this->created['items'][] = $i->getId();
        return $i;
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

    private function instrumentist(): User
    {
        $u = $this->user('ROLE_INSTRUMENTIST');
        $r = new InstrumentistRate();
        $r->setInstrumentist($u);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount('40.00');
        $r->setCurrency('EUR');
        $r->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($r); $this->em->flush();
        $this->created['rates'][] = $r->getId();
        return $u;
    }

    /**
     * Mission dont l'intervention a pour firme principale $interventionFirm, avec
     * optionnellement du matériel ($materialItem, quantité $qty) — éventuellement d'une
     * autre firme (cas multi-firmes).
     */
    private function mission(MissionStatus $status, Firm $interventionFirm, InterventionType $type, ?MaterialItem $materialItem = null, string $qty = '2.00', string $date = '2026-09-10 08:00:00'): Mission
    {
        $site = new Hospital();
        $site->setName('CKP-Delta-' . bin2hex(random_bytes(2)));
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
        $m->setInstrumentist($this->instrumentist());
        $this->em->persist($m); $this->em->flush();
        $this->created['missions'][] = $m->getId();

        $itv = new MissionIntervention();
        $itv->setMission($m);
        $itv->setCode($type->getCode());
        $itv->setLabel('Ligamentoplastie LCA');
        $itv->setInterventionType($type);
        $itv->setPrimaryFirm($interventionFirm);
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

    private function cockpit(KernelBrowser $client, string $token, Firm $firm): array
    {
        $r = $this->get($client, $token, sprintf('/api/firm-invoices/cockpit?from=%s&to=%s&firmId=%d', self::FROM, self::TO, $firm->getId()));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        return $this->json($r);
    }

    private function calculateAndApprove(KernelBrowser $client, string $token, Mission $m): int
    {
        $r = $this->post($client, $token, "/api/missions/{$m->getId()}/financial-calculations");
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $calcId = $this->json($r)['id'];
        $a = $this->post($client, $token, "/api/financial-calculations/{$calcId}/approve");
        self::assertSame(200, $a->getStatusCode(), (string) $a->getContent());
        return $calcId;
    }

    /** @return int[] */
    private function toInvoiceIds(array $cockpit): array
    {
        $ids = [];
        foreach ($cockpit['toInvoice'] as $group) {
            foreach ($group['lines'] as $l) { $ids[] = $l['id']; }
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

    // ── À vérifier → à facturer, de bout en bout ─────────────────────────

    public function test_validated_mission_goes_from_calculation_required_to_pending_approval_to_invoiceable(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->interventionRule($firm, $type, '350.00');
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $type);

        $c1 = $this->cockpit($client, $token, $firm);
        self::assertCount(1, $c1['toVerify']);
        self::assertSame('CALCULATION_REQUIRED', $c1['toVerify'][0]['reason']);
        self::assertSame(['calculate'], $c1['toVerify'][0]['allowedActions']);
        self::assertNull($c1['toVerify'][0]['totalAmount'], 'aucun montant n\'existe avant le calcul — jamais estimé');
        self::assertSame([], $c1['toInvoice']);

        $calc = $this->post($client, $token, "/api/missions/{$m->getId()}/financial-calculations");
        self::assertSame(201, $calc->getStatusCode(), (string) $calc->getContent());

        $c2 = $this->cockpit($client, $token, $firm);
        self::assertSame('CALCULATION_PENDING_APPROVAL', $c2['toVerify'][0]['reason']);
        self::assertSame($this->json($calc)['id'], $c2['toVerify'][0]['calculationId']);
        self::assertSame('350.00', $c2['toVerify'][0]['totalAmount']);
        self::assertSame([], $c2['toInvoice'], 'une ligne CALCULATED n\'est jamais facturable');

        $this->post($client, $token, "/api/financial-calculations/{$c2['toVerify'][0]['calculationId']}/approve");

        $c3 = $this->cockpit($client, $token, $firm);
        self::assertSame([], $c3['toVerify']);
        self::assertCount(1, $c3['toInvoice']);
        $line = $c3['toInvoice'][0]['lines'][0];
        self::assertSame('350.00', $line['totalAmount']);
        self::assertSame('2026-09-10', $line['mission']['date']);
        self::assertSame('Ligamentoplastie LCA', $line['intervention']['label']);
        self::assertStringStartsWith('CKP-Delta-', $line['mission']['site']);
        self::assertSame(1, $c3['kpis']['toInvoiceLineCount']);
        self::assertSame([['currency' => 'EUR', 'amount' => '350.00']], $c3['kpis']['toInvoiceAmounts']);
    }

    public function test_failed_calculation_is_listed_with_its_audited_anomalies(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Stryker');
        $m = $this->mission(MissionStatus::VALIDATED, $firm, $this->type()); // aucun tarif

        $r = $this->post($client, $token, "/api/missions/{$m->getId()}/financial-calculations");
        self::assertSame(422, $r->getStatusCode());

        $c = $this->cockpit($client, $token, $firm);
        self::assertSame('CALCULATION_FAILED', $c['toVerify'][0]['reason']);
        self::assertContains('MISSING_FIRM_INTERVENTION_RATE', array_column($c['toVerify'][0]['anomalies'], 'code'));
        self::assertNotSame('', $c['toVerify'][0]['anomalies'][0]['message']);
    }

    public function test_submitted_mission_is_listed_as_encoding_not_validated(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Zimmer');
        $this->mission(MissionStatus::SUBMITTED, $firm, $this->type());

        $c = $this->cockpit($client, $token, $firm);
        self::assertSame('ENCODING_NOT_VALIDATED', $c['toVerify'][0]['reason']);
        self::assertSame([], $c['toVerify'][0]['allowedActions']);
    }

    // ── Génération ────────────────────────────────────────────────────────

    public function test_partial_selection_invoices_only_the_selected_lines_and_references_the_invoice(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $item = $this->item($firm);
        $this->interventionRule($firm, $type, '350.00');
        $this->materialRule($firm, $item, '25.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firm, $type, $item, '2.00'));

        $before = $this->cockpit($client, $token, $firm);
        $ids = $this->toInvoiceIds($before);
        self::assertCount(2, $ids);

        $r = $this->generate($client, $token, $firm, [$ids[0]]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $invoice = $this->json($r);
        self::assertCount(1, $invoice['lines']);
        self::assertSame($ids[0], $invoice['lines'][0]['financialCalculationLineId']);
        self::assertSame('2026-09-01', $invoice['periodStart']);
        self::assertSame('GENERATED', $invoice['status']);
        self::assertSame(['send', 'cancel'], $invoice['allowedActions']);

        $after = $this->cockpit($client, $token, $firm);
        self::assertSame([$ids[1]], $this->toInvoiceIds($after), 'la ligne non sélectionnée reste à facturer');
        self::assertCount(1, $after['invoiced']);
        self::assertSame($ids[0], $after['invoiced'][0]['id']);
        self::assertSame($invoice['id'], $after['invoiced'][0]['invoice']['id']);
        self::assertSame($invoice['number'], $after['invoiced'][0]['invoice']['number']);
        self::assertSame(1, $after['kpis']['invoices']['generated']);
    }

    public function test_line_of_another_firm_is_rejected(): void
    {
        $client = $this->boot();
        $token = $this->login($client, $this->user('ROLE_MANAGER'));
        $firmA = $this->firm('A');
        $firmB = $this->firm('B');
        $type = $this->type();
        $this->interventionRule($firmA, $type, '100.00');
        $this->calculateAndApprove($client, $token, $this->mission(MissionStatus::VALIDATED, $firmA, $type));
        $lineA = $this->toInvoiceIds($this->cockpit($client, $token, $firmA))[0];

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
        $lineId = $this->toInvoiceIds($this->cockpit($client, $token, $firm))[0];

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
        $invoiceId = $this->json($this->generate($client, $token, $firm, $this->toInvoiceIds($this->cockpit($client, $token, $firm))))['id'];

        $this->em->clear();
        $fresh = $this->em->find(PricingRule::class, $rule->getId());
        $fresh->setUnitPrice('999.00');
        $this->em->flush();

        $detail = $this->json($this->get($client, $token, "/api/firm-invoices/{$invoiceId}"));
        self::assertSame('350.00', $detail['totalAmount']);
        self::assertSame('350.00', $detail['lines'][0]['unitPrice']);
        self::assertSame('350.00', $detail['lines'][0]['totalAmount']);

        $pdf = $this->get($client, $token, "/api/firm-invoices/{$invoiceId}/pdf");
        self::assertSame(200, $pdf->getStatusCode());
        self::assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
    }

    // ── Plusieurs firmes dans une même mission ───────────────────────────

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

        $linesA = $this->toInvoiceIds($this->cockpit($client, $token, $firmA));
        $linesB = $this->toInvoiceIds($this->cockpit($client, $token, $firmB));
        self::assertCount(1, $linesA);
        self::assertCount(1, $linesB);

        $invoiceA = $this->json($this->generate($client, $token, $firmA, $linesA));
        self::assertSame($linesA[0], $invoiceA['lines'][0]['financialCalculationLineId']);

        // B n'est pas considérée facturée : toujours à facturer, avec la contrainte de
        // verrouillage rendue visible (jamais masquée).
        $cockpitB = $this->cockpit($client, $token, $firmB);
        self::assertSame($linesB, $this->toInvoiceIds($cockpitB));
        $lineB = $cockpitB['toInvoice'][0]['lines'][0];
        self::assertTrue($lineB['calculationLocked']);
        self::assertTrue($lineB['missionPartiallyInvoiced']);
        self::assertStringContainsString('Calcul financier verrouillé', (string) $lineB['notice']);
        self::assertSame([], $cockpitB['invoiced']);

        $invoiceB = $this->generate($client, $token, $firmB, $linesB);
        self::assertSame(201, $invoiceB->getStatusCode(), (string) $invoiceB->getContent());
        self::assertSame('120.00', $this->json($invoiceB)['totalAmount']);
        self::assertNotSame($invoiceA['id'], $this->json($invoiceB)['id']);
        self::assertSame([], $this->cockpit($client, $token, $firmB)['toInvoice']);
    }

    // ── Cycle de vie GENERATED → SENT → PAID ─────────────────────────────

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
        [$l1, $l2] = $this->toInvoiceIds($this->cockpit($client, $token, $firm));

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

        $c = $this->json($this->get($client, $token, sprintf('/api/firm-invoices/cockpit?from=2027-01-01&to=2027-01-31&firmId=%d', $firm->getId())));
        $lineId = $c['toInvoice'][0]['lines'][0]['id'];

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

    public function test_cockpit_requires_business_dates_and_manager_role(): void
    {
        $client = $this->boot();
        $manager = $this->login($client, $this->user('ROLE_MANAGER'));
        self::assertSame(422, $this->get($client, $manager, '/api/firm-invoices/cockpit?from=2026-09-31&to=2026-09-30')->getStatusCode());
        self::assertSame(422, $this->get($client, $manager, '/api/firm-invoices/cockpit?from=2026-10-01&to=2026-09-30')->getStatusCode());

        $instr = $this->login($client, $this->user('ROLE_INSTRUMENTIST'));
        self::assertSame(403, $this->get($client, $instr, '/api/firm-invoices/cockpit?from=2026-09-01&to=2026-09-30')->getStatusCode());
    }
}
