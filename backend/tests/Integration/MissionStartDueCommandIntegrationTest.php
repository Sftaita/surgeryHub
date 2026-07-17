<?php

namespace App\Tests\Integration;

use App\Command\MissionStartDueCommand;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D-064 — real-DB coverage for MissionStartDueCommand.
 *
 * This is the test that actually would have caught the timezone bug found while building
 * this feature: Mission.startAt/endAt are DateTimeImmutable columns persisted by Doctrine
 * with NO timezone conversion (verified empirically — a value submitted with a "+02:00"
 * offset is stored as its own wall-clock digits, offset dropped, same as
 * MissionService::DEFAULT_TIMEZONE already assumes for its own today-boundary logic). A
 * mocked EntityManager/QueryBuilder (see MissionStartDueCommandTest, the unit-test sibling
 * of this file) never actually executes the DQL against a real MySQL column, so it cannot
 * catch a "now" computed in the wrong timezone — only a real query against real stored data
 * can. Every assertion here is expressed in the app's own timezone convention
 * (Europe/Brussels) precisely so a regression back to `new \DateTimeImmutable()` (implicit
 * UTC, off by the DST offset) would fail this test.
 */
final class MissionStartDueCommandIntegrationTest extends KernelTestCase
{
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['missions'] as $id) {
            foreach ($this->auditEventsForMissionId($id) as $evt) {
                $this->em->remove($evt);
            }
        }
        $this->em->flush();
        foreach ($this->createdIds['missions'] as $id) {
            $e = $this->em->find(Mission::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function auditEventsForMissionId(int $missionId): array
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if ($mission === null) { return []; }
        return $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')->setParameter('m', $mission)
            ->getQuery()->getResult();
    }

    private function nowInAppTimezone(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D064-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d064-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D064');
        $u->setLastname('Test');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    /** $startOffset/$endOffset are relative modifiers (e.g. '-30 minutes') applied in Europe/Brussels. */
    private function makeAssignedMission(User $surgeon, User $instrumentist, Hospital $site, string $startOffset, string $endOffset): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt($this->nowInAppTimezone()->modify($startOffset));
        $m->setEndAt($this->nowInAppTimezone()->modify($endOffset));
        $m->setCreatedBy($surgeon);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester(new MissionStartDueCommand(
            $this->em,
            self::getContainer()->get(MissionPostDeployService::class),
        ));
        $tester->execute([]);
        return $tester;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_mission_currently_inside_its_window_transitions_to_in_progress(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '-30 minutes', '+30 minutes');

        $tester = $this->runCommand();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->em->refresh($mission);
        $this->assertSame(MissionStatus::IN_PROGRESS, $mission->getStatus());
    }

    public function test_mission_not_yet_started_stays_assigned(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '+2 hours', '+5 hours');

        $this->runCommand();

        $this->em->refresh($mission);
        $this->assertSame(
            MissionStatus::ASSIGNED,
            $mission->getStatus(),
            'A mission whose startAt is still in the future must not be auto-started.',
        );
    }

    public function test_mission_already_past_its_window_is_left_alone(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '-5 hours', '-1 hour');

        $this->runCommand();

        $this->em->refresh($mission);
        $this->assertSame(
            MissionStatus::ASSIGNED,
            $mission->getStatus(),
            'An overdue never-submitted mission must not be flipped to a stale IN_PROGRESS.',
        );
    }

    public function test_writes_a_mission_started_audit_event_with_the_system_actor(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '-10 minutes', '+50 minutes');

        $this->runCommand();

        $events = $this->auditEventsForMissionId($mission->getId());
        $this->assertCount(1, $events);
        $this->assertSame('MISSION_STARTED', $events[0]->getEventType()->value);
        $this->assertSame('system@surgicalhub.internal', $events[0]->getActor()?->getEmail());
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeAssignedMission($surgeon, $instr, $site, '-15 minutes', '+45 minutes');

        $this->runCommand();
        $this->runCommand();

        $this->em->refresh($mission);
        $this->assertSame(MissionStatus::IN_PROGRESS, $mission->getStatus());
        $this->assertCount(
            1,
            $this->auditEventsForMissionId($mission->getId()),
            'A mission already IN_PROGRESS must not be re-processed (status guard in start()).',
        );
    }
}
