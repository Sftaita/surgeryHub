<?php
// src/Service/MissionEncodingGuard.php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MissionEncodingGuard
{
    public function assertEncodingAllowed(Mission $mission, ?User $actor): void
    {
        if (!$actor instanceof User) {
            throw new ConflictHttpException('Encoding is not allowed before mission start');
        }

        $roles = $actor->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        // Managers/admins can encode/support anytime.
        if ($isManager) {
            return;
        }

        // Only instrumentists are constrained by the "not before startAt" rule.
        if (!in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return;
        }

        $startAt = $mission->getStartAt();
        if ($startAt === null) {
            throw new ConflictHttpException('Encoding is not allowed before mission start');
        }

        $utc = new \DateTimeZone('UTC');
        $nowUtc = new \DateTimeImmutable('now', $utc);
        $startUtc = $startAt->setTimezone($utc);

        if ($nowUtc < $startUtc) {
            throw new ConflictHttpException('Encoding is not allowed before mission start');
        }
    }
}
