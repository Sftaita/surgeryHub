<?php

namespace App\Dto\Request;

use App\Enum\DisputeStatus;
use Symfony\Component\Validator\Constraints as Assert;

class ServiceDisputeUpdateRequest
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [DisputeStatus::class, 'cases'])]
    public ?DisputeStatus $status = null;

    #[Assert\Length(max: 1000)]
    public ?string $resolutionComment = null;
}
