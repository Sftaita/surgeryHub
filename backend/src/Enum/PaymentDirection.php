<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §13 du lot : extension du modèle
 * Payment (Lot 5) pour représenter le sens du mouvement, sans jamais modifier ni
 * supprimer un Payment existant. INBOUND = paiement reçu (le seul cas du Lot 5,
 * backfillé sur tous les Payment existants) ; OUTBOUND = remboursement (nouveau,
 * Lot 6). Le montant reste toujours positif — la direction porte le sens, jamais un
 * montant signé.
 */
enum PaymentDirection: string
{
    case INBOUND = 'INBOUND';
    case OUTBOUND = 'OUTBOUND';
}
