<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Notification\NotificationDispatcher;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * H5b (D-132, §27) — the three mission-declaration notices were NotificationEvent rows created
 * directly, invisible to the history. They are now NotificationIntents handed to the
 * dispatcher (in-app per preferences, traced, one episode per type and mission), with the
 * same recipients as before: every active manager / admin for a declaration, the
 * instrumentist for its approval or rejection.
 */
final class MissionDeclarationNoticeTest extends TestCase
{
    use RecordsNotificationIntents;

    private static int $nextId = 9100;

    public function test_a_declaration_tells_every_manager_and_admin_through_the_dispatcher(): void
    {
        $managers = [$this->user('ROLE_MANAGER'), $this->user('ROLE_ADMIN')];
        $mission  = $this->mission($this->user('ROLE_INSTRUMENTIST'));

        $this->service($managers)->missionDeclaredNotifyManagersAdmins($mission);

        self::assertCount(2, $this->intents);
        foreach ($managers as $i => $manager) {
            $intent = $this->intents[$i];
            self::assertSame($manager, $intent->recipient);
            self::assertSame(NotificationType::MISSION_DECLARED, $intent->type);
            self::assertSame($mission, $intent->mission);
            self::assertSame('declaration:MISSION_DECLARED:' . $mission->getId(), $intent->episodeRef);
            self::assertSame(['missionId' => $mission->getId(), 'siteId' => $mission->getSite()->getId(), 'status' => 'DECLARED'], $intent->inAppPayload);
            self::assertNull($intent->email, 'no email content: in-app (and push per preferences) only');
        }
    }

    public function test_approval_and_rejection_tell_the_instrumentist_only(): void
    {
        $instrumentist = $this->user('ROLE_INSTRUMENTIST');
        $mission       = $this->mission($instrumentist);
        $service       = $this->service([]);

        $service->missionDeclaredApprovedNotifyInstrumentist($mission);
        $service->missionDeclaredRejectedNotifyInstrumentist($mission);

        self::assertSame([NotificationType::MISSION_DECLARED_APPROVED, NotificationType::MISSION_DECLARED_REJECTED], array_map(static fn ($i) => $i->type, $this->intents));
        self::assertSame([$instrumentist, $instrumentist], array_map(static fn ($i) => $i->recipient, $this->intents));
    }

    public function test_no_instrumentist_no_notice(): void
    {
        $mission = $this->mission(null);
        $this->service([])->missionDeclaredApprovedNotifyInstrumentist($mission);

        self::assertSame([], $this->intents);
    }

    /** @param list<User> $managers */
    private function service(array $managers): NotificationService
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findManagersAndAdmins')->with(true)->willReturn($managers);

        return new NotificationService($this->createMock(EntityManagerInterface::class), $users, $this->createMock(MessageBusInterface::class),
            'https://app.test', 'no-reply@surgicalhub.test', 'SurgicalHub', $this->recordingDispatcher());
    }

    private function user(string $role): User
    {
        $u = (new User())->setEmail(uniqid('u', true) . '@surgicalhub.test')->setRoles([$role])->setActive(true);
        (new \ReflectionProperty($u, 'id'))->setValue($u, self::$nextId++);

        return $u;
    }

    private function mission(?User $instrumentist): Mission
    {
        $site = (new Hospital())->setName('Delta');
        (new \ReflectionProperty($site, 'id'))->setValue($site, self::$nextId++);
        $m = (new Mission())->setType(MissionType::BLOCK)->setStatus(MissionStatus::DECLARED)->setSite($site);
        $m->setInstrumentist($instrumentist);
        (new \ReflectionProperty($m, 'id'))->setValue($m, self::$nextId++);

        return $m;
    }
}
