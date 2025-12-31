<?php

namespace App\Dto\Request;

use App\Enum\PublicationScope;
use Symfony\Component\Validator\Constraints as Assert;

class MissionPublishRequest
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [PublicationScope::class, 'cases'])]
    public ?PublicationScope $scope = null;

    #[Assert\Positive]
    public ?int $targetUserId = null;
}
