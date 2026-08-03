<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\InstrumentistRate;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PricingRuleType;
use App\Service\FinancialCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Audit "la tarification du matériel par firme ne fonctionne pas" — preuve du scénario
 * complet demandé : Firme X → matériel Y → tarif 125 € créé via le VRAI endpoint HTTP
 * (pas une insertion directe) → reload → 125,00 € visible → calcul financier réel
 * utilisant ce matériel → ligne financière à 125,00 €.
 *
 * Root cause du signalement (voir docs, rapport de session) : aucun défaut de code — la
 * chaîne create/read/resolve/apply était déjà correcte (FirmBillingController →
 * PricingRuleVersioningService → PricingRuleWriteService → PricingRuleResolver →
 * FinancialCalculationService). Le blocage observé venait d'un environnement local dont
 * la migration D-092 (Version20260802120000, colonne material_item.billing_status entre
 * autres) n'avait jamais été appliquée — 500 sur la moindre création de MaterialItem.
 * Ce test vérifie la chaîne réelle post-migration, pas seulement l'affichage React.
 */
final class MaterialPricingEndToEndTest extends WebTestCase
{
    private const PASSWORD = 'MatPricingE2E15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'users' => [], 'firms' => [], 'types' => [], 'items' => [], 'rules' => [],
        'sites' => [], 'missions' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($this->em->getRepository(FinancialCalculation::class)->findBy(['mission' => $m]) as $calc) {
                        foreach ($this->em->getRepository(\App\Entity\FinancialCalculationLine::class)->findBy(['financialCalculation' => $calc]) as $line) {
                            $this->em->remove($line);
                        }
                        $this->em->flush();
                        $this->em->remove($calc);
                    }
                    $this->em->flush();
                    foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $m]) as $ml) {
                        $this->em->remove($ml);
                    }
                    $this->em->flush();
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['rules'] as $id) {
                $e = $this->em->find(PricingRule::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['items'] as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['firms'] as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            // AuditEvent (recordGlobal sur la création du tarif) + InstrumentistRate avant
            // les utilisateurs (FK actor_id / instrumentist_id).
            foreach ($this->createdIds['users'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
                foreach ($this->em->getRepository(\App\Entity\InstrumentistRate::class)->findBy(['instrumentist' => $id]) as $rate) {
                    $this->em->remove($rate);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('mat-pricing-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    private function request(KernelBrowser $client, string $method, string $uri, string $token, ?array $body = null): Response
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: $body !== null ? json_encode($body) : null);
        return $client->getResponse();
    }

    public function test_material_tariff_created_via_the_real_endpoint_is_reloaded_and_actually_used_by_a_real_financial_calculation(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        // ── Firme X → matériel Y ────────────────────────────────────────────
        $firm = new Firm();
        $firm->setName('FirmX-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $this->em->flush();
        $this->createdIds['firms'][] = $firm->getId();

        $createItemResponse = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firm->getId(),
            'label' => 'Agrafe de test',
            'unit' => 'pièce',
            'referenceCode' => 'STAPLE-E2E',
            'isImplant' => false,
        ]);
        self::assertSame(Response::HTTP_CREATED, $createItemResponse->getStatusCode(), (string) $createItemResponse->getContent());
        $itemBody = json_decode((string) $createItemResponse->getContent(), true);
        $itemId = $itemBody['id'];
        $this->createdIds['items'][] = $itemId;
        self::assertSame('UNSPECIFIED', $itemBody['billingStatus'], 'Un matériel jamais tarifé doit être UNSPECIFIED, jamais NOT_BILLABLE par défaut.');

        // ── Tarif 125 € — le VRAI endpoint HTTP, pas une insertion directe ──
        $createRuleResponse = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'MATERIAL_FEE',
            'materialItemId' => $itemId,
            'unitPrice' => 125,
        ]);
        self::assertSame(Response::HTTP_CREATED, $createRuleResponse->getStatusCode(), (string) $createRuleResponse->getContent());
        $ruleBody = json_decode((string) $createRuleResponse->getContent(), true);
        $this->createdIds['rules'][] = $ruleBody['id'];

        // ── Sauvegarde → reload : le montant doit revenir tel quel ──────────
        $reloadResponse = $this->request($client, 'GET', "/api/firms/{$firm->getId()}/pricing-rules", $token);
        self::assertSame(Response::HTTP_OK, $reloadResponse->getStatusCode());
        $reloaded = json_decode((string) $reloadResponse->getContent(), true);
        self::assertCount(1, $reloaded);
        self::assertSame('125.00', $reloaded[0]['unitPrice']);

        // ── Auto-promotion : poser un tarif rend le matériel BILLABLE ───────
        $reloadedItemResponse = $this->request($client, 'GET', "/api/material-items/{$itemId}", $token);
        self::assertSame('BILLABLE', json_decode((string) $reloadedItemResponse->getContent(), true)['billingStatus']);

        // ── Mission réelle, VALIDATED, avec ce matériel ─────────────────────
        $this->em->clear();
        $item = $this->em->find(MaterialItem::class, $itemId);
        $firmForMission = $this->em->find(Firm::class, $firm->getId());

        $type = new InterventionType();
        $type->setCode('E2E-' . bin2hex(random_bytes(4)));
        $type->setLabel('Type E2E');
        $this->em->persist($type);

        $site = new Hospital();
        $site->setName('SiteE2E-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdIds['types'][] = $type->getId();
        $this->createdIds['sites'][] = $site->getId();

        $surgeon = $this->createUser('ROLE_SURGEON');
        $instrumentist = $this->createUser('ROLE_INSTRUMENTIST');

        // Hors périmètre du diagnostic (forfait d'intervention, taux horaire) — présents
        // uniquement pour que le calcul global n'échoue pas sur des anomalies sans
        // rapport avec ce qui est testé ici (le tarif matériel).
        $interventionFeeRule = new PricingRule();
        $interventionFeeRule->setFirm($firmForMission);
        $interventionFeeRule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $interventionFeeRule->setInterventionType($type);
        $interventionFeeRule->setUnitPrice('50.00');
        $this->em->persist($interventionFeeRule);

        $rate = new InstrumentistRate();
        $rate->setInstrumentist($this->em->find(User::class, $instrumentist->getId()));
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate);
        $this->em->flush();
        $this->createdIds['rules'][] = $interventionFeeRule->getId();

        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($this->em->find(User::class, $surgeon->getId()));
        $mission->setCreatedBy($this->em->find(User::class, $surgeon->getId()));
        $mission->setStartAt(new \DateTimeImmutable('2026-06-01 08:00:00', new \DateTimeZone(self::TZ)));
        $mission->setEndAt(new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone(self::TZ)));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($this->em->find(User::class, $instrumentist->getId()));
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdIds['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel($type->getLabel());
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($this->em->find(Firm::class, $firm->getId()));
        $this->em->persist($intervention);
        $this->em->flush();
        $mission->getInterventions()->add($intervention);

        $matLine = new MaterialLine();
        $matLine->setMission($mission);
        $matLine->setMissionIntervention($intervention);
        $matLine->setItem($item);
        $matLine->setQuantity('1.00');
        $matLine->setCreatedBy($this->em->find(User::class, $surgeon->getId()));
        $this->em->persist($matLine);
        $this->em->flush();
        $mission->getMaterialLines()->add($matLine);

        // ── Calcul financier réel — le service réellement utilisé en production ──
        /** @var FinancialCalculationService $service */
        $service = static::getContainer()->get(FinancialCalculationService::class);
        $managerUser = $this->em->find(User::class, $manager->getId());
        $calculation = $service->calculate($mission, $managerUser);

        $materialLine = null;
        foreach ($calculation->getLines() as $line) {
            if ($line->getMaterialLine()?->getId() === $matLine->getId()) {
                $materialLine = $line;
                break;
            }
        }
        self::assertNotNull($materialLine, 'Le calcul financier doit produire une ligne pour ce matériel — la preuve que le tarif 125 € est réellement consommé, pas seulement affiché.');
        self::assertEqualsWithDelta(125.0, (float) $materialLine->getGrossAmount(), 0.001, "Montant brut attendu 125.00, obtenu {$materialLine->getGrossAmount()}.");
        self::assertEqualsWithDelta(125.0, (float) $materialLine->getTotalAmount(), 0.001, "Montant total attendu 125.00, obtenu {$materialLine->getTotalAmount()}.");

        // ── G. Historique financier : corriger la référence après coup ne réécrit rien ──
        $this->em->clear();
        $itemToRename = $this->em->find(MaterialItem::class, $itemId);
        $itemToRename->setReferenceCode('STAPLE-CORRIGEE');
        $this->em->flush();

        $this->em->clear();
        $reloadedCalc = $this->em->find(FinancialCalculation::class, $calculation->getId());
        $reloadedLine = null;
        foreach ($reloadedCalc->getLines() as $line) {
            if ($line->getMaterialLine()?->getId() === $matLine->getId()) {
                $reloadedLine = $line;
                break;
            }
        }
        self::assertNotNull($reloadedLine);
        self::assertEqualsWithDelta(125.0, (float) $reloadedLine->getTotalAmount(), 0.001, 'Corriger la référence catalogue après coup ne doit jamais altérer un montant financier déjà figé.');
    }
}
