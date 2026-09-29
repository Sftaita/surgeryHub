<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Exception\ReleasedRoomSlotConflictException;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\PlanningConflictDetectionService;
use App\Service\ReleasedRoomSlotTakeoverService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-124 — preuves de concurrence sur de VRAIES connexions MySQL distinctes (même technique que
 * MissionStartDueConcurrencyTest / BlockManagementCommunicationConcurrencyTest) :
 *
 * 1. Deux chirurgiens chargent le même créneau AVAILABLE ; le premier reprend ; le second,
 *    avec une entité en mémoire devenue périmée (toujours AVAILABLE), doit recevoir
 *    ROOM_SLOT_ALREADY_TAKEN et AUCUNE Mission ne doit être créée pour lui — c'est
 *    exactement la fenêtre d'une vraie course HTTP (entité lue avant le commit du gagnant,
 *    puis bloquée sur le verrou).
 * 2. Le verrou bloque réellement : un créneau verrouillé par une transaction non commitée
 *    fait expirer (innodb_lock_wait_timeout) une reprise concurrente — jamais une lecture
 *    fantôme qui passerait à côté.
 * 3. Deux instrumentistes tentent de prendre la nouvelle Mission OPEN, même fenêtre de
 *    course : un seul OPEN → ASSIGNED.
 */
