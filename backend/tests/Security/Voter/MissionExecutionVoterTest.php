<?php

namespace App\Tests\Security\Voter;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Security\Voter\MissionExecutionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** EPIC Exécution & Valorisation, Lot 1 — renommage de ServiceVoterTest, permissions inchangées. */
final class MissionExecutionVoterTest extends TestCase
{
    private MissionExecutionVoter $voter;
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->voter = new MissionExecutionVoter();
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeUser(array $roles, int $id): User
    {
        $u = new User();
        $u->setEmail("user{$id}@test.com");
        $u->setRoles($roles);
        $this->setId($u, $id);
        return $u;
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function makeMission(?User $instrumentist = null, ?User $surgeon = null): Mission
    {
        $m = new Mission();
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        if ($surgeon !== null) {
            $m->setSurgeon($surgeon);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    // ── VIEW ─────────────────────────────────────────────────────────────────

    public function test_manager_can_view(): void
    {
        $mission = $this->makeMission();
        $result = $this->voter->vote($this->tokenFor($this->makeUser(['ROLE_MANAGER'], 1)), $mission, [MissionExecutionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_assigned_instrumentist_can_view(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission(instrumentist: $instrumentist);
        $result = $this->voter->vote($this->tokenFor($instrumentist), $mission, [MissionExecutionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_mission_surgeon_can_view(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 3);
        $mission = $this->makeMission(surgeon: $surgeon);
        $result = $this->voter->vote($this->tokenFor($surgeon), $mission, [MissionExecutionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_unrelated_instrumentist_cannot_view(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 4);
        $stranger = $this->makeUser(['ROLE_INSTRUMENTIST'], 5);
        $mission = $this->makeMission(instrumentist: $instrumentist);
        $result = $this->voter->vote($this->tokenFor($stranger), $mission, [MissionExecutionVoter::VIEW]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── UPDATE ───────────────────────────────────────────────────────────────

    public function test_manager_can_update(): void
    {
        $mission = $this->makeMission();
        $result = $this->voter->vote($this->tokenFor($this->makeUser(['ROLE_MANAGER'], 6)), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_assigned_instrumentist_can_update(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 7);
        $mission = $this->makeMission(instrumentist: $instrumentist);
        $result = $this->voter->vote($this->tokenFor($instrumentist), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_surgeon_cannot_update(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 8);
        $mission = $this->makeMission(surgeon: $surgeon);
        $result = $this->voter->vote($this->tokenFor($surgeon), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_unrelated_instrumentist_cannot_update(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 9);
        $stranger = $this->makeUser(['ROLE_INSTRUMENTIST'], 10);
        $mission = $this->makeMission(instrumentist: $instrumentist);
        $result = $this->voter->vote($this->tokenFor($stranger), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── DISPUTE_CREATE ───────────────────────────────────────────────────────

    public function test_mission_surgeon_can_open_dispute(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 11);
        $mission = $this->makeMission(surgeon: $surgeon);
        $execution = new MissionExecution();
        $execution->setMission($mission);

        $result = $this->voter->vote($this->tokenFor($surgeon), $execution, [MissionExecutionVoter::DISPUTE_CREATE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_other_surgeon_cannot_open_dispute(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 12);
        $otherSurgeon = $this->makeUser(['ROLE_SURGEON'], 13);
        $mission = $this->makeMission(surgeon: $surgeon);
        $execution = new MissionExecution();
        $execution->setMission($mission);

        $result = $this->voter->vote($this->tokenFor($otherSurgeon), $execution, [MissionExecutionVoter::DISPUTE_CREATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_manager_cannot_open_dispute(): void
    {
        // Ouvrir une contestation reste réservé au chirurgien concerné — le manager
        // consulte/traite mais n'ouvre pas (permissions inchangées de ServiceVoter).
        $manager = $this->makeUser(['ROLE_MANAGER'], 14);
        $mission = $this->makeMission();
        $execution = new MissionExecution();
        $execution->setMission($mission);

        $result = $this->voter->vote($this->tokenFor($manager), $execution, [MissionExecutionVoter::DISPUTE_CREATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── DISPUTE_MANAGE ───────────────────────────────────────────────────────

    public function test_manager_can_manage_dispute(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER'], 15);
        $result = $this->voter->vote($this->tokenFor($manager), new MissionExecutionDispute(), [MissionExecutionVoter::DISPUTE_MANAGE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_instrumentist_cannot_manage_dispute(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 16);
        $result = $this->voter->vote($this->tokenFor($instrumentist), new MissionExecutionDispute(), [MissionExecutionVoter::DISPUTE_MANAGE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ── Abstain ──────────────────────────────────────────────────────────────

    public function test_abstains_on_unknown_attribute(): void
    {
        $result = $this->voter->vote($this->tokenFor($this->makeUser(['ROLE_MANAGER'], 17)), $this->makeMission(), ['SOME_UNKNOWN']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
