<?php

namespace App\Dto\Request\Response;

final class InstrumentistCreateResponse
{
    /**
     * @param list<int> $siteIds
     */
    public function __construct(
        public int $id,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public string $displayName,
        public bool $active,
        public ?string $employmentType,
        public ?string $defaultCurrency,
        public array $siteIds,
        public ?string $invitationExpiresAt,
    ) {
    }
}