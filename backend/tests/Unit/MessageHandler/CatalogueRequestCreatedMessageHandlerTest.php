<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\CatalogueRequestCreatedMessage;
use App\MessageHandler\CatalogueRequestCreatedMessageHandler;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Repository\UserRepository;
use App\Service\NotificationTargetResolver;
use App\Tests\Support\NotificationServiceFactory;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Follow-up to D-093 / D-113 — every active manager/admin learns that a catalogue proposal
 * awaits them. H5a: one dispatcher intent per manager, email an independent channel
 * (WHEN_ENABLED, never a push fallback), failure isolated per manager.
 */
final class CatalogueRequestCreatedMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $users;
    private LoggerInterface&MockObject $logger;
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->users  = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function handler(?NotificationDispatcher $dispatcher = null): CatalogueRequestCreatedMessageHandler
    {
        return new CatalogueRequestCreatedMessageHandler(
            $this->em, $this->users, $dispatcher ?? $this->recordingDispatcher(), new NotificationTargetResolver(),
            NotificationServiceFactory::create($this), $this->logger,
        );
    }

    private function id(object $e): object
    {
        (new \ReflectionProperty($e, 'id'))->setValue($e, self::$nextId++);

        return $e;
    }

    private function manager(): User
    {
        return $this->id((new User())->setEmail('mgr' . self::$nextId . '@test.com')->setFirstname('Max')->setRoles(['ROLE_MANAGER']));
    }

    private function mission(): Mission
    {
        $instr = $this->id((new User())->setEmail('i@test.com')->setFirstname('Jane')->setLastname('Doe')->setRoles(['ROLE_INSTRUMENTIST']));
        $m = $this->id((new Mission())->setStatus(MissionStatus::ASSIGNED)->setType(MissionType::BLOCK)
            ->setSite($this->id((new Hospital())->setName('Site Test')))->setInstrumentist($instr)
            ->setStartAt(new \DateTimeImmutable('2026-08-10 08:00'))->setEndAt(new \DateTimeImmutable('2026-08-10 10:00')));
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $class === Mission::class && $id === $m->getId() ? $m : null);

        return $m;
    }

    private function message(Mission $m): CatalogueRequestCreatedMessage
    {
        return new CatalogueRequestCreatedMessage(CatalogueRequestKind::MATERIAL_ITEM, 55, $m->getId(), 'Pince X', new \DateTimeImmutable());
    }

    public function test_one_intent_per_active_manager_with_an_independent_email(): void
    {
        $m = $this->mission();
        [$a, $b] = [$this->manager(), $this->manager()];
        $this->users->method('findOperationalManagers')->willReturn([$a, $b]);

        $this->handler()($this->message($m));

        self::assertSame([$a, $b], array_map(static fn (NotificationIntent $i) => $i->recipient, $this->intents));
        $intent = $this->intents[0];
        self::assertSame(NotificationType::CATALOGUE_REQUEST_CREATED, $intent->type);
        self::assertSame(NotificationEmailPolicy::WHEN_ENABLED, $intent->emailPolicy, 'D-113: an independent channel, not a push fallback');
        self::assertSame('catalogue_request_created:MATERIAL_ITEM:55', $intent->episodeRef);
        self::assertSame('emails/catalogue_request_created.html.twig', $intent->email->htmlTemplate);
        self::assertSame('Jane Doe', $intent->email->context['requesterName']);
        self::assertStringContainsString('requestId=55', $intent->email->context['requestsUrl']);
        self::assertSame('Nouvelle proposition de matériel', $intent->push->title);
        self::assertSame(['missionId' => $m->getId(), 'requestId' => 55, 'label' => 'Pince X', 'kind' => 'MATERIAL_ITEM'], $intent->inAppPayload);
    }

    public function test_one_manager_failing_does_not_block_the_others(): void
    {
        $m = $this->mission();
        [$a, $b] = [$this->manager(), $this->manager()];
        $this->users->method('findOperationalManagers')->willReturn([$a, $b]);
        $reached = [];
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $i) use ($a, &$reached) {
            if ($i->recipient === $a) {
                throw new \RuntimeException('boom');
            }
            $reached[] = $i->recipient;

            return new NotificationDispatch();
        });
        $this->logger->expects($this->once())->method('error');

        $this->handler($dispatcher)($this->message($m));

        self::assertSame([$b], $reached);
    }

    public function test_the_email_carries_no_patient_data(): void
    {
        $m = $this->mission();
        $this->users->method('findOperationalManagers')->willReturn([$this->manager()]);
        $this->handler()($this->message($m));

        self::assertSame(
            ['firstname', 'requesterName', 'label', 'kindLabel', 'siteName', 'requestsUrl', 'missionUrl', 'notificationPreferencesUrl'],
            array_keys($this->intents[0]->email->context),
        );
    }

    public function test_unknown_mission_dispatches_nothing(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->users->expects($this->never())->method('findOperationalManagers');
        $this->handler()(new CatalogueRequestCreatedMessage(CatalogueRequestKind::MATERIAL_ITEM, 1, 999, 'x', new \DateTimeImmutable()));

        self::assertSame([], $this->intents);
    }
}
