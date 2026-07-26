<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** D-084 — l'historique des notifications sortantes est réservé à ROLE_ADMIN, sans exception manager. */
final class OutboundNotificationVoter extends Voter
{
    public const LIST = 'OUTBOUND_NOTIFICATION_LIST';
    public const VIEW = 'OUTBOUND_NOTIFICATION_VIEW';

    private const ADMIN_ONLY_ATTRIBUTES = [self::LIST, self::VIEW];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ADMIN_ONLY_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
