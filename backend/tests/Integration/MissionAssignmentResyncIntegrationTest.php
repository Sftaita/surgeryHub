<?php

namespace App\Tests\Integration;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\MissionAssignmentResyncService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D-150 — `app:mission-assignment:resync`, on the real MySQL database. Each scenario builds
 * the state left by code older than D-150 running on the D-150 schema (production.md
 * §Rollback A): `mission` changed by plain SQL, `mission_assignment` not maintained. The check
 * mode writes nothing; --apply corrects exactly the listed rows, never a closed row nor a
 * mission, restores the invariant, and a second run finds nothing to do.
 */
final class MissionAssignmentResyncIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private array $userIds = [];
    private array $siteIds = [];
    private array $missionIds = [];

    private User $manager;
    private User $surgeon;
    private User $iris;
    private User $bruno;
    private Hospital $site;
    /** Fixed reference: every relative fixture date is computed from it, to the second. */
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->now = new \DateTimeImmutable('-1 minute');

        $this->site = new Hospital();
        $this->site->setName('Resync ' . bin2hex(random_bytes(3)));
        $this->em->persist($this->site);
        $this->manager = $this->makeUser('ROLE_MANAGER', 'Manon');
        $this->surgeon = $this->makeUser('ROLE_SURGEON', 'Simon');
        $this->iris = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris');
        $this->bruno = $this->makeUser('ROLE_INSTRUMENTIST', 'Bruno');
        $this->em->flush();
        $this->siteIds[] = $this->site->getId();
        foreach ([$this->manager, $this->surgeon, $this->iris, $this->bruno] as $u) {
            $this->userIds[] = $u->getId();
        }
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->missionIds as $mid) {
            foreach (['planning_alert', 'mission_assignment', 'audit_event'] as $table) {
                $this->db->executeStatement("DELETE FROM {$table} WHERE mission_id = ?", [$mid]);
            }
            $this->db->executeStatement('DELETE FROM mission WHERE id = ?', [$mid]);
        }
        foreach ($this->userIds as $uid) {
            $this->db->executeStatement('DELETE FROM audit_event WHERE actor_id = ?', [$uid]);
            $this->db->executeStatement('DELETE FROM user WHERE id = ?', [$uid]);
        }
        foreach ($this->siteIds as $id) {
            $this->db->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(string $role, string $firstname): User
    {
        $u = new User();
        $u->setEmail('resync-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Reprise');
        if ($role === 'ROLE_INSTRUMENTIST') {
            $u->setEmploymentType(EmploymentType::FREELANCER);
        }
        $this->em->persist($u);

        return $u;
    }

    /** A mission as the D-150 code left it: status, instrumentist and (optionally) its open row. */
    private function mission(MissionStatus $status, ?User $instrumentist, bool $openRow = true): int
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $day = (new \DateTimeImmutable('today', $tz))->modify('+10 days');
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($this->site);
        $m->setSurgeon($this->surgeon);
        $m->setCreatedBy($this->manager);
        $m->setStartAt($day->setTime(8, 0));
        $m->setEndAt($day->setTime(13, 0));
        $m->setStatus($status);
        $m->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $id = (int) $m->getId();
        $this->missionIds[] = $id;
        if ($openRow && $instrumentist !== null) {
            $this->row($id, $instrumentist, '-10 days', null, null);
        }

        return $id;
    }

    private function row(int $missionId, User $instrumentist, string $assignedAt, ?string $endedAt, ?string $endReason): int
    {
        $this->db->executeStatement(
            "INSERT INTO mission_assignment (mission_id, instrumentist_id, source, assigned_at, ended_at, end_reason) VALUES (?, ?, 'DIRECT_ASSIGNMENT', ?, ?, ?)",
            [$missionId, $instrumentist->getId(), $this->at($assignedAt), $endedAt !== null ? $this->at($endedAt) : null, $endReason],
        );

        return (int) $this->db->lastInsertId();
    }

    /** What the old code does: plain SQL on mission, no affectation history. */
    private function oldCode(int $missionId, string $status, ?User $instrumentist, ?string $auditType = null, string $auditAt = '-2 days'): void
    {
        $this->db->executeStatement('UPDATE mission SET status = ?, instrumentist_id = ? WHERE id = ?', [$status, $instrumentist?->getId(), $missionId]);
        if ($auditType !== null) {
            $this->audit($missionId, $auditType, $auditAt);
        }
    }

    private function audit(int $missionId, string $type, string $at): void
    {
        $this->db->executeStatement(
            'INSERT INTO audit_event (actor_id, mission_id, event_type, payload, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?)',
            [$this->manager->getId(), $missionId, $type, $this->at($at), $this->at($at)],
        );
    }

    private function at(string $relative): string
    {
        return $this->now->modify($relative)->format('Y-m-d H:i:s');
    }

    private function resync(): MissionAssignmentResyncService
    {
        return self::getContainer()->get(MissionAssignmentResyncService::class);
    }

    /** @return list<array<string, mixed>> every row of the test missions, as stored */
    private function rows(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, mission_id, instrumentist_id, source, assigned_at, ended_at, end_reason, ended_by_id FROM mission_assignment
              WHERE mission_id IN (?) ORDER BY id',
            [$this->missionIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /** @return list<array<string, mixed>> */
    private function missions(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, status, instrumentist_id FROM mission WHERE id IN (?) ORDER BY id', [$this->missionIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }

    /**
     * The nine situations an old-code period can leave behind.
     *
     * @return array<string, int> case => mission id
     */
    private function driftedMissions(): array
    {
        $aligned = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->row($aligned, $this->bruno, '-20 days', '-10 days', 'REPLACED'); // closed history, must stay untouched

        $released = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->oldCode($released, 'OPEN', null, 'MISSION_RELEASED_TO_POOL', '-2 days');

        $reassigned = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->oldCode($reassigned, 'ASSIGNED', $this->bruno, 'MISSION_REASSIGNED_POST_DEPLOY', '-3 days');

        $cancelled = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->oldCode($cancelled, 'CANCELLED', null, 'MISSION_CANCELLED_POST_DEPLOY', '-4 days');

        $rejected = $this->mission(MissionStatus::DECLARED, $this->iris);
        $this->oldCode($rejected, 'REJECTED', $this->iris, 'MISSION_DECLARED_REJECTED', '-5 days');

        $newAssigned = $this->mission(MissionStatus::ASSIGNED, $this->bruno, openRow: false);
        $newCancelled = $this->mission(MissionStatus::CANCELLED, $this->bruno, openRow: false);

        $releasedSilently = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->oldCode($releasedSilently, 'OPEN', null); // no AuditEvent: end date unknown

        $releasedThenClaimed = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->oldCode($releasedThenClaimed, 'OPEN', null, 'MISSION_RELEASED_TO_POOL', '-6 days');
        $this->oldCode($releasedThenClaimed, 'ASSIGNED', $this->bruno, 'MISSION_CLAIMED_FROM_POOL', '-1 day');

        return compact('aligned', 'released', 'reassigned', 'cancelled', 'rejected', 'newAssigned', 'newCancelled', 'releasedSilently', 'releasedThenClaimed');
    }

    // ── Scenarios ────────────────────────────────────────────────────────────

    public function test_check_lists_every_correction_with_the_origin_of_its_dates_and_writes_nothing(): void
    {
        $m = $this->driftedMissions();
        $rowsBefore = $this->rows();
        $missionsBefore = $this->missions();

        $before = $this->resync()->invariants($this->missionIds);
        self::assertGreaterThan(0, array_sum($before), 'the old code broke the invariant');

        $plan = $this->resync()->plan($this->missionIds);

        $close = [];
        foreach ($plan['close'] as $r) {
            $close[$r['missionId']] = $r;
        }
        self::assertSame([$m['released'], $m['reassigned'], $m['cancelled'], $m['rejected'], $m['releasedSilently'], $m['releasedThenClaimed']], array_keys($close));
        self::assertSame('RELEASED', $close[$m['released']]['endReason']);
        self::assertSame('REPLACED', $close[$m['reassigned']]['endReason']);
        self::assertSame('CANCELLED', $close[$m['cancelled']]['endReason']);
        self::assertSame('REJECTED', $close[$m['rejected']]['endReason']);
        self::assertSame('RELEASED', $close[$m['releasedSilently']]['endReason']);
        self::assertSame('audit_event MISSION_REASSIGNED_POST_DEPLOY', $close[$m['reassigned']]['endedAtSource']);
        self::assertSame('RELEASED', $close[$m['releasedThenClaimed']]['endReason'], 'ended by the release, not by the later claim');
        self::assertSame($this->at('-6 days'), $close[$m['releasedThenClaimed']]['endedAt']->format('Y-m-d H:i:s'));
        self::assertSame($this->at('-3 days'), $close[$m['reassigned']]['endedAt']->format('Y-m-d H:i:s'), 'ended when the old code journaled the change');
        self::assertStringStartsWith('instant de la reprise', $close[$m['releasedSilently']]['endedAtSource'], 'no journaled change: upper bound, reported as such');

        $open = array_column($plan['open'], null, 'missionId');
        self::assertSame([$m['reassigned'], $m['newAssigned'], $m['releasedThenClaimed']], array_keys($open));
        self::assertSame($this->at('-1 day'), $open[$m['releasedThenClaimed']]['assignedAt']->format('Y-m-d H:i:s'), 'the claim starts the new affectation');
        self::assertSame($this->bruno->getId(), $open[$m['reassigned']]['instrumentistId']);
        self::assertSame('audit_event', $open[$m['reassigned']]['assignedAtSource']);
        self::assertSame('mission.created_at', $open[$m['newAssigned']]['assignedAtSource']);

        self::assertSame([$m['newCancelled']], array_column($plan['closedRow'], 'missionId'));
        self::assertStringStartsWith('fin non datée', $plan['closedRow'][0]['endedAtSource']);
        self::assertEquals($plan['closedRow'][0]['assignedAt'], $plan['closedRow'][0]['endedAt']);

        self::assertSame($rowsBefore, $this->rows(), 'check mode: no affectation written');
        self::assertSame($missionsBefore, $this->missions());
    }

    public function test_apply_restores_the_invariant_without_touching_missions_or_closed_rows_and_is_idempotent(): void
    {
        $m = $this->driftedMissions();
        $closedHistory = $this->db->fetchAssociative('SELECT * FROM mission_assignment WHERE mission_id = ? AND ended_at IS NOT NULL', [$m['aligned']]);
        $missionsBefore = $this->missions();

        $after = $this->resync()->apply($this->resync()->plan($this->missionIds), $this->missionIds);

        self::assertSame(0, array_sum($after), 'every invariant at 0 after the corrections');
        self::assertSame($missionsBefore, $this->missions(), 'missions are never modified');
        self::assertSame($closedHistory, $this->db->fetchAssociative('SELECT * FROM mission_assignment WHERE id = ?', [$closedHistory['id']]), 'a closed row is never rewritten');

        $byMission = [];
        foreach ($this->rows() as $r) {
            $byMission[(int) $r['mission_id']][] = $r;
        }
        self::assertCount(2, $byMission[$m['aligned']], 'aligned mission: nothing added');
        self::assertNull($byMission[$m['aligned']][0]['ended_at']);
        [$old, $new] = $byMission[$m['reassigned']];
        self::assertSame(['REPLACED', $this->at('-3 days'), null], [$old['end_reason'], $old['ended_at'], $old['ended_by_id']]);
        self::assertSame(['LEGACY', (string) $this->bruno->getId(), null], [$new['source'], (string) $new['instrumentist_id'], $new['ended_at']]);
        self::assertSame('REJECTED', $byMission[$m['rejected']][0]['end_reason']);
        self::assertSame(['LEGACY', 'CANCELLED'], [$byMission[$m['newCancelled']][0]['source'], $byMission[$m['newCancelled']][0]['end_reason']]);
        self::assertSame($byMission[$m['newCancelled']][0]['assigned_at'], $byMission[$m['newCancelled']][0]['ended_at'], 'fin non datée');

        $rowsAfter = $this->rows();
        $second = $this->resync()->plan($this->missionIds);
        self::assertSame([[], [], []], [$second['close'], $second['open'], $second['closedRow']], 'second run: nothing to do');
        self::assertSame($rowsAfter, $this->rows());
    }

    public function test_closing_an_incompatible_affectation_resolves_its_conflict_alert(): void
    {
        $mission = $this->mission(MissionStatus::ASSIGNED, $this->iris);
        $this->db->executeStatement(
            "INSERT INTO planning_alert (mission_id, status, detected_at, snapshot_json, type) VALUES (?, 'OPEN', NOW(), '{}', 'SURGEON_INCOMPATIBILITY')",
            [$mission],
        );
        $this->oldCode($mission, 'ASSIGNED', $this->bruno, 'MISSION_REASSIGNED_POST_DEPLOY');

        $this->resync()->apply($this->resync()->plan($this->missionIds), $this->missionIds);

        $alert = $this->db->fetchAssociative('SELECT status, resolved_by_id, resolution_note FROM planning_alert WHERE mission_id = ?', [$mission]);
        self::assertSame('RESOLVED', $alert['status']);
        self::assertNull($alert['resolved_by_id']);
        self::assertStringContainsString('retour arrière du code', (string) $alert['resolution_note']);
    }

    public function test_apply_writes_nothing_when_a_listed_row_changed_after_the_check(): void
    {
        $this->driftedMissions();
        $plan = $this->resync()->plan($this->missionIds);
        $target = $plan['close'][0]['assignmentId'];
        // Concurrent write between the check and --apply.
        $this->db->executeStatement("UPDATE mission_assignment SET ended_at = NOW(), end_reason = 'RELEASED' WHERE id = ?", [$target]);
        $rowsBefore = $this->rows();

        try {
            $this->resync()->apply($plan, $this->missionIds);
            self::fail('apply must refuse a plan that no longer matches the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('rien n\'a été écrit', $e->getMessage());
        }
        self::assertSame($rowsBefore, $this->rows(), 'the whole correction is rolled back');
    }

    public function test_command_check_then_apply_then_check(): void
    {
        $this->driftedMissions();
        $application = new Application(self::$kernel);
        $options = ['--mission' => array_map('strval', $this->missionIds)];

        $check = new CommandTester($application->find('app:mission-assignment:resync'));
        self::assertSame(1, $check->execute($options), 'drift pending: exit 1');
        self::assertStringContainsString('aucune écriture faite', $check->getDisplay());
        self::assertStringContainsString('instant de la reprise', $check->getDisplay());

        $apply = new CommandTester($application->find('app:mission-assignment:resync'));
        self::assertSame(0, $apply->execute($options + ['--apply' => true]));
        self::assertStringContainsString('correction(s) écrite(s)', $apply->getDisplay());

        $again = new CommandTester($application->find('app:mission-assignment:resync'));
        self::assertSame(0, $again->execute($options));
        self::assertStringContainsString('Aucune correction nécessaire', $again->getDisplay());

        $invalid = new CommandTester($application->find('app:mission-assignment:resync'));
        self::assertSame(2, $invalid->execute(['--mission' => ['12a']]));
    }
}
