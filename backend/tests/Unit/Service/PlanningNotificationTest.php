<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PublicationChannel;
use App\Enum\SchedulePrecision;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests NotificationService planning methods.
 */
class PlanningNotificationTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationService $service;

    private array $persisted = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(
            function (object $e): void { $this->persisted[] = $e; }
        );
        $this->em->method('flush');

        $this->service = new NotificationService(
            $this->em,
            $this->createMock(UserRepository::class),
            $this->createMock(EmailService::class),
            $this->createMock(MessageBusInterface::class),
            'http://localhost:5173',
            'noreply@test.com',
            'SurgicalHub',
        );
    }

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        return $u;
    }

    private function makeMission(MissionStatus $status, User $surgeon, ?User $instrumentist, ?Hospital $site = null): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable('2026-03-24 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-03-24 13:00:00'));
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setInstrumentist($instrumentist);

        $s = $site ?? new Hospital();
        if ($site === null) $s->setName('Alpha');
        $m->setSite($s);

        return $m;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_assigned_mission_creates_notification_for_instrumentist(): void
    {
        $surgeon      = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $mission      = $this->makeMission(MissionStatus::ASSIGNED, $surgeon, $instrumentist);

        $this->service->planningMissionAssignedNotifyInstrumentist($mission);

        $notifications = array_filter($this->persisted, fn ($e) => $e instanceof NotificationEvent);
        $this->assertCount(1, $notifications, 'One notification must be created for the instrumentist');

        /** @var NotificationEvent $notif */
        $notif = array_values($notifications)[0];
        $this->assertSame($instrumentist, $notif->getUser());
        $this->assertSame('PLANNING_MISSION_ASSIGNED', $notif->getEventType());
        $this->assertSame(PublicationChannel::IN_APP, $notif->getChannel());
    }

    public function test_assigned_mission_with_no_instrumentist_creates_no_notification(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission(MissionStatus::DRAFT, $surgeon, null);

        $this->service->planningMissionAssignedNotifyInstrumentist($mission);

        $notifications = array_filter($this->persisted, fn ($e) => $e instanceof NotificationEvent);
        $this->assertCount(0, $notifications);
    }

    public function test_open_missions_pool_notifies_each_site_instrumentist(): void
    {
        $inst1 = $this->makeUser('inst1@test.com');
        $inst2 = $this->makeUser('inst2@test.com');
        $inst3 = $this->makeUser('inst3@test.com');

        $this->service->planningNewOpenMissionsNotifySite([$inst1, $inst2, $inst3], 5, 'Delta', '2026-03-23', '2026-03-27');

        $notifications = array_filter($this->persisted, fn ($e) => $e instanceof NotificationEvent);
        $this->assertCount(3, $notifications, 'One pool notification per instrumentist');

        foreach ($notifications as $notif) {
            $this->assertSame('PLANNING_OPEN_MISSIONS_AVAILABLE', $notif->getEventType());
            $this->assertSame(5,          $notif->getPayload()['missionCount']);
            $this->assertSame('Delta',    $notif->getPayload()['siteName']);
            $this->assertSame('2026-03-23', $notif->getPayload()['periodFrom']);
            $this->assertSame('2026-03-27', $notif->getPayload()['periodTo']);
        }
    }

    public function test_manager_deploy_notification_created(): void
    {
        $manager = $this->makeUser('manager@test.com');
        $manager->setRoles(['ROLE_MANAGER']);

        $this->service->planningDeployedNotifyManager($manager, 12, '2026-03-23', '2026-03-27');

        $notifications = array_filter($this->persisted, fn ($e) => $e instanceof NotificationEvent);
        $this->assertCount(1, $notifications);

        /** @var NotificationEvent $notif */
        $notif = array_values($notifications)[0];
        $this->assertSame($manager, $notif->getUser());
        $this->assertSame('PLANNING_DEPLOYED', $notif->getEventType());
        $this->assertSame(12, $notif->getPayload()['missionCount']);
    }

    public function test_empty_site_instrumentists_creates_no_notifications(): void
    {
        $this->service->planningNewOpenMissionsNotifySite([], 3, 'Alpha', '2026-03-23', '2026-03-27');

        $notifications = array_filter($this->persisted, fn ($e) => $e instanceof NotificationEvent);
        $this->assertCount(0, $notifications);
    }
}
