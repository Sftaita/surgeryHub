<?php

namespace App\Enum;

/**
 * D-133 — état de facturation d'une prestation (intervention ou matériel) dans la worklist
 * « Facturation firmes ». Établi exclusivement par FirmBillingWorklistService ; le frontend
 * l'affiche tel quel et ne le recalcule jamais.
 *
 *  - BILLABLE     : ligne valorisée par un calcul approuvé (APPROVED/LOCKED), montant > 0,
 *                   pas encore rattachée à une facture ;
 *  - NOT_BILLABLE : exclusion métier normale (jamais une erreur) — voir FirmBillingReason ;
 *  - TO_REVIEW    : quelque chose empêche la détermination ou la valorisation normale ;
 *  - INVOICED     : la ligne financière est rattachée à une facture (FirmInvoiceLine).
 */
enum FirmBillingStatus: string
{
    case BILLABLE = 'BILLABLE';
    case NOT_BILLABLE = 'NOT_BILLABLE';
    case TO_REVIEW = 'TO_REVIEW';
    case INVOICED = 'INVOICED';

    public function label(): string
    {
        return match ($this) {
            self::BILLABLE => 'Facturable',
            self::NOT_BILLABLE => 'Non facturable',
            self::TO_REVIEW => 'À vérifier',
            self::INVOICED => 'Facturé',
        };
    }
}
