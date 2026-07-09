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
use App\Service\PlanningChangeSummaryService;
use App\Service\PlanningDiffService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
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
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->bus         = $this->createMock(MessageBusInterface::class);
        $this->diffService = $this->createMock(PlanningDiffService::class);

        $this->dispatched = [];
        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });
    }

    private function makeService(): PlanningChangeSummaryService
    {
        return new PlanningChangeSummaryService(
            $this->em, $this->bus, $this->diffService,
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
}
