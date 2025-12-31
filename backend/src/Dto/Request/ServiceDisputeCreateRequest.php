<?php

namespace App\Dto\Request;

use App\Enum\DisputeReasonCode;
use Symfony\Component\Validator\Constraints as Assert;

class ServiceDisputeCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [DisputeReasonCode::class, 'cases'])]
    public ?DisputeReasonCode $reasonCode = null;

    #[Assert\Length(max: 1000)]
    public ?string $comment = null;
}
