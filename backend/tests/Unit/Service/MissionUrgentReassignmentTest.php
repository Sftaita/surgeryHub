<?php

namespace App\Tests\Unit\Service;

use App\Dto\EligibilityResult;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EligibilityReason;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\InstrumentistIneligibleException;
use App\Exception\MissionReassignmentException;
use App\Service\AuditService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\MissionReassignmentPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-181 — urgent reassignment of a published mission: missions of the day / already started,
 * relay of a started mission, refusals of SUBMITTED / VALIDATED / CLOSED, concurrency guard.
 */
final class MissionUrgentReassignmentTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject $bus;
    private AuditService&MockObject $audit;
    private MissionEligibilityService&MockObject $eligibility;
    /** @var list<array{0: AuditEventType, 1: array<string,mixed>, 2: Mission}> */
    private array $audits = [];
    /** @var list<object> */
    private array $dispatched = [];
    private static int $nextId = 1000;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(function (object $m) { $this->dispatched[] = $m; return new Envelope($m); });
        $this->audit = $this->createMock(AuditService::class);
        $this->audit->method('record')->willReturnCallback(function (Mission $m, $actor, AuditEventType $type, array $payload) {
            $this->audits[] = [$type, $payload, $m];
        });
        $this->eligibility = $this->createMock(MissionEligibilityService::class);
        $this->eligibility->method('evaluateForReassignment')->willReturnCallback(fn (Mission $m, User $u) => new EligibilityResult($u, []));
    }

    private function service(): MissionPostDeployService
    {
        return new MissionPostDeployService($this->em, $this->bus, $this->audit, $this->eligibility);
    }

    private function user(string $first, string $last): User
    {
        $u = (new User())->setEmail(strtolower($first) . '@test.local')->setFirstname($first)->setLastname($last);
        $this->setId($u, self::$nextId++);

        return $u;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function mission(MissionStatus $status, ?User $instrumentist, string $start = '2026-11-03 08:00', string $end = '2026-11-03 13:00'): Mission
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = (new Mission())
            ->setSite((new Hospital())->setName('CHU Test'))
            ->setSurgeon($this->user('Paul', 'Chirurgien'))
            ->setStatus($status)
            ->setType(MissionType::BLOCK)
            ->setStartAt(new \DateTimeImmutable($start, $tz))
            ->setEndAt(new \DateTimeImmutable($end, $tz));
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);

        return $m;
    }

    private function withOwnHours(Mission $m): Mission
    {
        $execution = (new MissionExecution())->setActualStartAt($m->getStartAt())->setActualDurationMinutes(90);
        $m->setExecution($execution);

        return $m;
    }

    // ── Policy ────────────────────────────────────────────────────────────────

    public function test_policy_future_mission_is_direct_only(): void
    {
        $m = $this->mission(MissionStatus::ASSIGNED, $this->user('Ole', 'Salve'));

        self::assertSame(['DIRECT'], MissionReassignmentPolicy::modes($m, new \DateTimeImmutable('2026-11-02 18:00', new \DateTimeZone('Europe/Brussels'))));
    }

    public function test_policy_started_mission_without_encoding_offers_direct_then_relay(): void
    {
        $m = $this->mission(MissionStatus::IN_PROGRESS, $this->user('Ole', 'Salve'));

        self::assertSame(['DIRECT', 'RELAY'], MissionReassignmentPolicy::modes($m, new \DateTimeImmutable('2026-11-03 09:30', new \DateTimeZone('Europe/Brussels'))));
    }

    public function test_policy_mission_with_hours_offers_relay_only(): void
    {
        $m = $this->withOwnHours($this->mission(MissionStatus::ENCODING_IN_PROGRESS, $this->user('Ole', 'Salve')));

        self::assertSame(['RELAY'], MissionReassignmentPolicy::modes($m, new \DateTimeImmutable('2026-11-03 10:00', new \DateTimeZone('Europe/Brussels'))));
    }

    #[DataProvider('lockedStatuses')]
    public function test_policy_locked_statuses_are_explained(MissionStatus $status, string $code, string $message): void
    {
        $m = $this->mission($status, $this->user('Ole', 'Salve'));

        self::assertSame([], MissionReassignmentPolicy::modes($m, new \DateTimeImmutable('2026-11-03 10:00')));
        self::assertSame($code, MissionReassignmentPolicy::blockedReason($m)['code']);
        // Formulation neutre, propre à chaque statut.
        self::assertStringStartsWith($message, MissionReassignmentPolicy::blockedReason($m)['message']);
    }

    public static function lockedStatuses(): array
    {
        return [
            'submitted' => [MissionStatus::SUBMITTED, MissionReassignmentPolicy::ENCODING_SUBMITTED, "Le changement d'instrumentiste n'est plus autorisé pour une mission soumise."],
            'validated' => [MissionStatus::VALIDATED, MissionReassignmentPolicy::ENCODING_VALIDATED, "Le changement d'instrumentiste n'est plus autorisé pour une mission validée."],
            'closed'    => [MissionStatus::CLOSED, MissionReassignmentPolicy::MISSION_CLOSED, 'Cette mission est clôturée'],
        ];
    }

    // ── reassign(): same day, started, protected statuses ─────────────────────

    public function test_reassign_mission_already_started_without_encoding_keeps_its_status(): void
    {
        $from = $this->user('Ole', 'Salve');
        $to = $this->user('Jean', 'Martin');
        $m = $this->mission(MissionStatus::IN_PROGRESS, $from);
        $this->em->method('find')->willReturn($to);

        $payload = $this->service()->reassign($m, $this->user('Mia', 'Manager'), (int) $to->getId(), notify: false, internalComment: 'Ole malade');

        self::assertSame($to, $m->getInstrumentist());
        self::assertSame(MissionStatus::IN_PROGRESS, $m->getStatus());
        self::assertArrayNotHasKey('internalComment', $payload, 'never in the payload handed to notifications');
        [$type, $auditPayload] = $this->audits[0];
        self::assertSame(AuditEventType::MISSION_REASSIGNED_POST_DEPLOY, $type);
        self::assertSame('Ole malade', $auditPayload['internalComment']);
        self::assertSame((int) $from->getId(), $auditPayload['fromInstrumentistId']);
        self::assertSame([], $this->dispatched, 'notify: false — the operation notifies');
    }

    public function test_reassign_never_overwrites_hours_already_encoded(): void
    {
        $from = $this->user('Ole', 'Salve');
        $to = $this->user('Jean', 'Martin');
        $m = $this->withOwnHours($this->mission(MissionStatus::ENCODING_IN_PROGRESS, $from));
        $this->em->method('find')->willReturn($to);

        try {
            $this->service()->reassign($m, $this->user('Mia', 'Manager'), (int) $to->getId(), notify: false);
            self::fail('Expected MISSION_HAS_ENCODING_DATA');
        } catch (\App\Exception\PrestationConflictException $e) {
            self::assertSame('MISSION_HAS_ENCODING_DATA', $e->getErrorCode());
        }
        self::assertSame($from, $m->getInstrumentist());
        self::assertSame(90, $m->getExecution()->getActualDurationMinutes());
    }

    #[DataProvider('lockedStatuses')]
    public function test_reassign_refuses_submitted_validated_and_closed(MissionStatus $status, string $code): void
    {
        $from = $this->user('Ole', 'Salve');
        $m = $this->mission($status, $from);
        $this->em->expects(self::never())->method('flush');

        try {
            $this->service()->reassign($m, $this->user('Mia', 'Manager'), 4242, notify: false);
            self::fail('Expected a refusal');
        } catch (MissionReassignmentException $e) {
            self::assertSame($code, $e->getErrorCode());
        }
        self::assertSame($from, $m->getInstrumentist());
    }

    public function test_reassign_refuses_when_someone_else_changed_the_mission_meanwhile(): void
    {
        $other = $this->user('Ana', 'Autre');
        $m = $this->mission(MissionStatus::ASSIGNED, $other);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(MissionReassignmentException::class);
        $this->expectExceptionMessage("vient d'être modifiée");

        $this->service()->reassign($m, $this->user('Mia', 'Manager'), 4242, notify: false, expectedInstrumentistId: 999999);
    }

    public function test_reassign_to_the_same_instrumentist_is_not_a_change(): void
    {
        $from = $this->user('Ole', 'Salve');
        $m = $this->mission(MissionStatus::ASSIGNED, $from);

        try {
            $this->service()->reassign($m, $this->user('Mia', 'Manager'), (int) $from->getId(), notify: false);
            self::fail('Expected NO_CHANGE');
        } catch (MissionReassignmentException $e) {
            self::assertSame(MissionReassignmentPolicy::NO_CHANGE, $e->getErrorCode());
        }
        self::assertSame([], $this->audits);
    }

    // ── relay() ───────────────────────────────────────────────────────────────

    public function test_relay_keeps_the_first_part_and_its_encoding_and_creates_a_linked_mission(): void
    {
        $from = $this->user('Ole', 'Salve');
        $to = $this->user('Jean', 'Martin');
        $m = $this->withOwnHours($this->mission(MissionStatus::ENCODING_IN_PROGRESS, $from));
        $m->setRoomLabel('Salle 3');
        $this->em->method('find')->willReturn($to);
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function (object $e) use (&$persisted) {
            if ($e instanceof Mission) {
                $this->setId($e, self::$nextId++);
            }
            $persisted[] = $e;
        });

        $relayAt = new \DateTimeImmutable('2026-11-03 10:30', new \DateTimeZone('Europe/Brussels'));
        $result = $this->service()->relay($m, $this->user('Mia', 'Manager'), (int) $to->getId(), $relayAt, (int) $from->getId(), 'Relais midi');

        $relay = $result['relayMission'];
        // Original: same instrumentist, status and hours — only its planned end moved.
        self::assertSame($from, $m->getInstrumentist());
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $m->getStatus());
        self::assertSame(90, $m->getExecution()->getActualDurationMinutes());
        self::assertSame('10:30', $m->getEndAt()->format('H:i'));
        // Relay: the rest of the slot, with the replacement, linked to the original.
        self::assertSame($m, $relay->getRelayOf());
        self::assertSame($to, $relay->getInstrumentist());
        self::assertSame(MissionStatus::ASSIGNED, $relay->getStatus());
        self::assertSame('10:30', $relay->getStartAt()->format('H:i'));
        self::assertSame('13:00', $relay->getEndAt()->format('H:i'));
        self::assertSame('Salle 3', $relay->getRoomLabel());
        self::assertNull($relay->getExecution(), 'nothing of the first instrumentist is carried over');
        // Audited on both missions, before any dispatch, with the internal comment.
        $relayed = array_values(array_filter($this->audits, static fn ($a) => $a[0] === AuditEventType::MISSION_RELAYED));
        self::assertCount(2, $relayed);
        self::assertSame(['ORIGINAL', 'RELAY'], array_map(static fn ($a) => $a[1]['role'], $relayed));
        self::assertSame('Relais midi', $relayed[0][1]['internalComment']);
        self::assertArrayNotHasKey('internalComment', $result['payload']);
        self::assertSame([], $this->dispatched);
    }

    public function test_relay_time_must_fall_strictly_inside_the_mission(): void
    {
        $m = $this->mission(MissionStatus::IN_PROGRESS, $this->user('Ole', 'Salve'));
        $this->em->expects(self::never())->method('flush');

        try {
            $this->service()->relay($m, $this->user('Mia', 'Manager'), 4242, new \DateTimeImmutable('2026-11-03 13:00', new \DateTimeZone('Europe/Brussels')));
            self::fail('Expected RELAY_TIME_INVALID');
        } catch (MissionReassignmentException $e) {
            self::assertSame(MissionReassignmentPolicy::RELAY_TIME_INVALID, $e->getErrorCode());
        }
        self::assertSame('13:00', $m->getEndAt()->format('H:i'));
    }

    public function test_relay_checks_the_replacement_on_the_relay_window_and_changes_nothing_when_refused(): void
    {
        $from = $this->user('Ole', 'Salve');
        $to = $this->user('Jean', 'Martin');
        $m = $this->mission(MissionStatus::IN_PROGRESS, $from);
        $this->em->method('find')->willReturn($to);
        $this->em->expects(self::never())->method('flush');
        $windows = [];
        $this->eligibility = $this->createMock(MissionEligibilityService::class);
        $this->eligibility->method('evaluateForReassignment')->willReturnCallback(function (Mission $mm, User $u) use (&$windows) {
            $windows[] = $mm->getStartAt()->format('H:i') . '-' . $mm->getEndAt()->format('H:i');

            return new EligibilityResult($u, [EligibilityReason::SCHEDULE_CONFLICT]);
        });

        try {
            $this->service()->relay($m, $this->user('Mia', 'Manager'), (int) $to->getId(), new \DateTimeImmutable('2026-11-03 11:00', new \DateTimeZone('Europe/Brussels')));
            self::fail('Expected InstrumentistIneligibleException');
        } catch (InstrumentistIneligibleException $e) {
            self::assertSame([EligibilityReason::SCHEDULE_CONFLICT], $e->getReasons());
        }
        self::assertSame(['11:00-13:00'], $windows);
        self::assertSame('13:00', $m->getEndAt()->format('H:i'), 'original untouched');
        self::assertSame($from, $m->getInstrumentist());
    }
}
