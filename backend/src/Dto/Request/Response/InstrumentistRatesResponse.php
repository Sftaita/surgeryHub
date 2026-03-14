<?php

namespace App\Dto\Request\Response;

final readonly class InstrumentistRatesResponse
{
    public function __construct(
        public int $id,
        public ?string $hourlyRate,
        public ?string $consultationFee,
    ) {
    }
}