<?php

namespace App\Dto;

use App\Entity\User;
use App\Enum\EligibilityReason;

final readonly class EligibilityResult
{
    public bool $eligible;

    /** @param EligibilityReason[] $reasons */
    public function __construct(
        public User  $candidate,
        public array $reasons,
    ) {
        $this->eligible = empty($reasons);
    }
}
