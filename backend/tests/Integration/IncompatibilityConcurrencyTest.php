<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Enum\IncompatibilityOrigin;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\PlanningAlertService;
use App\Service\SurgeonInstrumentistIncompatibilityService;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-152 — a claim (or any affectation) and the creation of an incompatibility for the same pair
 * are serialised by MySQL, on two REAL connections:
 *
 * 1. while an affectation's transaction holds its locking read of the (surgeon, instrumentist)
 *    range, the creation of the incompatibility cannot insert — it waits (here: times out), so it
 *    will see the new affectation and raise it as a conflict;
 * 2. while a creation is uncommitted, the affectation's locking read waits (here: times out) —
 *    it can never slip through as if no incompatibility existed.
 */
final class IncompatibilityConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $userIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $ids = implode(',', $this->userIds ?: [0]);
        $conn->executeStatement("DELETE FROM surgeon_instrumentist_incompatibility WHERE surgeon_id IN ($ids)");
        $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($ids)");
        $conn->executeStatement("DELETE FROM `user` WHERE id IN ($ids)");
        parent::tearDown();
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d152conc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        $em = new EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
        $em->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_TIMEOUT_SECONDS);

        return $em;
    }

    private function incompatibilitiesFor(EntityManagerInterface $em): SurgeonInstrumentistIncompatibilityService
    {
        return new SurgeonInstrumentistIncompatibilityService(
            $em,
            new AuditService($em),
            new PlanningAlertService($em),
            self::getContainer()->get(MessageBusInterface::class),
            $em->getRepository(User::class),
        );
    }

    public function test_a_creation_waits_for_an_affectation_holding_its_locking_read(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');

        $emClaim = $this->freshEntityManager();
        $emClaim->getConnection()->beginTransaction();
        self::assertFalse((new MissionEligibilityService($emClaim))->isIncompatibleLocked(
            $emClaim->find(User::class, $surgeon->getId()), $emClaim->find(User::class, $instrumentist->getId()),
        ));

        $emBlock = $this->freshEntityManager();
        try {
            $this->incompatibilitiesFor($emBlock)->create(
                $emBlock->find(User::class, $surgeon->getId()), $emBlock->find(User::class, $instrumentist->getId()),
                IncompatibilityOrigin::MANAGER, $emBlock->find(User::class, $surgeon->getId()),
            );
            self::fail('The creation must wait for the affectation transaction.');
        } catch (LockWaitTimeoutException) {
        } finally {
            $emClaim->getConnection()->rollBack();
            $emClaim->getConnection()->close();
            $emBlock->getConnection()->close();
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM surgeon_instrumentist_incompatibility WHERE surgeon_id = ?', [$surgeon->getId()],
        ));
    }

    public function test_an_affectation_cannot_read_past_an_uncommitted_creation(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');

        $emBlock = $this->freshEntityManager();
        $emBlock->getConnection()->beginTransaction();
        $this->incompatibilitiesFor($emBlock)->createInTransaction(
            $emBlock->find(User::class, $surgeon->getId()), $emBlock->find(User::class, $instrumentist->getId()),
            IncompatibilityOrigin::MANAGER, $emBlock->find(User::class, $surgeon->getId()),
        );

        $emClaim = $this->freshEntityManager();
        $emClaim->getConnection()->beginTransaction();
        try {
            (new MissionEligibilityService($emClaim))->isIncompatibleLocked(
                $emClaim->find(User::class, $surgeon->getId()), $emClaim->find(User::class, $instrumentist->getId()),
            );
            self::fail('The locking read must wait for the uncommitted creation.');
        } catch (LockWaitTimeoutException) {
        } finally {
            $emClaim->getConnection()->rollBack();
            $emClaim->getConnection()->close();
        }

        // Once the creation commits, the affectation sees it.
        $emBlock->getConnection()->commit();
        $emBlock->getConnection()->close();
        $emAfter = $this->freshEntityManager();
        $emAfter->getConnection()->beginTransaction();
        self::assertTrue((new MissionEligibilityService($emAfter))->isIncompatibleLocked(
            $emAfter->find(User::class, $surgeon->getId()), $emAfter->find(User::class, $instrumentist->getId()),
        ));
        $emAfter->getConnection()->rollBack();
        $emAfter->getConnection()->close();
    }
}
