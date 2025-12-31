<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ExportSurgeonActivityRequest
{
    #[Assert\NotNull]
    #[Assert\Date]
    public ?string $periodStart = null;

    #[Assert\NotNull]
    #[Assert\Date]
    public ?string $periodEnd = null;

    /**
     * @var list<int>|null
     */
    #[Assert\All([new Assert\Positive()])]
    public ?array $siteIds = null;

    #[Assert\Type('string')]
    public ?string $status = null;

    #[Assert\Type('string')]
    public ?string $type = null;
}
