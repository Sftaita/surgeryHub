<?php

namespace App\Dto;

use App\Enum\EncodingState;

/**
 * Suivi des encodages (D-118) — ventilation canonique d'une période par EncodingState.
 *
 * Source UNIQUE et partagée par deux consommateurs volontairement distincts :
 *  - GET /api/billing/encoding-tracking (cockpit opérationnel, KPI du haut de page) ;
 *  - GET /api/financial-statistics/overview, pour expliquer pourquoi une période sans
 *    donnée financière n'en a pas ("11 missions exécutées, mais aucune éligible : 6 à
 *    encoder, 2 en cours, 3 soumises non validées") — cf. D-077 §12.
 *
 * Ne recouvre JAMAIS le pipeline financier des 9 compteurs de FinancialPipelineDto :
 * celui-ci décrit l'AVANT-VALIDATED (pourquoi une mission n'est pas encore éligible),
 * l'autre l'APRÈS-VALIDATED (pourquoi une mission éligible n'a pas encore produit de
 * document ou d'encaissement). Les deux se lisent bout à bout, jamais en concurrence.
 */
final readonly class EncodingTrackingSummary
{
    public function __construct(
        /** Toutes les missions de la période, tous statuts confondus (dénominateur brut). */
        public int $totalMissions,
        /**
         * Missions dont un encodage est réellement attendu (exclut DRAFT/OPEN/REJECTED/
         * CANCELLED). C'est le seul dénominateur honnête d'un taux d'encodage : une
         * mission annulée n'est pas un encodage manquant.
         */
        public int $encodingExpected,
        public int $upcoming,
        public int $toEncode,
        public int $inProgress,
        public int $submitted,
        public int $validated,
        public int $locked,
        public int $notApplicable,
        /**
         * Missions terminées chronologiquement dont l'encodage est commencé mais pas
         * soumis — sous-ensemble de $inProgress. Un encodage entamé sur une mission déjà
         * finie est un retard réel ; sur une mission en cours de journée, non.
         */
        public int $staleInProgress,
        /**
         * Missions dont la DERNIÈRE tentative de calcul financier a échoué sur des
         * anomalies (AuditEvent FINANCIAL_CALCULATION_FAILED postérieur au dernier calcul
         * actif). Jamais recalculé ici : lire l'historique ne relance jamais le moteur
         * de tarification.
         */
        public int $financialAnomalies,
    ) {}

    /**
     * @param array<string, int> $countsByState clés = EncodingState::value
     */
    public static function fromStateCounts(
        array $countsByState,
        int $staleInProgress,
        int $financialAnomalies,
    ): self {
        $count = static fn (EncodingState $state): int => $countsByState[$state->value] ?? 0;

        $total = 0;
        foreach (EncodingState::cases() as $state) {
            $total += $count($state);
        }

        return new self(
            totalMissions: $total,
            encodingExpected: $total - $count(EncodingState::NOT_APPLICABLE),
            upcoming: $count(EncodingState::UPCOMING),
            toEncode: $count(EncodingState::TO_ENCODE),
            inProgress: $count(EncodingState::IN_PROGRESS),
            submitted: $count(EncodingState::SUBMITTED),
            validated: $count(EncodingState::VALIDATED),
            locked: $count(EncodingState::LOCKED),
            notApplicable: $count(EncodingState::NOT_APPLICABLE),
            staleInProgress: $staleInProgress,
            financialAnomalies: $financialAnomalies,
        );
    }

    /** Encodage terminé du point de vue de l'instrumentiste (soumis ou au-delà). */
    public function encoded(): int
    {
        return $this->submitted + $this->validated + $this->locked;
    }

    /** Missions terminées dont l'encodage n'est pas soumis — le retard réel. */
    public function missingEncoding(): int
    {
        return $this->toEncode + $this->staleInProgress;
    }

    /**
     * Total de la vue "À traiter" (Étape 8) : ce qui réclame une action, quelle qu'elle
     * soit. Volontairement calculé ici et jamais côté frontend, pour que le badge de
     * navigation et la liste ne puissent pas diverger.
     */
    public function toTreat(): int
    {
        return $this->missingEncoding() + $this->submitted + $this->financialAnomalies;
    }

    /**
     * Une période peut-elle produire de la donnée financière ? Faux quand il y a de
     * l'activité mais que rien n'a atteint VALIDATED — c'est exactement le cas que la
     * page Statistiques doit expliquer au lieu d'afficher "Aucune donnée".
     */
    public function hasFinanciallyEligibleMissions(): bool
    {
        return $this->validated + $this->locked > 0;
    }
}
