<?php

namespace App\Dto;

use App\Enum\CorrectionReasonCode;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — entrée validée par le contrôleur avant
 * d'atteindre FinancialCorrectionService (§20 du lot : "les contrôleurs ne construisent
 * jamais directement les lignes" — ce DTO porte l'intention du manager, le service
 * construit seul l'entité ligne réelle).
 */
final readonly class CorrectionLineInput
{
    public function __construct(
        public ?int $originalDocumentLineId,
        public CorrectionReasonCode $reasonCode,
        public string $description,
        public string $quantity,
        public string $unitAmount,
        public ?string $comment = null,
        public ?int $missionId = null,
        public ?int $financialCalculationLineId = null,
    ) {}
}
