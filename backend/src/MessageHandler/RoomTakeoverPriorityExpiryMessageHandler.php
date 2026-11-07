<?php

namespace App\MessageHandler;

use App\Message\RoomTakeoverPriorityExpiryMessage;
use App\Service\RoomTakeoverPriorityService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-177 — expires a room take-over priority at its deadline. Idempotent: a retry, a second
 * worker or the sweep command having already expired it is a no-op. A message delivered
 * before the deadline (clock drift) is re-scheduled, never applied early.
 */
#[AsMessageHandler]
final class RoomTakeoverPriorityExpiryMessageHandler
{
    public function __construct(
        private readonly RoomTakeoverPriorityService $priorities,
    ) {
    }

    public function __invoke(RoomTakeoverPriorityExpiryMessage $message): void
    {
        if (!$this->priorities->expire($message->publicationId)) {
            $this->priorities->rescheduleIfNotDue($message->publicationId);
        }
    }
}
