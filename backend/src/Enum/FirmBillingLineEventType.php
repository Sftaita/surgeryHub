<?php

namespace App\Enum;

/**
 * D-134 — événements du journal append-only d'une ligne de facturation firme
 * (FirmBillingLineEvent). Seuls des faits réellement produits sont émis ; les événements
 * de brouillon le sont depuis D-135 (FirmInvoiceService : createDraft, addLinesToDraft,
 * removeLineFromDraft, moveLinesToDraft, abandonDraft).
 */
enum FirmBillingLineEventType: string
{
    // D-135 — brouillon.
    case ADDED_TO_DRAFT = 'ADDED_TO_DRAFT';
    case REMOVED_FROM_DRAFT = 'REMOVED_FROM_DRAFT';
    case MOVED_TO_DRAFT = 'MOVED_TO_DRAFT';

    // Émis par FirmInvoiceService / DocumentPaymentService.
    case INVOICE_GENERATED = 'INVOICE_GENERATED';
    case INVOICE_SENT = 'INVOICE_SENT';
    case PAYMENT_RECORDED = 'PAYMENT_RECORDED';
    case INVOICE_PAID = 'INVOICE_PAID';
    /** Facture GENERATED annulée : la ligne redevient libre (FirmInvoiceService::cancel()). */
    case INVOICE_CANCELLED = 'INVOICE_CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::ADDED_TO_DRAFT => 'Ajoutée au brouillon',
            self::REMOVED_FROM_DRAFT => 'Retirée du brouillon',
            self::MOVED_TO_DRAFT => 'Déplacée vers le brouillon',
            self::INVOICE_GENERATED => 'Facture générée',
            self::INVOICE_SENT => 'Facture envoyée',
            self::PAYMENT_RECORDED => 'Paiement enregistré',
            self::INVOICE_PAID => 'Facture payée',
            self::INVOICE_CANCELLED => 'Facture annulée — ligne de nouveau libre',
        };
    }
}
