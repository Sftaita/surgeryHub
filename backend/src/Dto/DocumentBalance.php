<?php

namespace App\Dto;

use App\Enum\PaymentStatus;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — §7 du lot : solde toujours calculé,
 * jamais stocké en doublon. Retourné par DocumentPaymentService::computeBalance().
 *
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §12 du lot : enrichi des corrections
 * (creditNotesAmount/debitNotesAmount/netDocumentAmount) et des remboursements
 * (refundedAmount/overpaidAmount). Formules (§12/§15 du lot) :
 *
 *   netDocumentAmount = originalGrossAmount - creditNotesAmount + debitNotesAmount
 *   remainingAmount   = netDocumentAmount - paidAmount + refundedAmount
 *   overpaidAmount    = max(0, paidAmount - refundedAmount - netDocumentAmount)
 *
 * paidAmount/refundedAmount ne comptent que les Payment du document RACINE
 * (PaymentDirection::INBOUND/OUTBOUND respectivement) — §17/§18 du lot : les
 * documents correctifs n'ont jamais de solde propre, tout est rattaché à la racine.
 */
final readonly class DocumentBalance
{
    public function __construct(
        public string $originalGrossAmount,
        public string $creditNotesAmount,
        public string $debitNotesAmount,
        public string $netDocumentAmount,
        public string $paidAmount,
        public string $refundedAmount,
        public string $remainingAmount,
        public string $overpaidAmount,
        public PaymentStatus $status,
    ) {}

    /**
     * @return array{grossAmount: string, originalGrossAmount: string, creditNotesAmount: string,
     *     debitNotesAmount: string, netDocumentAmount: string, paidAmount: string,
     *     refundedAmount: string, remainingAmount: string, overpaidAmount: string, paymentStatus: string}
     */
    public function toArray(): array
    {
        return [
            // 'grossAmount' conservé (clé Lot 5, API existante, jamais cassée) — alias
            // exact de 'originalGrossAmount' (nom littéral du §12 du Lot 6).
            'grossAmount' => $this->originalGrossAmount,
            'originalGrossAmount' => $this->originalGrossAmount,
            'creditNotesAmount' => $this->creditNotesAmount,
            'debitNotesAmount' => $this->debitNotesAmount,
            'netDocumentAmount' => $this->netDocumentAmount,
            'paidAmount' => $this->paidAmount,
            'refundedAmount' => $this->refundedAmount,
            'remainingAmount' => $this->remainingAmount,
            'overpaidAmount' => $this->overpaidAmount,
            'paymentStatus' => $this->status->value,
        ];
    }
}
