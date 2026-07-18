<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — machine à états de FinancialCalculation.
 *
 * Volontairement SANS statut DRAFT : ce moteur résout tous les tarifs, détecte toutes
 * les anomalies, puis persiste atomiquement — soit un calcul CALCULATED complet, soit
 * rien du tout (aucune version partielle, voir FinancialCalculationService). Un DRAFT
 * persisté n'aurait donc jamais de transition réelle observable — introduire un statut
 * sans comportement va à l'encontre de la consigne du lot ("évite d'introduire des
 * statuts sans comportement réel").
 *
 *   CALCULATED ──approve()──▶ APPROVED ──lock()──▶ LOCKED (terminal, jamais superseded)
 *        │                        │
 *        │◄──recalculate()───────┤   (l'un des deux, jamais LOCKED) : ancien → SUPERSEDED, nouveau → CALCULATED
 *        │                        │
 *        └──────cancel()─────────┴──▶ CANCELLED (terminal)
 */
enum FinancialCalculationStatus: string
{
    case CALCULATED = 'CALCULATED';
    case APPROVED = 'APPROVED';
    case LOCKED = 'LOCKED';
    case SUPERSEDED = 'SUPERSEDED';
    case CANCELLED = 'CANCELLED';
}
