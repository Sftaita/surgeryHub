<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Exception\EncodingConcurrencyException;
use Symfony\Component\HttpFoundation\Request;

/**
 * D-154 — protection against concurrent overwrites of an encoding (instrumentist, surgeon,
 * manager). The client sends the version it read (`If-Match: "N"`, from GET .../encoding)
 * with each modification or deletion of an intervention or a material line; a different
 * current version means someone else changed it meanwhile → 409, nothing is written.
 *
 * Mandatory for the mission's surgeon (new client, D-154); optional for the instrumentist and
 * the manager only so that an older cached client keeps working — the current clients always
 * send it. The race between this check and the flush is closed by the entity version itself
 * (Doctrine #[ORM\Version] → OptimisticLockException → same 409).
 */
final class EncodingVersionGuard
{
    public static function assertCurrent(Request $request, Mission $mission, ?User $actor, int $currentVersion, string $subject): void
    {
        $header = trim((string) $request->headers->get('If-Match', ''));
        if ($header === '') {
            if ($actor !== null && self::isRequiredFor($mission, $actor)) {
                throw EncodingConcurrencyException::versionRequired();
            }
            return;
        }

        if (preg_match('/^(?:W\/)?"?(\d+)"?$/', $header, $m) !== 1) {
            throw EncodingConcurrencyException::versionRequired();
        }

        if ((int) $m[1] !== $currentVersion) {
            throw EncodingConcurrencyException::modified($subject);
        }
    }

    private static function isRequiredFor(Mission $mission, User $actor): bool
    {
        $roles = $actor->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        return !$isManager && SurgeonEncodingPolicy::isMissionSurgeon($mission, $actor);
    }
}
