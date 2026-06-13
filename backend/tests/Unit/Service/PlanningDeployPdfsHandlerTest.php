<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningDeploymentStatus;
use App\Enum\SchedulePrecision;
use App\Message\PlanningDeployPdfsMessage;
use App\Message\SendBillingEmailMessage;
use App\MessageHandler\PlanningDeployPdfsMessageHandler;
use App\Service\NotificationService;
use App\Service\PdfService;
use App\Service\PlanningDiffService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests PlanningDeployPdfsMessageHandler:
 * - PDF generation for instrumentists and surgeons
 * - Global PDF attached to surgeon emails
 * - Push notifications dispatched
 * - Failures of individual PDFs do not abort the whole batch
 */
class PlanningDeployPdfsHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PdfService&MockObject             $pdf;
    private NotificationService&MockObject    $notif;
    private MessageBusInterface&MockObject    $bus;
    private PlanningDiffService&MockObject    $diffService;

    private array $dispatched   = [];
    private array $pdfTemplates = [];

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->pdf         = $this->createMock(PdfService::class);
        $this->notif       = $this->createMock(NotificationService::class);
        $this->bus         = $this->createMock(MessageBusInterface::class);
        $this->diffService = $this->createMock(PlanningDiffService::class);

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
            $this->bus,
            $this->diffService,
            'noreply@test.com',
            'SurgicalHub',
        );
    }

    private function makeMessage(
        array $openUncoveredIds = [],
        bool $sendChangeSummary = false,
        ?int $deploymentId = null,
        ?int $versionId = null,
    ): PlanningDeployPdfsMessage {
        return new PlanningDeployPdfsMessage(
            from:              '2026-03-23',
            to:                '2026-03-27',
            siteId:            null,
            deployedById:      99,
            deploymentId:      $deploymentId,
            openUncoveredIds:  $openUncoveredIds,
            sendChangeSummary: $sendChangeSummary,
            versionId:         $versionId,
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

    public function test_handler_generates_global_pdf_for_surgeons(): void
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
            'Global PDF must be generated for surgeon email'
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
        $this->assertNotEmpty($msg->extraAttachments, 'Global PDF must be attached as extra attachment');
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
                $this->em, $this->pdf, $this->notif, $this->bus,
                $this->diffService, 'noreply@test.com', 'SurgicalHub'
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
        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mgr     = $this->makeUser('mgr@test.com',     ['ROLE_MANAGER'], 99);

        $site = new Hospital();
        $site->setName('Alpha');
        (new \ReflectionProperty(Hospital::class, 'id'))->setValue($site, 10);

        // Mission 101 — in openUncoveredIds → counts toward pool notification
        $m101 = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m101, 101);

        // Mission 102 — NOT in openUncoveredIds → must not trigger a notification
        $m102 = $this->makeMission($surgeon, null, MissionStatus::OPEN);
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

        // Notification must be called once, with missionCount=1 (only mission 101)
        $this->notif->expects($this->once())
            ->method('planningNewOpenMissionsNotifySite')
            ->with(
                $this->anything(),   // site instrumentists (empty in test)
                1,                   // only mission 101, not 102
                $this->anything(),   // site name
                '2026-03-23',        // from
                '2026-03-27',        // to
            );

        $this->makeHandler()->__invoke(
            $this->makeMessage(openUncoveredIds: [101]) // 101 selected, 102 not
        );
    }

    // ── Change summary emails ─────────────────────────────────────────────────

    /**
     * When sendChangeSummary = false, diffService->diff() must never be called
     * and no changeSummary email must be dispatched.
     */
    public function test_change_summary_not_sent_when_flag_is_false(): void
    {
        $this->diffService->expects($this->never())->method('diff');

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com', ['ROLE_INSTRUMENTIST'], 2);
        $mgr     = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
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

        // sendChangeSummary = false (default)
        $this->makeHandler()->__invoke($this->makeMessage(sendChangeSummary: false));

        $summaryEmails = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && str_contains($m->htmlTemplate, 'change_summary'),
        );
        $this->assertEmpty($summaryEmails, 'No changeSummary emails when flag is false.');
    }

    /**
     * When the diff is empty (no changes vs previous version), no changeSummary email must be sent.
     */
    public function test_change_summary_not_sent_when_diff_is_empty(): void
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $version->setGeneratedBy($this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, 7);

        $this->diffService->method('diff')->willReturn(['added' => [], 'removed' => [], 'modified' => []]);

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com', ['ROLE_INSTRUMENTIST'], 2);
        $mgr     = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningVersion::class && $id === 7 => $version,
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
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

        $this->makeHandler()->__invoke($this->makeMessage(sendChangeSummary: true, versionId: 7));

        $summaryEmails = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && str_contains($m->htmlTemplate, 'change_summary'),
        );
        $this->assertEmpty($summaryEmails, 'No changeSummary email when diff is empty.');
    }

    /**
     * An affected instrumentist must receive a changeSummary email when the diff concerns them.
     */
    public function test_change_summary_email_sent_to_affected_instrumentist(): void
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $version->setGeneratedBy($this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, 7);

        // Diff: instr (id=2) has a new added mission
        $this->diffService->method('diff')->willReturn([
            'added' => [[
                'date' => '2026-03-24', 'period' => 'AM',
                'startAt' => '08:00', 'endAt' => '13:00',
                'missionType' => 'BLOCK',
                'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
                'instrumentistId' => 2, 'instrumentistName' => 'Test User',
                'siteName' => 'Alpha',
            ]],
            'removed'  => [],
            'modified' => [],
        ]);

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $instr   = $this->makeUser('instr@test.com', ['ROLE_INSTRUMENTIST'], 2);
        $mgr     = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningVersion::class && $id === 7 => $version,
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeon,
                $class === User::class && $id === 2  => $instr,
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

        $this->makeHandler()->__invoke($this->makeMessage(sendChangeSummary: true, versionId: 7));

        $summaryToInstr = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'instr@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_instrumentist.html.twig',
        );
        $this->assertCount(1, $summaryToInstr,
            'Affected instrumentist must receive exactly one changeSummary email.'
        );
    }

    /**
     * When there are uncovered pool missions, surgeons must receive a changeSummary email
     * with the global PDF attached.
     */
    public function test_change_summary_surgeon_email_has_global_pdf_when_uncovered_slots_exist(): void
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $version->setGeneratedBy($this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, 7);

        // Non-empty diff (so changeSummary is sent)
        $this->diffService->method('diff')->willReturn([
            'added' => [[
                'date' => '2026-03-24', 'period' => 'AM',
                'startAt' => '08:00', 'endAt' => '13:00',
                'missionType' => 'BLOCK',
                'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
                'instrumentistId' => null, 'instrumentistName' => null,
                'siteName' => 'Alpha',
            ]],
            'removed'  => [],
            'modified' => [],
        ]);

        $surgeon = $this->makeUser('surgeon@test.com', ['ROLE_SURGEON'], 1);
        $mgr     = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        // Pool mission (no instrumentist) with id=101
        $poolMission = $this->makeMission($surgeon, null, MissionStatus::OPEN);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($poolMission, 101);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningVersion::class && $id === 7 => $version,
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
        $q->method('getResult')->willReturn([$poolMission]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->makeHandler()->__invoke($this->makeMessage(
            sendChangeSummary: true,
            versionId: 7,
            openUncoveredIds: [101],
        ));

        $surgeonSummary = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        );
        $this->assertCount(1, $surgeonSummary,
            'Surgeon must receive exactly one changeSummary email when uncovered slots exist.'
        );

        /** @var SendBillingEmailMessage $msg */
        $msg = array_values($surgeonSummary)[0];
        $this->assertNotEmpty($msg->extraAttachments,
            'Global PDF must be attached to surgeon changeSummary email.'
        );
    }

    /**
     * REGRESSION — chaque chirurgien ne doit recevoir que SES créneaux non couverts.
     * Avant le fix, tous les chirurgiens recevaient la liste globale de tous les uncovered slots.
     *
     * Scénario : chirurgien A (id=1) a le slot 101, chirurgien B (id=3) a le slot 102.
     * Email de A → uncovered contient uniquement 101.
     * Email de B → uncovered contient uniquement 102.
     */
    public function test_change_summary_each_surgeon_receives_only_own_uncovered_slots(): void
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $version->setGeneratedBy($this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, 7);

        $this->diffService->method('diff')->willReturn([
            'added'    => [['date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                            'missionType' => 'BLOCK', 'surgeonId' => 1, 'surgeonName' => 'A',
                            'instrumentistId' => null, 'instrumentistName' => null, 'siteName' => 'Alpha']],
            'removed'  => [],
            'modified' => [],
        ]);

        $surgeonA = $this->makeUser('surgeon-a@test.com', ['ROLE_SURGEON'], 1);
        $surgeonB = $this->makeUser('surgeon-b@test.com', ['ROLE_SURGEON'], 3);
        $mgr      = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        // Uncovered pool mission 101 belongs to surgeon A
        $poolA = $this->makeMission($surgeonA, null, MissionStatus::OPEN);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($poolA, 101);

        // Uncovered pool mission 102 belongs to surgeon B
        $poolB = $this->makeMission($surgeonB, null, MissionStatus::OPEN);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($poolB, 102);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningVersion::class && $id === 7 => $version,
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeonA,
                $class === User::class && $id === 3  => $surgeonB,
                default => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$poolA, $poolB]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->makeHandler()->__invoke($this->makeMessage(
            sendChangeSummary: true,
            versionId: 7,
            openUncoveredIds: [101, 102],
        ));

        // Surgeon A must receive exactly 1 summary email
        $emailsA = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon-a@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        ));
        $this->assertCount(1, $emailsA, 'Surgeon A must receive exactly one changeSummary email.');

        // Surgeon A's email must contain only slot 101 (date = 2026-03-24)
        $uncoveredA = $emailsA[0]->context['uncovered'];
        $this->assertCount(1, $uncoveredA,
            'Surgeon A must receive only 1 uncovered slot (their own), not surgeon B\'s.'
        );
        $this->assertSame('2026-03-24', $uncoveredA[0]['date'],
            'The uncovered slot in A\'s email must be their own mission 101.'
        );

        // Surgeon B must receive exactly 1 summary email
        $emailsB = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon-b@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        ));
        $this->assertCount(1, $emailsB, 'Surgeon B must receive exactly one changeSummary email.');

        // Surgeon B's email must contain only slot 102
        $uncoveredB = $emailsB[0]->context['uncovered'];
        $this->assertCount(1, $uncoveredB,
            'Surgeon B must receive only 1 uncovered slot (their own), not surgeon A\'s.'
        );
    }

    /**
     * Un chirurgien sans créneau non couvert ne doit pas recevoir d'email summary.
     *
     * Scénario : chirurgien A (id=1) a une mission ASSIGNED (pas de poste non couvert).
     * Chirurgien B (id=3) a un poste non couvert (id=102).
     * → Seul B reçoit un email summary. A n'en reçoit pas.
     */
    public function test_change_summary_surgeon_without_uncovered_slot_gets_no_email(): void
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $version->setGeneratedBy($this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, 7);

        $this->diffService->method('diff')->willReturn([
            'added'    => [['date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                            'missionType' => 'BLOCK', 'surgeonId' => 3, 'surgeonName' => 'B',
                            'instrumentistId' => null, 'instrumentistName' => null, 'siteName' => 'Alpha']],
            'removed'  => [],
            'modified' => [],
        ]);

        $surgeonA = $this->makeUser('surgeon-a@test.com', ['ROLE_SURGEON'], 1);
        $surgeonB = $this->makeUser('surgeon-b@test.com', ['ROLE_SURGEON'], 3);
        $instr    = $this->makeUser('instr@test.com', ['ROLE_INSTRUMENTIST'], 2);
        $mgr      = $this->makeUser('mgr@test.com', ['ROLE_MANAGER'], 99);

        // Surgeon A has a covered (ASSIGNED) mission — no uncovered slot
        $assignedA = $this->makeMission($surgeonA, $instr, MissionStatus::ASSIGNED);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($assignedA, 100);

        // Surgeon B has an uncovered pool mission
        $poolB = $this->makeMission($surgeonB, null, MissionStatus::OPEN);
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($poolB, 102);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match (true) {
                $class === PlanningVersion::class && $id === 7 => $version,
                $class === User::class && $id === 99 => $mgr,
                $class === User::class && $id === 1  => $surgeonA,
                $class === User::class && $id === 2  => $instr,
                $class === User::class && $id === 3  => $surgeonB,
                default => null,
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('getResult')->willReturn([$assignedA, $poolB]);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')->willReturnCallback(function (): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn([]);
            return $q;
        });

        $this->makeHandler()->__invoke($this->makeMessage(
            sendChangeSummary: true,
            versionId: 7,
            openUncoveredIds: [102], // only surgeon B's mission
        ));

        // Surgeon A must NOT receive a summary email (no uncovered slot for them)
        $emailsA = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon-a@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        );
        $this->assertEmpty($emailsA,
            'Surgeon A has no uncovered slot — must not receive a changeSummary email.'
        );

        // Surgeon B must receive exactly 1 summary email
        $emailsB = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon-b@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        );
        $this->assertCount(1, $emailsB,
            'Surgeon B has an uncovered slot — must receive exactly one changeSummary email.'
        );
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

        $this->notif->expects($this->never())
            ->method('planningNewOpenMissionsNotifySite');

        // No IDs selected → no pool notification
        $this->makeHandler()->__invoke($this->makeMessage(openUncoveredIds: []));
    }
}
