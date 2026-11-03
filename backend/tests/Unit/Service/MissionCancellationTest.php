<?php

namespace App\Tests\Unit\Service;

use App\Entity\FinancialCalculation;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Entity\WorkedPresence;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\MissionCancellationException;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionReassignmentNotificationMessageHandler;
use App\Service\AuditService;
use App\Service\FinancialCalculationService;
use App\Service\MissionActionsService;
use App\Service\MissionCancellationPolicy;
use App\Service\MissionEncodingGuard;
use App\Service\MissionEncodingWorkflowService;
use App\Service\MissionEligibilityService;
use App\Service\MissionPostDeployService;
use App\Service\MissionReplacementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-182 — a manager cancels a mission that did not take place (also once started or encoded;
 * a submitted / validated one after the explicit correction of its encoding, in the same
 * operation), never a billed / closed one; a duplicate is cancelled in favour of an
 * existing replacement mission, whose people are told of a change of instrumentist.
 */
final class MissionCancellationTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject $bus;
    private AuditService&MockObject $audit;
    /** @var list<array{0: AuditEventType, 1: array<string,mixed>, 2: ?Mission}> */
    private array $audits = [];
    /** @var list<object> */
    private array $dispatched = [];
    private static int $nextId = 5000;
    private Hospital $site;
    private User $surgeon;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(function (object $m) { $this->dispatched[] = $m; return new Envelope($m); });
        $this->audit = $this->createMock(AuditService::class);
        $this->audit->method('record')->willReturnCallback(function (?Mission $m, $actor, AuditEventType $type, array $payload) {
            $this->audits[] = [$type, $payload, $m];
        });
        $this->site = (new Hospital())->setName('CHU Test');
        $this->setId($this->site, self::$nextId++);
        $this->surgeon = $this->user('Paul', 'Chirurgien');
    }

    private function service(?FinancialCalculationService $calculations = null, ?MissionEncodingWorkflowService $workflow = null): MissionPostDeployService
    {
        return new MissionPostDeployService($this->em, $this->bus, $this->audit, $this->createMock(MissionEligibilityService::class), financialCalculations: $calculations, encodingWorkflow: $workflow);
    }

    /** The real encoding workflow, built on the mocked EntityManager / audit (its other collaborators are unused by the correction). */
    private function workflow(): MissionEncodingWorkflowService
    {
        $service = (new \ReflectionClass(MissionEncodingWorkflowService::class))->newInstanceWithoutConstructor();
        $em = $this->em;
        $audit = $this->audit;
        \Closure::bind(function () use ($em, $audit) { $this->em = $em; $this->audit = $audit; }, $service, MissionEncodingWorkflowService::class)();

        return $service;
    }

    /** The financial calculations "in the database" are those of $m (reopen() reads them there). */
    private function calculationsInDatabaseOf(Mission $m): void
    {
        $byStatus = static fn (array $criteria): array => array_values(array_filter(
            $m->getFinancialCalculations()->toArray(),
            static fn (FinancialCalculation $c) => in_array($c->getStatus(), (array) $criteria['status'], true),
        ));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback($byStatus);
        $repository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => $byStatus($criteria)[0] ?? null);
        $this->em->method('getRepository')->willReturn($repository);
    }

    /** The real calculation service, built on the mocked EntityManager / audit (its other collaborators are unused by cancel()). */
    private function calculations(): FinancialCalculationService
    {
        $service = (new \ReflectionClass(FinancialCalculationService::class))->newInstanceWithoutConstructor();
        $em = $this->em;
        $audit = $this->audit;
        \Closure::bind(function () use ($em, $audit) { $this->em = $em; $this->audit = $audit; }, $service, FinancialCalculationService::class)();

        return $service;
    }

    private function user(string $first, string $last): User
    {
        $u = (new User())->setEmail(strtolower($first) . self::$nextId . '@test.local')->setFirstname($first)->setLastname($last);
        $this->setId($u, self::$nextId++);

        return $u;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function mission(MissionStatus $status, ?User $instrumentist, string $start = '2026-11-03 08:00', string $end = '2026-11-03 13:00', ?Hospital $site = null, ?User $surgeon = null): Mission
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = (new Mission())
            ->setSite($site ?? $this->site)
            ->setSurgeon($surgeon ?? $this->surgeon)
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

    private function withHours(Mission $m): Mission
    {
        $m->setExecution((new MissionExecution())->setActualStartAt($m->getStartAt())->setActualDurationMinutes(90));

        return $m;
    }

    private function auditsOf(AuditEventType $type): array
    {
        return array_values(array_filter($this->audits, static fn (array $a) => $a[0] === $type));
    }

    // ── Policy ────────────────────────────────────────────────────────────────

    #[DataProvider('cancellableStatuses')]
    public function test_policy_mission_that_did_not_take_place_is_cancellable(MissionStatus $status): void
    {
        self::assertNull(MissionCancellationPolicy::blockedReason($this->withHours($this->mission($status, $this->user('Ole', 'Salve')))));
    }

    public static function cancellableStatuses(): array
    {
        return [
            'draft'                => [MissionStatus::DRAFT],
            'open'                 => [MissionStatus::OPEN],
            'assigned'             => [MissionStatus::ASSIGNED],
            'start time passed'    => [MissionStatus::IN_PROGRESS],
            'encoding in progress' => [MissionStatus::ENCODING_IN_PROGRESS],
        ];
    }

    #[DataProvider('protectedStatuses')]
    public function test_policy_closed_declared_cancelled_are_protected(MissionStatus $status, string $code, string $path): void
    {
        $blocked = MissionCancellationPolicy::blockedReason($this->mission($status, $this->user('Ole', 'Salve')));

        self::assertSame($code, $blocked['code']);
        self::assertStringContainsString($path, $blocked['message']);
    }

    public static function protectedStatuses(): array
    {
        return [
            'closed'    => [MissionStatus::CLOSED, MissionCancellationPolicy::MISSION_CLOSED, 'clôturée'],
            'declared'  => [MissionStatus::DECLARED, MissionCancellationPolicy::NOT_CANCELLABLE, 'refusez la déclaration'],
            'cancelled' => [MissionStatus::CANCELLED, MissionCancellationPolicy::NOT_CANCELLABLE, 'déjà annulée'],
        ];
    }

    public function test_policy_submitted_and_validated_are_cancelled_after_their_correction(): void
    {
        $submitted = $this->mission(MissionStatus::SUBMITTED, $this->user('Ole', 'Salve'));
        $validated = $this->mission(MissionStatus::VALIDATED, $this->user('Ole', 'Salve'));

        self::assertNull(MissionCancellationPolicy::blockedReason($submitted));
        self::assertSame(MissionCancellationPolicy::CORRECTION_REJECT, MissionCancellationPolicy::correction($submitted)['action']);
        self::assertNull(MissionCancellationPolicy::blockedReason($validated));
        self::assertSame(MissionCancellationPolicy::CORRECTION_REOPEN, MissionCancellationPolicy::correction($validated)['action']);
        self::assertNull(MissionCancellationPolicy::correction($this->mission(MissionStatus::ENCODING_IN_PROGRESS, null)));

        // A multi-room prestation is corrected as a whole first (Suivi des encodages).
        self::assertSame(MissionCancellationPolicy::ENCODING_SUBMITTED, MissionCancellationPolicy::blockedReason($submitted, 2)['code']);
        self::assertSame(MissionCancellationPolicy::ENCODING_VALIDATED, MissionCancellationPolicy::blockedReason($validated, 2)['code']);
    }

    public function test_policy_billed_mission_is_never_cancelled(): void
    {
        $m = $this->mission(MissionStatus::VALIDATED, $this->user('Ole', 'Salve'));
        $m->getFinancialCalculations()->add((new FinancialCalculation())->setMission($m)->setStatus(FinancialCalculationStatus::LOCKED));

        self::assertSame(MissionCancellationPolicy::MISSION_BILLED, MissionCancellationPolicy::blockedReason($m)['code']);
    }

    public function test_policy_shared_presence_is_detached_first(): void
    {
        $m = $this->mission(MissionStatus::ENCODING_IN_PROGRESS, $this->user('Ole', 'Salve'));
        $m->setExecution((new MissionExecution())->setPresence(new WorkedPresence()));

        self::assertSame(MissionCancellationPolicy::SHARED_PRESENCE, MissionCancellationPolicy::blockedReason($m)['code']);
    }

    public function test_policy_mission_carrying_the_content_of_a_multi_room_prestation_is_protected(): void
    {
        $m = $this->mission(MissionStatus::ENCODING_IN_PROGRESS, $this->user('Ole', 'Salve'));
        $m->getInterventions()->add(new MissionIntervention());

        self::assertSame(MissionCancellationPolicy::PRESTATION_CONTENT, MissionCancellationPolicy::blockedReason($m, 2)['code']);
        // Alone, its content is its own: archived with it.
        self::assertNull(MissionCancellationPolicy::blockedReason($m, 1));
        // Another room of the prestation, without content: cancellable.
        self::assertNull(MissionCancellationPolicy::blockedReason($this->mission(MissionStatus::ASSIGNED, $this->user('Eva', 'X')), 2));
    }

    // ── Simple cancellation ──────────────────────────────────────────────────

    public function test_cancel_assigned_mission_directly_without_release(): void
    {
        $sophie = $this->user('Sophie', 'Martin');
        $m = $this->mission(MissionStatus::ASSIGNED, $sophie);

        $this->service()->cancelByManager($m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_PROGRAM_CANCELLED, 'Bloc fermé');

        self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
        self::assertNull($m->getInstrumentist());
        $message = $this->dispatched[0];
        self::assertInstanceOf(MissionLifecycleChangedMessage::class, $message);
        self::assertSame($sophie->getId(), $message->payload['fromInstrumentistId']);
        self::assertSame('Bloc fermé', $message->payload['reason']);
    }

    public function test_cancel_mission_whose_start_time_passed_archives_encoding_and_cancels_open_calculations(): void
    {
        $m = $this->withHours($this->mission(MissionStatus::ENCODING_IN_PROGRESS, $this->user('Sophie', 'Martin')));
        $m->getInterventions()->add(new MissionIntervention());
        $calculated = (new FinancialCalculation())->setMission($m)->setStatus(FinancialCalculationStatus::CALCULATED);
        $approved   = (new FinancialCalculation())->setMission($m)->setStatus(FinancialCalculationStatus::APPROVED);
        $m->getFinancialCalculations()->add($calculated);
        $m->getFinancialCalculations()->add($approved);
        $execution = $m->getExecution();

        $this->service($this->calculations())->cancelByManager($m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_PROGRAM_CANCELLED, internalComment: 'Pas eu lieu');

        self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
        // Archived, never deleted: the encoding stays on the cancelled mission.
        self::assertSame($execution, $m->getExecution());
        self::assertSame(90, $m->getExecution()->getActualDurationMinutes());
        self::assertSame(1, $m->getInterventions()->count());
        // No financial effect: nothing open is left for statistics, worklists or invoices.
        self::assertSame(FinancialCalculationStatus::CANCELLED, $calculated->getStatus());
        self::assertSame(FinancialCalculationStatus::CANCELLED, $approved->getStatus());
        self::assertCount(2, $this->auditsOf(AuditEventType::FINANCIAL_CALCULATION_CANCELLED));

        $payload = $this->auditsOf(AuditEventType::MISSION_CANCELLED_POST_DEPLOY)[0][1];
        self::assertSame('ENCODING_IN_PROGRESS', $payload['previousStatus']);
        self::assertSame(MissionCancellationPolicy::REASON_PROGRAM_CANCELLED, $payload['cancellationReason']);
        self::assertTrue($payload['archivedEncoding']['hasOwnHours']);
        self::assertSame(1, $payload['archivedEncoding']['interventionCount']);
        self::assertSame('Pas eu lieu', $payload['internalComment']);
        // The internal comment never reaches a notification.
        self::assertArrayNotHasKey('internalComment', $this->dispatched[0]->payload);
    }

    public function test_cancel_submitted_mission_without_confirmed_correction_is_refused_and_changes_nothing(): void
    {
        $sophie = $this->user('Sophie', 'Martin');
        $m = $this->mission(MissionStatus::SUBMITTED, $sophie);

        try {
            $this->service()->cancelByManager($m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_PROGRAM_CANCELLED);
            self::fail('A submitted mission must not be cancelled.');
        } catch (MissionCancellationException $e) {
            self::assertSame(MissionCancellationPolicy::CORRECTION_REQUIRED, $e->getErrorCode());
        }
        self::assertSame(MissionStatus::SUBMITTED, $m->getStatus());
        self::assertSame($sophie, $m->getInstrumentist());
        self::assertSame([], $this->audits);
        self::assertSame([], $this->dispatched);
    }

    public function test_cancel_submitted_mission_sends_it_back_to_correction_then_cancels_it_in_one_operation(): void
    {
        $sophie = $this->user('Sophie', 'Martin');
        $m = $this->withHours($this->mission(MissionStatus::SUBMITTED, $sophie));
        $m->getInterventions()->add(new MissionIntervention());
        $calculated = (new FinancialCalculation())->setMission($m)->setStatus(FinancialCalculationStatus::CALCULATED);
        $m->getFinancialCalculations()->add($calculated);
        $this->calculationsInDatabaseOf($m);

        $this->service($this->calculations(), $this->workflow())->cancelByManager(
            $m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_PROGRAM_CANCELLED, 'Patient décédé', correctionConfirmed: true,
        );

        self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
        self::assertNull($m->getInstrumentist());
        // Traceability: the correction is audited and historised like « Renvoyer en correction ».
        $rejected = $this->auditsOf(AuditEventType::MISSION_ENCODING_REJECTED);
        self::assertCount(1, $rejected);
        self::assertSame('SUBMITTED', $rejected[0][1]['fromStatus']);
        self::assertTrue($rejected[0][1]['beforeCancellation']);
        self::assertSame('Annulation de la mission : programme annulé. Patient décédé', $rejected[0][1]['comment']);
        self::assertSame('Annulation de la mission : programme annulé. Patient décédé', $m->getEncodingComments()->first()->getComment());
        // Then cancelled: encoding archived, open calculation cancelled.
        $payload = $this->auditsOf(AuditEventType::MISSION_CANCELLED_POST_DEPLOY)[0][1];
        self::assertSame(['action' => 'REJECT', 'fromStatus' => 'SUBMITTED', 'obsoleteFinancialCalculationIds' => []], $payload['encodingCorrection']);
        self::assertSame(1, $payload['archivedEncoding']['interventionCount']);
        self::assertSame(FinancialCalculationStatus::CANCELLED, $calculated->getStatus());
        // One notification only: the cancellation, never a "correction requested".
        self::assertCount(1, $this->dispatched);
        self::assertSame('CANCELLED', $this->dispatched[0]->changeType->value);
    }

    public function test_cancel_validated_mission_reopens_it_then_cancels_it(): void
    {
        $m = $this->withHours($this->mission(MissionStatus::VALIDATED, $this->user('Sophie', 'Martin')));
        $m->setEncodingLockedAt(new \DateTimeImmutable('2026-11-03 15:00'));
        $approved = (new FinancialCalculation())->setMission($m)->setStatus(FinancialCalculationStatus::APPROVED);
        $this->setId($approved, self::$nextId++);
        $m->getFinancialCalculations()->add($approved);
        $this->calculationsInDatabaseOf($m);

        $this->service($this->calculations(), $this->workflow())->cancelByManager(
            $m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_PROGRAM_CANCELLED, correctionConfirmed: true,
        );

        self::assertSame(MissionStatus::CANCELLED, $m->getStatus());
        self::assertNull($m->getEncodingLockedAt());
        $reopened = $this->auditsOf(AuditEventType::MISSION_ENCODING_REOPENED);
        self::assertCount(1, $reopened);
        self::assertSame([$approved->getId()], $reopened[0][1]['obsoleteFinancialCalculationIds']);
        // Never billed: the approved calculation is cancelled with the mission.
        self::assertSame(FinancialCalculationStatus::CANCELLED, $approved->getStatus());
        $payload = $this->auditsOf(AuditEventType::MISSION_CANCELLED_POST_DEPLOY)[0][1];
        self::assertSame('REOPEN', $payload['encodingCorrection']['action']);
        self::assertSame('VALIDATED', $payload['encodingCorrection']['fromStatus']);
        self::assertCount(1, $this->dispatched);
    }

    public function test_cancel_validated_mission_billed_in_the_database_is_refused(): void
    {
        $m = $this->mission(MissionStatus::VALIDATED, $this->user('Sophie', 'Martin'));
        // Locked meanwhile: only the database knows it (reopen()'s own check).
        $locked = (new FinancialCalculation())->setMission($m)->setStatus(FinancialCalculationStatus::LOCKED);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($locked);
        $this->em->method('getRepository')->willReturn($repository);

        try {
            $this->service($this->calculations(), $this->workflow())->cancelByManager(
                $m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_PROGRAM_CANCELLED, correctionConfirmed: true,
            );
            self::fail('A billed mission must never be cancelled.');
        } catch (ConflictHttpException) {
        }
        self::assertSame([], $this->dispatched);
        self::assertSame([], $this->auditsOf(AuditEventType::MISSION_CANCELLED_POST_DEPLOY));
    }

    public function test_replaced_reason_requires_the_replacement_and_the_reverse(): void
    {
        $m = $this->mission(MissionStatus::ASSIGNED, $this->user('Sophie', 'Martin'));

        $this->expectException(MissionCancellationException::class);
        $this->service()->cancelByManager($m, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_REPLACED_BY_MISSION);
    }

    public function test_automatic_callers_keep_their_narrower_rule(): void
    {
        // Absence reaction, room release, planning edits: never a started mission.
        $m = $this->mission(MissionStatus::IN_PROGRESS, $this->user('Sophie', 'Martin'));

        $this->expectException(ConflictHttpException::class);
        $this->service()->cancel($m, $this->user('Mia', 'Manager'));
    }

    public function test_actions_offer_cancel_on_started_mission_but_not_on_shared_presence(): void
    {
        $actions = new MissionActionsService(new MissionEncodingGuard());
        $manager = $this->user('Mia', 'Manager')->setRoles(['ROLE_MANAGER']);

        $started = $this->mission(MissionStatus::IN_PROGRESS, $this->user('Sophie', 'Martin'));
        self::assertContains('cancel', $actions->allowedActions($started, $manager));

        $shared = $this->mission(MissionStatus::ASSIGNED, $this->user('Ole', 'Salve'));
        $shared->setExecution((new MissionExecution())->setPresence(new WorkedPresence()));
        self::assertNotContains('cancel', $actions->allowedActions($shared, $manager));

        // Submitted / validated (never billed): offered, corrected in the same operation.
        self::assertContains('cancel', $actions->allowedActions($this->mission(MissionStatus::SUBMITTED, $this->user('Eva', 'X')), $manager));
        self::assertContains('cancel', $actions->allowedActions($this->mission(MissionStatus::VALIDATED, $this->user('Eva', 'X')), $manager));
        $billed = $this->mission(MissionStatus::VALIDATED, $this->user('Eva', 'X'));
        $billed->getFinancialCalculations()->add((new FinancialCalculation())->setMission($billed)->setStatus(FinancialCalculationStatus::LOCKED));
        self::assertNotContains('cancel', $actions->allowedActions($billed, $manager));

        // With its multi-room prestation, exactly as the guard: never offered then refused.
        $carrier = $this->mission(MissionStatus::ENCODING_IN_PROGRESS, $this->user('Eva', 'X'));
        $carrier->getInterventions()->add(new MissionIntervention());
        self::assertContains('cancel', $actions->allowedActions($carrier, $manager));
        self::assertNotContains('cancel', $actions->allowedActions($carrier, $manager, 2));
    }

    // ── Replacement by an existing mission ───────────────────────────────────

    public function test_replacement_must_be_the_same_programme(): void
    {
        $planned = $this->mission(MissionStatus::IN_PROGRESS, $this->user('Sophie', 'Martin'));
        $salve = $this->user('Ole', 'Salve');

        self::assertNull(MissionCancellationPolicy::replacementIncompatibility($planned, $this->mission(MissionStatus::DECLARED, $salve, '2026-11-03 08:20', '2026-11-03 12:30')));

        $otherSite = (new Hospital())->setName('Autre');
        $this->setId($otherSite, self::$nextId++);
        $cases = [
            'other site'     => $this->mission(MissionStatus::DECLARED, $salve, site: $otherSite),
            'other surgeon'  => $this->mission(MissionStatus::DECLARED, $salve, surgeon: $this->user('Jean', 'Autre')),
            'other day'      => $this->mission(MissionStatus::DECLARED, $salve, '2026-11-04 08:00', '2026-11-04 13:00'),
            'no overlap'     => $this->mission(MissionStatus::DECLARED, $salve, '2026-11-03 14:00', '2026-11-03 17:00'),
            'nobody on it'   => $this->mission(MissionStatus::OPEN, null),
            'cancelled'      => $this->mission(MissionStatus::CANCELLED, $salve),
        ];
        foreach ($cases as $label => $candidate) {
            self::assertNotNull(MissionCancellationPolicy::replacementIncompatibility($planned, $candidate), $label);
        }
        self::assertNotNull(MissionCancellationPolicy::replacementIncompatibility($planned, $planned), 'itself');
    }

    public function test_replacement_notifies_a_change_of_instrumentist_never_a_cancellation(): void
    {
        $change = ['mode' => MissionReplacementService::MODE_REPLACED, 'fromInstrumentistId' => 9, 'toInstrumentistId' => 11, 'surgeonId' => 2, 'approved' => true];

        self::assertSame(
            [MissionReassignmentNotificationMessageHandler::ROLE_PREVIOUS => 9, MissionReassignmentNotificationMessageHandler::ROLE_CONFIRMED => 11, MissionReassignmentNotificationMessageHandler::ROLE_SURGEON => 2],
            MissionReassignmentNotificationMessageHandler::roles($change),
        );
        // Not approved now: the replacement is not told her mission was validated.
        self::assertSame(
            [MissionReassignmentNotificationMessageHandler::ROLE_PREVIOUS => 9, MissionReassignmentNotificationMessageHandler::ROLE_SURGEON => 2],
            MissionReassignmentNotificationMessageHandler::roles(['approved' => false] + $change),
        );
        // Same person on both missions: nobody changed, only the confirmation.
        self::assertSame(
            [MissionReassignmentNotificationMessageHandler::ROLE_CONFIRMED => 9],
            MissionReassignmentNotificationMessageHandler::roles(['toInstrumentistId' => 9] + $change),
        );
    }

    public function test_cancel_as_duplicate_records_the_replacement_and_sends_no_cancellation(): void
    {
        $planned = $this->mission(MissionStatus::IN_PROGRESS, $this->user('Sophie', 'Martin'));
        $kept = $this->mission(MissionStatus::ASSIGNED, $this->user('Ole', 'Salve'));

        $this->service()->cancelByManager($planned, $this->user('Mia', 'Manager'), MissionCancellationPolicy::REASON_REPLACED_BY_MISSION, notify: false, replacement: $kept);

        self::assertSame(MissionStatus::CANCELLED, $planned->getStatus());
        self::assertSame($kept, $planned->getReplacedBy());
        self::assertSame(MissionStatus::ASSIGNED, $kept->getStatus());
        self::assertSame($kept->getId(), $this->auditsOf(AuditEventType::MISSION_CANCELLED_POST_DEPLOY)[0][1]['replacementMissionId']);
        self::assertSame([], $this->dispatched);
    }
}
