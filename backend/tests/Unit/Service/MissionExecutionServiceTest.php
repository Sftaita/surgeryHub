<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\DisputeReasonCode;
use App\Enum\DisputeStatus;
use App\Enum\EffectiveDurationSource;
use App\Enum\HoursSource;
use App\Service\AuditService;
use App\Service\MissionExecutionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 1 — MissionExecutionService couvre le RÉALISÉ
 * uniquement : aucun montant, aucun tarif, aucune règle financière n'est exercé ici.
 */
final class MissionExecutionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditService&MockObject           $audit;
    private MissionExecutionService           $service;

    /** @var AuditEventType[] captured via the audit stub below — see recordedEventTypes(). */
    private array $recordedEvents = [];

    protected function setUp(): void
    {
        $this->em    = $this->createMock(EntityManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->recordedEvents = [];

        $this->audit->method('record')->willReturnCallback(
            function (Mission $mission, User $actor, AuditEventType $type, array $payload = []): void {
                $this->recordedEvents[] = $type;
            },
        );

        $this->service = new MissionExecutionService($this->em, $this->audit);
    }

    /** @return AuditEventType[] */
    private function recordedEventTypes(): array
    {
        return $this->recordedEvents;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeActor(array $roles = ['ROLE_MANAGER']): User
    {
        $u = new User();
        $u->setEmail('actor@test.com');
        $u->setFirstname('Ada');
        $u->setLastname('Lovelace');
        $u->setRoles($roles);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(?\DateTimeImmutable $startAt = null, ?\DateTimeImmutable $endAt = null): Mission
    {
        $m = new Mission();
        $m->setStartAt($startAt ?? new \DateTimeImmutable('2026-07-18 08:00:00'));
        $m->setEndAt($endAt ?? new \DateTimeImmutable('2026-07-18 10:00:00'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    // ── resolveEffectiveDuration() — §3.1 ───────────────────────────────────────

    public function test_resolve_effective_duration_falls_back_to_planned_when_no_execution(): void
    {
        $mission = $this->makeMission(new \DateTimeImmutable('2026-07-18 08:00:00'), new \DateTimeImmutable('2026-07-18 10:00:00'));

        $result = $this->service->resolveEffectiveDuration($mission);

        self::assertSame(120, $result->minutes);
        self::assertSame(EffectiveDurationSource::PLANNED, $result->source);
    }

    public function test_resolve_effective_duration_uses_actual_times_when_both_present(): void
    {
        $mission = $this->makeMission();
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualStartAt(new \DateTimeImmutable('2026-07-18 08:10:00'));
        $execution->setActualEndAt(new \DateTimeImmutable('2026-07-18 10:25:00'));
        $mission->setExecution($execution);

        $result = $this->service->resolveEffectiveDuration($mission);

        self::assertSame(135, $result->minutes);
        self::assertSame(EffectiveDurationSource::ACTUAL_TIMES, $result->source);
    }

    public function test_resolve_effective_duration_uses_explicit_duration_without_actual_times(): void
    {
        $mission = $this->makeMission();
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualDurationMinutes(90);
        $mission->setExecution($execution);

        $result = $this->service->resolveEffectiveDuration($mission);

        self::assertSame(90, $result->minutes);
        self::assertSame(EffectiveDurationSource::ACTUAL_EXPLICIT, $result->source);
    }

    public function test_resolve_effective_duration_falls_back_to_planned_when_execution_has_no_data(): void
    {
        $mission = $this->makeMission(new \DateTimeImmutable('2026-07-18 08:00:00'), new \DateTimeImmutable('2026-07-18 09:30:00'));
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $mission->setExecution($execution);

        $result = $this->service->resolveEffectiveDuration($mission);

        self::assertSame(90, $result->minutes);
        self::assertSame(EffectiveDurationSource::PLANNED, $result->source);
    }

    // ── findOrCreateExecution() — création paresseuse ───────────────────────────

    public function test_find_or_create_execution_creates_when_absent(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $execution = $this->service->findOrCreateExecution($mission, $actor);

        self::assertSame($mission, $execution->getMission());
        self::assertSame($execution, $mission->getExecution());
        self::assertSame([AuditEventType::MISSION_EXECUTION_CREATED], $this->recordedEventTypes());
    }

    public function test_find_or_create_execution_returns_existing_without_creating(): void
    {
        $mission = $this->makeMission();
        $existing = new MissionExecution();
        $existing->setMission($mission);
        $mission->setExecution($existing);
        $actor = $this->makeActor();

        $this->em->expects($this->never())->method('persist');

        $result = $this->service->findOrCreateExecution($mission, $actor);

        self::assertSame($existing, $result);
    }

    // ── updateActuals() — §3.2 règles de cohérence ──────────────────────────────

    public function test_update_actuals_with_explicit_duration_only(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        $execution = $this->service->updateActuals($mission, $actor, null, null, 75, null);

        self::assertSame(75, $execution->getActualDurationMinutes());
        self::assertNull($execution->getActualStartAt());
        self::assertNull($execution->getActualEndAt());
        // Pas d'exécution préexistante : findOrCreateExecution() émet CREATED avant que
        // updateActuals() n'émette DURATION_CHANGED — deux faits distincts, pas un doublon.
        self::assertSame(
            [AuditEventType::MISSION_EXECUTION_CREATED, AuditEventType::MISSION_EXECUTION_DURATION_CHANGED],
            $this->recordedEventTypes(),
        );
    }

    public function test_update_actuals_derives_duration_from_actual_times(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();
        $start = new \DateTimeImmutable('2026-07-18 08:00:00');
        $end = new \DateTimeImmutable('2026-07-18 09:45:00');

        $execution = $this->service->updateActuals($mission, $actor, $start, $end, null, null);

        self::assertSame(105, $execution->getActualDurationMinutes());
    }

    public function test_update_actuals_accepts_explicit_duration_consistent_with_actual_times(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();
        $start = new \DateTimeImmutable('2026-07-18 08:00:00');
        $end = new \DateTimeImmutable('2026-07-18 09:00:00');

        $execution = $this->service->updateActuals($mission, $actor, $start, $end, 60, null);

        self::assertSame(60, $execution->getActualDurationMinutes());
    }

    public function test_update_actuals_rejects_contradictory_explicit_duration(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();
        $start = new \DateTimeImmutable('2026-07-18 08:00:00');
        $end = new \DateTimeImmutable('2026-07-18 09:00:00');

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->service->updateActuals($mission, $actor, $start, $end, 45, null);
    }

    public function test_update_actuals_rejects_single_actual_time_without_the_other(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->service->updateActuals($mission, $actor, new \DateTimeImmutable('2026-07-18 08:00:00'), null, null, null);
    }

    public function test_update_actuals_rejects_end_before_start(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->service->updateActuals(
            $mission,
            $actor,
            new \DateTimeImmutable('2026-07-18 10:00:00'),
            new \DateTimeImmutable('2026-07-18 09:00:00'),
            null,
            null,
        );
    }

    public function test_update_actuals_hours_source_only_emits_updated_not_duration_changed(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        $execution = $this->service->updateActuals($mission, $actor, null, null, null, HoursSource::MANAGER);

        self::assertSame(HoursSource::MANAGER, $execution->getHoursSource());
        self::assertSame(
            [AuditEventType::MISSION_EXECUTION_CREATED, AuditEventType::MISSION_EXECUTION_UPDATED],
            $this->recordedEventTypes(),
        );
    }

    public function test_update_actuals_is_idempotent_on_identical_repeated_call(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        $this->service->updateActuals($mission, $actor, null, null, 60, HoursSource::MANAGER);
        $eventsAfterFirstCall = $this->recordedEventTypes();

        // Deuxième appel, valeurs strictement identiques : aucun changement détecté,
        // donc aucun événement supplémentaire par rapport au premier appel.
        $this->service->updateActuals($mission, $actor, null, null, 60, HoursSource::MANAGER);

        self::assertSame($eventsAfterFirstCall, $this->recordedEventTypes());
    }

    public function test_update_actuals_merges_with_previously_stored_values(): void
    {
        $mission = $this->makeMission();
        $actor = $this->makeActor();

        // Un premier appel ne fixe que le début réel doit échouer (règle "ensemble").
        // On construit donc l'état existant directement pour tester la fusion PATCH :
        // actualStartAt déjà stocké, actualEndAt fourni dans cet appel seulement.
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualStartAt(new \DateTimeImmutable('2026-07-18 08:00:00'));
        $mission->setExecution($execution);

        $result = $this->service->updateActuals($mission, $actor, null, new \DateTimeImmutable('2026-07-18 09:15:00'), null, null);

        self::assertSame(75, $result->getActualDurationMinutes());
    }

    // ── Disputes — §6 ────────────────────────────────────────────────────────

    public function test_open_dispute_succeeds_when_none_open(): void
    {
        $mission = $this->makeMission();
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $surgeon = $this->makeActor(['ROLE_SURGEON']);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->with(MissionExecutionDispute::class)->willReturn($repo);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $dispute = $this->service->openDispute($mission, $execution, $surgeon, DisputeReasonCode::DURATION_INCOHERENT, 'Trop long');

        self::assertSame($execution, $dispute->getMissionExecution());
        self::assertCount(1, $execution->getDisputes());
        self::assertSame([AuditEventType::MISSION_EXECUTION_DISPUTE_OPENED], $this->recordedEventTypes());
    }

    public function test_open_dispute_rejects_when_one_already_open(): void
    {
        $mission = $this->makeMission();
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $surgeon = $this->makeActor(['ROLE_SURGEON']);

        $existingOpen = new MissionExecutionDispute();
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existingOpen);
        $this->em->method('getRepository')->with(MissionExecutionDispute::class)->willReturn($repo);

        $this->em->expects($this->never())->method('persist');
        $this->expectException(BadRequestHttpException::class);

        $this->service->openDispute($mission, $execution, $surgeon, DisputeReasonCode::WRONG_DATE, null);
    }

    public function test_update_dispute_resolved_emits_dispute_resolved(): void
    {
        $mission = $this->makeMission();
        $dispute = new MissionExecutionDispute();
        $dispute->setMission($mission);
        $manager = $this->makeActor(['ROLE_MANAGER']);

        $result = $this->service->updateDispute($dispute, $manager, DisputeStatus::RESOLVED, 'Corrigé');

        self::assertSame(DisputeStatus::RESOLVED, $result->getStatus());
        self::assertSame('Corrigé', $result->getResolutionComment());
        self::assertSame([AuditEventType::MISSION_EXECUTION_DISPUTE_RESOLVED], $this->recordedEventTypes());
    }

    public function test_update_dispute_rejected_emits_dispute_rejected(): void
    {
        $mission = $this->makeMission();
        $dispute = new MissionExecutionDispute();
        $dispute->setMission($mission);
        $manager = $this->makeActor(['ROLE_MANAGER']);

        $result = $this->service->updateDispute($dispute, $manager, DisputeStatus::REJECTED, 'Non fondé');

        self::assertSame(DisputeStatus::REJECTED, $result->getStatus());
        self::assertSame([AuditEventType::MISSION_EXECUTION_DISPUTE_REJECTED], $this->recordedEventTypes());
    }

    public function test_update_dispute_in_review_emits_no_specific_audit_event(): void
    {
        $mission = $this->makeMission();
        $dispute = new MissionExecutionDispute();
        $dispute->setMission($mission);
        $manager = $this->makeActor(['ROLE_MANAGER']);

        $result = $this->service->updateDispute($dispute, $manager, DisputeStatus::IN_REVIEW, null);

        self::assertSame(DisputeStatus::IN_REVIEW, $result->getStatus());
        self::assertSame([], $this->recordedEventTypes());
    }
}
