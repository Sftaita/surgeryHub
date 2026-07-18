<?php

namespace App\Dto;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — une anomalie bloquante détectée lors de
 * la (re)vérification, sous verrou, des lignes sélectionnées pour un document financier
 * (§28 du lot). Miroir exact de FinancialCalculationAnomaly (Lot 3) : $code est stable,
 * $context porte l'identification précise de la ligne en cause.
 */
final readonly class DocumentLineSelectionAnomaly
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
