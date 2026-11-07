<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Exception\ReleasedRoomSlotConflictException;
use App\Repository\ReleasedOperatingRoomSlotRepository;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\PlanningConflictDetectionService;
use App\Service\ReleasedOperatingRoomSlotService;
use App\Service\ReleasedRoomCapacityMatcher;
use App\Service\ReleasedRoomSlotTakeoverService;
use App\Service\SurgeonAbsenceBlockOccurrenceResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-159 — concurrency on real, distinct MySQL connections (same technique as
 * RoomTakeoverConcurrencyTest):
 *
 * 1. Two writers create the same released room: the second one sees NEW, the first commits
 *    in between (exact race window: after the read, before the flush). One row, never two,
 *    never an error for the caller, and the other occurrences of the absence are still created.
 * 2. Two surgeons race to take over a capacity whose post has since been replaced: one wins,
 *    one Mission; the real-time path then never opens a second room on the new post.
 */
final class ReleasedRoomPostReplacementConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $created = ['users' => [], 'sites' => [], 'posts' => [], 'absences' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $ids = static fn (array $l): string => implode(',', array_map('intval', $l)) ?: '0';
        $sites = $ids($this->created['sites']);
        $users = $ids($this->created['users']);

        $missions = $ids($conn->fetchFirstColumn("SELECT id FROM mission WHERE site_id IN ($sites)"));
        $conn->executeStatement("DELETE FROM audit_event WHERE JSON_EXTRACT(payload, '$.siteId') IN ($sites)");
        $conn->executeStatement("DELETE FROM released_operating_room_slot WHERE site_id IN ($sites)");
        foreach (['notification_event', 'planning_alert', 'audit_event', 'mission_claim', 'mission_assignment', 'mission_communication', 'mission_publication'] as $table) {
            $conn->executeStatement("DELETE FROM $table WHERE mission_id IN ($missions)");
        }
        $conn->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
        $conn->executeStatement('DELETE FROM planning_occurrence_exception WHERE post_id IN (' . $ids($this->created['posts']) . ')');
        $conn->executeStatement('DELETE FROM surgeon_schedule_post WHERE id IN (' . $ids($this->created['posts']) . ')');
        $conn->executeStatement('DELETE FROM absence WHERE id IN (' . $ids($this->created['absences']) . ')');
        $conn->executeStatement("DELETE FROM shift_period_config WHERE site_id IN ($sites)");
        $conn->executeStatement("DELETE FROM site_membership WHERE user_id IN ($users)");
        $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($users)");
        $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($users)");
        $conn->executeStatement("DELETE FROM user WHERE id IN ($users)");
        $conn->executeStatement("DELETE FROM hospital WHERE id IN ($sites)");
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(string $role, string $lastname): User
    {
        $u = new User();
        $u->setEmail('d159conc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D159');
        $u->setLastname($lastname);
        $u->setEmploymentType(EmploymentType::FREELANCER);
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function makeSite(): Hospital
    {
        $site = new Hospital();
        $site->setName('D159Conc ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        foreach ([[ShiftPeriod::MATIN, '07:45', '13:00'], [ShiftPeriod::JOURNEE, '07:45', '18:00']] as [$period, $start, $end]) {
            $config = new ShiftPeriodConfig();
            $config->setSite($site);
            $config->setPeriod($period);
            $config->setStartTime(new \DateTimeImmutable($start . ':00'));
            $config->setEndTime(new \DateTimeImmutable($end . ':00'));
            $config->setActive(true);
            $this->em->persist($config);
        }
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        return $site;
    }

    private function makePost(User $surgeon, Hospital $site, ShiftPeriod $period, int $isoWeekday, \DateTimeImmutable $start, ?\DateTimeImmutable $end = null, bool $active = true): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([$isoWeekday]);
        $rule->setAnchorDate($start);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod($period);
        $post->setRecurrence($rule);
        $post->setStartDate($start);
        $post->setEndDate($end);
        $post->setActive($active);
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->created['posts'][] = $post->getId();

        return $post;
    }

    private function makeAbsence(User $surgeon, \DateTimeImmutable $from, \DateTimeImmutable $to): Absence
    {
        $absence = new Absence();
        $absence->setUser($surgeon);
        $absence->setDateStart($from);
        $absence->setDateEnd($to);
        $absence->setCreatedBy($surgeon);
        $this->em->persist($absence);
        $this->em->flush();
        $this->created['absences'][] = $absence->getId();

        return $absence;
    }

    private static function nextWeekday(int $isoWeekday, int $minDays): \DateTimeImmutable
    {
        $d = (new \DateTimeImmutable('today'))->modify("+{$minDays} days");
        while ((int) $d->format('N') !== $isoWeekday) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function takeoverServiceFor(EntityManagerInterface $em): ReleasedRoomSlotTakeoverService
    {
        $eligibility = new MissionEligibilityService($em);

        return new ReleasedRoomSlotTakeoverService(
            $em,
            new MissionPostDeployService($em, self::getContainer()->get(MessageBusInterface::class), new AuditService($em), $eligibility),
            $eligibility,
            self::getContainer()->get(PlanningConflictDetectionService::class),
            new AuditService($em),
            self::getContainer()->get(MessageBusInterface::class),
            null,
            new ReleasedRoomCapacityMatcher($em, self::getContainer()->get(SurgeonAbsenceBlockOccurrenceResolver::class)),
        );
    }

    private function slotsOn(Hospital $site): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT post_id, occurrence_date, status FROM released_operating_room_slot WHERE site_id = ? ORDER BY occurrence_date, post_id',
            [$site->getId()],
        );
    }

    // ── 1. Concurrent creation of the same room ──────────────────────────────

    public function test_concurrent_creation_of_the_same_room_yields_one_row_and_never_fails_the_other_occurrences(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Racer');
        $wed = self::nextWeekday(3, 30);
        $mon = $wed->modify('+5 days');
        $wedPost = $this->makePost($surgeon, $site, ShiftPeriod::MATIN, 3, new \DateTimeImmutable('today'));
        $this->makePost($surgeon, $site, ShiftPeriod::JOURNEE, 1, new \DateTimeImmutable('today'));
        $absence = $this->makeAbsence($surgeon, $wed, $mon);

        // The concurrent writer commits the Wednesday room on its own connection, exactly
        // between our read (NEW) and our write.
        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $matcher = new class($this->em, self::getContainer()->get(SurgeonAbsenceBlockOccurrenceResolver::class), $other, $wedPost->getId(), $site->getId(), $surgeon->getId(), $absence->getId()) extends ReleasedRoomCapacityMatcher {
            private bool $raced = false;

            public function __construct(EntityManagerInterface $em, SurgeonAbsenceBlockOccurrenceResolver $generator, private readonly Connection $other, private readonly int $postId, private readonly int $siteId, private readonly int $surgeonId, private readonly int $absenceId)
            {
                parent::__construct($em, $generator);
            }

            public function classify(SurgeonSchedulePost $post, \DateTimeImmutable $date): array
            {
                $result = parent::classify($post, $date);
                if (!$this->raced && $post->getId() === $this->postId) {
                    $this->raced = true;
                    $this->other->insert('released_operating_room_slot', [
                        'site_id' => $this->siteId, 'post_id' => $this->postId, 'schedule_post_id' => $this->postId,
                        'occurrence_date' => $date->format('Y-m-d'), 'period' => 'MATIN', 'start_time' => '07:45:00', 'end_time' => '13:00:00',
                        'surgeon_id' => $this->surgeonId, 'source_absence_id' => $this->absenceId, 'status' => 'AVAILABLE',
                        'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                }

                return $result;
            }
        };

        $container = self::getContainer();
        $service = new ReleasedOperatingRoomSlotService(
            $this->em,
            $container->get(SurgeonAbsenceBlockOccurrenceResolver::class),
            $container->get(ReleasedOperatingRoomSlotRepository::class),
            $container->get(AuditService::class),
            $container->get(ReleasedRoomSlotTakeoverService::class),
            $matcher,
            new NullLogger(),
        );

        $service->onAbsenceCreated($absence, $surgeon);
        $other->close();

        $slots = $this->slotsOn($site);
        self::assertCount(2, $slots, json_encode($slots));
        self::assertSame([$wed->format('Y-m-d'), $mon->format('Y-m-d')], array_column($slots, 'occurrence_date'), 'one Wednesday room (the concurrent one), and Monday still created');
        self::assertTrue($this->em->isOpen());
    }

    // ── 2. Concurrent take-over of a replaced capacity ───────────────────────

    public function test_two_surgeons_racing_on_a_replaced_capacity_only_one_wins_and_no_second_room_appears(): void
    {
        $site = $this->makeSite();
        $absent = $this->makeUser('ROLE_SURGEON', 'Absent');
        $bruno = $this->makeUser('ROLE_SURGEON', 'Bruno');
        $claire = $this->makeUser('ROLE_SURGEON', 'Claire');
        $wed = self::nextWeekday(3, 30);
        $old = $this->makePost($absent, $site, ShiftPeriod::MATIN, 3, new \DateTimeImmutable('today'), $wed->modify('-7 days'), false);
        $this->makePost($absent, $site, ShiftPeriod::MATIN, 3, $wed->modify('-6 days'));
        $absence = $this->makeAbsence($absent, $wed, $wed);

        $slot = new ReleasedOperatingRoomSlot();
        $slot->setSite($site);
        $slot->setPostId($old->getId());
        $slot->setSchedulePost($old);
        $slot->setOccurrenceDate($wed);
        $slot->setPeriod(ShiftPeriod::MATIN);
        $slot->setStartTime(new \DateTimeImmutable('07:45'));
        $slot->setEndTime(new \DateTimeImmutable('13:00'));
        $slot->setSurgeon($absent);
        $slot->setSourceAbsence($absence);
        $this->em->persist($slot);
        $this->em->flush();

        $emB = $this->freshEntityManager();
        $emC = $this->freshEntityManager();
        $slotB = $emB->find(ReleasedOperatingRoomSlot::class, $slot->getId());
        $slotC = $emC->find(ReleasedOperatingRoomSlot::class, $slot->getId());

        $this->takeoverServiceFor($emB)->takeOver($slotB, $emB->find(User::class, $bruno->getId()));
        try {
            $this->takeoverServiceFor($emC)->takeOver($slotC, $emC->find(User::class, $claire->getId()));
            self::fail('The second take-over must be refused.');
        } catch (ReleasedRoomSlotConflictException $e) {
            self::assertSame(ReleasedRoomSlotConflictException::ALREADY_TAKEN, $e->getErrorCode());
        }

        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM mission WHERE surgeon_id = ?', [$bruno->getId()]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM mission WHERE surgeon_id = ?', [$claire->getId()]));

        // The real-time path (absence edited) never opens a second room on the new post.
        $this->em->clear();
        self::getContainer()->get(ReleasedOperatingRoomSlotService::class)->onAbsenceUpdated($this->em->find(Absence::class, $absence->getId()), $this->em->find(User::class, $absent->getId()));
        $slots = $this->slotsOn($site);
        self::assertCount(1, $slots);
        self::assertSame(ReleasedRoomSlotStatus::CLAIMED->value, $slots[0]['status']);
        self::assertSame($old->getId(), (int) $slots[0]['post_id']);
    }
}
