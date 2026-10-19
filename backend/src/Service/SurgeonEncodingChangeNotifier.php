<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Message\SurgeonEncodingChangedMessage;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-154 — called by the intervention / material-line endpoints after a successful write: when
 * the author is the mission's surgeon (not a manager correcting, D-153), the assigned
 * instrumentist is told — grouped by the handler, never one notification per write.
 * Never blocks nor fails the write: the notification is async and failure-isolated.
 */
final class SurgeonEncodingChangeNotifier
{
    public function __construct(private readonly MessageBusInterface $bus) {}

    public function afterWrite(Mission $mission, User $actor): void
    {
        $roles = $actor->getRoles();
        if (in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
            return;
        }
        if (!SurgeonEncodingPolicy::isMissionSurgeon($mission, $actor) || $mission->getInstrumentist() === null) {
            return;
        }

        $this->bus->dispatch(new SurgeonEncodingChangedMessage((int) $mission->getId(), (int) $actor->getId(), Clock::get()->now()));
    }
}
