<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class InvitationCompleteRequest
{
    #[Assert\NotBlank]
    public string $token;

    #[Assert\NotBlank]
    public string $firstname;

    #[Assert\NotBlank]
    public string $lastname;

    #[Assert\NotBlank]
    public string $phone;

    public ?string $companyName = null;

    public ?string $vatNumber = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8)]
    public string $password;

    #[Assert\NotBlank]
    public string $confirmPassword;
}