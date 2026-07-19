<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §5/§7/§10 du lot : un agrégat monétaire par
 * devise, jamais de total artificiel entre devises différentes. Tous les montants sont
 * des chaînes décimales exactes (jamais float).
 *
 * §10 — generatedContributionMargin = generatedFirmRevenue - generatedInstrumentistCompensation.
 * Ce n'est PAS un bénéfice net : elle n'intègre ni charges sociales, ni TVA, ni coûts
 * administratifs, ni matériel non valorisé, ni impôts, ni frais généraux (voir D-077).
 *
 * §20 — paymentsIn/paymentsOut représentent le flux de trésorerie RÉEL de
 * l'entreprise, jamais une lecture brute de Payment.direction : un paiement
 * INBOUND sur un FirmInvoice est un encaissement réel ; un paiement INBOUND sur un
 * InstrumentistStatement est un décaissement réel (l'entreprise paie l'instrumentiste)
 * bien que la colonne porte la même valeur "INBOUND" (elle signifie "règle le
 * document", pas "argent entrant en caisse" — voir D-077 pour la convention complète).
 */
final readonly class FinancialOverviewCurrencyDto
{
    public function __construct(
        public string $currency,
        public string $generatedFirmRevenue,
        public string $generatedInstrumentistCompensation,
        public string $generatedTotalValue,
        public string $generatedContributionMargin,
        public string $invoicedGrossAmount,
        public string $invoiceCreditNotesAmount,
        public string $invoiceDebitNotesAmount,
        public string $invoicedNetAmount,
        public string $statementGrossAmount,
        public string $statementCreditNotesAmount,
        public string $statementDebitNotesAmount,
        public string $statementNetAmount,
        public string $paymentsIn,
        public string $paymentsOut,
        public string $netCashFlow,
        public string $openFirmBalance,
        public string $openInstrumentistBalance,
        public string $averageMissionValue,
    ) {}
}
