<?php

namespace App\Dto;

use App\Enum\PaymentStatus;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — §7 du lot : solde toujours calculé,
 * jamais stocké en doublon. Retourné par DocumentPaymentService::computeBalance().
 */
final readonly class DocumentBalance
{
    public function __construct(
        public string $grossAmount,
        public string $paidAmount,
        public string $remainingAmount,
        public PaymentStatus $status,
    ) {}

    /** @return array{grossAmount: string, paidAmount: string, remainingAmount: string, paymentStatus: string} */
    public function toArray(): array
    {
        return [
            'grossAmount' => $this->grossAmount,
            'paidAmount' => $this->paidAmount,
            'remainingAmount' => $this->remainingAmount,
            'paymentStatus' => $this->status->value,
        ];
    }
}
