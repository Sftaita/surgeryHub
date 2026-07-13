<?php

namespace App\Tests\Unit\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\MissionLifecycleChangedMessage;
use App\Repository\UserRepository;
use App\Service\AbsenceMissionReactionService;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit-level coverage for AbsenceMissionReactionService — the core decision logic (which
 * missions are actionable, what gets dispatched, role detection, empty-result no-op). Real
 * persistence/idempotency/concurrency proof lives in the functional test
 * (AbsenceMissionReactionFunctionalTest) against a real database — a fully mocked
 * EntityManager cannot prove a query-based idempotency guarantee.
 *
 * MissionPostDeployService itself is mocked here (already covered by its own
 * MissionPostDeployServiceTest) — this suite only verifies AbsenceMissionReactionService
 * calls it correctly (right mission, right actor, right reason, notify:false) and correctly
 * reconstructs the MissionLifecycleChangedMessage it dispatches afterward.
 */
final class AbsenceMissionReactionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MissionPostDeployService&MockObject $missionPostDeployService;
    private MessageBusInterface&MockObject $bus;
    private UserRepository&MockObject $userRepository;
    private AbsenceMissionReactionService $service;

    private array $dispatched = [];
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->missionPostDeployService = $this->createMock(MissionPostDeployService::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->dispatched = [];
        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });

        $this->em->method('wrapInTransaction')->willReturnCallback(function (callable $fn) {
            return $fn();
        });
        $this->em->method('lock');

        $this->service = new AbsenceMissionReactionService(
            $this->em, $this->missionPostDeployService, $this->bus, $this->userRepository,
        );
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeUser(array $roles, string $firstname = 'Test', string $lastname = 'User'): User
    {
        $u = new User();
        $u->setEmail(strtolower($firstname) . '@test.com');
        $u->setRoles($roles);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Site Test');
        $this->setId($h, self::$nextId++);
        return $h;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($this->makeSite());
        $m->setStartAt(new \DateTimeImmutable('2026-09-10 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-10 13:00:00'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function makeAbsence(User $user, string $start = '2026-09-10', string $end = '2026-09-10'): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($start));
        $a->setDateEnd(new \DateTimeImmutable($end));
        $a->setCreatedBy($user);
        $this->setId($a, self::$nextId++);
        return $a;
    }

    /** @param Mission[] $missions */
    private function stubOverlapQuery(array $missions): void
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn($missions);
        $this->em->method('createQuery')->willReturn($query);
    }

    private function actor(): User
    {
        return $this->makeUser(['ROLE_MANAGER'], 'Manager', 'Actor');
    }

    // ── Instrumentist absence ────────────────────────────────────────────────

    public function test_instrumentist_absence_over_assigned_mission_calls_release_with_reason_and_notify_false(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $absence = $this->makeAbsence($instr);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->missionPostDeployService->expects($this->once())
            ->method('release')
            ->with(
                $this->identicalTo($mission),
                $this->identicalTo($actor),
                notify: false,
                reason: $this->stringContains('Absence instrumentiste'),
            );

        $this->service->onAbsenceCreated($absence, $actor);
    }

    public function test_instrumentist_absence_dispatches_released_lifecycle_message_after_processing(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $absence = $this->makeAbsence($instr);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->service->onAbsenceCreated($absence, $actor);

        $lifecycleMessages = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof MissionLifecycleChangedMessage));
        self::assertCount(1, $lifecycleMessages);
        self::assertSame(MissionChangeType::RELEASED, $lifecycleMessages[0]->changeType);
        self::assertSame($mission->getId(), $lifecycleMessages[0]->missionId);
        self::assertSame($instr->getId(), $lifecycleMessages[0]->payload['fromInstrumentistId']);
    }

    public function test_instrumentist_absence_dispatches_one_absence_reacted_message_with_all_missions(): void
    {
        $instr    = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon1 = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $surgeon2 = $this->makeUser(['ROLE_SURGEON'], 'Alice', 'Martin');
        $mission1 = $this->makeMission($surgeon1, $instr, MissionStatus::ASSIGNED);
        $mission2 = $this->makeMission($surgeon2, $instr, MissionStatus::ASSIGNED);
        $absence  = $this->makeAbsence($instr, '2026-09-10', '2026-09-12');
        $actor    = $this->actor();

        $this->stubOverlapQuery([$mission1, $mission2]);

        $this->service->onAbsenceCreated($absence, $actor);

        $reacted = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof AbsenceMissionsReactedMessage));
        self::assertCount(1, $reacted, 'Exactly one AbsenceMissionsReactedMessage per processing run, never one per mission');
        self::assertSame('INSTRUMENTIST', $reacted[0]->absentUserRole);
        self::assertCount(2, $reacted[0]->missions);
        self::assertSame('RELEASED', $reacted[0]->missions[0]['changeType']);
    }

    public function test_instrumentist_absence_over_open_mission_does_nothing(): void
    {
        // OPEN missions never have an instrumistent set, so the overlap query (which
        // filters on m.instrumentist = :user) would never return one in practice — this
        // confirms the service does not crash/misbehave if it somehow received one anyway.
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $absence = $this->makeAbsence($instr);
        $actor   = $this->actor();

        $this->stubOverlapQuery([]);

        $this->missionPostDeployService->expects($this->never())->method('release');
        $this->bus->expects($this->never())->method('dispatch');

        $this->service->onAbsenceCreated($absence, $actor);
    }

    // ── Surgeon absence ───────────────────────────────────────────────────────

    public function test_surgeon_absence_over_assigned_mission_calls_cancel_with_reason(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $absence = $this->makeAbsence($surgeon);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->missionPostDeployService->expects($this->once())
            ->method('cancel')
            ->with(
                $this->identicalTo($mission),
                $this->identicalTo($actor),
                $this->stringContains('Absence chirurgien'),
                notify: false,
            );

        $this->service->onAbsenceCreated($absence, $actor);
    }

    public function test_surgeon_absence_over_open_mission_calls_cancel(): void
    {
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        $absence = $this->makeAbsence($surgeon);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->missionPostDeployService->expects($this->once())->method('cancel');

        $this->service->onAbsenceCreated($absence, $actor);
    }

    public function test_surgeon_absence_dispatches_cancelled_lifecycle_message(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $absence = $this->makeAbsence($surgeon);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->service->onAbsenceCreated($absence, $actor);

        $lifecycleMessages = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof MissionLifecycleChangedMessage));
        self::assertCount(1, $lifecycleMessages);
        self::assertSame(MissionChangeType::CANCELLED, $lifecycleMessages[0]->changeType);
        self::assertSame($instr->getId(), $lifecycleMessages[0]->payload['fromInstrumentistId'], 'Removed instrumentist must be captured before cancel() clears it');
    }

    public function test_surgeon_absence_reacted_message_captures_instrumentist_before_it_is_cleared(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $absence = $this->makeAbsence($surgeon);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->service->onAbsenceCreated($absence, $actor);

        $reacted = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof AbsenceMissionsReactedMessage));
        self::assertCount(1, $reacted);
        self::assertSame('SURGEON', $reacted[0]->absentUserRole);
        self::assertSame($instr->getId(), $reacted[0]->missions[0]['instrumentistId']);
        self::assertSame('CANCELLED', $reacted[0]->missions[0]['changeType']);
    }

    // ── Role detection / no-op cases ──────────────────────────────────────────

    public function test_absence_for_a_manager_does_nothing(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER'], 'Marc', 'Manager');
        $absence = $this->makeAbsence($manager);
        $actor   = $this->actor();

        $this->em->expects($this->never())->method('createQuery');
        $this->missionPostDeployService->expects($this->never())->method('release');
        $this->missionPostDeployService->expects($this->never())->method('cancel');

        $this->service->onAbsenceCreated($absence, $actor);
    }

    public function test_onAbsenceUpdated_uses_the_same_reaction_logic_as_created(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $surgeon = $this->makeUser(['ROLE_SURGEON'], 'Jean', 'Dupont');
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $absence = $this->makeAbsence($instr);
        $actor   = $this->actor();

        $this->stubOverlapQuery([$mission]);

        $this->missionPostDeployService->expects($this->once())->method('release');

        $this->service->onAbsenceUpdated($absence, $actor);
    }

    // ── onAbsenceDeleted: manager notification, never a mission mutation ─────

    public function test_onAbsenceDeleted_never_touches_missions(): void
    {
        $instr   = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $absence = $this->makeAbsence($instr);
        $actor   = $this->actor();

        $this->userRepository->method('findManagersAndAdmins')->willReturn([]);

        $this->missionPostDeployService->expects($this->never())->method('release');
        $this->missionPostDeployService->expects($this->never())->method('cancel');
        $this->em->expects($this->never())->method('createQuery');

        $this->service->onAbsenceDeleted($absence, $actor);
    }

    public function test_onAbsenceDeleted_notifies_every_manager_and_admin(): void
    {
        $instr    = $this->makeUser(['ROLE_INSTRUMENTIST'], 'Ole', 'Salve');
        $absence  = $this->makeAbsence($instr);
        $actor    = $this->actor();
        $manager1 = $this->makeUser(['ROLE_MANAGER'], 'Marc', 'Un');
        $manager2 = $this->makeUser(['ROLE_ADMIN'], 'Alix', 'Deux');

        $this->userRepository->method('findManagersAndAdmins')->willReturn([$manager1, $manager2]);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $this->em->expects($this->once())->method('flush');

        $this->service->onAbsenceDeleted($absence, $actor);

        self::assertCount(2, $persisted);
    }

    public function test_onAbsenceDeleted_for_non_surgeon_non_instrumentist_does_nothing(): void
    {
        $manager = $this->makeUser(['ROLE_MANAGER'], 'Marc', 'Manager');
        $absence = $this->makeAbsence($manager);
        $actor   = $this->actor();

        $this->userRepository->expects($this->never())->method('findManagersAndAdmins');
        $this->em->expects($this->never())->method('flush');

        $this->service->onAbsenceDeleted($absence, $actor);
    }
}
