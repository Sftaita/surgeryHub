<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class AdminChangeRoleRequest
{
    // ROLE_ADMIN added (Lot 7, audit PWA/mobile/admin 2026-07-29) — this endpoint is
    // already ADMIN-only (UserAdministrationVoter::CHANGE_ROLE, ADMIN_ONLY_ATTRIBUTES),
    // so allowing it here only extends what an admin can already do to other accounts;
    // UserAdministrationService::changeRole still forbids self-promotion/demotion.
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['ROLE_INSTRUMENTIST', 'ROLE_SURGEON', 'ROLE_MANAGER', 'ROLE_ADMIN'])]
    public ?string $newRole = null;
}
