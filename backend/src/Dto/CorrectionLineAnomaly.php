<?php

namespace App\Dto;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — une anomalie bloquante détectée lors de
 * la validation d'une note de crédit/débit (§30 du lot : aucun document correctif
 * partiel). Miroir exact de DocumentLineSelectionAnomaly (Lot 4)/
 * FinancialCalculationAnomaly (Lot 3).
 */
final readonly class CorrectionLineAnomaly
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}

    /** @return array{code: string, message: string, context: array<string, mixed>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'context' => $this->context];
    }
}
