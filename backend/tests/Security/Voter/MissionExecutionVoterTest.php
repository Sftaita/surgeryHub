<?php

namespace App\Tests\Security\Voter;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Enum\MissionStatus;
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
        $this->voter = new MissionExecutionVoter(new \App\Service\MissionEncodingGuard());
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

    private function makeMission(?User $instrumentist = null, ?User $surgeon = null, MissionStatus $status = MissionStatus::ENCODING_IN_PROGRESS): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setStartAt(new \DateTimeImmutable('-2 hours'));
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
        $mission = $this->makeMission(instrumentist: $this->makeUser(['ROLE_INSTRUMENTIST'], 60));
        $result = $this->voter->vote($this->tokenFor($this->makeUser(['ROLE_MANAGER'], 6)), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** D-153 — les heures sont celles d'une instrumentiste ; une mission validée se rouvre d'abord. */
    public function test_manager_cannot_update_without_instrumentist_or_on_validated_mission(): void
    {
        $manager = $this->tokenFor($this->makeUser(['ROLE_MANAGER'], 61));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($manager, $this->makeMission(), [MissionExecutionVoter::UPDATE]));
        $validated = $this->makeMission(instrumentist: $this->makeUser(['ROLE_INSTRUMENTIST'], 62), status: MissionStatus::VALIDATED);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($manager, $validated, [MissionExecutionVoter::UPDATE]));
    }

    public function test_assigned_instrumentist_can_update(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 7);
        $mission = $this->makeMission(instrumentist: $instrumentist);
        $result = $this->voter->vote($this->tokenFor($instrumentist), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** D-143 — une fois soumise, l'instrumentiste ne réécrit plus ses heures en silence. */
    public function test_assigned_instrumentist_cannot_update_once_submitted_or_validated(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 21);
        foreach ([MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED, MissionStatus::REJECTED] as $status) {
            $mission = $this->makeMission(instrumentist: $instrumentist, status: $status);
            $result = $this->voter->vote($this->tokenFor($instrumentist), $mission, [MissionExecutionVoter::UPDATE]);
            self::assertSame(VoterInterface::ACCESS_DENIED, $result, $status->value);
        }
    }

    /** D-143 — même règle que l'encodage : pas avant le début de la mission. */
    public function test_assigned_instrumentist_cannot_update_before_mission_start(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 22);
        $mission = $this->makeMission(instrumentist: $instrumentist, status: MissionStatus::ASSIGNED);
        $mission->setStartAt(new \DateTimeImmutable('+3 hours'));
        $result = $this->voter->vote($this->tokenFor($instrumentist), $mission, [MissionExecutionVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /** D-143 — un encodage verrouillé (validé) ou facturé n'est plus modifiable, manager compris. */
    public function test_nobody_updates_hours_of_a_locked_encoding(): void
    {
        $instrumentist = $this->makeUser(['ROLE_INSTRUMENTIST'], 23);
        $mission = $this->makeMission(instrumentist: $instrumentist, status: MissionStatus::VALIDATED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($this->makeUser(['ROLE_MANAGER'], 24)), $mission, [MissionExecutionVoter::UPDATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($instrumentist), $mission, [MissionExecutionVoter::UPDATE]));
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
