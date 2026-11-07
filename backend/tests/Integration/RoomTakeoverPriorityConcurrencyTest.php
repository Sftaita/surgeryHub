<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionPublication;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SiteMembership;
use App\Entity\SurgeonMissionRequest;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PublicationCloseReason;
use App\Enum\PublicationScope;
use App\Enum\RecurrenceFrequency;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Exception\ReleasedRoomSlotConflictException;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\PlanningConflictDetectionService;
use App\Service\ReleasedRoomSlotTakeoverService;
use App\Service\RoomTakeoverPriorityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-177 — courses sur de VRAIES connexions MySQL distinctes (même technique que
 * RoomTakeoverConcurrencyTest) : chaque acteur charge l'état AVANT que l'autre ne commite —
 * exactement la fenêtre d'une vraie course (requête HTTP, worker Messenger, cron).
 *
 * 1. Acceptation à la dernière seconde contre expiration : une seule issue, aucune publication
 *    au pool, aucune notification de fin de priorité.
 * 2. Deux expirations concurrentes (message différé + cron + retry) : une seule fin de
 *    priorité, une seule publication au pool.
 * 3. Expiration commitée avant une acceptation périmée : l'acceptation passe par le pool (la
 *    mission y est), une seule affectation.
 */
final class RoomTakeoverPriorityConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = ['slots' => [], 'absences' => [], 'users' => [], 'sites' => [], 'posts' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->createdIds['slots'] as $id) {
            $conn->executeStatement('DELETE FROM released_operating_room_slot WHERE id = ?', [$id]);
        }
        foreach ($this->createdIds['users'] as $uid) {
            foreach ($conn->fetchFirstColumn('SELECT id FROM mission WHERE surgeon_id = ? OR instrumentist_id = ?', [$uid, $uid]) as $mid) {
                foreach (['notification_event', 'audit_event', 'mission_claim', 'mission_publication', 'mission_assignment', 'mission_communication'] as $table) {
                    $conn->executeStatement("DELETE FROM {$table} WHERE mission_id = ?", [$mid]);
                }
                $conn->executeStatement('DELETE FROM surgeon_mission_request WHERE created_mission_id = ?', [$mid]);
                $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$mid]);
            }
            $conn->executeStatement('DELETE FROM surgeon_mission_request WHERE surgeon_id = ?', [$uid]);
        }
        foreach ($this->createdIds['absences'] as $id) {
            $conn->executeStatement('DELETE FROM absence WHERE id = ?', [$id]);
        }
        foreach ($this->createdIds['posts'] as $id) {
            $conn->executeStatement('DELETE FROM surgeon_schedule_post WHERE id = ?', [$id]);
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

    private function prioritiesFor(EntityManagerInterface $em): RoomTakeoverPriorityService
    {
        return new RoomTakeoverPriorityService($em, new MissionEligibilityService($em), new AuditService($em), self::getContainer()->get(MessageBusInterface::class), new NullLogger());
    }

    private function postDeployFor(EntityManagerInterface $em): MissionPostDeployService
    {
        return new MissionPostDeployService(
            $em,
            self::getContainer()->get(MessageBusInterface::class),
            new AuditService($em),
            new MissionEligibilityService($em),
            priorities: $this->prioritiesFor($em),
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
            priorities: $this->prioritiesFor($em),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(string $role, string $firstname, ?EmploymentType $employment = null): User
    {
        $u = new User();
        $u->setEmail('d176conc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Conc');
        $u->setEmploymentType($employment);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();

        return $u;
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

    /**
     * Released room of absent A (post with usual instrumentist Marie), B (taker) and Paul
     * affiliated. Returns [slot, B, Marie, Paul].
     *
     * @return array{0: ReleasedOperatingRoomSlot, 1: User, 2: User, 3: User}
     */
    private function scene(int $daysAhead): array
    {
        $site = new Hospital();
        $site->setName('D176Conc ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();

        $a = $this->makeUser('ROLE_SURGEON', 'Absent');
        $b = $this->makeUser('ROLE_SURGEON', 'Bruno');
        $marie = $this->makeUser('ROLE_INSTRUMENTIST', 'Marie', EmploymentType::EMPLOYEE);
        $paul = $this->makeUser('ROLE_INSTRUMENTIST', 'Paul', EmploymentType::EMPLOYEE);
        foreach ([[$b, 'SURGEON'], [$marie, 'INSTRUMENTIST'], [$paul, 'INSTRUMENTIST']] as [$u, $role]) {
            $this->affiliate($u, $site, $role);
        }

        $date = (new \DateTimeImmutable('today'))->modify("+{$daysAhead} days");
        $rule = (new RecurrenceRule())->setFrequency(RecurrenceFrequency::WEEKLY)->setInterval(1)
            ->setWeekdays([(int) $date->format('N')])->setAnchorDate($date)->setMonthWeeks([]);
        $post = (new SurgeonSchedulePost())->setSurgeon($a)->setSite($site)->setType(MissionType::BLOCK)
            ->setPeriod(ShiftPeriod::MATIN)->setRecurrence($rule)->setStartDate($date)->setEndDate($date)
            ->setInstrumentist($marie)->setCreatedBy($a);
        $this->em->persist($post);
        $this->em->flush();

        $absence = (new Absence())->setUser($a)->setDateStart($date)->setDateEnd($date)->setCreatedBy($a);
        $this->em->persist($absence);

        $slot = (new ReleasedOperatingRoomSlot())->setSite($site)->setPostId($post->getId())->setSchedulePost($post)->setOccurrenceDate($date)
            ->setPeriod(ShiftPeriod::MATIN)->setStartTime(new \DateTimeImmutable('08:00'))->setEndTime(new \DateTimeImmutable('13:00'))
            ->setSurgeon($a)->setSourceAbsence($absence);
        $this->em->persist($slot);
        $this->em->flush();

        $this->createdIds['posts'][] = $post->getId();
        $this->createdIds['absences'][] = $absence->getId();
        $this->createdIds['slots'][] = $slot->getId();

        return [$slot, $b, $marie, $paul];
    }

    /** @return array{0: int, 1: int} mission id, priority publication id */
    private function takeOver(ReleasedOperatingRoomSlot $slot, User $taker): array
    {
        $this->takeoverServiceFor($this->em)->takeOver($slot, $taker);
        $missionId = $slot->getTakeoverMission()->getId();
        $priority = $this->em->getRepository(MissionPublication::class)->findOneBy(['mission' => $missionId, 'scope' => PublicationScope::TARGETED]);
        self::assertNotNull($priority?->getExpiresAt(), 'precondition: an exclusive priority is running');

        return [$missionId, $priority->getId()];
    }

    private function backdate(int $publicationId): void
    {
        $this->em->getConnection()->executeStatement('UPDATE mission_publication SET expires_at = ? WHERE id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $publicationId]);
    }

    private function countRows(string $sql, array $params): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    // ── 1. Acceptation à la dernière seconde contre expiration ───────────────

    public function test_last_second_acceptance_against_the_expiry_only_one_outcome(): void
    {
        [$slot, $b, $marie] = $this->scene(20);
        [$missionId, $priorityId] = $this->takeOver($slot, $b);
        $this->backdate($priorityId);

        // Both load BEFORE either commits: the claim sees OPEN, the expiry sees an active priority.
        $emClaim = $this->freshEntityManager();
        $emExpire = $this->freshEntityManager();
        $mission = $emClaim->find(Mission::class, $missionId);
        $emExpire->find(MissionPublication::class, $priorityId)->getMission()->getPublications()->toArray();

        $this->postDeployFor($emClaim)->claim($mission, $emClaim->find(User::class, $marie->getId()));
        self::assertFalse($this->prioritiesFor($emExpire)->expire($priorityId), 'the stale expiry must lose');

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mission_publication WHERE mission_id = ? AND scope = ?', [$missionId, 'POOL']), 'never published to the pool');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_event WHERE mission_id = ? AND event_type = 'ROOM_TAKEOVER_PRIORITY_ENDED'", [$missionId]));
        self::assertSame('ACCEPTED', $this->em->getConnection()->fetchOne('SELECT close_reason FROM mission_publication WHERE id = ?', [$priorityId]));
        self::assertSame($marie->getId(), (int) $this->em->getConnection()->fetchOne('SELECT instrumentist_id FROM mission WHERE id = ?', [$missionId]));
    }

    // ── 2. Deux expirations concurrentes ─────────────────────────────────────

    public function test_two_concurrent_expiries_end_the_priority_once_and_publish_to_the_pool_once(): void
    {
        [$slot, $b] = $this->scene(21);
        [$missionId, $priorityId] = $this->takeOver($slot, $b);
        $this->backdate($priorityId);

        $emA = $this->freshEntityManager();
        $emB = $this->freshEntityManager();
        $emA->find(MissionPublication::class, $priorityId)->getMission()->getPublications()->toArray();
        $emB->find(MissionPublication::class, $priorityId)->getMission()->getPublications()->toArray();

        self::assertTrue($this->prioritiesFor($emA)->expire($priorityId));
        self::assertFalse($this->prioritiesFor($emB)->expire($priorityId), 'the second run (cron / retry) changes nothing');

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mission_publication WHERE mission_id = ? AND scope = ?', [$missionId, 'POOL']));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_event WHERE mission_id = ? AND event_type = 'ROOM_TAKEOVER_PRIORITY_ENDED'", [$missionId]));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_event WHERE mission_id = ? AND event_type = 'MISSION_PUBLISHED_TO_POOL'", [$missionId]));
    }

    // ── 3. Expiration commitée avant une acceptation périmée ─────────────────

    public function test_expiry_committed_first_then_stale_claims_one_assignment_only(): void
    {
        [$slot, $b, $marie, $paul] = $this->scene(22);
        [$missionId, $priorityId] = $this->takeOver($slot, $b);
        $this->backdate($priorityId);

        $emMarie = $this->freshEntityManager();
        $emPaul = $this->freshEntityManager();
        $missionMarie = $emMarie->find(Mission::class, $missionId);
        $missionPaul = $emPaul->find(Mission::class, $missionId);

        self::assertTrue($this->prioritiesFor($this->freshEntityManager())->expire($priorityId));

        $this->postDeployFor($emPaul)->claim($missionPaul, $emPaul->find(User::class, $paul->getId()));
        $secondFailed = false;
        try {
            $this->postDeployFor($emMarie)->claim($missionMarie, $emMarie->find(User::class, $marie->getId()));
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $secondFailed = true;
        }

        self::assertTrue($secondFailed);
        self::assertSame($paul->getId(), (int) $this->em->getConnection()->fetchOne('SELECT instrumentist_id FROM mission WHERE id = ?', [$missionId]));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mission_claim WHERE mission_id = ?', [$missionId]));
    }

    // ── 4. Exclusivité relue sous verrou ─────────────────────────────────────

    public function test_a_non_recipient_claim_is_refused_under_the_lock_even_past_the_voter(): void
    {
        [$slot, $b, , $paul] = $this->scene(23);
        [$missionId] = $this->takeOver($slot, $b);

        $em = $this->freshEntityManager();
        try {
            $this->postDeployFor($em)->claim($em->find(Mission::class, $missionId), $em->find(User::class, $paul->getId()));
            self::fail('Reserved for the priority recipient.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            self::assertStringContainsString('priority', $e->getMessage());
        }
        self::assertSame(MissionStatus::OPEN->value, $this->em->getConnection()->fetchOne('SELECT status FROM mission WHERE id = ?', [$missionId]));
    }
}
