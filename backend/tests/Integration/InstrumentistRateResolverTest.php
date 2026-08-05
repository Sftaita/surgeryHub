<?php

namespace App\Tests\Integration;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Service\InstrumentistRateResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Vérification tarif 0€ (2026-08-05) — InstrumentistRateResolver::resolve() ne doit
 * jamais utiliser le MONTANT pour décider si un tarif existe, uniquement la couverture
 * de date (coversDate()). Un tarif à 0.00 est un tarif réel, pas une absence de tarif.
 */
final class InstrumentistRateResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InstrumentistRateResolver $resolver;
    private array $createdUserIds = [];
    private array $createdRateIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(InstrumentistRateResolver::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->createdRateIds as $id) {
                $e = $this->em->find(InstrumentistRate::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
        $u->setEmail('rateresolver-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function makeRate(User $instrumentist, string $amount, \DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo = null): InstrumentistRate
    {
        $r = new InstrumentistRate();
        $r->setInstrumentist($instrumentist);
        $r->setRateType(InstrumentistRateType::HOURLY_RATE);
        $r->setAmount($amount);
        $r->setCurrency('EUR');
        $r->setValidFrom($validFrom);
        $r->setValidTo($validTo);
        $this->em->persist($r);
        $this->em->flush();
        $this->createdRateIds[] = $r->getId();
        return $r;
    }

    public function test_zero_amount_rate_covering_today_with_open_end_is_resolved(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $rate = $this->makeRate($instrumentist, '0.00', new \DateTimeImmutable('2020-01-01'), null);

        $resolved = $this->resolver->resolve($instrumentist, InstrumentistRateType::HOURLY_RATE, new \DateTimeImmutable('today'));

        self::assertNotNull($resolved, 'un tarif à 0.00 EUR est un tarif réel — resolve() ne doit jamais retourner null pour ce cas');
        self::assertSame($rate->getId(), $resolved->getId());
        self::assertSame('0.00', $resolved->getAmount());
    }

    public function test_zero_amount_rate_is_resolved_identically_to_a_nonzero_rate(): void
    {
        $zeroInstrumentist = $this->makeInstrumentist();
        $paidInstrumentist = $this->makeInstrumentist();
        $this->makeRate($zeroInstrumentist, '0.00', new \DateTimeImmutable('2020-01-01'));
        $this->makeRate($paidInstrumentist, '45.00', new \DateTimeImmutable('2020-01-01'));

        $today = new \DateTimeImmutable('today');
        $zeroResolved = $this->resolver->resolve($zeroInstrumentist, InstrumentistRateType::HOURLY_RATE, $today);
        $paidResolved = $this->resolver->resolve($paidInstrumentist, InstrumentistRateType::HOURLY_RATE, $today);

        self::assertNotNull($zeroResolved);
        self::assertNotNull($paidResolved);
    }

    public function test_no_rate_at_all_resolves_to_null(): void
    {
        $instrumentist = $this->makeInstrumentist(); // aucun tarif créé

        $resolved = $this->resolver->resolve($instrumentist, InstrumentistRateType::HOURLY_RATE, new \DateTimeImmutable('today'));

        self::assertNull($resolved, 'absence de InstrumentistRate applicable — seul vrai cas "tarif manquant"');
    }
}
