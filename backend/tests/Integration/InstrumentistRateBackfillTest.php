<?php

namespace App\Tests\Integration;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — vérifie la logique de backfill exacte
 * de la migration Version20260718121937 : reproduit ses deux requêtes INSERT...SELECT
 * sur un utilisateur de test fraîchement créé (0 ligne réelle en production au moment
 * de ce lot — voir docblock de la migration), pour prouver que la stratégie documentée
 * (amount préservé, currency = defaultCurrency, validFrom = DATE(createdAt), validTo
 * = NULL) produit bien le résultat attendu si des données réelles existaient.
 */
final class InstrumentistRateBackfillTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdUserIds = [];
    private array $createdRateIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->createdRateIds as $id) { $e = $this->em->find(InstrumentistRate::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /**
     * Reproduit exactement les deux requêtes de Version20260718121937::up(), scopées au
     * seul utilisateur de test (`AND id = :userId`) pour l'isolation du test — la
     * migration réelle n'a pas ce filtre (elle s'exécute une fois, sur toute la table).
     */
    private function runBackfill(int $userId): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('
            INSERT INTO instrumentist_rate (instrumentist_id, rate_type, amount, currency, valid_from, valid_to, created_at, updated_at)
            SELECT id, \'HOURLY_RATE\', hourly_rate, COALESCE(NULLIF(default_currency, \'\'), \'EUR\'), DATE(created_at), NULL, NOW(), NOW()
            FROM user WHERE hourly_rate IS NOT NULL AND id = :userId
        ', ['userId' => $userId]);
        $conn->executeStatement('
            INSERT INTO instrumentist_rate (instrumentist_id, rate_type, amount, currency, valid_from, valid_to, created_at, updated_at)
            SELECT id, \'CONSULTATION_FEE\', consultation_fee, COALESCE(NULLIF(default_currency, \'\'), \'EUR\'), DATE(created_at), NULL, NOW(), NOW()
            FROM user WHERE consultation_fee IS NOT NULL AND id = :userId
        ', ['userId' => $userId]);
    }

    public function test_backfill_preserves_hourly_rate_value_and_uses_created_at_as_valid_from(): void
    {
        $user = new User();
        $user->setEmail('backfill-' . bin2hex(random_bytes(4)) . '@test.com');
        $user->setRoles(['ROLE_INSTRUMENTIST']);
        $user->setActive(true);
        $user->setHourlyRate('38.50');
        $user->setDefaultCurrency('EUR');
        $this->em->persist($user);
        $this->em->flush();
        $this->createdUserIds[] = $user->getId();
        $createdAtDate = $user->getCreatedAt()->format('Y-m-d');

        $this->runBackfill($user->getId());

        $rate = $this->em->getRepository(InstrumentistRate::class)->findOneBy([
            'instrumentist' => $user, 'rateType' => InstrumentistRateType::HOURLY_RATE,
        ]);
        self::assertNotNull($rate, 'le backfill doit avoir créé une InstrumentistRate');
        $this->createdRateIds[] = $rate->getId();

        self::assertSame('38.50', $rate->getAmount(), 'la valeur actuelle doit être préservée telle quelle');
        self::assertSame('EUR', $rate->getCurrency());
        self::assertSame($createdAtDate, $rate->getValidFrom()->format('Y-m-d'), 'validFrom = date de création utilisateur (priorité documentée D-072 §5.2)');
        self::assertNull($rate->getValidTo(), 'ouvert — tarif actuellement en vigueur');
    }

    public function test_backfill_skips_users_without_a_rate(): void
    {
        $user = new User();
        $user->setEmail('backfill-none-' . bin2hex(random_bytes(4)) . '@test.com');
        $user->setRoles(['ROLE_INSTRUMENTIST']);
        $user->setActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->createdUserIds[] = $user->getId();

        $this->runBackfill($user->getId());

        $rate = $this->em->getRepository(InstrumentistRate::class)->findOneBy(['instrumentist' => $user]);
        self::assertNull($rate, 'aucun tarif à backfiller pour un utilisateur sans hourlyRate/consultationFee');
    }

    public function test_backfill_creates_both_rate_types_independently(): void
    {
        $user = new User();
        $user->setEmail('backfill-both-' . bin2hex(random_bytes(4)) . '@test.com');
        $user->setRoles(['ROLE_INSTRUMENTIST']);
        $user->setActive(true);
        $user->setHourlyRate('40.00');
        $user->setConsultationFee('25.00');
        $this->em->persist($user);
        $this->em->flush();
        $this->createdUserIds[] = $user->getId();

        $this->runBackfill($user->getId());

        $hourly = $this->em->getRepository(InstrumentistRate::class)->findOneBy(['instrumentist' => $user, 'rateType' => InstrumentistRateType::HOURLY_RATE]);
        $consultation = $this->em->getRepository(InstrumentistRate::class)->findOneBy(['instrumentist' => $user, 'rateType' => InstrumentistRateType::CONSULTATION_FEE]);
        self::assertNotNull($hourly);
        self::assertNotNull($consultation);
        $this->createdRateIds[] = $hourly->getId();
        $this->createdRateIds[] = $consultation->getId();

        self::assertSame('40.00', $hourly->getAmount());
        self::assertSame('25.00', $consultation->getAmount());
    }
}