final class RoomTakeoverConcurrencyTest extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $createdIds = ['slots' => [], 'absences' => [], 'users' => [], 'sites' => [], 'missions' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        foreach ($this->createdIds['slots'] as $id) {
            $conn->executeStatement('DELETE FROM released_operating_room_slot WHERE id = ?', [$id]);
        }
        // Missions created by the service under test (surgeon = one of our users) + fixtures.
        foreach ($this->createdIds['users'] as $uid) {
            foreach ($conn->fetchFirstColumn('SELECT id FROM mission WHERE surgeon_id = ? OR instrumentist_id = ?', [$uid, $uid]) as $mid) {
                $this->createdIds['missions'][] = (int) $mid;
            }
        }
        foreach (array_unique($this->createdIds['missions']) as $mid) {
            $conn->executeStatement('DELETE FROM notification_event WHERE mission_id = ?', [$mid]);
            $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$mid]);
            $conn->executeStatement('DELETE FROM mission_claim WHERE mission_id = ?', [$mid]);
            $conn->executeStatement('UPDATE released_operating_room_slot SET takeover_mission_id = NULL, original_mission_id = NULL WHERE takeover_mission_id = ? OR original_mission_id = ?', [$mid, $mid]);
            $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$mid]);
        }
        foreach ($this->createdIds['absences'] as $id) {
            $conn->executeStatement('DELETE FROM absence WHERE id = ?', [$id]);
        }
        foreach ($this->createdIds['users'] as $uid) {
            $conn->executeStatement('DELETE FROM audit_event WHERE actor_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM site_membership WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$uid]);
        }
        foreach ($this->createdIds['sites'] as $id) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    // ── Wiring on independent connections ────────────────────────────────────

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function postDeployFor(EntityManagerInterface $em): MissionPostDeployService
    {
        return new MissionPostDeployService(
            $em,
            self::getContainer()->get(MessageBusInterface::class),
            new AuditService($em),
            new MissionEligibilityService($em),
        );
    }

    private function takeoverServiceFor(EntityManagerInterface $em): ReleasedRoomSlotTakeoverService
    {
        return new ReleasedRoomSlotTakeoverService(
            $em,
            $this->postDeployFor($em),
            new MissionEligibilityService($em),
            self::getContainer()->get(PlanningConflictDetectionService::class),
            new AuditService($em),
            self::getContainer()->get(MessageBusInterface::class),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(string $role, string $firstname, ?EmploymentType $employment = null): User
    {
        $u = new User();
        $u->setEmail('d124conc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Conc');
        if ($employment !== null) {
            $u->setEmploymentType($employment);
        }
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D124Conc ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function affiliate(User $user, Hospital $site, string $role): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole($role);
        $this->em->persist($sm);
        $this->em->flush();
    }

    /** A real released slot: surgeon A absent that day, 08:00–13:00 known. */
    private function makeReleasedSlot(Hospital $site, User $absentSurgeon, \DateTimeImmutable $date): ReleasedOperatingRoomSlot
    {
        $absence = new Absence();
        $absence->setUser($absentSurgeon);
        $absence->setDateStart($date);
        $absence->setDateEnd($date);
        $absence->setCreatedBy($absentSurgeon);
        $this->em->persist($absence);

        $slot = new ReleasedOperatingRoomSlot();
        $slot->setSite($site);
        $slot->setPostId(random_int(900000, 999999));
        $slot->setOccurrenceDate($date);
        $slot->setPeriod(ShiftPeriod::MATIN);
        $slot->setStartTime(new \DateTimeImmutable('08:00'));
        $slot->setEndTime(new \DateTimeImmutable('13:00'));
        $slot->setSurgeon($absentSurgeon);
        $slot->setSourceAbsence($absence);
        $this->em->persist($slot);
        $this->em->flush();

        $this->createdIds['absences'][] = $absence->getId();
        $this->createdIds['slots'][] = $slot->getId();
        return $slot;
    }

    private function setLockTimeout(EntityManagerInterface $em, int $seconds): void
    {
        $em->getConnection()->executeStatement("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    private function countMissionsOf(User $surgeon): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission WHERE surgeon_id = ?', [$surgeon->getId()]);
    }

    // ── 1. Double reprise, fenêtre de course réelle ──────────────────────────

    public function test_two_surgeons_racing_on_the_same_slot_only_one_takes_it_and_only_one_mission_exists(): void
    {
        $site = $this->makeSite();
        $absentA = $this->makeUser('ROLE_SURGEON', 'Absent');
        $surgeonB = $this->makeUser('ROLE_SURGEON', 'Bruno');
        $surgeonC = $this->makeUser('ROLE_SURGEON', 'Claire');
        $slot = $this->makeReleasedSlot($site, $absentA, (new \DateTimeImmutable('today'))->modify('+20 days'));

        // Both requests load the slot BEFORE either commits — both see AVAILABLE in memory.
        $emB = $this->freshEntityManager();
        $emC = $this->freshEntityManager();
        $slotB = $emB->find(ReleasedOperatingRoomSlot::class, $slot->getId());
        $slotC = $emC->find(ReleasedOperatingRoomSlot::class, $slot->getId());
        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $slotC->getStatus());

        $this->takeoverServiceFor($emB)->takeOver($slotB, $emB->find(User::class, $surgeonB->getId()));

        self::assertSame(ReleasedRoomSlotStatus::AVAILABLE, $slotC->getStatus(), 'precondition: C\'s in-memory entity is stale');

        try {
            $this->takeoverServiceFor($emC)->takeOver($slotC, $emC->find(User::class, $surgeonC->getId()));
            self::fail('The second take-over must be refused.');
        } catch (ReleasedRoomSlotConflictException $e) {
            self::assertSame(ReleasedRoomSlotConflictException::ALREADY_TAKEN, $e->getErrorCode());
            self::assertSame($surgeonB->getId(), $e->getExtra()['takenBy']['id']);
            self::assertStringContainsString('Bruno', $e->getMessage());
        }

        self::assertSame(1, $this->countMissionsOf($surgeonB), 'exactly one Mission for the winner');
        self::assertSame(0, $this->countMissionsOf($surgeonC), 'never a Mission for the loser');

        $this->em->clear();
        $reloaded = $this->em->find(ReleasedOperatingRoomSlot::class, $slot->getId());
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED, $reloaded->getStatus());
        self::assertSame($surgeonB->getId(), $reloaded->getClaimedBy()?->getId());
    }

    // ── 2. Le verrou bloque réellement ───────────────────────────────────────

    public function test_take_over_genuinely_blocks_while_another_transaction_holds_the_slot_lock(): void
    {
        $site = $this->makeSite();
        $absentA = $this->makeUser('ROLE_SURGEON', 'Absent');
        $surgeonC = $this->makeUser('ROLE_SURGEON', 'Claire');
        $slot = $this->makeReleasedSlot($site, $absentA, (new \DateTimeImmutable('today'))->modify('+21 days'));

        $emHolder = $this->freshEntityManager();
        $held = $emHolder->find(ReleasedOperatingRoomSlot::class, $slot->getId());
        $emHolder->getConnection()->beginTransaction();
        $emHolder->lock($held, LockMode::PESSIMISTIC_WRITE); // winner mid-transaction, not committed

        $emC = $this->freshEntityManager();
        $this->setLockTimeout($emC, self::LOCK_TIMEOUT_SECONDS);

        $blocked = false;
        try {
            $this->takeoverServiceFor($emC)->takeOver($emC->find(ReleasedOperatingRoomSlot::class, $slot->getId()), $emC->find(User::class, $surgeonC->getId()));
        } catch (\Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205');
        }
        $emHolder->getConnection()->rollBack();

        self::assertTrue($blocked, 'A concurrent take-over must wait on the row lock, never read past it.');
        self::assertSame(0, $this->countMissionsOf($surgeonC));
    }

    // ── 3. Double claim sur la nouvelle Mission ──────────────────────────────

    public function test_two_instrumentists_racing_to_claim_the_new_mission_only_one_wins(): void
    {
        $site = $this->makeSite();
        $absentA = $this->makeUser('ROLE_SURGEON', 'Absent');
        $surgeonB = $this->makeUser('ROLE_SURGEON', 'Bruno');
        $instrX = $this->makeUser('ROLE_INSTRUMENTIST', 'Xena', EmploymentType::FREELANCER);
        $instrY = $this->makeUser('ROLE_INSTRUMENTIST', 'Yann', EmploymentType::FREELANCER);
        $slot = $this->makeReleasedSlot($site, $absentA, (new \DateTimeImmutable('today'))->modify('+22 days'));

        $this->takeoverServiceFor($this->em)->takeOver($slot, $surgeonB);
        $missionId = $slot->getTakeoverMission()->getId();
        self::assertSame(MissionStatus::OPEN, $slot->getTakeoverMission()->getStatus());

        // Both claim requests load the OPEN mission before either commits.
        $emX = $this->freshEntityManager();
        $emY = $this->freshEntityManager();
        $missionX = $emX->find(Mission::class, $missionId);
        $missionY = $emY->find(Mission::class, $missionId);

        $this->postDeployFor($emX)->claim($missionX, $emX->find(User::class, $instrX->getId()));

        $secondFailed = false;
        try {
            $this->postDeployFor($emY)->claim($missionY, $emY->find(User::class, $instrY->getId()));
        } catch (ConflictHttpException) {
            $secondFailed = true;
        }

        self::assertTrue($secondFailed, 'The second claim must be refused (409), never silently overwrite the first.');

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $missionId);
        self::assertSame(MissionStatus::ASSIGNED, $reloaded->getStatus());
        self::assertSame($instrX->getId(), $reloaded->getInstrumentist()?->getId(), 'first valid claim wins');
        self::assertCount(1, $this->em->getRepository(MissionClaim::class)->findBy(['mission' => $missionId]), 'exactly one MissionClaim row');
        self::assertCount(1, $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId, 'eventType' => 'MISSION_CLAIMED_FROM_POOL']));
    }
}
