<?php

namespace App\Entity;

use App\Enum\FinancialDocumentType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentDocumentType;
use Doctrine\Common\Collections\Collection;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — contrat minimal partagé par
 * FirmInvoice et InstrumentistStatement, pour que DocumentPaymentService reste unique
 * (§18 du lot : "il ne doit pas être spécifique aux factures") sans dupliquer sa
 * logique par type de document. Ne remplace pas les entités par un modèle générique —
 * elles restent deux agrégats et deux tables distinctes (même principe que le Lot 4
 * pour la coexistence FirmInvoice/InstrumentistStatement).
 *
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — étendu avec documentType/
 * correctsDocument/getLines() pour que DocumentPaymentService::computeBalance()
 * reste lui aussi unique et corrections-aware sans dupliquer sa logique (§12/§18).
 */
interface PayableDocument
{
    public function getId(): ?int;

    public function getStatus(): InvoiceStatus;

    public function setStatus(InvoiceStatus $status): static;

    public function getCurrency(): string;

    /** Montant BRUT du document — jamais modifié par un paiement (§6/§7 du lot). */
    public function getTotalAmount(): string;

    /** Discriminant Payment.documentType — chaque entité déclare le sien, jamais deviné par le service. */
    public function getPaymentDocumentType(): PaymentDocumentType;

    /** STANDARD | CREDIT_NOTE | DEBIT_NOTE (Lot 6). */
    public function getDocumentType(): FinancialDocumentType;

    /** NULL pour un document STANDARD ; le document STANDARD racine pour une correction (Lot 6). */
    public function getCorrectsDocument(): ?self;

    /** @return Collection<int, FirmInvoiceLine|InstrumentistStatementLine> */
    public function getLines(): Collection;
}
