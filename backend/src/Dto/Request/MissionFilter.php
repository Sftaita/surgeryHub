<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionFilter
{
    #[Assert\Date]
    public ?string $periodStart = null;

    #[Assert\Date]
    public ?string $periodEnd = null;

    #[Assert\Positive]
    public ?int $siteId = null;

    #[Assert\Type('string')]
    public ?string $status = null;

    #[Assert\Type('string')]
    public ?string $type = null;

    #[Assert\Type('boolean')]
    public ?bool $assignedToMe = null;

    #[Assert\Positive]
    public ?int $page = null;

    #[Assert\Positive]
    public ?int $limit = null;
}
