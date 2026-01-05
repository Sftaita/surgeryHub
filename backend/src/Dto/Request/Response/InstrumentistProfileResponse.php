<?php
// src/Dto/Response/InstrumentistProfileResponse.php

namespace App\Dto\Response;

final class InstrumentistProfileResponse
{
    public function __construct(
        public string $employmentType,   // EMPLOYEE | FREELANCER
        public string $defaultCurrency,  // ex. EUR
    ) {}
}
