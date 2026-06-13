<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\PlanningDiffService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests PlanningDiffService::computeDiff() — the pure diff engine.
 *
 * computeDiff() takes two plain arrays of Mission objects and returns
 * {added, removed, modified}. No EM access happens in these tests.
 *
 * Key: siteId_surgeonId_missionType_date_startRounded(15min)
 * Excluded from diff: status, notes, metadata, financial fields.
 */
class PlanningDiffServiceTest extends TestCase
{
    // ── Factory helpers ───────────────────────────────────────────────────────

    private function makeService(): PlanningDiffService
    {
        return new PlanningDiffService($this->createMock(EntityManagerInterface::class));
    }

    private function makeUser(int $id, string $firstname, string $lastname): User
    {
        $u = new User();
        $u->setEmail(strtolower($firstname) . '@test.com');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
        return $u;
    }

    private function makeSite(int $id, string $name): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($h, $id);
        return $h;
    }

    /**
     * @param array{site: Hospital, surgeon: User, instrumentist?: User|null, start: string, end: string, type?: MissionType} $opts
     */
    private function makeMission(array $opts): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::DRAFT);
        $m->setType($opts['type'] ?? MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable($opts['start']));
        $m->setEndAt(new \DateTimeImmutable($opts['end']));
        $m->setSite($opts['site']);
        $m->setSurgeon($opts['surgeon']);
        $m->setCreatedBy($opts['surgeon']);
        $m->setInstrumentist($opts['instrumentist'] ?? null);
        return $m;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * First deploy: no previous version exists → old list is empty.
     * All new missions must appear as "added".
     */
    public function test_first_deploy_all_missions_appear_as_added(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');

        $m1 = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $m2 = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-25 08:00', 'end' => '2026-03-25 13:00']);

        $diff = $service->computeDiff([], [$m1, $m2]);

        $this->assertCount(2, $diff['added'],   'Both new missions must appear as added on first deploy');
        $this->assertCount(0, $diff['removed'],  'Nothing can be removed on first deploy');
        $this->assertCount(0, $diff['modified'], 'Nothing can be modified on first deploy');
    }

    /**
     * Instrumentist replaced for the same slot → appears as modified with changes.instrumentist.
     */
    public function test_changed_instrumentist_appears_as_modified(): void
    {
        $service  = $this->makeService();
        $site     = $this->makeSite(1, 'Alpha');
        $surgeon  = $this->makeUser(10, 'Jean', 'Dupont');
        $instrOld = $this->makeUser(20, 'Marie', 'Martin');
        $instrNew = $this->makeUser(21, 'Sophie', 'Bernard');

        $old = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => $instrOld, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => $instrNew, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(1, $diff['modified'], 'Instrumentist change must produce exactly one modified entry');

        $change = $diff['modified'][0];
        $this->assertArrayHasKey('instrumentist', $change['changes'],
            'changes must include "instrumentist" key'
        );
        $this->assertSame(20, $change['changes']['instrumentist']['from']['id']);
        $this->assertSame(21, $change['changes']['instrumentist']['to']['id']);
    }

    /**
     * Instrumentist was null, now assigned → modified (null → user).
     */
    public function test_instrumentist_added_to_previously_unassigned_slot(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');
        $instr   = $this->makeUser(20, 'Marie', 'Martin');

        $old = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => null, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => $instr,  'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(1, $diff['modified']);
        $this->assertNull($diff['modified'][0]['changes']['instrumentist']['from'],
            '"from" must be null when old slot had no instrumentist'
        );
        $this->assertSame(20, $diff['modified'][0]['changes']['instrumentist']['to']['id']);
    }

    /**
     * End time changed (shorter intervention) → modified with changes.schedule.
     */
    public function test_changed_schedule_endAt_appears_as_modified(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');

        $old = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 12:00']);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(1, $diff['modified'], 'End-time change must produce exactly one modified entry');

        $change = $diff['modified'][0];
        $this->assertArrayHasKey('schedule', $change['changes'],
            'changes must include "schedule" key for time changes'
        );
        $this->assertSame('13:00', $change['changes']['schedule']['from']['endAt']);
        $this->assertSame('12:00', $change['changes']['schedule']['to']['endAt']);
    }

    /**
     * Start time shifted within the same 15-min rounding slot → keys match → modified.
     *
     * Rounding to 15 min: 08:00 → 08:00, 08:07 → 08:00 (487÷15 = 32.47 → 32 × 15 = 480).
     * Both share key suffix "08:00", so they're compared rather than treated as add+remove.
     * The exact start times differ (08:00 vs 08:07) → schedule change reported.
     */
    public function test_changed_schedule_startAt_within_rounding_slot_appears_as_modified(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');

        $old = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:07', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['removed'],  '08:00 and 08:07 round to the same slot — no false add/remove');
        $this->assertCount(1, $diff['modified'],  'Exact start time still differs → schedule change reported');
        $this->assertArrayHasKey('schedule', $diff['modified'][0]['changes']);
        $this->assertSame('08:00', $diff['modified'][0]['changes']['schedule']['from']['startAt']);
        $this->assertSame('08:07', $diff['modified'][0]['changes']['schedule']['to']['startAt']);
    }

    /**
     * An old published mission has no counterpart in the new generation → removed.
     */
    public function test_mission_absent_from_new_version_appears_as_removed(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');

        $old1 = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $old2 = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-25 08:00', 'end' => '2026-03-25 13:00']);
        $new1 = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([$old1, $old2], [$new1]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(1, $diff['removed'],  'Mission absent from new version must appear as removed');
        $this->assertCount(0, $diff['modified']);
        $this->assertSame('2026-03-25', $diff['removed'][0]['date'],
            'The removed mission must be the one on 2026-03-25'
        );
    }

    /**
     * REGRESSION — multi-slot: two slots for the same surgeon on the same morning.
     * They must get distinct keys and produce no false collision.
     *
     * Scenario: surgeon has Block at 08:00 (site Alpha) AND Consultation at 08:00 (site Beta).
     * Keys differ on site_id → no collision → both preserved, no diff.
     */
    public function test_two_slots_same_surgeon_same_morning_different_sites_no_collision(): void
    {
        $service  = $this->makeService();
        $siteA    = $this->makeSite(1, 'Alpha');
        $siteB    = $this->makeSite(2, 'Beta');
        $surgeon  = $this->makeUser(10, 'Jean', 'Dupont');
        $instrA   = $this->makeUser(20, 'Marie', 'Martin');
        $instrB   = $this->makeUser(21, 'Sophie', 'Bernard');

        $oldA = $this->makeMission(['site' => $siteA, 'surgeon' => $surgeon, 'instrumentist' => $instrA, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $oldB = $this->makeMission(['site' => $siteB, 'surgeon' => $surgeon, 'instrumentist' => $instrB, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $newA = $this->makeMission(['site' => $siteA, 'surgeon' => $surgeon, 'instrumentist' => $instrA, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $newB = $this->makeMission(['site' => $siteB, 'surgeon' => $surgeon, 'instrumentist' => $instrB, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([$oldA, $oldB], [$newA, $newB]);

        $this->assertCount(0, $diff['added'],   'No missions added');
        $this->assertCount(0, $diff['removed'],  'No missions removed');
        $this->assertCount(0, $diff['modified'], 'No collisions → no false modifications');
    }

    /**
     * Same site, same surgeon, same morning — different mission types → distinct keys.
     */
    public function test_two_slots_same_surgeon_same_site_different_type_no_collision(): void
    {
        $service  = $this->makeService();
        $site     = $this->makeSite(1, 'Alpha');
        $surgeon  = $this->makeUser(10, 'Jean', 'Dupont');

        $oldBlock  = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00', 'type' => MissionType::BLOCK]);
        $oldConsult= $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00', 'type' => MissionType::CONSULTATION]);
        $newBlock  = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00', 'type' => MissionType::BLOCK]);
        $newConsult= $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00', 'type' => MissionType::CONSULTATION]);

        $diff = $service->computeDiff([$oldBlock, $oldConsult], [$newBlock, $newConsult]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(0, $diff['modified']);
    }

    /**
     * Multiple change types on a single slot → all changes reported together.
     */
    public function test_multiple_changes_on_same_slot_all_reported(): void
    {
        $service  = $this->makeService();
        $siteOld  = $this->makeSite(1, 'Alpha');
        $siteNew  = $this->makeSite(1, 'Alpha'); // same id — only instrumentist changes
        $surgeon  = $this->makeUser(10, 'Jean', 'Dupont');
        $instrOld = $this->makeUser(20, 'Marie', 'Martin');
        $instrNew = $this->makeUser(21, 'Sophie', 'Bernard');

        $old = $this->makeMission(['site' => $siteOld, 'surgeon' => $surgeon, 'instrumentist' => $instrOld, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new = $this->makeMission(['site' => $siteNew, 'surgeon' => $surgeon, 'instrumentist' => $instrNew, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 12:30']);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(1, $diff['modified']);
        $changes = $diff['modified'][0]['changes'];
        $this->assertArrayHasKey('schedule',     $changes, 'Schedule change must be reported');
        $this->assertArrayHasKey('instrumentist', $changes, 'Instrumentist change must be reported');
    }

    /**
     * Identical missions in old and new → empty diff.
     */
    public function test_identical_missions_produce_no_diff(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');
        $instr   = $this->makeUser(20, 'Marie', 'Martin');

        $old = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => $instr, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => $instr, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(0, $diff['modified']);
    }

    /**
     * Status change (DRAFT vs OPEN) must NOT appear in diff — status is excluded.
     */
    public function test_status_change_is_excluded_from_diff(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');

        $old = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $old->setStatus(MissionStatus::OPEN);

        $new = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);
        $new->setStatus(MissionStatus::DRAFT);

        $diff = $service->computeDiff([$old], [$new]);

        $this->assertCount(0, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(0, $diff['modified'], 'Status change alone must not appear in diff');
    }

    /**
     * The serialized mission shape must include planning-visible fields only.
     */
    public function test_added_mission_serialized_shape(): void
    {
        $service = $this->makeService();
        $site    = $this->makeSite(1, 'Alpha');
        $surgeon = $this->makeUser(10, 'Jean', 'Dupont');
        $instr   = $this->makeUser(20, 'Marie', 'Martin');

        $m = $this->makeMission(['site' => $site, 'surgeon' => $surgeon, 'instrumentist' => $instr, 'start' => '2026-03-24 08:00', 'end' => '2026-03-24 13:00']);

        $diff = $service->computeDiff([], [$m]);

        $added = $diff['added'][0];
        $this->assertSame('2026-03-24',   $added['date']);
        $this->assertSame('AM',           $added['period']);
        $this->assertSame('08:00',        $added['startAt']);
        $this->assertSame('13:00',        $added['endAt']);
        $this->assertSame('BLOCK',        $added['missionType']);
        $this->assertSame('Jean Dupont',  $added['surgeonName']);
        $this->assertSame('Marie Martin', $added['instrumentistName']);
        $this->assertSame('Alpha',        $added['siteName']);

        // Ensure financial/status/metadata fields are not present
        $this->assertArrayNotHasKey('status',   $added);
        $this->assertArrayNotHasKey('id',        $added);
        $this->assertArrayNotHasKey('createdAt', $added);
    }
}
