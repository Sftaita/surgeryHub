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

    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,14}$/',
        message: 'Phone must be in E.164 format (e.g. +32490123456)',
    )]
    public ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['ROLE_INSTRUMENTIST', 'ROLE_SURGEON', 'ROLE_MANAGER'])]
    public ?string $role = null;

    /**
     * Whether at least one site is required depends on the role — INSTRUMENTIST and
     * SURGEON must have one, MANAGER (and ADMIN, if ever creatable here) do not. That
     * role-dependent check lives in UserAdministrationService::createUser(), not here,
     * since a static per-field constraint can't see the value of another field.
     *
     * @var list<int>
     */
    #[Assert\NotNull]
    #[Assert\All([
        new Assert\NotNull(),
        new Assert\Positive(),
    ])]
    public array $siteIds = [];
}
