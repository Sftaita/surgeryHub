<?php
// src/Service/MissionEncodingGuard.php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MissionEncodingGuard
{
    public function assertEncodingAllowed(Mission $mission, ?User $actor): void
    {
        // 🔒 REJECTED = statut terminal figé
        if ($mission->getStatus() === MissionStatus::REJECTED) {
            throw new AccessDeniedHttpException('Encoding is not allowed for a rejected mission.');
        }

        // 🔒 Hard lock: une fois verrouillé (manager) ou facture générée, personne ne peut modifier.
        if ($mission->getEncodingLockedAt() !== null || $mission->getInvoiceGeneratedAt() !== null) {
            throw new AccessDeniedHttpException('Encoding is locked for this mission.');
        }

        if (!$actor instanceof User) {
            throw new ConflictHttpException('Encoding is not allowed before mission start');
        }

        $roles = $actor->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        // Managers/admins can encode/support anytime (except hard lock above).
        if ($isManager) {
            return;
        }

        // Instrumentists and (D-154) surgeons are constrained by the "not before startAt" rule.
        if (!in_array('ROLE_INSTRUMENTIST', $roles, true) && !in_array('ROLE_SURGEON', $roles, true)) {
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