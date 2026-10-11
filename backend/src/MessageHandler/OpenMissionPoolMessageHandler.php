<?php

namespace App\MessageHandler;

use App\Message\OpenMissionPoolMessage;
use App\Notification\OpenMissionPoolNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** H3 (D-129) — thin bridge: the pool is solicited by OpenMissionPoolNotifier only. */
#[AsMessageHandler]
final class OpenMissionPoolMessageHandler
{
    public function __construct(
        private readonly OpenMissionPoolNotifier $notifier,
    ) {
    }

    public function __invoke(OpenMissionPoolMessage $message): void
    {
        $episodes = $message->toEpisodes();

        if ($message->batch) {
            $this->notifier->notifyBatch($episodes);
            return;
        }

        foreach ($episodes as $episode) {
            $this->notifier->notify($episode);
        }
    }
}
