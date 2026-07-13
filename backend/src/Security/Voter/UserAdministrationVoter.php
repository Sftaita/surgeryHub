<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class UserAdministrationVoter extends Voter
{
    public const LIST               = 'USER_LIST';
    public const VIEW               = 'USER_VIEW';
    public const UPDATE             = 'USER_UPDATE';
    public const CREATE             = 'USER_CREATE';
    public const SUSPEND            = 'USER_SUSPEND';
    public const ACTIVATE           = 'USER_ACTIVATE';
    public const CHANGE_ROLE        = 'USER_CHANGE_ROLE';
    public const RESEND_INVITATION  = 'USER_RESEND_INVITATION';
    public const ADD_SITE           = 'USER_ADD_SITE_MEMBERSHIP';
    public const REMOVE_SITE        = 'USER_REMOVE_SITE_MEMBERSHIP';
    public const UPDATE_EMAIL       = 'USER_UPDATE_EMAIL';

    /** Attributes gated to ROLE_ADMIN only — the /api/admin/users surface. */
    private const ADMIN_ONLY_ATTRIBUTES = [
        self::LIST,
        self::VIEW,
        self::UPDATE,
        self::CREATE,
        self::SUSPEND,
        self::ACTIVATE,
        self::CHANGE_ROLE,
        self::RESEND_INVITATION,
        self::ADD_SITE,
        self::REMOVE_SITE,
    ];

    /** Attributes open to MANAGER or ADMIN — the manager-facing instrumentist/surgeon drawers. */
    private const MANAGER_OR_ADMIN_ATTRIBUTES = [
        self::UPDATE_EMAIL,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ADMIN_ONLY_ATTRIBUTES, true)
            || in_array($attribute, self::MANAGER_OR_ADMIN_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();

        if (in_array($attribute, self::MANAGER_OR_ADMIN_ATTRIBUTES, true)) {
            return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
        }

        return in_array('ROLE_ADMIN', $roles, true);
    }
}
