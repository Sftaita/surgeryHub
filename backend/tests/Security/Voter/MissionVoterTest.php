<?php

namespace App\Tests\Security\Voter;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Security\Voter\MissionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class MissionVoterTest extends TestCase
{
    private MissionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new MissionVoter();
    }

    private function tokenForUser(array $roles): UsernamePasswordToken
    {
        $user = new User();
        $user->setEmail('test@surgicalhub.test');
        $user->setRoles($roles);
        return new UsernamePasswordToken($user, 'main', $roles);
    }

    private function makeMission(MissionStatus $status = MissionStatus::OPEN): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        return $m;
    }

    // ── RELEASE ───────────────────────────────────────────────────────────────

    public function test_manager_can_release_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::RELEASE],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_admin_can_release_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_ADMIN']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::RELEASE],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_instrumentist_cannot_release_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::RELEASE],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_surgeon_cannot_release_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_SURGEON']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::RELEASE],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── CANCEL ────────────────────────────────────────────────────────────────

    public function test_manager_can_cancel_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::OPEN),
            [MissionVoter::CANCEL],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_admin_can_cancel_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_ADMIN']),
            $this->makeMission(MissionStatus::OPEN),
            [MissionVoter::CANCEL],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_instrumentist_cannot_cancel_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::OPEN),
            [MissionVoter::CANCEL],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── REASSIGN ──────────────────────────────────────────────────────────────

    public function test_manager_can_reassign_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::REASSIGN],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_admin_can_reassign_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_ADMIN']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::REASSIGN],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_instrumentist_cannot_reassign_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::REASSIGN],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── ASSIGN_INSTRUMENTIST (RC1-C, Cluster C fix) ───────────────────────────

    public function test_manager_can_assign_instrumentist_on_draft_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::DRAFT),
            [MissionVoter::ASSIGN_INSTRUMENTIST],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_admin_can_assign_instrumentist_on_draft_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_ADMIN']),
            $this->makeMission(MissionStatus::DRAFT),
            [MissionVoter::ASSIGN_INSTRUMENTIST],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_instrumentist_cannot_assign_instrumentist(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::DRAFT),
            [MissionVoter::ASSIGN_INSTRUMENTIST],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_surgeon_cannot_assign_instrumentist(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_SURGEON']),
            $this->makeMission(MissionStatus::DRAFT),
            [MissionVoter::ASSIGN_INSTRUMENTIST],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── Abstain on unknown attribute ──────────────────────────────────────────

    public function test_voter_abstains_on_unknown_attribute(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(),
            ['SOME_UNKNOWN_ATTRIBUTE'],
        );
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    // ── VIEW ─────────────────────────────────────────────────────────────────

    public function test_manager_can_view_any_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::DRAFT),
            [MissionVoter::VIEW],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_v2_open_mission_any_instrumentist_can_view(): void
    {
        // RC1-A P0-2: V2 OPEN missions have no MissionPublication rows.
        // Any authenticated instrumentist must be able to view them to place a claim.
        $mission = $this->makeMission(MissionStatus::OPEN);
        // Default new Mission has empty publications collection → V2 path.

        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $mission,
            [MissionVoter::VIEW],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_instrumentist_cannot_view_assigned_mission_they_do_not_own(): void
    {
        // ASSIGNED mission: instrumentist must be the assigned one, not any random instrumentist.
        // The mission has no surgeon/instrumentist set → getId() comparisons must not match.
        // Give the token user a non-null id so null-vs-int comparisons stay false.
        $user = new User();
        $user->setEmail('instr@test.com');
        $user->setRoles(['ROLE_INSTRUMENTIST']);
        $this->setId($user, 1);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_INSTRUMENTIST']);

        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        // No surgeon, no instrumentist, status ASSIGNED → all voter checks fail.

        $result = $this->voter->vote($token, $mission, [MissionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_surgeon_can_view_their_own_mission(): void
    {
        $user = new User();
        $user->setEmail('surgeon@test.com');
        $user->setRoles(['ROLE_SURGEON']);
        $this->setId($user, 10);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_SURGEON']);

        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $mission->setSurgeon($user);

        $result = $this->voter->vote($token, $mission, [MissionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_surgeon_cannot_view_another_surgeons_mission(): void
    {
        $otherSurgeon = new User();
        $otherSurgeon->setEmail('other@test.com');
        $otherSurgeon->setRoles(['ROLE_SURGEON']);
        $this->setId($otherSurgeon, 99);

        $mission = $this->makeMission(MissionStatus::ASSIGNED);
        $mission->setSurgeon($otherSurgeon);
        // No instrumentist set on mission.

        // Token user has id=1 — different from otherSurgeon (id=99) and from mission's
        // instrumentist (null).  Both getId() comparisons must be false.
        $tokenUser = new User();
        $tokenUser->setEmail('stranger@test.com');
        $tokenUser->setRoles(['ROLE_SURGEON']);
        $this->setId($tokenUser, 1);
        $token = new UsernamePasswordToken($tokenUser, 'main', ['ROLE_SURGEON']);

        $result = $this->voter->vote($token, $mission, [MissionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** Sets private $id via reflection — avoids needing a persist/flush cycle in unit tests. */
    private function setId(User $user, int $id): void
    {
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);
    }

    // ── ENCODING_START (Lot 7, D-070) ───────────────────────────────────────────

    private function makeInstrumentist(int $id): User
    {
        $user = new User();
        $user->setEmail("instr{$id}@test.com");
        $user->setRoles(['ROLE_INSTRUMENTIST']);
        $this->setId($user, $id);
        return $user;
    }

    private function makeAssignedMission(MissionStatus $status, User $instrumentist, ?\DateTimeImmutable $startAt = null): Mission
    {
        $mission = $this->makeMission($status);
        $mission->setInstrumentist($instrumentist);
        $mission->setStartAt($startAt ?? new \DateTimeImmutable('-1 hour'));
        return $mission;
    }

    public function test_assigned_instrumentist_can_start_encoding_on_assigned_mission(): void
    {
        $instrumentist = $this->makeInstrumentist(1);
        $mission = $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist);
        $token = new UsernamePasswordToken($instrumentist, 'main', ['ROLE_INSTRUMENTIST']);

        $result = $this->voter->vote($token, $mission, [MissionVoter::ENCODING_START]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_assigned_instrumentist_can_start_encoding_on_in_progress_mission(): void
    {
        $instrumentist = $this->makeInstrumentist(1);
        $mission = $this->makeAssignedMission(MissionStatus::IN_PROGRESS, $instrumentist);
        $token = new UsernamePasswordToken($instrumentist, 'main', ['ROLE_INSTRUMENTIST']);

        $result = $this->voter->vote($token, $mission, [MissionVoter::ENCODING_START]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_other_instrumentist_cannot_start_encoding(): void
    {
        $instrumentist = $this->makeInstrumentist(1);
        $stranger = $this->makeInstrumentist(2);
        $mission = $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist);
        $token = new UsernamePasswordToken($stranger, 'main', ['ROLE_INSTRUMENTIST']);

        $result = $this->voter->vote($token, $mission, [MissionVoter::ENCODING_START]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_cannot_start_encoding_before_mission_start_time(): void
    {
        $instrumentist = $this->makeInstrumentist(1);
        $mission = $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist, new \DateTimeImmutable('+1 hour'));
        $token = new UsernamePasswordToken($instrumentist, 'main', ['ROLE_INSTRUMENTIST']);

        $result = $this->voter->vote($token, $mission, [MissionVoter::ENCODING_START]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_cannot_start_encoding_on_submitted_mission(): void
    {
        $instrumentist = $this->makeInstrumentist(1);
        $mission = $this->makeAssignedMission(MissionStatus::SUBMITTED, $instrumentist);
        $token = new UsernamePasswordToken($instrumentist, 'main', ['ROLE_INSTRUMENTIST']);

        $result = $this->voter->vote($token, $mission, [MissionVoter::ENCODING_START]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_manager_cannot_start_encoding(): void
    {
        // Manager permissions are "consulte / valide / refuse / rouvre" (D-070) — never "démarre".
        $instrumentist = $this->makeInstrumentist(1);
        $mission = $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist);

        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $mission,
            [MissionVoter::ENCODING_START],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── ENCODING_VALIDATE (Lot 7, D-070) ────────────────────────────────────────

    public function test_manager_can_validate_submitted_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::SUBMITTED),
            [MissionVoter::ENCODING_VALIDATE],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_manager_cannot_validate_non_submitted_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::ENCODING_IN_PROGRESS),
            [MissionVoter::ENCODING_VALIDATE],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_cannot_validate_submitted_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::SUBMITTED),
            [MissionVoter::ENCODING_VALIDATE],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── ENCODING_REJECT (Lot 7, D-070) ──────────────────────────────────────────

    public function test_manager_can_reject_submitted_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::SUBMITTED),
            [MissionVoter::ENCODING_REJECT],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_manager_cannot_reject_non_submitted_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::VALIDATED),
            [MissionVoter::ENCODING_REJECT],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_cannot_reject_submitted_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::SUBMITTED),
            [MissionVoter::ENCODING_REJECT],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── ENCODING_REOPEN (Lot 7, D-070) ──────────────────────────────────────────

    public function test_manager_can_reopen_validated_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::VALIDATED),
            [MissionVoter::ENCODING_REOPEN],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_manager_cannot_reopen_closed_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::CLOSED),
            [MissionVoter::ENCODING_REOPEN],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_cannot_reopen_validated_mission(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_INSTRUMENTIST']),
            $this->makeMission(MissionStatus::VALIDATED),
            [MissionVoter::ENCODING_REOPEN],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── ENCODING_REMIND (D-120, cockpit Suivi des encodages) ────────────────────

    public function test_manager_can_remind_on_statuses_where_encoding_is_still_open(): void
    {
        $instrumentist = $this->makeInstrumentist(10);

        foreach ([
            MissionStatus::ASSIGNED,
            MissionStatus::IN_PROGRESS,
            MissionStatus::ENCODING_IN_PROGRESS,
            MissionStatus::DECLARED,
        ] as $status) {
            $result = $this->voter->vote(
                $this->tokenForUser(['ROLE_MANAGER']),
                $this->makeAssignedMission($status, $instrumentist),
                [MissionVoter::ENCODING_REMIND],
            );
            self::assertSame(VoterInterface::ACCESS_GRANTED, $result, "expected grant for status {$status->value}");
        }
    }

    public function test_admin_can_remind(): void
    {
        $instrumentist = $this->makeInstrumentist(11);

        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_ADMIN']),
            $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist),
            [MissionVoter::ENCODING_REMIND],
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_manager_cannot_remind_once_submitted_or_validated(): void
    {
        $instrumentist = $this->makeInstrumentist(12);

        foreach ([MissionStatus::SUBMITTED, MissionStatus::VALIDATED] as $status) {
            $result = $this->voter->vote(
                $this->tokenForUser(['ROLE_MANAGER']),
                $this->makeAssignedMission($status, $instrumentist),
                [MissionVoter::ENCODING_REMIND],
            );
            self::assertSame(VoterInterface::ACCESS_DENIED, $result, "expected denial for status {$status->value}");
        }
    }

    public function test_manager_cannot_remind_a_mission_with_no_instrumentist(): void
    {
        $result = $this->voter->vote(
            $this->tokenForUser(['ROLE_MANAGER']),
            $this->makeMission(MissionStatus::ASSIGNED),
            [MissionVoter::ENCODING_REMIND],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_cannot_remind(): void
    {
        $instrumentist = $this->makeInstrumentist(13);

        $result = $this->voter->vote(
            new UsernamePasswordToken($instrumentist, 'main', ['ROLE_INSTRUMENTIST']),
            $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist),
            [MissionVoter::ENCODING_REMIND],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── HOURS_REMIND (D-136, rappel des heures réelles manquantes) ──────────────
    // Les branches fines (statuts, heures réelles, verrou) sont couvertes par
    // MissionHoursReminderPolicyTest ; ici : le rôle et le branchement sur la policy.

    private function makeEndedAssignedMission(User $instrumentist): Mission
    {
        $mission = $this->makeAssignedMission(MissionStatus::ASSIGNED, $instrumentist, new \DateTimeImmutable('-10 hours'));
        $mission->setEndAt(new \DateTimeImmutable('-2 hours'));
        return $mission;
    }

    public function test_manager_and_admin_can_remind_hours_on_ended_mission_without_real_hours(): void
    {
        $instrumentist = $this->makeInstrumentist(20);

        foreach (['ROLE_MANAGER', 'ROLE_ADMIN'] as $role) {
            $result = $this->voter->vote(
                $this->tokenForUser([$role]),
                $this->makeEndedAssignedMission($instrumentist),
                [MissionVoter::HOURS_REMIND],
            );
            self::assertSame(VoterInterface::ACCESS_GRANTED, $result, "expected grant for {$role}");
        }
    }

    public function test_manager_cannot_remind_hours_once_real_hours_exist(): void
    {
        $mission = $this->makeEndedAssignedMission($this->makeInstrumentist(21));
        $mission->setExecution((new \App\Entity\MissionExecution())->setActualDurationMinutes(300));

        $result = $this->voter->vote($this->tokenForUser(['ROLE_MANAGER']), $mission, [MissionVoter::HOURS_REMIND]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_manager_cannot_remind_hours_before_mission_end(): void
    {
        $mission = $this->makeAssignedMission(MissionStatus::ASSIGNED, $this->makeInstrumentist(22));
        $mission->setEndAt(new \DateTimeImmutable('+2 hours'));

        $result = $this->voter->vote($this->tokenForUser(['ROLE_MANAGER']), $mission, [MissionVoter::HOURS_REMIND]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_instrumentist_and_surgeon_cannot_remind_hours(): void
    {
        $instrumentist = $this->makeInstrumentist(23);

        $asInstrumentist = $this->voter->vote(
            new UsernamePasswordToken($instrumentist, 'main', ['ROLE_INSTRUMENTIST']),
            $this->makeEndedAssignedMission($instrumentist),
            [MissionVoter::HOURS_REMIND],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $asInstrumentist);

        $asSurgeon = $this->voter->vote(
            $this->tokenForUser(['ROLE_SURGEON']),
            $this->makeEndedAssignedMission($instrumentist),
            [MissionVoter::HOURS_REMIND],
        );
        self::assertSame(VoterInterface::ACCESS_DENIED, $asSurgeon);
    }
}
