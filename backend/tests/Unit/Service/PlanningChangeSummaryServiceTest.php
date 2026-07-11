<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Message\SendBillingEmailMessage;
use App\Service\PdfService;
use App\Service\PlanningChangeSummaryService;
use App\Service\PlanningDiffService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * PlanningChangeSummaryService — extracted from PlanningDeployPdfsMessageHandler during
 * the deploy email policy redesign. This capability is NOT invoked during initial
 * deploy anymore (see PlanningDeployPdfsHandlerTest::test_no_change_summary_email_ever_sent_during_deploy).
 * It remains a standalone, callable service for a future trigger on already-published
 * plannings (reassignment, cancellation, etc. — no such trigger is wired up yet).
 * These tests prove the capability itself still works correctly when invoked directly.
 */
class PlanningChangeSummaryServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject     $bus;
    private PlanningDiffService&MockObject     $diffService;
    private PdfService&MockObject              $pdfService;
    private LoggerInterface&MockObject         $logger;
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->bus         = $this->createMock(MessageBusInterface::class);
        $this->diffService = $this->createMock(PlanningDiffService::class);
        $this->pdfService  = $this->createMock(PdfService::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->pdfService->method('generateFromTemplate')->willReturn('%PDF-1.4 fake pdf bytes');

        $this->dispatched = [];
        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });
    }

    private function makeService(): PlanningChangeSummaryService
    {
        return new PlanningChangeSummaryService(
            $this->em, $this->bus, $this->diffService, $this->pdfService, $this->logger,
            'noreply@test.com', 'SurgicalHub',
        );
    }

    private function makeUser(string $email, int $id): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
        return $u;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, MissionStatus $status, int $id): Mission
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
        (new \ReflectionProperty(Mission::class, 'id'))->setValue($m, $id);
        return $m;
    }

    private function makeVersion(int $id): PlanningVersion
    {
        $version = new PlanningVersion();
        $version->setPeriodStart(new \DateTimeImmutable('2026-03-23'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-03-27'));
        $version->setGeneratedBy($this->makeUser('mgr@test.com', 99));
        (new \ReflectionProperty(PlanningVersion::class, 'id'))->setValue($version, $id);
        return $version;
    }

    public function test_no_email_sent_when_diff_is_empty(): void
    {
        $version = $this->makeVersion(7);
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            default => null,
        });
        $this->diffService->method('diff')->willReturn(['added' => [], 'removed' => [], 'modified' => []]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7, missions: [], byInstrumentist: [], bySurgeon: [],
            openUncoveredIds: [], globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $this->assertEmpty($this->dispatched, 'No email must be sent when the diff is empty.');
    }

    public function test_affected_instrumentist_receives_change_summary_email(): void
    {
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);
        $instr   = $this->makeUser('instr@test.com', 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED, 50);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $instr,
            default => null,
        });
        $this->diffService->method('diff')->willReturn([
            'added' => [[
                'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                'missionType' => 'BLOCK', 'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
                'instrumentistId' => 2, 'instrumentistName' => 'Test User', 'siteName' => 'Alpha',
            ]],
            'removed' => [], 'modified' => [],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$mission],
            byInstrumentist: [2 => [$mission]],
            bySurgeon: [1 => [$mission]],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toInstr = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'instr@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_instrumentist.html.twig',
        ));
        $this->assertCount(1, $toInstr, 'Affected instrumentist must receive exactly one change-summary email.');
        $this->assertNotNull($toInstr[0]->attachmentBase64, 'The recap email must carry the instrumentist\'s own up-to-date planning PDF.');
        $this->assertSame(base64_encode('%PDF-1.4 fake pdf bytes'), $toInstr[0]->attachmentBase64);
        $this->assertStringStartsWith('planning-', $toInstr[0]->attachmentFilename);
    }

    public function test_pdf_generation_failure_is_logged_and_email_still_sent_without_attachment(): void
    {
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);
        $instr   = $this->makeUser('instr@test.com', 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED, 50);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $instr,
            default => null,
        });
        $this->pdfService = $this->createMock(PdfService::class);
        $this->pdfService->method('generateFromTemplate')->willThrowException(new \RuntimeException('dompdf choked'));

        $this->logger->expects($this->once())->method('error')->with(
            'Failed to generate personal planning PDF for change-summary notification',
            $this->callback(fn (array $c) => $c['recipientId'] === 2 && $c['recipientRole'] === 'instrumentist'),
        );

        $this->diffService->method('diff')->willReturn([
            'added' => [[
                'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                'missionType' => 'BLOCK', 'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
                'instrumentistId' => 2, 'instrumentistName' => 'Test User', 'siteName' => 'Alpha',
            ]],
            'removed' => [], 'modified' => [],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$mission],
            byInstrumentist: [2 => [$mission]],
            bySurgeon: [1 => [$mission]],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toInstr = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com',
        ));
        $this->assertCount(1, $toInstr, 'A PDF failure must not prevent the recap email itself from being sent.');
        $this->assertNull($toInstr[0]->attachmentBase64, 'No attachment when PDF generation failed.');
    }

    public function test_instrumentist_with_unrelated_open_mission_at_same_site_receives_nothing(): void
    {
        // Same bug class as test_surgeon_with_unrelated_open_mission_elsewhere_receives_nothing,
        // but on the instrumentist side: sharing a site with an unrelated pool mission must
        // never be a trigger on its own — that's the separate OPEN_MISSION_AVAILABLE flow.
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);

        $changedInstr    = $this->makeUser('changed-instr@test.com', 2);
        $changedMission  = $this->makeMission($surgeon, $changedInstr, MissionStatus::ASSIGNED, 50);

        $unrelatedInstr   = $this->makeUser('unrelated-instr@test.com', 77);
        $unrelatedMission = $this->makeMission($surgeon, $unrelatedInstr, MissionStatus::ASSIGNED, 51);
        $poolMission      = $this->makeMission($surgeon, null, MissionStatus::OPEN, 200);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $changedInstr,
            $class === User::class && $id === 77 => $unrelatedInstr,
            default => null,
        });
        // Only mission 50 (changedInstr's) is in the diff; mission 200 is a pre-existing,
        // unrelated open mission at the same site as unrelatedInstr's own mission 51.
        $this->diffService->method('diff')->willReturn([
            'added' => [], 'removed' => [],
            'modified' => [[
                'mission' => [
                    'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                    'surgeonId' => 1, 'surgeonName' => 'Jean Dupont', 'siteName' => 'Alpha',
                ],
                'changes' => [
                    'instrumentist' => ['from' => null, 'to' => ['id' => 2, 'name' => 'Changed Instr']],
                ],
            ]],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$changedMission, $unrelatedMission, $poolMission],
            byInstrumentist: [2 => [$changedMission], 77 => [$unrelatedMission]],
            bySurgeon: [1 => [$changedMission, $unrelatedMission, $poolMission]],
            openUncoveredIds: [200],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toUnrelated = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'unrelated-instr@test.com',
        );
        $this->assertEmpty($toUnrelated, 'An instrumentist with an unrelated open mission at the same site must receive nothing.');

        $toChanged = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'changed-instr@test.com',
        );
        $this->assertCount(1, $toChanged, 'The instrumentist actually assigned by the diff must still receive exactly one email.');
    }

    public function test_surgeon_with_uncovered_slot_receives_email_with_global_pdf(): void
    {
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);
        $poolMission = $this->makeMission($surgeon, null, MissionStatus::OPEN, 101);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 1 => $surgeon,
            default => null,
        });
        $this->diffService->method('diff')->willReturn([
            'added' => [[
                'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                'missionType' => 'BLOCK', 'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
                'instrumentistId' => null, 'instrumentistName' => null, 'siteName' => 'Alpha',
            ]],
            'removed' => [], 'modified' => [],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$poolMission],
            byInstrumentist: [],
            bySurgeon: [1 => [$poolMission]],
            openUncoveredIds: [101],
            globalPdf: 'fake-pdf-bytes', globalFilename: 'planning-global.pdf',
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toSurgeon = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        ));
        $this->assertCount(1, $toSurgeon);
        $this->assertNotEmpty($toSurgeon[0]->extraAttachments,
            'Global PDF must be attached to the change-summary surgeon email.'
        );
    }

    public function test_surgeon_whose_own_mission_was_reassigned_receives_email(): void
    {
        $version   = $this->makeVersion(7);
        $surgeon   = $this->makeUser('surgeon@test.com', 1);
        $newInstr  = $this->makeUser('newinstr@test.com', 3);
        $mission   = $this->makeMission($surgeon, $newInstr, MissionStatus::ASSIGNED, 60);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 1 => $surgeon,
            default => null,
        });
        $this->diffService->method('diff')->willReturn([
            'added' => [], 'removed' => [],
            'modified' => [[
                'mission' => [
                    'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                    'surgeonId' => 1, 'surgeonName' => 'Jean Dupont', 'siteName' => 'Alpha',
                ],
                'changes' => [
                    'instrumentist' => ['from' => ['id' => 2, 'name' => 'Diane Morel'], 'to' => ['id' => 3, 'name' => 'Léa Martin']],
                ],
            ]],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$mission],
            byInstrumentist: [3 => [$mission]],
            bySurgeon: [1 => [$mission]],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toSurgeon = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage
                && $m->to === 'surgeon@test.com'
                && $m->htmlTemplate === 'emails/planning_change_summary_surgeon.html.twig',
        ));
        $this->assertCount(1, $toSurgeon, 'A surgeon whose own mission was reassigned must receive exactly one recap email.');
    }

    public function test_surgeon_with_unrelated_open_mission_elsewhere_receives_nothing(): void
    {
        // Regression test: a real live run against production data showed surgeons with
        // ANY open mission anywhere in the whole PlanningVersion were emailed regardless
        // of whether the diff concerned them at all. Only a diff entry belonging to THIS
        // surgeon may trigger their email.
        $version        = $this->makeVersion(7);
        $unrelatedSurgeon = $this->makeUser('unrelated@test.com', 99);
        $unrelatedOpenMission = $this->makeMission($unrelatedSurgeon, null, MissionStatus::OPEN, 200);

        $reassignedSurgeon = $this->makeUser('reassigned-surgeon@test.com', 1);
        $instr             = $this->makeUser('instr@test.com', 2);
        $reassignedMission = $this->makeMission($reassignedSurgeon, $instr, MissionStatus::ASSIGNED, 50);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 1 => $reassignedSurgeon,
            $class === User::class && $id === 2 => $instr,
            $class === User::class && $id === 99 => $unrelatedSurgeon,
            default => null,
        });
        // Only mission 50 (reassignedSurgeon's) is actually in the diff. Mission 200
        // (unrelatedSurgeon's, OPEN) is NOT part of this diff — it was open before and after.
        $this->diffService->method('diff')->willReturn([
            'added' => [], 'removed' => [],
            'modified' => [[
                'mission' => [
                    'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                    'surgeonId' => 1, 'surgeonName' => 'Reassigned Surgeon', 'siteName' => 'Alpha',
                ],
                'changes' => [
                    'instrumentist' => ['from' => null, 'to' => ['id' => 2, 'name' => 'Test User']],
                ],
            ]],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$reassignedMission, $unrelatedOpenMission],
            byInstrumentist: [2 => [$reassignedMission]],
            bySurgeon: [1 => [$reassignedMission], 99 => [$unrelatedOpenMission]],
            openUncoveredIds: [200], // mission 200 is open, but pre-existing and unrelated to the diff
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toUnrelated = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'unrelated@test.com',
        );
        $this->assertEmpty($toUnrelated, 'A surgeon with an unrelated pre-existing open mission must receive nothing.');

        $toReassigned = array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'reassigned-surgeon@test.com',
        );
        $this->assertCount(1, $toReassigned, 'The surgeon whose mission was actually reassigned must still receive exactly one email.');
    }

    public function test_precomputed_diff_bypasses_diff_service_and_is_used_directly(): void
    {
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);
        $instr   = $this->makeUser('instr@test.com', 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED, 50);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $instr,
            default => null,
        });

        // Planning V2 Modification mode never diffs against the previous version.
        $this->diffService->expects($this->never())->method('diff');

        $precomputedDiff = [
            'added' => [[
                'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                'missionType' => 'BLOCK', 'surgeonId' => 1, 'surgeonName' => 'Jean Dupont',
                'instrumentistId' => 2, 'instrumentistName' => 'Test User', 'siteName' => 'Alpha',
            ]],
            'removed' => [], 'modified' => [],
        ];

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$mission],
            byInstrumentist: [2 => [$mission]],
            bySurgeon: [1 => [$mission]],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
            precomputedDiff: $precomputedDiff,
        );

        $toInstr = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com',
        ));
        $this->assertCount(1, $toInstr);
    }

    // ── Async dispatch failures must never disappear silently ───────────────────────────

    public function test_dispatch_failure_is_logged_with_full_context(): void
    {
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);
        $instr   = $this->makeUser('instr@test.com', 2);
        $mission = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED, 50);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $instr,
            default => null,
        });

        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willThrowException(new TransportException('Redis connection refused'));

        $this->logger->expects($this->once())->method('error')->with(
            'Failed to dispatch planning change-summary notification',
            $this->callback(function (array $context) {
                $this->assertSame(7, $context['planningVersionId']);
                $this->assertSame(2, $context['recipientId']);
                $this->assertSame('instr@test.com', $context['recipientEmail']);
                $this->assertSame('instrumentist', $context['recipientRole']);
                $this->assertSame('planning_change_summary', $context['summaryType']);
                $this->assertArrayHasKey('redeploymentBatchId', $context);
                $this->assertNotEmpty($context['redeploymentBatchId']);
                $this->assertSame(TransportException::class, $context['exceptionClass']);
                $this->assertStringContainsString('Redis connection refused', $context['exceptionMessage']);
                $this->assertArrayHasKey('exceptionTrace', $context);
                return true;
            }),
        );

        $this->diffService->method('diff')->willReturn([
            'added' => [], 'removed' => [],
            'modified' => [[
                'mission' => [
                    'date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00',
                    'surgeonId' => 1, 'surgeonName' => 'Jean Dupont', 'siteName' => 'Alpha',
                ],
                'changes' => ['instrumentist' => ['from' => null, 'to' => ['id' => 2, 'name' => 'Test User']]],
            ]],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$mission],
            byInstrumentist: [2 => [$mission]],
            bySurgeon: [],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );
    }

    public function test_one_recipient_dispatch_failure_does_not_prevent_other_recipients_from_being_notified(): void
    {
        $version = $this->makeVersion(7);
        $surgeon  = $this->makeUser('surgeon@test.com', 1);
        $failingInstr = $this->makeUser('failing-instr@test.com', 2);
        $okInstr      = $this->makeUser('ok-instr@test.com', 3);
        $missionA = $this->makeMission($surgeon, $failingInstr, MissionStatus::ASSIGNED, 50);
        $missionB = $this->makeMission($surgeon, $okInstr, MissionStatus::ASSIGNED, 51);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $failingInstr,
            $class === User::class && $id === 3 => $okInstr,
            default => null,
        });

        $sentTo = [];
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(function (SendBillingEmailMessage $m) use (&$sentTo) {
            $sentTo[] = $m->to;
            if ($m->to === 'failing-instr@test.com') {
                throw new TransportException('boom');
            }
            return new Envelope($m);
        });

        $this->diffService->method('diff')->willReturn([
            'added' => [], 'removed' => [],
            'modified' => [
                [
                    'mission' => ['date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00', 'surgeonId' => 1, 'surgeonName' => 'X', 'siteName' => 'Alpha'],
                    'changes' => ['instrumentist' => ['from' => null, 'to' => ['id' => 2, 'name' => 'Failing']]],
                ],
                [
                    'mission' => ['date' => '2026-03-25', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00', 'surgeonId' => 1, 'surgeonName' => 'X', 'siteName' => 'Alpha'],
                    'changes' => ['instrumentist' => ['from' => null, 'to' => ['id' => 3, 'name' => 'Ok']]],
                ],
            ],
        ]);

        // Failure is expected and logged, not re-thrown — the assertion is that BOTH
        // recipients were attempted despite the first one throwing.
        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$missionA, $missionB],
            byInstrumentist: [2 => [$missionA], 3 => [$missionB]],
            bySurgeon: [],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $this->assertContains('failing-instr@test.com', $sentTo, 'The failing recipient must still be attempted.');
        $this->assertContains('ok-instr@test.com', $sentTo, 'A later recipient must not be skipped because an earlier one threw.');
    }

    public function test_single_call_sends_at_most_one_email_per_recipient_even_with_multiple_diff_entries(): void
    {
        // Guards against duplicate sends within one redeploy: an instrumentist with TWO
        // modified missions in the same diff must still receive exactly one consolidated
        // email, not one per changed mission (Messenger-level retry duplication is a
        // separate, transport-level concern already covered by max_retries/failure_transport
        // in messenger.yaml — this test guards the application-level fan-out only).
        $version = $this->makeVersion(7);
        $surgeon = $this->makeUser('surgeon@test.com', 1);
        $instr   = $this->makeUser('instr@test.com', 2);
        $missionA = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED, 50);
        $missionB = $this->makeMission($surgeon, $instr, MissionStatus::ASSIGNED, 51);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => match (true) {
            $class === PlanningVersion::class && $id === 7 => $version,
            $class === User::class && $id === 2 => $instr,
            default => null,
        });

        $this->diffService->method('diff')->willReturn([
            'added' => [], 'removed' => [],
            'modified' => [
                [
                    'mission' => ['date' => '2026-03-24', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00', 'surgeonId' => 1, 'surgeonName' => 'X', 'siteName' => 'Alpha'],
                    'changes' => ['instrumentist' => ['from' => null, 'to' => ['id' => 2, 'name' => 'Test']]],
                ],
                [
                    'mission' => ['date' => '2026-03-25', 'period' => 'AM', 'startAt' => '08:00', 'endAt' => '13:00', 'surgeonId' => 1, 'surgeonName' => 'X', 'siteName' => 'Alpha'],
                    'changes' => ['instrumentist' => ['from' => null, 'to' => ['id' => 2, 'name' => 'Test']]],
                ],
            ],
        ]);

        $this->makeService()->sendChangeSummaryEmails(
            versionId: 7,
            missions: [$missionA, $missionB],
            byInstrumentist: [2 => [$missionA, $missionB]],
            bySurgeon: [],
            openUncoveredIds: [],
            globalPdf: null, globalFilename: null,
            fromDate: new \DateTimeImmutable('2026-03-23'), toDate: new \DateTimeImmutable('2026-03-27'),
        );

        $toInstr = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com',
        ));
        $this->assertCount(1, $toInstr, 'Two modified missions for the same instrumentist must still produce exactly one email.');
        $this->assertCount(2, $toInstr[0]->context['modified'], 'Both changes must be inside that single email though.');
    }
}
