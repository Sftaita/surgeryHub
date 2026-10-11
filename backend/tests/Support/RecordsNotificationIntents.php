<?php

namespace App\Tests\Support;

use App\Entity\NotificationDispatch;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * H5a — for unit tests of the business side of a notification: WHO is told WHAT, on which
 * episode, with which email policy and content. Channels, preferences, delivery records and
 * idempotency are NotificationDispatcher's (NotificationDispatcherIntegrationTest,
 * H5aEmailDispatchIntegrationTest) — never re-tested through a handler.
 */
trait RecordsNotificationIntents
{
    /** @var list<NotificationIntent> */
    private array $intents = [];

    private function recordingDispatcher(): NotificationDispatcher&MockObject
    {
        $this->intents = [];
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $intent): NotificationDispatch {
            $this->intents[] = $intent;

            return new NotificationDispatch();
        });

        return $dispatcher;
    }

    /** @return list<NotificationIntent> */
    private function intentsTo(int $userId): array
    {
        return array_values(array_filter($this->intents, static fn (NotificationIntent $i) => $i->recipient->getId() === $userId));
    }
}
