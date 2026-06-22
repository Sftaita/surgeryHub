<?php

namespace App\Tests\Unit\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Service\PlanningAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared anti-duplicate / resolution mechanics used by both AbsenceImpactService
 * and PlanningOccurrenceExceptionService — this is THE anti-doublon guard (section F).
 */
class PlanningAlertServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    /** Canned result returned by the next getOneOrNullResult()/getResult() call. */
    private mixed $nextResult = null;
    private array $persisted  = [];

    /** Canned results for search()'s two queries (count, then items). */
    private int   $nextTotal = 0;
    private array $nextItems = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->nextResult = null;
        $this->persisted  = [];
        $this->nextTotal  = 0;
        $this->nextItems  = [];

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturnCallback(fn () => $this->nextResult);
        $query->method('getResult')->willReturnCallback(fn () => $this->nextResult ?? $this->nextItems);
        $query->method('getSingleScalarResult')->willReturnCallback(fn () => $this->nextTotal);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);
        $this->em->method('persist')->willReturnCallback(function (object $e): void { $this->persisted[] = $e; });
    }

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        return $u;
    }

    private function makeService(): PlanningAlertService
    {
        return new PlanningAlertService($this->em);
    }

    private function makeMission(): Mission
    {
        $surgeon = new User();
        $surgeon->setEmail('surgeon@test.com');
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($surgeon, ++self::$idSeq);

        $site = new Hospital();
        $site->setName('Alpha');
        $rp2 = new \ReflectionProperty(Hospital::class, 'id');
        $rp2->setValue($site, ++self::$idSeq);

        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable('2026-01-12 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-01-12 13:00:00'));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);
        return $m;
    }

    private function makeAbsence(): Absence
    {
        $user = new User();
        $user->setEmail('absent@test.com');
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable('2026-01-12'));
        $a->setDateEnd(new \DateTimeImmutable('2026-01-12'));
        $a->setCreatedBy($user);
        return $a;
    }

    // ── Anti-duplicate (section F) ───────────────────────────────────────────

    public function test_create_if_not_duplicate_creates_when_none_exists(): void
    {
        $mission = $this->makeMission();
        $absence = $this->makeAbsence();
        $this->nextResult = null; // findActiveAlert finds nothing

        $result = $this->makeService()->createIfNotDuplicate($mission, PlanningAlertType::SURGEON_ABSENCE, $absence, ['k' => 'v']);

        $this->assertTrue($result['created']);
        $this->assertContains($result['alert'], $this->persisted);
        $this->assertSame(PlanningAlertType::SURGEON_ABSENCE, $result['alert']->getType());
    }

    public function test_create_if_not_duplicate_returns_existing_alert_without_persisting_again(): void
    {
        $mission = $this->makeMission();
        $absence = $this->makeAbsence();
        $existing = new PlanningAlert();
        $existing->setMission($mission);
        $existing->setType(PlanningAlertType::SURGEON_ABSENCE);
        $existing->setAbsence($absence);
        $this->nextResult = $existing; // findActiveAlert finds the same alert

        $result = $this->makeService()->createIfNotDuplicate($mission, PlanningAlertType::SURGEON_ABSENCE, $absence, ['k' => 'v']);

        $this->assertFalse($result['created']);
        $this->assertSame($existing, $result['alert']);
        $this->assertSame([], $this->persisted, 'Must not call persist() when an active alert already covers this case');
    }

    public function test_calling_create_if_not_duplicate_twice_in_a_row_only_persists_once(): void
    {
        $mission = $this->makeMission();
        $absence = $this->makeAbsence();
        $service = $this->makeService();

        $this->nextResult = null;
        $first = $service->createIfNotDuplicate($mission, PlanningAlertType::SURGEON_ABSENCE, $absence, []);
        $this->assertTrue($first['created']);

        // Simulate the alert now existing on the second call (as it would after a real flush+query).
        $this->nextResult = $first['alert'];
        $second = $service->createIfNotDuplicate($mission, PlanningAlertType::SURGEON_ABSENCE, $absence, []);

        $this->assertFalse($second['created']);
        $this->assertSame($first['alert'], $second['alert']);
        $this->assertCount(1, $this->persisted, 'Re-running impact detection on the same absence must not create a second identical alert');
    }

    // ── Resolution (sections D/E) ────────────────────────────────────────────

    public function test_resolve_all_for_absence_resolves_without_deleting(): void
    {
        $absence = $this->makeAbsence();
        $alert1 = new PlanningAlert();
        $alert1->setMission($this->makeMission());
        $alert1->setType(PlanningAlertType::SURGEON_ABSENCE);
        $alert2 = new PlanningAlert();
        $alert2->setMission($this->makeMission());
        $alert2->setType(PlanningAlertType::INSTRUMENTIST_ABSENCE);
        $this->nextResult = [$alert1, $alert2];

        $this->em->expects($this->never())->method('remove');

        $resolved = $this->makeService()->resolveAllForAbsence($absence, 'Absence supprimée.');

        $this->assertCount(2, $resolved);
        foreach ($resolved as $alert) {
            $this->assertSame(PlanningAlertStatus::RESOLVED, $alert->getStatus());
            $this->assertSame('Absence supprimée.', $alert->getResolutionNote());
            $this->assertNotNull($alert->getResolvedAt());
        }
    }

    public function test_resolve_all_for_absence_with_no_active_alerts_resolves_nothing(): void
    {
        $absence = $this->makeAbsence();
        $this->nextResult = [];

        $resolved = $this->makeService()->resolveAllForAbsence($absence, 'no-op');

        $this->assertSame([], $resolved);
    }

    // ── Batch 4: manager transitions ─────────────────────────────────────────

    private function makeAlert(PlanningAlertStatus $status = PlanningAlertStatus::OPEN, PlanningAlertType $type = PlanningAlertType::SURGEON_ABSENCE): PlanningAlert
    {
        $alert = new PlanningAlert();
        $alert->setType($type);
        $alert->setMission($this->makeMission());
        $rp = new \ReflectionProperty(PlanningAlert::class, 'status');
        $rp->setValue($alert, $status);
        return $alert;
    }

    public function test_acknowledge_open_alert_changes_status(): void
    {
        $alert   = $this->makeAlert(PlanningAlertStatus::OPEN);
        $manager = $this->makeUser('manager@test.com');

        $changed = $this->makeService()->acknowledge($alert, $manager);

        $this->assertTrue($changed);
        $this->assertSame(PlanningAlertStatus::ACKNOWLEDGED, $alert->getStatus());
        $this->assertSame($manager, $alert->getResolvedBy());
    }

    public function test_acknowledge_already_acknowledged_is_idempotent_noop(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::ACKNOWLEDGED);

        $changed = $this->makeService()->acknowledge($alert, $this->makeUser('manager@test.com'));

        $this->assertFalse($changed);
        $this->assertSame(PlanningAlertStatus::ACKNOWLEDGED, $alert->getStatus());
    }

    public function test_acknowledge_resolved_alert_throws_conflict(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::RESOLVED);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->makeService()->acknowledge($alert, $this->makeUser('manager@test.com'));
    }

    public function test_acknowledge_ignored_alert_throws_conflict(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::IGNORED);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->makeService()->acknowledge($alert, $this->makeUser('manager@test.com'));
    }

    public function test_resolve_open_alert_changes_status_and_keeps_note(): void
    {
        $alert   = $this->makeAlert(PlanningAlertStatus::OPEN);
        $manager = $this->makeUser('manager@test.com');

        $changed = $this->makeService()->resolve($alert, $manager, 'Mission annulée.');

        $this->assertTrue($changed);
        $this->assertSame(PlanningAlertStatus::RESOLVED, $alert->getStatus());
        $this->assertSame('Mission annulée.', $alert->getResolutionNote());
    }

    public function test_resolve_already_resolved_is_idempotent_and_preserves_original_note(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::OPEN);
        $this->makeService()->resolve($alert, $this->makeUser('first@test.com'), 'Première résolution.');

        $changed = $this->makeService()->resolve($alert, $this->makeUser('second@test.com'), 'Seconde tentative.');

        $this->assertFalse($changed, 'Resolving an already-resolved alert must be a no-op');
        $this->assertSame('Première résolution.', $alert->getResolutionNote(), 'The first resolution must win — history is never overwritten');
    }

    public function test_resolve_ignored_alert_throws_conflict(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::IGNORED);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->makeService()->resolve($alert, $this->makeUser('manager@test.com'), 'note');
    }

    public function test_ignore_open_alert_changes_status(): void
    {
        $alert   = $this->makeAlert(PlanningAlertStatus::ACKNOWLEDGED);
        $manager = $this->makeUser('manager@test.com');

        $changed = $this->makeService()->ignore($alert, $manager, 'Faux positif.');

        $this->assertTrue($changed);
        $this->assertSame(PlanningAlertStatus::IGNORED, $alert->getStatus());
    }

    public function test_ignore_already_ignored_is_idempotent_noop(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::IGNORED);

        $changed = $this->makeService()->ignore($alert, $this->makeUser('manager@test.com'), 'note');

        $this->assertFalse($changed);
    }

    public function test_ignore_resolved_alert_throws_conflict(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::RESOLVED);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->makeService()->ignore($alert, $this->makeUser('manager@test.com'), 'note');
    }

    public function test_transitions_never_touch_the_mission(): void
    {
        $alert   = $this->makeAlert(PlanningAlertStatus::OPEN);
        $mission = $alert->getMission();
        $statusBefore = $mission->getStatus();
        $instrumentistBefore = $mission->getInstrumentist();

        $this->makeService()->acknowledge($alert, $this->makeUser('manager@test.com'));
        $this->makeService()->resolve($alert, $this->makeUser('manager@test.com'), 'note');

        $this->assertSame($statusBefore, $mission->getStatus());
        $this->assertSame($instrumentistBefore, $mission->getInstrumentist());
    }

    // ── Batch 4: action flags ─────────────────────────────────────────────────

    public function test_action_flags_for_open_reassignment_required_assigned_mission(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::OPEN, PlanningAlertType::REASSIGNMENT_REQUIRED);
        $alert->getMission()->setStatus(MissionStatus::ASSIGNED);

        $flags = $this->makeService()->computeActionFlags($alert);

        $this->assertTrue($flags['canAcknowledge']);
        $this->assertTrue($flags['canResolve']);
        $this->assertTrue($flags['canIgnore']);
        $this->assertTrue($flags['canReassign']);
        $this->assertTrue($flags['canOpenAsAvailable']);
        $this->assertSame('REASSIGN', $flags['recommendedAction']);
    }

    public function test_action_flags_for_resolved_alert_are_all_inactive(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::RESOLVED, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $flags = $this->makeService()->computeActionFlags($alert);

        $this->assertFalse($flags['canAcknowledge']);
        $this->assertFalse($flags['canResolve']);
        $this->assertFalse($flags['canIgnore']);
        $this->assertFalse($flags['canReassign']);
        $this->assertFalse($flags['canOpenAsAvailable']);
        $this->assertSame('NONE', $flags['recommendedAction']);
    }

    public function test_action_flags_for_instrumentist_absence_on_open_mission_recommend_none(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::OPEN, PlanningAlertType::INSTRUMENTIST_ABSENCE);
        $alert->getMission()->setStatus(MissionStatus::OPEN);

        $flags = $this->makeService()->computeActionFlags($alert);

        $this->assertSame('NONE', $flags['recommendedAction'], 'Mission is already in the open pool — nothing urgent to recommend');
    }

    public function test_action_flags_for_surgeon_absence_recommend_review_and_never_allow_reassign(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::OPEN, PlanningAlertType::SURGEON_ABSENCE);
        $alert->getMission()->setStatus(MissionStatus::ASSIGNED);

        $flags = $this->makeService()->computeActionFlags($alert);

        $this->assertSame('REVIEW', $flags['recommendedAction']);
        $this->assertFalse($flags['canReassign'], 'Reassigning an instrumentist does not fix a surgeon being absent');
    }

    public function test_action_flags_disabled_when_mission_is_terminal(): void
    {
        $alert = $this->makeAlert(PlanningAlertStatus::OPEN, PlanningAlertType::REASSIGNMENT_REQUIRED);
        $alert->getMission()->setStatus(MissionStatus::SUBMITTED);

        $flags = $this->makeService()->computeActionFlags($alert);

        $this->assertFalse($flags['canReassign'], 'SUBMITTED is past the point where reassignment makes sense');
        $this->assertFalse($flags['canOpenAsAvailable']);
    }

    // ── Batch 4: search ───────────────────────────────────────────────────────

    public function test_search_returns_items_and_total_from_two_queries(): void
    {
        $alert = $this->makeAlert();
        $this->nextTotal = 1;
        $this->nextItems = [$alert];

        $result = $this->makeService()->search(['status' => PlanningAlertStatus::OPEN], 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame([$alert], $result['items']);
    }

    public function test_search_with_no_filters_still_works(): void
    {
        $this->nextTotal = 0;
        $this->nextItems = [];

        $result = $this->makeService()->search([], 1, 20);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    // ── Batch 11 Fix 4: serialize() includes a stable name for user refs ────

    public function test_serialize_includes_name_for_surgeon_and_instrumentist_refs(): void
    {
        $surgeon = new User();
        $surgeon->setEmail('surgeon@test.com');
        $surgeon->setFirstname('Jean');
        $surgeon->setLastname('Dupont');
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($surgeon, ++self::$idSeq);

        $instrumentist = new User();
        $instrumentist->setEmail('instr@test.com');
        $instrumentist->setFirstname('Marie');
        $instrumentist->setLastname('Curie');
        $rp->setValue($instrumentist, ++self::$idSeq);

        $site = new Hospital();
        $site->setName('Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($site, ++self::$idSeq);

        $mission = new Mission();
        $mission->setStatus(MissionStatus::ASSIGNED);
        $mission->setType(MissionType::BLOCK);
        $mission->setSurgeon($surgeon);
        $mission->setInstrumentist($instrumentist);
        $mission->setSite($site);
        $mission->setStartAt(new \DateTimeImmutable('2026-01-12 08:00:00'));
        $mission->setEndAt(new \DateTimeImmutable('2026-01-12 13:00:00'));
        $mission->setCreatedBy($surgeon);
        $mission->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);

        $alert = new PlanningAlert();
        $alert->setType(PlanningAlertType::SURGEON_ABSENCE);
        $alert->setMission($mission);
        (new \ReflectionProperty(PlanningAlert::class, 'id'))->setValue($alert, ++self::$idSeq);

        $serialized = $this->makeService()->serialize($alert);

        $this->assertSame('Jean Dupont', $serialized['mission']['surgeon']['name']);
        $this->assertSame('Marie Curie', $serialized['mission']['instrumentist']['name']);
    }

    public function test_serialize_falls_back_to_email_when_name_is_blank(): void
    {
        $surgeon = new User();
        $surgeon->setEmail('noname@test.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($surgeon, ++self::$idSeq);

        $site = new Hospital();
        $site->setName('Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($site, ++self::$idSeq);

        $mission = new Mission();
        $mission->setStatus(MissionStatus::ASSIGNED);
        $mission->setType(MissionType::BLOCK);
        $mission->setSurgeon($surgeon);
        $mission->setSite($site);
        $mission->setStartAt(new \DateTimeImmutable('2026-01-12 08:00:00'));
        $mission->setEndAt(new \DateTimeImmutable('2026-01-12 13:00:00'));
        $mission->setCreatedBy($surgeon);
        $mission->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);

        $alert = new PlanningAlert();
        $alert->setType(PlanningAlertType::SURGEON_ABSENCE);
        $alert->setMission($mission);
        (new \ReflectionProperty(PlanningAlert::class, 'id'))->setValue($alert, ++self::$idSeq);

        $serialized = $this->makeService()->serialize($alert);

        $this->assertSame('noname@test.com', $serialized['mission']['surgeon']['name']);
    }
}
