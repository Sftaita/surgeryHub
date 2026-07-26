<?php

namespace App\Tests\Integration;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\EncodingReminderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-083 — real-DB test for EncodingReminderService::findEligibleMissions(). The
 * eligibility query is DQL-heavy (status whitelist, day-boundary comparison against
 * business_datetime_immutable columns, DST-sensitive) — mocking QueryBuilder can't
 * verify WHERE-clause correctness, only a real execution can (same rationale as
 * MissionEligibilityServiceFindEligibleTest, RC1-D/E).
 *
 * The dev DB is a prod copy with real, unrelated missions — every assertion here checks
 * whether THIS test's own mission id is present/absent in the result set, never a raw
 * count, so pre-existing data can never make this test flaky.
 */
final class EncodingReminderServiceEligibilityTest extends KernelTestCase
{
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

    private function service(): EncodingReminderService
    {
        return self::getContainer()->get(EncodingReminderService::class);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D083-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d083-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('D083');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    /**
     * @param array{status?:MissionStatus,instrumentist?:?User,submittedAt?:?\DateTimeImmutable,
     *              encodingReminderSentAt?:?\DateTimeImmutable} $overrides
     */
    private function makeMission(Hospital $site, User $surgeon, User $createdBy, string $endAt, array $overrides = []): Mission
    {
        $m = new Mission();
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStatus($overrides['status'] ?? MissionStatus::ASSIGNED);
        // Explicit Europe/Brussels tz: BusinessDateTimeImmutableType::convertToDatabaseValue()
        // calls setTimezone(Brussels) before formatting, which genuinely SHIFTS the instant
        // (not just re-labels it) if the object was constructed in a different zone — the
        // container's default timezone is UTC, so an unqualified DateTimeImmutable here would
        // silently drift by the DST offset and cross midnight for anything late-evening.
        $tz = new \DateTimeZone('Europe/Brussels');
        $m->setStartAt(new \DateTimeImmutable($endAt . ' -1 hour', $tz));
        $m->setEndAt(new \DateTimeImmutable($endAt, $tz));

        $instrumentist = array_key_exists('instrumentist', $overrides) ? $overrides['instrumentist'] : $this->makeUser('ROLE_INSTRUMENTIST');
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        if (array_key_exists('submittedAt', $overrides)) {
            $m->setSubmittedAt($overrides['submittedAt']);
        }
        if (array_key_exists('encodingReminderSentAt', $overrides)) {
            $m->setEncodingReminderSentAt($overrides['encodingReminderSentAt']);
        }

        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** @return int[] */
    private function eligibleIds(\DateTimeImmutable $now): array
    {
        return array_map(static fn (Mission $m): int => $m->getId(), $this->service()->findEligibleMissions($now));
    }

    private function brussels(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Brussels'));
    }

    public function test_mission_ended_yesterday_not_submitted_is_eligible(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 18:00:00');

        $this->assertContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_mission_ended_today_is_not_eligible(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-26 08:00:00');

        $this->assertNotContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_submitted_mission_is_not_eligible(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 18:00:00', [
            'submittedAt' => new \DateTimeImmutable('2026-07-25 19:00:00'),
        ]);

        $this->assertNotContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_cancelled_mission_is_not_eligible(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 18:00:00', [
            'status' => MissionStatus::CANCELLED,
        ]);

        $this->assertNotContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_rejected_mission_is_not_eligible(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 18:00:00', [
            'status' => MissionStatus::REJECTED,
        ]);

        $this->assertNotContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_mission_without_instrumentist_is_not_eligible(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 18:00:00', [
            'status' => MissionStatus::OPEN,
            'instrumentist' => null,
        ]);

        $this->assertNotContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_already_reminded_mission_is_not_eligible_again(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 18:00:00', [
            'encodingReminderSentAt' => new \DateTimeImmutable('2026-07-26 08:05:00'),
        ]);

        $this->assertNotContains($mission->getId(), $this->eligibleIds($this->brussels('2026-07-26 10:00:00')));
    }

    public function test_summer_dst_boundary_belgian_summer_time(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        // 2026-08-14 is CEST (UTC+2). Mission ends late the day before "now".
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-08-14 23:30:00');

        $this->assertContains($mission->getId(), $this->eligibleIds($this->brussels('2026-08-15 08:30:00')));
    }

    public function test_winter_standard_time_boundary(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        // 2026-01-14 is CET (UTC+1).
        $mission = $this->makeMission($site, $surgeon, $surgeon, '2026-01-14 23:30:00');

        $this->assertContains($mission->getId(), $this->eligibleIds($this->brussels('2026-01-15 08:30:00')));
    }

    public function test_exact_midnight_boundary(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $justBeforeMidnight = $this->makeMission($site, $surgeon, $surgeon, '2026-07-25 23:59:59');
        $exactlyMidnightNextDay = $this->makeMission($site, $surgeon, $surgeon, '2026-07-26 00:00:00');

        $eligible = $this->eligibleIds($this->brussels('2026-07-26 10:00:00'));

        $this->assertContains($justBeforeMidnight->getId(), $eligible, "23:59:59 the day before must be eligible");
        $this->assertNotContains($exactlyMidnightNextDay->getId(), $eligible, "00:00:00 already counts as 'today', not eligible yet");
    }
}
