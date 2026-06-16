<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class AdminCreateUserRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    public ?string $firstname = null;

    public ?string $lastname = null;

    public ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['ROLE_INSTRUMENTIST', 'ROLE_SURGEON', 'ROLE_MANAGER'])]
    public ?string $role = null;

    /**
     * @var list<int>
     */
    #[Assert\NotNull]
    #[Assert\Count(min: 1)]
    #[Assert\All([
        new Assert\NotNull(),
        new Assert\Positive(),
    ])]
    public array $siteIds = [];
}
