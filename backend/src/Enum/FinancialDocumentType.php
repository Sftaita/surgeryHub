<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §3/§5 du lot : extension du modèle
 * FirmInvoice/InstrumentistStatement existant (stratégie recommandée) plutôt qu'un
 * agrégat générique de type Settlement. Convention de signe centralisée ici :
 * les montants stockés dans les lignes restent TOUJOURS positifs — le type
 * documentaire porte seul le signe économique (§5 du lot), jamais une double
 * convention.
 */
enum FinancialDocumentType: string
{
    case STANDARD = 'STANDARD';
    case CREDIT_NOTE = 'CREDIT_NOTE';
    case DEBIT_NOTE = 'DEBIT_NOTE';

    /** +1 pour STANDARD/DEBIT_NOTE (augmente la créance/dette), -1 pour CREDIT_NOTE (la diminue). */
    public function signCoefficient(): int
    {
        return $this === self::CREDIT_NOTE ? -1 : 1;
    }

    public function isCorrection(): bool
    {
        return $this !== self::STANDARD;
    }
}
