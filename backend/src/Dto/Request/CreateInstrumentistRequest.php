<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class CreateInstrumentistRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    public ?string $firstname = null;

    public ?string $lastname = null;

    public ?string $phone = null;

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