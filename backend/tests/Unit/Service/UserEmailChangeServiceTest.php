<?php

namespace App\Tests\Unit\Service;

use App\Entity\NotificationDispatch;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Repository\UserRepository;
use App\Security\Session\DeviceSessionService;
use App\Service\UserAuditService;
use App\Tests\Support\NotificationServiceFactory;
use App\Service\UserEmailChangeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Validation;

/**
 * H5a (category C) — both notices go through NotificationDispatcher: MANDATORY email (never
 * subject to preferences), explicit address (the PREVIOUS one for the security notice), no
 * in-app, one episode per change and side. A notice that could not be queued is a warning,
 * never a rollback of the change.
 */
final class UserEmailChangeServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $users;
    private UserAuditService&MockObject $audit;
    private NotificationDispatcher&MockObject $dispatcher;
    private DeviceSessionService&MockObject $sessions;
    private UserEmailChangeService $service;
    /** @var list<NotificationIntent> */
    private array $intents = [];
    /** @var callable(int): string email delivery status for the n-th notice (1-based), or throws */
    private $emailOutcome;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(UserAuditService::class);
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->intents = [];
        $this->emailOutcome = static fn (int $n) => 'QUEUED';
        $this->dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $intent): NotificationDispatch {
            $this->intents[] = $intent;
            $status = ($this->emailOutcome)(count($this->intents));
            $dispatch = new NotificationDispatch();
            $dispatch->addDelivery((new OutboundNotification())->setChannel(OutboundNotificationChannel::EMAIL)->setStatus(OutboundNotificationStatus::from($status)));

            return $dispatch;
        });

        $this->service = new UserEmailChangeService(
            $this->em,
            $this->users,
            $this->audit,
            NotificationServiceFactory::create($this, $this->dispatcher),
            Validation::createValidator(),
            $this->sessions = $this->createMock(DeviceSessionService::class),
        );
    }

    private function buildTarget(string $email = 'old@example.com'): User
    {
        $target = new User();
        $target->setEmail($email);
        $target->setFirstname('Jean');
        $target->setLastname('Martin');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($target, 123);

        return $target;
    }

    private function buildActor(): User
    {
        $actor = new User();
        $actor->setEmail('manager@example.com');
        $actor->setRoles(['ROLE_MANAGER']);

        return $actor;
    }

    public function testChangeEmailSucceeds(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->audit->expects(self::once())->method('userEmailChanged')
            ->with($actor, $target, 'old@example.com', 'new@example.com');
        $this->em->expects(self::once())->method('flush');
        // D-063 / D-157 — the sessions opened under the former email are closed explicitly.
        $this->sessions->expects(self::once())->method('revokeAllForIdentifier')->with('old@example.com');
        $result = $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame('new@example.com', $target->getEmail());
        self::assertSame('new@example.com', $result['user']->getEmail());
        self::assertSame([], $result['warnings']);
        self::assertCount(2, $this->intents);
    }

    public function testOldAndNewAddressesAreCapturedCorrectly(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertCount(2, $this->intents);
        [$old, $new] = $this->intents;
        foreach ($this->intents as $i) {
            self::assertSame(NotificationType::ACCOUNT_EMAIL_CHANGED, $i->type);
            self::assertSame(NotificationEmailPolicy::MANDATORY, $i->emailPolicy, 'never subject to business preferences');
            self::assertFalse($i->inApp);
            self::assertNull($i->push);
            self::assertSame($target, $i->recipient);
        }
        self::assertSame('old@example.com', $old->emailAddress, 'the security notice goes to the PREVIOUS address');
        self::assertSame('old@example.com', $old->email->context['oldEmail']);
        self::assertSame('new@example.com', $old->email->context['newEmail']);
        self::assertSame('emails/user_email_changed_old_address.html.twig', $old->email->htmlTemplate);
        self::assertSame('new@example.com', $new->emailAddress);
        self::assertSame('emails/user_email_changed_new_address.html.twig', $new->email->htmlTemplate);
        self::assertStringEndsWith(':old', $old->episodeRef);
        self::assertStringEndsWith(':new', $new->episodeRef);
        self::assertSame(substr($old->episodeRef, 0, -4), substr($new->episodeRef, 0, -4), 'one change, two sides');
    }

    public function testSameEmailIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $actor = $this->buildActor();
        $target = $this->buildTarget('same@example.com');

        $this->em->expects(self::never())->method('flush');

        $this->service->changeEmail($actor, $target, 'same@example.com');
    }

    public function testSameEmailIsRejectedCaseInsensitively(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $actor = $this->buildActor();
        $target = $this->buildTarget('Same@Example.com');

        $this->service->changeEmail($actor, $target, 'same@example.com');
    }

    public function testEmptyEmailIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $actor = $this->buildActor();
        $target = $this->buildTarget();

        $this->service->changeEmail($actor, $target, '   ');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->expectException(ConflictHttpException::class);

        $actor = $this->buildActor();
        $target = $this->buildTarget();

        $otherUser = new User();
        $otherUser->setEmail('taken@example.com');
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($otherUser, 999);

        $this->users->method('findOneByEmailInsensitive')->willReturn($otherUser);
        $this->em->expects(self::never())->method('flush');

        $this->service->changeEmail($actor, $target, 'taken@example.com');
    }

    public function testInvalidEmailFormatIsRejected(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $actor = $this->buildActor();
        $target = $this->buildTarget();

        $this->em->expects(self::never())->method('flush');

        $this->service->changeEmail($actor, $target, 'not-an-email');
    }

    public function testAuditEventCreatedWithActorAndSnapshot(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->audit->expects(self::once())->method('userEmailChanged')
            ->with(
                self::identicalTo($actor),
                self::identicalTo($target),
                'old@example.com',
                'new@example.com',
            );

        $this->service->changeEmail($actor, $target, 'new@example.com');
    }

    public function testFlushHappensBeforeEmailDispatch(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $callOrder = [];
        $this->em->method('flush')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'flush';
        });
        $this->emailOutcome = function () use (&$callOrder) {
            $callOrder[] = 'dispatch';

            return 'QUEUED';
        };

        $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame(['flush', 'dispatch', 'dispatch'], $callOrder);
    }

    public function testMutationIsKeptWhenOldAddressDispatchFails(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->emailOutcome = static fn (int $n) => $n === 1 ? 'FAILED' : 'QUEUED';

        $result = $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame('new@example.com', $target->getEmail());
        self::assertCount(1, $result['warnings']);
        self::assertSame('old', $result['warnings'][0]['recipient']);
        self::assertSame('EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED', $result['warnings'][0]['code']);
    }

    public function testMutationIsKeptWhenNewAddressDispatchFails(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->emailOutcome = static fn (int $n) => $n === 2 ? throw new \RuntimeException('transport down') : 'QUEUED';

        $result = $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame('new@example.com', $target->getEmail());
        self::assertCount(1, $result['warnings']);
        self::assertSame('new', $result['warnings'][0]['recipient']);
    }

    public function testBothDispatchFailuresProduceTwoWarnings(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->emailOutcome = static fn (int $n) => 'FAILED';

        $result = $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame('new@example.com', $target->getEmail());
        self::assertCount(2, $result['warnings']);
    }
}
