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
}
