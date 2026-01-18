<?php

namespace App\Dto\Request\Response;

final class InstrumentistWithRatesListItemResponse
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public bool $active,
        public ?string $employmentType,
        public ?string $defaultCurrency,
        public ?string $hourlyRate,
        public ?string $consultationFee,
        public string $displayName,
    ) {
    }
}
