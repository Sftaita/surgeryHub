<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class AdminChangeRoleRequest
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['ROLE_INSTRUMENTIST', 'ROLE_SURGEON', 'ROLE_MANAGER'])]
    public ?string $newRole = null;
}
