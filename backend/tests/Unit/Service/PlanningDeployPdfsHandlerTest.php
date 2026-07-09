<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningDeployment;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\PlanningDeploymentStatus;
use App\Enum\SchedulePrecision;
use App\Enum\UncoveredReason;
use App\Message\PlanningDeployPdfsMessage;
use App\Message\SendBillingEmailMessage;
use App\MessageHandler\PlanningDeployPdfsMessageHandler;
use App\Service\MissionEligibilityService;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\PdfService;
use App\Service\UncoveredReasonResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests PlanningDeployPdfsMessageHandler under the "exactly ONE deploy email per
 * recipient" policy (Planning V2 deploy email redesign):
 * - Surgeon: one email, aggregate counts (total/covered/uncovered), own PDF only —
 *   no global PDF attached, no change-summary email ever sent during deploy.
 * - Instrumentist: one email with missionCount + own PDF — no change-summary email.
 * - Manager: one email (deployment confirmation + stats) with the global PDF attached,
 *   plus the pre-existing in-app notification.
 * - Change-summary emails (PlanningChangeSummaryService) are entirely decoupled from
 *   this handler now — see PlanningChangeSummaryServiceTest for that capability.
 */
class PlanningDeployPdfsHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject         $em;
    private PdfService&MockObject                    $pdf;
    private NotificationService&MockObject           $notif;
    private WebPushServiceInterface&MockObject       $webPush;
    private MessageBusInterface&MockObject           $bus;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private UncoveredReasonResolver&MockObject       $uncoveredReasonResolver;
    private MissionEligibilityService&MockObject     $eligibilityService;

    private array $dispatched   = [];
    private array $pdfTemplates = [];

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->pdf         = $this->createMock(PdfService::class);
        $this->notif       = $this->createMock(NotificationService::class);
        $this->webPush     = $this->createMock(WebPushServiceInterface::class);
        $this->bus         = $this->createMock(MessageBusInterface::class);

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: true, push: false));

        $this->uncoveredReasonResolver = $this->createMock(UncoveredReasonResolver::class);
        $this->uncoveredReasonResolver->method('resolveForMission')
            ->willReturn(UncoveredReason::MANUALLY_LEFT_OPEN);

        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')->willReturn([]);

        $this->dispatched   = [];
        $this->pdfTemplates = [];

        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });

        $this->pdf->method('generateFromTemplate')->willReturnCallback(
            function (string $template): string {
                $this->pdfTemplates[] = $template;
                return 'fake-pdf-' . basename($template, '.html.twig');
            }
        );

        $this->em->method('flush');
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function makeUser(string $email, array $roles, int $id): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles($roles);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, $id);
        return $u;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, MissionStatus $status = MissionStatus::OPEN): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable('2026-03-24 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-03-24 13:00:00'));
        $site = new Hospital(); $site->setName('Alpha');
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setInstrumentist($instrumentist);
        return $m;
    }

    private function makeHandler(): PlanningDeployPdfsMessageHandler
    {
        return new PlanningDeployPdfsMessageHandler(
            $this->em,
            $this->pdf,
            $this->notif,
            $this->webPush,
            $this->bus,
            $this->preferenceResolver,
            $this->uncoveredReasonResolver,
            $this->eligibilityService,
            'noreply@test.com',
            'SurgicalHub',
        );
    }

    private function makeMessage(
        array $openUncoveredIds = [],
        ?int $deploymentId = null,
    ): PlanningDeployPdfsMessage {
        return new PlanningDeployPdfsMessage(
            from:              '2026-03-23',
            to:                '2026-03-27',
            siteId:            null,
            deployedById:      99,
            deploymentId:      $deploymentId,
            openUncoveredIds:  $openUncoveredIds,
        );
    }

    private function setupMissionsQuery(array $missions): void
    {
        $deployedBy = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $deployedBy,
                $class === User::class && $id === 1  => $missions[0]?->getSurgeon(),
                $class === User::class && $id === 2  => $missions[0]?->getInstrumentist(),
                default                              => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn($missions);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_handler_generates_instrumentist_pdf(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'],      1);
        $instr    = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission  = $this->makeMission($surgeon, $instr);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 2  => $instr,
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $this->assertContains('pdf/planning_instrumentist.html.twig', $this->pdfTemplates);

        $emailsToInstr = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com'
        );
        $this->assertCount(1, $emailsToInstr, 'Instrumentist must receive exactly one email');
    }

    /**
     * REGRESSION (email policy redesign): the global PDF is generated (still needed
     * for the manager email) but must NOT be attached to the surgeon's email anymore —
     * only the surgeon's own personal PDF.
     */
    public function test_handler_generates_global_pdf_but_does_not_attach_it_to_surgeon_email(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'],      1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission($surgeon, $instr);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $this->assertContains('pdf/planning_global.html.twig', $this->pdfTemplates,
            'Global PDF must still be generated (needed for the manager email)'
        );
        $this->assertContains('pdf/planning_surgeon.html.twig', $this->pdfTemplates,
            'Personal surgeon PDF must also be generated'
        );

        $surgeonEmails = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'surgeon@test.com'
        );
        $this->assertCount(1, $surgeonEmails);

        /** @var SendBillingEmailMessage $msg */
        $msg = array_values($surgeonEmails)[0];
        $this->assertEmpty($msg->extraAttachments,
            'The global PDF must NOT be duplicated onto the surgeon email — manager-only content.'
        );
    }

    public function test_handler_does_nothing_when_no_missions(): void
    {
        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                default => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->makeHandler()->__invoke($this->makeMessage());

        $this->assertEmpty($this->pdfTemplates, 'No PDFs must be generated when there are no missions');
        $emailDispatches = array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage);
        $this->assertEmpty($emailDispatches);
    }

    public function test_handler_skips_invalid_deployed_by(): void
    {
        // If the deployedBy user is not found, handler must return silently (no crash)
        $this->em->method('find')->willReturn(null);
        $this->em->method('createQueryBuilder')->willReturn($this->createMock(QueryBuilder::class));

        $this->makeHandler()->__invoke($this->makeMessage());

        $this->assertEmpty($this->pdfTemplates);
    }

    public function test_pdf_failure_does_not_abort_other_pdfs(): void
    {
        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'],      1);
        $instr    = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $surgeon2 = $this->makeUser('surgeon2@test.com', ['ROLE_SURGEON'],     3);
        $mission1 = $this->makeMission($surgeon,  $instr);
        $mission2 = $this->makeMission($surgeon2, null);

        // First PDF call throws, second must still be attempted
        $callCount = 0;
        $this->pdf = $this->createMock(PdfService::class);
        $this->pdf->method('generateFromTemplate')->willReturnCallback(
            function () use (&$callCount): string {
                $callCount++;
                if ($callCount === 1) throw new \RuntimeException('PDF engine crash');
                return 'ok-pdf';
            }
        );

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 2  => $instr,
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 3  => $surgeon2,
                default => null,
            });
        $this->setupMissionsQuery([$mission1, $mission2]);

        // Must not throw
        $threw = false;
        try {
            (new PlanningDeployPdfsMessageHandler(
                $this->em, $this->pdf, $this->notif, $this->webPush,
                $this->bus,
                $this->preferenceResolver, $this->uncoveredReasonResolver,
                $this->eligibilityService,
                'noreply@test.com', 'SurgicalHub'
            ))->__invoke($this->makeMessage());
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'A PDF failure must not abort the handler — other PDFs must still be attempted');
        $this->assertGreaterThan(1, $callCount, 'PDF generation must be attempted more than once');
    }

    // ── Idempotence & status tracking ─────────────────────────────────────────

    /**
     * If PlanningDeployment.status == DONE, the handler must return immediately.
     * No PDFs generated, no emails dispatched.
     */
    public function test_handler_skips_when_deployment_is_done(): void
    {
        $deployment = new PlanningDeployment();
        $deployment->setStatus(PlanningDeploymentStatus::DONE);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningDeployment::class && $id === 7 => $deployment,
                default => null,
            });

        $this->makeHandler()->__invoke($this->makeMessage(deploymentId: 7));

        $this->assertEmpty($this->pdfTemplates,
            'No PDFs must be generated when deployment is already DONE.'
        );
        $emailDispatches = array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage);
        $this->assertEmpty($emailDispatches,
            'No emails must be dispatched when deployment is already DONE.'
        );
    }

    /**
     * On successful completion, handler must set deployment status to DONE and populate completedAt.
     */
    public function test_handler_marks_deployment_done_on_success(): void
    {
        $deployment = new PlanningDeployment();
        $this->assertSame(PlanningDeploymentStatus::PENDING, $deployment->getStatus(),
            'Deployment must start as PENDING.'
        );

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'],      1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mgr     = $this->makeUser('mgr@test.com',     ['ROLE_MANAGER'],      99);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningDeployment::class && $id === 7 => $deployment,
                $class === User::class && $id === 99              => $mgr,
                $class === User::class && $id === 1               => $surgeon,
                $class === User::class && $id === 2               => $instr,
                default                                           => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$mission]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->makeHandler()->__invoke($this->makeMessage(deploymentId: 7));

        $this->assertSame(PlanningDeploymentStatus::DONE, $deployment->getStatus(),
            'Handler must set deployment status to DONE on success.'
        );
        $this->assertNotNull($deployment->getCompletedAt(),
            'completedAt must be set after successful processing.'
        );
    }

    /**
     * Pool notifications must only be sent for missions in openUncoveredIds.
     * Mission 101 → selected → 1 notification. Mission 102 → not selected → no notification.
     */
    public function test_pool_notification_only_for_open_uncovered_ids(): void
    {
        $surgeon   = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mgr       = $this->makeUser('mgr@test.com',     ['ROLE_MANAGER'], 99);
        $poolInstr = $this->makeUser('pool@test.com',    ['ROLE_INSTRUMENTIST'], 5);

        $site = new Hospital();
        $site->setName('Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($site, 10);

        // Mission 101 — in openUncoveredIds → counts toward pool notification
        $m101 = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        $m101->setSite($site);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m101, 101);

        // Mission 102 — NOT in openUncoveredIds → must not trigger a notification
        $m102 = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        $m102->setSite($site);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m102, 102);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                default                              => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$m101, $m102]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        // Re-create the eligibility service mock to override setUp() default ([])
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')
            ->willReturn([10 => [$poolInstr]]);

        // Push must be called exactly once with "1 nouvelle" (only mission 101, not 102)
        $this->webPush->expects($this->once())
            ->method('sendToUsers')
            ->with(
                $this->anything(),
                'Nouvelles missions disponibles',
                $this->stringContains('1 nouvelle'),
                $this->anything(),
            );

        $this->makeHandler()->__invoke(
            $this->makeMessage(openUncoveredIds: [101]) // 101 selected, 102 not
        );
    }

    // ── Batch 11 Fix 3: outer catch marks deployment FAILED ─────────────────

    /**
     * An exception that escapes every inner try/catch (e.g. the missions query itself
     * failing) must be caught by the handler's outer try/catch, which marks the
     * deployment FAILED with a useful error message and rethrows so Messenger's own
     * retry/failure-transport handling still applies. This locks in already-correct
     * behavior that had no test coverage before Batch 11 — it's the catchable half of
     * the stuck-PROCESSING bug (the other half, a non-catchable OOM fatal, is handled
     * by raising the worker's memory_limit and by PlanningDeploymentReconcileStuckCommand).
     */
    public function test_exception_during_processing_marks_deployment_failed_and_rethrows(): void
    {
        $deployment = new PlanningDeployment();
        $mgr = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningDeployment::class && $id === 7 => $deployment,
                $class === User::class && $id === 99              => $mgr,
                default                                           => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willThrowException(new \RuntimeException('DB connection lost mid-query'));
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $threw = false;
        try {
            $this->makeHandler()->__invoke($this->makeMessage(deploymentId: 7));
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('DB connection lost mid-query', $e->getMessage());
        }

        $this->assertTrue($threw, 'The handler must rethrow so Messenger can apply its own retry/failure policy');
        $this->assertSame(PlanningDeploymentStatus::FAILED, $deployment->getStatus(),
            'Deployment must be marked FAILED, not left stuck on PROCESSING'
        );
        $this->assertNotNull($deployment->getErrorLog(), 'errorLog must contain a useful message for managers/ops');
        $this->assertStringContainsString('DB connection lost mid-query', $deployment->getErrorLog());
        $this->assertNull($deployment->getCompletedAt(), 'completedAt must stay null on failure');
    }

    // ── Batch 15C — Notification preference gating ───────────────────────────

    /**
     * When the resolver returns email=false for PLANNING_DEPLOYED_INSTRUMENTIST,
     * the handler must not dispatch a planning email to the instrumentist.
     */
    public function test_instrumentist_email_skipped_when_email_disabled(): void
    {
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturnCallback(function (User $user, NotificationType $type): NotificationChannels {
                if ($type === NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST) {
                    return new NotificationChannels(inApp: true, email: false, push: false);
                }
                return new NotificationChannels(inApp: true, email: true, push: false);
            });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $emailsToInstr = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com',
        );
        $this->assertEmpty($emailsToInstr,
            'Instrumentist email must be suppressed when resolver returns email=false.'
        );
    }

    /**
     * The instrumentist email context must include missionCount.
     */
    public function test_instrumentist_email_context_includes_mission_count(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $instrEmails = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com',
        ));
        $this->assertCount(1, $instrEmails);
        $this->assertSame(1, $instrEmails[0]->context['missionCount']);
    }

    /**
     * When the resolver returns inApp=true for PLANNING_DEPLOYED_INSTRUMENTIST,
     * the handler must persist a NotificationEvent with eventType=PLANNING_DEPLOYED_INSTRUMENTIST.
     */
    public function test_instrumentist_notification_event_created_when_inapp_enabled(): void
    {
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $instrEvents = array_values(array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST->value,
        ));
        $this->assertCount(1, $instrEvents,
            'Handler must persist one PLANNING_DEPLOYED_INSTRUMENTIST event for the instrumentist.'
        );
        $this->assertSame($instr, $instrEvents[0]->getUser());
        $payload = $instrEvents[0]->getPayload();
        $this->assertArrayHasKey('missionCount', $payload);
        $this->assertSame(1, $payload['missionCount']);
    }

    /**
     * When the resolver returns inApp=false for PLANNING_DEPLOYED_INSTRUMENTIST,
     * the handler must NOT persist a NotificationEvent of that type.
     */
    public function test_instrumentist_notification_event_not_created_when_inapp_disabled(): void
    {
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $instrEvents = array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST->value,
        );
        $this->assertEmpty($instrEvents,
            'Handler must not persist PLANNING_DEPLOYED_INSTRUMENTIST when inApp=false.'
        );
    }

    /**
     * REGRESSION: the surgeon EMAIL context must contain aggregate counts
     * (total/covered/uncovered) — the old per-post posts[] table was removed from
     * the email (it remains only in the in-app notification payload, see below).
     */
    public function test_surgeon_email_context_has_aggregate_counts_not_posts(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $covered = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $uncovered = $this->makeMission($surgeon, null, MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$covered, $uncovered]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $surgeonEmails = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'surgeon@test.com'
                && $m->htmlTemplate === 'emails/planning_surgeon.html.twig',
        ));
        $this->assertCount(1, $surgeonEmails, 'Surgeon must receive exactly one deploy email.');

        $context = $surgeonEmails[0]->context;
        $this->assertArrayNotHasKey('posts', $context,
            'Surgeon email context must no longer expose the per-post table.'
        );
        $this->assertSame(2, $context['totalCount']);
        $this->assertSame(1, $context['coveredCount']);
        $this->assertSame(1, $context['uncoveredCount']);
    }

    /**
     * When resolver returns inApp=true for PLANNING_DEPLOYED_SURGEON, the handler
     * must persist a NotificationEvent with the posts[] payload (in-app only — unchanged).
     */
    public function test_surgeon_notification_event_created_with_posts(): void
    {
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $surgeonEvents = array_values(array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_SURGEON->value,
        ));
        $this->assertCount(1, $surgeonEvents,
            'Handler must persist one PLANNING_DEPLOYED_SURGEON event per surgeon.'
        );
        $this->assertSame($surgeon, $surgeonEvents[0]->getUser());
        $payload = $surgeonEvents[0]->getPayload();
        $this->assertArrayHasKey('posts', $payload);
        $this->assertIsArray($payload['posts']);
        $this->assertCount(1, $payload['posts']);
    }

    /**
     * The in-app notification's posts[] must still carry the per-mission
     * covered/uncoveredReasonLabel detail — unchanged, only the email was simplified.
     */
    public function test_surgeon_posts_include_uncovered_reason_for_open_mission(): void
    {
        $this->uncoveredReasonResolver = $this->createMock(UncoveredReasonResolver::class);
        $this->uncoveredReasonResolver->method('resolveForMission')
            ->willReturn(UncoveredReason::ALL_ABSENT);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $surgeonEvents = array_values(array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_SURGEON->value,
        ));
        $this->assertCount(1, $surgeonEvents);
        $post = $surgeonEvents[0]->getPayload()['posts'][0];
        $this->assertFalse($post['covered']);
        $this->assertSame(UncoveredReason::ALL_ABSENT->label(), $post['uncoveredReasonLabel']);
        $this->assertNull($post['instrumentistName']);
    }

    /**
     * When resolver returns inApp=false for PLANNING_DEPLOYED_SURGEON, the handler
     * must NOT persist a NotificationEvent of that type.
     */
    public function test_surgeon_notification_event_not_created_when_inapp_disabled(): void
    {
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $surgeonEvents = array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_SURGEON->value,
        );
        $this->assertEmpty($surgeonEvents,
            'Handler must not persist PLANNING_DEPLOYED_SURGEON when inApp=false.'
        );
    }

    /**
     * When findEligible returns a site instrumentist and inApp=true for OPEN_MISSION_AVAILABLE,
     * the handler must persist a NotificationEvent for that instrumentist.
     */
    public function test_pool_notification_event_created_per_site_instrumentist(): void
    {
        $siteInstrumentist = $this->makeUser('pool-instr@test.com', ['ROLE_INSTRUMENTIST'], 5);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mgr     = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        $site = new Hospital();
        $site->setName('Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($site, 10);

        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        $mission->setSite($site);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($mission, 101);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$mission]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        // Re-create the eligibility service mock to override setUp() default ([])
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')
            ->willReturn([10 => [$siteInstrumentist]]);

        $this->makeHandler()->__invoke(
            $this->makeMessage(openUncoveredIds: [101])
        );

        $poolEvents = array_values(array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::OPEN_MISSION_AVAILABLE->value,
        ));
        $this->assertCount(1, $poolEvents,
            'Handler must persist one OPEN_MISSION_AVAILABLE event per eligible site instrumentist.'
        );
        $this->assertSame($siteInstrumentist, $poolEvents[0]->getUser());
        $payload = $poolEvents[0]->getPayload();
        $this->assertSame(1, $payload['missionCount']);
    }

    /**
     * When resolver returns inApp=false for OPEN_MISSION_AVAILABLE, no NotificationEvent
     * of that type must be created.
     */
    public function test_pool_notification_event_not_created_when_inapp_disabled(): void
    {
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $siteInstrumentist = $this->makeUser('pool-instr@test.com', ['ROLE_INSTRUMENTIST'], 5);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mgr     = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        $site = new Hospital();
        $site->setName('Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($site, 10);

        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        $mission->setSite($site);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($mission, 101);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$mission]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        // Re-create the eligibility service mock to override setUp() default ([])
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')
            ->willReturn([10 => [$siteInstrumentist]]);

        $this->makeHandler()->__invoke(
            $this->makeMessage(openUncoveredIds: [101])
        );

        $poolEvents = array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::OPEN_MISSION_AVAILABLE->value,
        );
        $this->assertEmpty($poolEvents,
            'No OPEN_MISSION_AVAILABLE event must be created when inApp=false.'
        );
    }

    /**
     * On successful deploy, a PLANNING_DEPLOYED_MANAGER event must be persisted
     * for the deploying user, with missionCount, assignedCount, and openPoolCount.
     */
    public function test_manager_notification_event_created_with_summary(): void
    {
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon  = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr    = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mgr      = $this->makeUser('mgr@test.com',     ['ROLE_MANAGER'], 99);

        $assigned = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $open     = $this->makeMission($surgeon, null,  MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$assigned, $open]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $mgrEvents = array_values(array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_MANAGER->value,
        ));
        $this->assertCount(1, $mgrEvents,
            'Handler must persist one PLANNING_DEPLOYED_MANAGER event for the deploying manager.'
        );
        $this->assertSame($mgr, $mgrEvents[0]->getUser());
        $payload = $mgrEvents[0]->getPayload();
        $this->assertSame(2, $payload['missionCount']);
        $this->assertSame(1, $payload['assignedCount']);
        $this->assertSame(1, $payload['openPoolCount']);
        $this->assertArrayHasKey('periodLabel', $payload);
    }

    /**
     * When resolver returns inApp=false for PLANNING_DEPLOYED_MANAGER, no event is created.
     */
    public function test_manager_notification_event_not_created_when_inapp_disabled(): void
    {
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $mgrEvents = array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent
                && $e->getEventType() === NotificationType::PLANNING_DEPLOYED_MANAGER->value,
        );
        $this->assertEmpty($mgrEvents,
            'No PLANNING_DEPLOYED_MANAGER event must be created when inApp=false.'
        );
    }

    /**
     * NEW (email policy redesign): the manager must also receive a deployment
     * confirmation EMAIL with the global PDF attached, gated the same way as
     * every other deploy email (email channel + a valid recipient address).
     */
    public function test_manager_receives_deployment_confirmation_email_with_global_pdf(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $mgr     = $this->makeUser('mgr@test.com',     ['ROLE_MANAGER'], 99);

        $assigned = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $open     = $this->makeMission($surgeon, null,  MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$assigned, $open]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $mgrEmails = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'mgr@test.com'
                && $m->htmlTemplate === 'emails/planning_manager.html.twig',
        ));
        $this->assertCount(1, $mgrEmails, 'Manager must receive exactly one deployment confirmation email.');

        $msg = $mgrEmails[0];
        $this->assertSame(2, $msg->context['missionCount']);
        $this->assertSame(1, $msg->context['assignedCount']);
        $this->assertSame(1, $msg->context['openPoolCount']);
        $this->assertNotEmpty($msg->attachmentBase64, 'The global PDF must be attached to the manager email.');
    }

    /**
     * If openUncoveredIds is empty, planningNewOpenMissionsNotifySite must never be called.
     */
    public function test_no_pool_notification_when_no_uncovered_ids_selected(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mgr     = $this->makeUser('mgr@test.com',     ['ROLE_MANAGER'], 99);
        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                default                              => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$mission]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->notif->expects($this->never())->method('planningNewOpenMissionsNotifySite');
        $this->webPush->expects($this->never())->method('sendToUsers');

        // No IDs selected → no pool notification (sendPoolNotifications exits early)
        $this->makeHandler()->__invoke($this->makeMessage(openUncoveredIds: []));
    }

    // ── Email policy redesign — regression guarantees ────────────────────────

    /**
     * REGRESSION: exactly one deploy email per surgeon and one per instrumentist —
     * no matter how many missions they have. This is the core invariant the
     * duplicate-email bug report was about.
     */
    public function test_exactly_one_email_per_surgeon_and_per_instrumentist(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com',   ['ROLE_INSTRUMENTIST'], 2);
        $m1 = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $m2 = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);
        $m2->setStartAt(new \DateTimeImmutable('2026-03-25 08:00:00'));
        $m2->setEndAt(new \DateTimeImmutable('2026-03-25 13:00:00'));

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
                default => null,
            });
        $this->setupMissionsQuery([$m1, $m2]);

        $this->makeHandler()->__invoke($this->makeMessage());

        $toSurgeon = array_filter($this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'surgeon@test.com');
        $toInstr = array_filter($this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com');

        $this->assertCount(1, $toSurgeon, 'Surgeon must receive exactly ONE email per deploy.');
        $this->assertCount(1, $toInstr, 'Instrumentist must receive exactly ONE email per deploy.');
    }

    /**
     * REGRESSION: no "uncovered posts" / change-summary email is ever dispatched
     * during deployment — that capability moved out of this handler entirely.
     */
    public function test_no_change_summary_email_ever_sent_during_deploy(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mission = $this->makeMission($surgeon, null, MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99),
                $class === User::class && $id === 1  => $surgeon,
                default => null,
            });
        $this->setupMissionsQuery([$mission]);

        $this->makeHandler()->__invoke(
            $this->makeMessage(openUncoveredIds: [$mission->getId()])
        );

        $summaryEmails = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && str_contains($m->htmlTemplate, 'change_summary'),
        );
        $this->assertEmpty($summaryEmails,
            'No change-summary email must ever be dispatched by the deploy handler.'
        );
    }
}
