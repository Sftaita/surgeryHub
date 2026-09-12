<?php

namespace App\Dto;

/**
 * Suivi des encodages (D-118) — faits financiers bruts d'une mission, lus sans jamais
 * rejouer le moteur de tarification (PricingRuleResolver et consorts restent hors de tout
 * chemin de lecture).
 */
final readonly class MissionFinancialFacts
{
    public function __construct(
        /** Un FinancialCalculation en statut CALCULATED/APPROVED/LOCKED existe (D-077). */
        public bool $hasActiveCalculation,
        /** Au moins un document émis (SENT/PAID) référence cette mission (D-077). */
        public bool $hasIssuedDocument,
        /** Tous les documents émis rattachés sont PAID (faux s'il n'y en a aucun). */
        public bool $allIssuedDocumentsPaid,
        /**
         * La dernière tentative de calcul a échoué et n'a pas été suivie d'un calcul actif
         * plus récent.
         */
        public bool $hasUnresolvedCalculationFailure,
    ) {}

    public static function none(): self
    {
        return new self(false, false, false, false);
    }
}
