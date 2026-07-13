<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Message\SendTemplatedEmailMessage;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\UserAuditService;
use App\Service\UserEmailChangeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Validation;

final class UserEmailChangeServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $users;
    private UserAuditService&MockObject $audit;
    private EmailService&MockObject $emailService;
    private UserEmailChangeService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(UserAuditService::class);
        $this->emailService = $this->createMock(EmailService::class);

        $this->service = new UserEmailChangeService(
            $this->em,
            $this->users,
            $this->audit,
            $this->emailService,
            Validation::createValidator(),
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
        $this->emailService->expects(self::exactly(2))->method('sendTemplatedEmail');

        $result = $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame('new@example.com', $target->getEmail());
        self::assertSame('new@example.com', $result['user']->getEmail());
        self::assertSame([], $result['warnings']);
    }

    public function testOldAndNewAddressesAreCapturedCorrectly(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $captured = [];
        $this->emailService->method('sendTemplatedEmail')
            ->willReturnCallback(function (string $to, string $subject, string $htmlTemplate, array $context = [], ?string $textTemplate = null) use (&$captured) {
                $captured[] = ['to' => $to, 'context' => $context];
            });

        $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertCount(2, $captured);
        self::assertSame('old@example.com', $captured[0]['to']);
        self::assertSame('old@example.com', $captured[0]['context']['oldEmail']);
        self::assertSame('new@example.com', $captured[0]['context']['newEmail']);
        self::assertSame('new@example.com', $captured[1]['to']);
        self::assertSame('new@example.com', $captured[1]['context']['newEmail']);
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
        $this->emailService->method('sendTemplatedEmail')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'dispatch';
        });

        $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame(['flush', 'dispatch', 'dispatch'], $callOrder);
    }

    public function testMutationIsKeptWhenOldAddressDispatchFails(): void
    {
        $actor = $this->buildActor();
        $target = $this->buildTarget('old@example.com');

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $callCount = 0;
        $this->emailService->method('sendTemplatedEmail')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RuntimeException('transport down');
            }
        });

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

        $callCount = 0;
        $this->emailService->method('sendTemplatedEmail')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 2) {
                throw new \RuntimeException('transport down');
            }
        });

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
        $this->emailService->method('sendTemplatedEmail')
            ->willThrowException(new \RuntimeException('transport down'));

        $result = $this->service->changeEmail($actor, $target, 'new@example.com');

        self::assertSame('new@example.com', $target->getEmail());
        self::assertCount(2, $result['warnings']);
    }
}
