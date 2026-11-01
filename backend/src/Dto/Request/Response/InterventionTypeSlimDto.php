<?php

namespace App\Dto\Request\Response;

final class InterventionTypeSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        /** D-178 — proposé sous « Aucune firme » (paramétrage manager). */
        public readonly bool $allowedWithoutFirm = false,
    ) {}
}
