<?php

namespace App\Entity;

use App\Enum\InvoiceStatus;
use App\Enum\PaymentDocumentType;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — contrat minimal partagé par
 * FirmInvoice et InstrumentistStatement, pour que DocumentPaymentService reste unique
 * (§18 du lot : "il ne doit pas être spécifique aux factures") sans dupliquer sa
 * logique par type de document. Ne remplace pas les entités par un modèle générique —
 * elles restent deux agrégats et deux tables distinctes (même principe que le Lot 4
 * pour la coexistence FirmInvoice/InstrumentistStatement).
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
}
