<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\PlanningScoreService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlanningScoreServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService $service;

    // Controlled return values for mocked queries
    private array $candidates    = [];
    private mixed $absenceResult = null;
    private mixed $conflictResult = null;
    private int   $historyCount  = 0;
    private int   $totalCount    = 0;
    private int   $typeCount     = 0;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->service = new PlanningScoreService($this->em);

        // The service calls getConnection() for an unused raw SQL query — mock it away
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $this->em->method('getConnection')->willReturn($conn);

        // Route each DQL to the right stub
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('setMaxResults')->willReturnSelf();

                if (str_contains($dql, 'FROM App\Entity\User')) {
                    $q->method('getResult')
                      ->willReturnCallback(fn () => $this->candidates);
                } elseif (str_contains($dql, 'FROM App\Entity\Absence')) {
                    $q->method('getOneOrNullResult')
                      ->willReturnCallback(fn () => $this->absenceResult);
                } elseif (str_contains($dql, 'm.instrumentist = :user')) {
                    // conflict check — uses :user (not :candidate)
                    $q->method('getOneOrNullResult')
                      ->willReturnCallback(fn () => $this->conflictResult);
                } elseif (str_contains($dql, ':candidate') && str_contains($dql, ':surgeon')) {
                    $q->method('getSingleScalarResult')
                      ->willReturnCallback(fn () => $this->historyCount);
                } elseif (str_contains($dql, ':candidate') && str_contains($dql, ':type')) {
                    $q->method('getSingleScalarResult')
                      ->willReturnCallback(fn () => $this->typeCount);
                } else {
                    // total count (no :surgeon, no :type)
                    $q->method('getSingleScalarResult')
                      ->willReturnCallback(fn () => $this->totalCount);
                }

                return $q;
            });
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function makeCandidate(string $email, array $specialties = []): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setSpecialties($specialties);
        return $u;
    }

    private function makeSurgeon(array $specialties = ['GENOU']): User
    {
        $u = new User();
        $u->setEmail('surgeon@test.com');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $u->setSpecialties($specialties);
        return $u;
    }

    private function makeMission(?Hospital $site = null, ?User $surgeon = null, MissionType $type = MissionType::BLOCK): Mission
    {
        $site    ??= new Hospital();
        $surgeon ??= $this->makeSurgeon();

        $m = new Mission();
        $m->setStatus(MissionStatus::OPEN);
        $m->setType($type);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setStartAt(new \DateTimeImmutable('2026-01-05 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-01-05 12:00:00'));
        return $m;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_returns_empty_when_no_candidates(): void
    {
        $this->candidates = [];

        $result = $this->service->suggestForMission($this->makeMission());

        $this->assertSame([], $result);
    }

    public function test_filters_out_absent_candidate(): void
    {
        $this->candidates    = [$this->makeCandidate('inst@test.com', ['GENOU'])];
        $this->absenceResult = new \stdClass(); // non-null → absent

        $result = $this->service->suggestForMission($this->makeMission());

        $this->assertSame([], $result, 'Absent candidate must be excluded');
    }

    public function test_filters_out_candidate_with_conflicting_mission(): void
    {
        $this->candidates     = [$this->makeCandidate('inst@test.com')];
        $this->absenceResult  = null;
        $this->conflictResult = new \stdClass(); // non-null → conflict

        $result = $this->service->suggestForMission($this->makeMission());

        $this->assertSame([], $result, 'Conflicting candidate must be excluded');
    }

    public function test_specialty_match_adds_40_points(): void
    {
        $this->candidates     = [$this->makeCandidate('inst@test.com', ['GENOU'])];
        $this->absenceResult  = null;
        $this->conflictResult = null;
        $this->historyCount   = 0;
        $this->totalCount     = 0;
        $this->typeCount      = 0;

        $surgeon = $this->makeSurgeon(['GENOU']);
        $result  = $this->service->suggestForMission($this->makeMission(surgeon: $surgeon));

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['specialtyMatch']);
        $this->assertSame(40, $result[0]['score']);
    }

    public function test_no_specialty_match_gives_zero_specialty_points(): void
    {
        $this->candidates     = [$this->makeCandidate('inst@test.com', ['RACHIS'])];
        $this->absenceResult  = null;
        $this->conflictResult = null;
        $this->historyCount   = 0;
        $this->totalCount     = 0;
        $this->typeCount      = 0;

        $surgeon = $this->makeSurgeon(['GENOU']);
        $result  = $this->service->suggestForMission($this->makeMission(surgeon: $surgeon));

        $this->assertFalse($result[0]['specialtyMatch']);
        $this->assertSame(0, $result[0]['score']);
    }

    public function test_history_with_surgeon_sets_has_history_true(): void
    {
        $this->candidates     = [$this->makeCandidate('inst@test.com')];
        $this->absenceResult  = null;
        $this->conflictResult = null;
        $this->historyCount   = 3;
        $this->totalCount     = 3;
        $this->typeCount      = 3;

        $result = $this->service->suggestForMission($this->makeMission());

        $this->assertTrue($result[0]['hasHistory']);
        // historyScore = min(3/10, 1.0) * 35 = 10.5 → rounded → contributes to score
        $this->assertGreaterThan(0, $result[0]['score']);
    }

    public function test_sorts_specialty_match_before_no_match(): void
    {
        $withSpecialty    = $this->makeCandidate('spec@test.com',  ['GENOU']);
        $withoutSpecialty = $this->makeCandidate('plain@test.com', ['RACHIS']);

        // Start in wrong order — expect sorting to fix it
        $this->candidates     = [$withoutSpecialty, $withSpecialty];
        $this->absenceResult  = null;
        $this->conflictResult = null;
        $this->historyCount   = 0;
        $this->totalCount     = 0;
        $this->typeCount      = 0;

        $surgeon = $this->makeSurgeon(['GENOU']);
        $result  = $this->service->suggestForMission($this->makeMission(surgeon: $surgeon));

        $this->assertCount(2, $result);
        $this->assertSame('spec@test.com',  $result[0]['email']);
        $this->assertSame('plain@test.com', $result[1]['email']);
    }
}
