<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class AddInstrumentistSiteMembershipRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $siteId = null;
}