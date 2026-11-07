<?php

namespace App\MessageHandler;

use App\Message\ReleasedRoomSlotsCreatedMessage;
use App\Notification\ReleasedRoomAvailabilityNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** D-161 — thin bridge: rooms are announced by ReleasedRoomAvailabilityNotifier only. */
#[AsMessageHandler]
final class ReleasedRoomSlotsCreatedMessageHandler
{
    public function __construct(
        private readonly ReleasedRoomAvailabilityNotifier $notifier,
    ) {
    }

    public function __invoke(ReleasedRoomSlotsCreatedMessage $message): void
    {
        $this->notifier->announce($message->slotIds);
    }
}
