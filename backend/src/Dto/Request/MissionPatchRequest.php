<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class MissionPatchRequest
{
    #[Assert\AtLeastOneOf([
        new Assert\DateTime(format: \DATE_ATOM),
        new Assert\DateTime(format: 'Y-m-d\TH:i:sP'),
    ])]
    public ?string $startAt = null;

    #[Assert\AtLeastOneOf([
        new Assert\DateTime(format: \DATE_ATOM),
        new Assert\DateTime(format: 'Y-m-d\TH:i:sP'),
    ])]
    public ?string $endAt = null;

    #[Assert\Choice(choices: ['APPROXIMATE', 'EXACT'])]
    public ?string $schedulePrecision = null;

    #[Assert\Choice(choices: ['BLOCK', 'CONSULTATION'])]
    public ?string $type = null;

    #[Assert\Positive]
    public ?int $siteId = null;
}
