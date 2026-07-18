<?php

namespace App\Dto;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — une anomalie bloquante détectée
 * pendant la résolution des tarifs (§14 du lot). $code est stable (jamais un message
 * libre à parser côté client), $context porte l'identification précise de l'élément en
 * cause (interventionId, materialLineId, etc.).
 */
final readonly class FinancialCalculationAnomaly
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
