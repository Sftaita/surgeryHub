<?php

namespace App\Tests\Support;

use App\Notification\NotificationDispatcher;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * H5a — a real NotificationService (its email-content builders are plain functions of
 * their inputs) wired on mocks, for unit tests asserting the content handed to the dispatcher.
 */
final class NotificationServiceFactory
{
    public static function create(TestCase $test, ?NotificationDispatcher $dispatcher = null, string $frontendUrl = 'https://app.test'): NotificationService
    {
        $mock = static fn (string $class) => (new \ReflectionMethod(TestCase::class, 'createMock'))->invoke($test, $class);

        return new NotificationService(
            $mock(EntityManagerInterface::class),
            $mock(UserRepository::class),
            $mock(MessageBusInterface::class),
            $frontendUrl,
            'no-reply@surgicalhub.test',
            'SurgicalHub',
            $dispatcher ?? $mock(NotificationDispatcher::class),
        );
    }
}
