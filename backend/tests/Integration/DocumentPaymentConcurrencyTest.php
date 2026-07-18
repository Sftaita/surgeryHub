<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoice;
use App\Entity\Hospital;
use App\Entity\InstrumentistRate;
use App\Entity\InterventionType;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\Payment;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PaymentMethod;
use App\Enum\PricingRuleType;
use App\Service\AuditService;
use App\Service\DocumentPaymentService;
use App\Service\FinancialCalculationService;
use App\Service\FirmInvoiceService;
use App\Service\InstrumentistRateResolver;
use App\Service\MissionExecutionService;
use App\Service\PricingRuleResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — §19 du lot : deux managers encodant un
 * paiement simultanément sur le MÊME document ne doivent jamais pouvoir dépasser le
 * solde. Même méthode que les Lots 3/4 : connexions DBAL réellement distinctes.
 */
final class DocumentPaymentConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = [
        'payments' => [], 'invoices' => [], 'missions' => [], 'interventions' => [],
        'calculations' => [], 'rules' => [], 'rates' => [], 'types' => [], 'firms' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->created['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            foreach ($this->created['users'] as $userId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $userId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->created['payments'] as $id) { $e = $this->em->find(Payment::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['invoices'] as $id) { $e = $this->em->find(FirmInvoice::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) {
                $calc = $this->em->find(FinancialCalculation::class, $id);
                if ($calc) { foreach ($calc->getLines() as $l) { $this->em->remove($l); } }
            }
            $this->em->flush();
            foreach ($this->created['calculations'] as $id) { $e = $this->em->find(FinancialCalculation::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['interventions'] as $id) { $e = $this->em->find(MissionIntervention::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['rates'] as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['rules'] as $id) { $e = $this->em->find(PricingRule::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->created['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->created['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function financialCalculationServiceFor(EntityManagerInterface $em): FinancialCalculationService
    {
        $audit = new AuditService($em);
        return new FinancialCalculationService(
            $em, new PricingRuleResolver($em), new InstrumentistRateResolver($em),
            new MissionExecutionService($em, $audit), $audit,
        );
    }

    private function firmInvoiceServiceFor(EntityManagerInterface $em): FirmInvoiceService
    {
        return new FirmInvoiceService($em, $this->financialCalculationServiceFor($em), new AuditService($em));
    }

    private function paymentServiceFor(EntityManagerInterface $em): DocumentPaymentService
    {
        return new DocumentPaymentService($em, new AuditService($em));
    }

    private function setLockTimeout(EntityManagerInterface $em, int $seconds): void
    {
        $em->getConnection()->executeStatement("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function isLockTimeoutError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Lock wait timeout') || str_contains($message, '1205');
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('dpc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u); $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    /** Facture SENT de 1000.00 EUR, prête à recevoir des paiements. */
    private function makeSentInvoiceOf1000(): array
    {
        $firm = new Firm();
        $firm->setName('DPC-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm); $this->em->flush();
        $this->created['firms'][] = $firm->getId();

        $type = new InterventionType();
        $type->setCode('DPC-' . bin2hex(random_bytes(3)));
        $type->setLabel('DPC');
        $this->em->persist($type); $this->em->flush();
        $this->created['types'][] = $type->getId();

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType(PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('1000.00');
        $this->em->persist($rule); $this->em->flush();
        $this->created['rules'][] = $rule->getId();

        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType(InstrumentistRateType::HOURLY_RATE);
        $rate->setAmount('40.00');
        $rate->setCurrency('EUR');
        $rate->setValidFrom(new \DateTimeImmutable('2020-01-01'));
        $this->em->persist($rate); $this->em->flush();
        $this->created['rates'][] = $rate->getId();

        $site = new Hospital();
        $site->setName('DPC-Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($site); $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $today = new \DateTimeImmutable('2026-06-15 08:00:00');
        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt($today);
        $mission->setEndAt($today->modify('+1 hour'));
        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setInstrumentist($instrumentist);
        $this->em->persist($mission); $this->em->flush();
        $this->created['missions'][] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode($type->getCode());
        $intervention->setLabel('DPC');
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $this->em->persist($intervention); $this->em->flush();
        $this->created['interventions'][] = $intervention->getId();
        $mission->getInterventions()->add($intervention);

        $actor = $this->makeUser('ROLE_MANAGER');
        $calcService = $this->financialCalculationServiceFor($this->em);
        $calc = $calcService->calculate($mission, $actor);
        $calc = $calcService->approve($calc, $actor);
        $this->created['calculations'][] = $calc->getId();
        $firmLine = $calc->getLines()->filter(static fn ($l) => $l->getLineType()->value === 'FIRM_INTERVENTION_FEE')->first();

        $invoiceService = $this->firmInvoiceServiceFor($this->em);
        $invoice = $invoiceService->createFromEligibleLines($firm, 'EUR', $today->modify('-1 day'), $today->modify('+1 day'), [$firmLine->getId()], $actor);
        $invoice = $invoiceService->issue($invoice, $actor);
        $this->created['invoices'][] = $invoice->getId();

        return [$invoice, $actor];
    }

    public function test_two_concurrent_payments_never_exceed_the_remaining_balance(): void
    {
        [$invoice, $actor] = $this->makeSentInvoiceOf1000();
        $invoiceId = $invoice->getId();
        $actorId = $actor->getId();

        // Worker B : tient le verrou pessimiste sur la facture, transaction non committée
        // — reproduit la fenêtre de contention réelle de recordPayment() (verrouille le
        // document avant de calculer le solde).
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $invoiceB = $emB->find(FirmInvoice::class, $invoiceId);
        $emB->lock($invoiceB, LockMode::PESSIMISTIC_WRITE);

        // Worker A : tentative réelle de paiement de 800 EUR (sur un solde de 1000),
        // EntityManager frais avec timeout court — doit être bloqué réellement.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA, self::LOCK_TIMEOUT_SECONDS);
        $invoiceA = $emA->find(FirmInvoice::class, $invoiceId);
        $actorA = $emA->find(User::class, $actorId);

        $blocked = false;
        try {
            $this->paymentServiceFor($emA)->recordPayment(
                $invoiceA, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'),
                PaymentMethod::BANK_TRANSFER, null, null, $actorA,
            );
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeoutError($e);
        }
        self::assertTrue($blocked, 'recordPayment() doit être réellement bloqué par le verrou pessimiste tenu sur la même facture.');

        // B libère le verrou (il ne représentait qu'un concurrent en cours).
        $emB->getConnection()->rollBack();

        // A retente avec un EntityManager frais : 800 EUR doit passer (solde = 1000).
        $emA2 = $this->freshEntityManager();
        $invoiceA2 = $emA2->find(FirmInvoice::class, $invoiceId);
        $actorA2 = $emA2->find(User::class, $actorId);
        $payment1 = $this->paymentServiceFor($emA2)->recordPayment(
            $invoiceA2, '800.00', 'EUR', new \DateTimeImmutable('2026-06-20'),
            PaymentMethod::BANK_TRANSFER, null, null, $actorA2,
        );
        $this->created['payments'][] = $payment1->getId();

        // Une seconde tentative de 300 EUR (solde restant = 200) doit maintenant être
        // refusée par la validation métier normale (pas par un verrou — B a disparu) :
        // preuve qu'aucun état incohérent n'a pu être atteint par la contention.
        $emA3 = $this->freshEntityManager();
        $invoiceA3 = $emA3->find(FirmInvoice::class, $invoiceId);
        $actorA3 = $emA3->find(User::class, $actorId);
        $this->expectException(\App\Exception\PaymentExceedsRemainingException::class);
        $this->paymentServiceFor($emA3)->recordPayment(
            $invoiceA3, '300.00', 'EUR', new \DateTimeImmutable('2026-06-25'),
            PaymentMethod::BANK_TRANSFER, null, null, $actorA3,
        );
    }
}
