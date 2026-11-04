<?php

namespace App\Dto\Request\Response;

final class InterventionTypeSlimDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        /** D-178 — autorisé sans firme par le manager (paramétrage, indépendant des rattachements). */
        public readonly bool $allowedWithoutFirm = false,
        /** D-183 — proposé sous « Aucune firme » : sans rattachement effectif à une firme, ou autorisé (D-178). */
        public readonly bool $selectableWithoutFirm = false,
    ) {}
}
