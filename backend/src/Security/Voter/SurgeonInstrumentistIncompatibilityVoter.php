<?php

namespace App\Security\Voter;

use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-152 — surgeon–instrumentist incompatibilities.
 *
 * - MANAGE      (no subject): managers / admins — consult any file, create any incompatibility.
 * - SELF_MANAGE (no subject): a surgeon — consult and create his own blocks only.
 * - LIFT        (incompatibility): managers / admins lift any active one; its surgeon lifts the
 *               ones he created himself (profile or durable refusal), never a manager's.
 *
 * The instrumentist never has any access: an incompatibility is never shown to her.
 */
final class SurgeonInstrumentistIncompatibilityVoter extends Voter
{
    public const MANAGE      = 'INCOMPATIBILITY_MANAGE';
    public const SELF_MANAGE = 'INCOMPATIBILITY_SELF_MANAGE';
    public const LIFT        = 'INCOMPATIBILITY_LIFT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MANAGE, self::SELF_MANAGE => $subject === null,
            self::LIFT => $subject instanceof SurgeonInstrumentistIncompatibility,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles     = $user->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        return match ($attribute) {
            self::MANAGE      => $isManager,
            self::SELF_MANAGE => in_array('ROLE_SURGEON', $roles, true),
            self::LIFT        => $subject->isActive() && ($isManager || (
                in_array('ROLE_SURGEON', $roles, true)
                && $subject->getSurgeon()->getId() === $user->getId()
                && $subject->getOrigin()->isSurgeonOwned()
            )),
            default => false,
        };
    }
}
