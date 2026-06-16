<?php

namespace App\Controller\Api;

use App\Entity\User;

/**
 * Shared response helpers for admin controllers.
 * Keeps display-name and business-role derivation consistent across AdminUserController,
 * AdminInvitationController, and AdminAuditController.
 */
trait AdminResponseTrait
{
    private function buildDisplayName(User $u): string
    {
        $name = trim((string) ($u->getFirstname() ?? '') . ' ' . (string) ($u->getLastname() ?? ''));
        return $name !== '' ? $name : (string) $u->getEmail();
    }

    private function buildBusinessRole(User $u): string
    {
        $roles = $u->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) return 'ADMIN';
        if (in_array('ROLE_MANAGER', $roles, true)) return 'MANAGER';
        if (in_array('ROLE_SURGEON', $roles, true)) return 'SURGEON';
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) return 'INSTRUMENTIST';
        return 'UNKNOWN';
    }
}
