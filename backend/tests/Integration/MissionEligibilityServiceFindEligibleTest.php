<?php

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\MissionEligibilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-DB test for RC1-D/RC1-E — MissionEligibilityService::findEligibleByMission()
 * (H3/D-129 replaced the per-site union findEligible(): candidates are now computed and
 * returned PER MISSION, same three queries, so a pool message can only list the missions
 * its recipient can actually take).
 *
 * RC1-D fixed invalid DQL (`JOIN FETCH sm.user u`, which is HQL syntax, not DQL) that threw a
 * Semantical Error on every real execution. RC1-E fixed a second, independent bug: the
 * candidate pool was built from an INNER JOIN on SiteMembership, so a FREELANCER without a
 * SiteMembership row was invisible here even though evaluate() (the actual claim() gate)
 * already bypasses that requirement for freelancers — violating D-057 (single source of
 * truth: both methods must agree on who is a candidate). Every prior test mocked
 * EntityManager::createQuery(), so the DQL string itself was never actually parsed. Only a
 * real-DB run (like this one, and like AbsenceReminderServiceTest before it) proves it.
 */
final class MissionEligibilityServiceFindEligibleTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'memberships' => [], 'absences' => [], 'users' => [], 'sites' => [],
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['absences'] as $id) {
            $e = $this->em->find(Absence::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['missions'] as $id) {
            $e = $this->em->find(Mission::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['memberships'] as $id) {
            $e = $this->em->find(SiteMembership::class, $id);
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

    private function service(): MissionEligibilityService
    {
        return self::getContainer()->get(MissionEligibilityService::class);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('RC1D-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeInstrumentist(bool $active = true, ?EmploymentType $employmentType = null): User
    {
        $u = new User();
        $u->setEmail('rc1d-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive($active);
        $u->setFirstname('Jean');
        $u->setLastname('Dupont');
        if ($employmentType !== null) {
            $u->setEmploymentType($employmentType);
        }
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSurgeon(): User
    {
        $u = new User();
        $u->setEmail('rc1d-surgeon-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $u->setFirstname('Alice');
        $u->setLastname('Chirurgien');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeMembership(User $user, Hospital $site): SiteMembership
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole('INSTRUMENTIST');
        $this->em->persist($sm);
        $this->em->flush();
        $this->createdIds['memberships'][] = $sm->getId();
        return $sm;
    }

    private function makeOpenMission(Hospital $site, User $surgeon, User $createdBy, string $start = '2026-09-01 08:00:00', string $end = '2026-09-01 13:00:00'): Mission
    {
        $m = new Mission();
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStatus(MissionStatus::OPEN);
        $m->setStartAt(new \DateTimeImmutable($start));
        $m->setEndAt(new \DateTimeImmutable($end));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function makeAssignedMission(Hospital $site, User $surgeon, User $createdBy, User $instrumentist, string $start, string $end): Mission
    {
        $m = new Mission();
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setInstrumentist($instrumentist);
        $m->setStartAt(new \DateTimeImmutable($start));
        $m->setEndAt(new \DateTimeImmutable($end));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function makeAbsence(User $user, string $dateStart, string $dateEnd): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($dateStart));
        $a->setDateEnd(new \DateTimeImmutable($dateEnd));
        $a->setCreatedBy($user);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_employee_with_site_membership_is_eligible(): void
    {
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        $this->makeMembership($candidate, $site);
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertContains($candidate->getId(), $ids);
    }

    public function test_inactive_user_is_never_eligible(): void
    {
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist(active: false);
        $this->makeMembership($candidate, $site);
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertNotContains($candidate->getId(), $ids);
    }

    public function test_absent_candidate_is_excluded(): void
    {
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        $this->makeMembership($candidate, $site);
        $this->makeAbsence($candidate, '2026-09-01', '2026-09-01');
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertNotContains($candidate->getId(), $ids);
    }

    public function test_candidate_with_schedule_conflict_is_excluded(): void
    {
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        $this->makeMembership($candidate, $site);
        // Existing ASSIGNED mission overlapping the OPEN mission's slot.
        $this->makeAssignedMission($site, $surgeon, $surgeon, $candidate, '2026-09-01 08:00:00', '2026-09-01 13:00:00');
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertNotContains($candidate->getId(), $ids);
    }

    public function test_multiple_eligible_candidates_are_all_returned(): void
    {
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate1 = $this->makeInstrumentist();
        $candidate2 = $this->makeInstrumentist();
        $this->makeMembership($candidate1, $site);
        $this->makeMembership($candidate2, $site);
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertContains($candidate1->getId(), $ids);
        self::assertContains($candidate2->getId(), $ids);
    }

    public function test_candidate_is_listed_once_per_mission_she_can_take(): void
    {
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        $this->makeMembership($candidate, $site);
        $mission1 = $this->makeOpenMission($site, $surgeon, $surgeon, '2026-09-01 08:00:00', '2026-09-01 13:00:00');
        $mission2 = $this->makeOpenMission($site, $surgeon, $surgeon, '2026-09-08 08:00:00', '2026-09-08 13:00:00');

        $result = $this->service()->findEligibleByMission([$mission1, $mission2]);

        foreach ([$mission1, $mission2] as $mission) {
            $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
            self::assertSame(1, array_count_values($ids)[$candidate->getId()] ?? 0, 'exactly once in each mission list');
        }
    }

    public function test_a_conflict_on_one_mission_removes_the_candidate_from_that_mission_only(): void
    {
        // The defect H3 fixes: the per-site union offered a person every OPEN mission of the
        // site as soon as she was free for ONE of them.
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        $this->makeMembership($candidate, $site);
        $free     = $this->makeOpenMission($site, $surgeon, $surgeon, '2026-09-01 08:00:00', '2026-09-01 13:00:00');
        $busySlot = $this->makeOpenMission($site, $surgeon, $surgeon, '2026-09-08 08:00:00', '2026-09-08 13:00:00');
        $this->makeAssignedMission($site, $surgeon, $surgeon, $candidate, '2026-09-08 09:00:00', '2026-09-08 12:00:00');

        $result = $this->service()->findEligibleByMission([$free, $busySlot]);

        self::assertContains($candidate->getId(), array_map(fn (User $u) => $u->getId(), $result[$free->getId()]));
        self::assertNotContains($candidate->getId(), array_map(fn (User $u) => $u->getId(), $result[$busySlot->getId()]));
    }

    public function test_freelancer_without_site_membership_is_eligible(): void
    {
        // RC1-E fix: the candidate pool must match evaluate()'s — a FREELANCER
        // bypasses the SiteMembership requirement entirely (D-057 single source of truth).
        $site       = $this->makeSite();
        $surgeon    = $this->makeSurgeon();
        $freelancer = $this->makeInstrumentist(employmentType: EmploymentType::FREELANCER);
        // No SiteMembership created for the freelancer — must still be eligible.
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertContains($freelancer->getId(), $ids);
    }

    public function test_freelancer_with_site_membership_is_still_eligible_and_not_duplicated(): void
    {
        $site       = $this->makeSite();
        $surgeon    = $this->makeSurgeon();
        $freelancer = $this->makeInstrumentist(employmentType: EmploymentType::FREELANCER);
        $this->makeMembership($freelancer, $site);
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        $occurrences = array_count_values($ids);
        self::assertSame(1, $occurrences[$freelancer->getId()] ?? 0, 'freelancer must not appear twice even with a membership row');
    }

    public function test_freelancer_still_excluded_when_absent(): void
    {
        // The FREELANCER bypass is specifically for the site-membership rule — absence and
        // schedule-conflict checks must still apply, exactly like evaluate().
        $site       = $this->makeSite();
        $surgeon    = $this->makeSurgeon();
        $freelancer = $this->makeInstrumentist(employmentType: EmploymentType::FREELANCER);
        $this->makeAbsence($freelancer, '2026-09-01', '2026-09-01');
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertNotContains($freelancer->getId(), $ids);
    }

    public function test_freelancer_still_excluded_on_schedule_conflict(): void
    {
        $site       = $this->makeSite();
        $surgeon    = $this->makeSurgeon();
        $freelancer = $this->makeInstrumentist(employmentType: EmploymentType::FREELANCER);
        $this->makeAssignedMission($site, $surgeon, $surgeon, $freelancer, '2026-09-01 08:00:00', '2026-09-01 13:00:00');
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertNotContains($freelancer->getId(), $ids);
    }

    public function test_freelancer_is_eligible_at_multiple_sites_simultaneously(): void
    {
        $siteA      = $this->makeSite();
        $siteB      = $this->makeSite();
        $surgeon    = $this->makeSurgeon();
        $freelancer = $this->makeInstrumentist(employmentType: EmploymentType::FREELANCER);
        // No membership anywhere — still eligible at both sites, non-overlapping times.
        $missionA = $this->makeOpenMission($siteA, $surgeon, $surgeon, '2026-09-01 08:00:00', '2026-09-01 13:00:00');
        $missionB = $this->makeOpenMission($siteB, $surgeon, $surgeon, '2026-09-02 08:00:00', '2026-09-02 13:00:00');

        $result = $this->service()->findEligibleByMission([$missionA, $missionB]);

        $idsA = array_map(fn (User $u) => $u->getId(), $result[$missionA->getId()]);
        $idsB = array_map(fn (User $u) => $u->getId(), $result[$missionB->getId()]);
        self::assertContains($freelancer->getId(), $idsA);
        self::assertContains($freelancer->getId(), $idsB);
    }

    public function test_employee_with_membership_at_one_site_is_not_eligible_at_another(): void
    {
        $siteA     = $this->makeSite();
        $siteB     = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        $this->makeMembership($candidate, $siteA);
        $missionA = $this->makeOpenMission($siteA, $surgeon, $surgeon, '2026-09-01 08:00:00', '2026-09-01 13:00:00');
        $missionB = $this->makeOpenMission($siteB, $surgeon, $surgeon, '2026-09-02 08:00:00', '2026-09-02 13:00:00');

        $result = $this->service()->findEligibleByMission([$missionA, $missionB]);

        $idsA = array_map(fn (User $u) => $u->getId(), $result[$missionA->getId()]);
        $idsB = array_map(fn (User $u) => $u->getId(), $result[$missionB->getId()]);
        self::assertContains($candidate->getId(), $idsA);
        self::assertNotContains($candidate->getId(), $idsB);
    }

    public function test_employee_without_membership_anywhere_is_not_eligible(): void
    {
        // Note: this does not assert the whole per-site result is empty — active FREELANCER
        // instrumentists are eligible at every site by design (see the tests above), so a
        // freshly created site is not guaranteed to have zero candidates system-wide. Only
        // this specific employee (no membership, not a freelancer) must be excluded.
        $site      = $this->makeSite();
        $surgeon   = $this->makeSurgeon();
        $candidate = $this->makeInstrumentist();
        // No SiteMembership created — an EMPLOYEE-type candidate must be excluded.
        $mission = $this->makeOpenMission($site, $surgeon, $surgeon);

        $result = $this->service()->findEligibleByMission([$mission]);

        $ids = array_map(fn (User $u) => $u->getId(), $result[$mission->getId()]);
        self::assertNotContains($candidate->getId(), $ids);
    }

    public function test_empty_mission_list_returns_empty_array(): void
    {
        $result = $this->service()->findEligibleByMission([]);

        self::assertSame([], $result);
    }
}
