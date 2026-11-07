<?php

namespace App\MessageHandler;

use App\Absence\AbsenceImpact;
use App\Absence\AbsenceImpactNotifier;
use App\Message\AbsenceImpactNotificationMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** H4 (D-130) — see AbsenceImpactNotifier. Idempotent: replaying the message sends nothing new. */
#[AsMessageHandler]
final class AbsenceImpactNotificationMessageHandler
{
    public function __construct(private readonly AbsenceImpactNotifier $notifier)
    {
    }

    public function __invoke(AbsenceImpactNotificationMessage $message): void
    {
        $this->notifier->notify(
            $message->absentName,
            $message->dateStart,
            $message->dateEnd,
            array_map(static fn (array $i) => AbsenceImpact::fromArray($i), $message->impacts),
            array_map(static fn (array $i) => AbsenceImpact::fromArray($i), $message->recovered),
        );
    }
}
