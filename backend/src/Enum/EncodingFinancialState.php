<?php

namespace App\Enum;

/**
 * Suivi des encodages (D-118) — statut financier SYNTHÉTIQUE d'une mission, volontairement
 * grossier : sur le cockpit d'encodage le financier est secondaire, il sert à repérer un
 * blocage, jamais à analyser des montants (c'est le rôle de la page Statistiques).
 *
 * Aucun montant n'est exposé ici, et aucun moteur de tarification n'est rejoué : les
 * définitions réutilisent telles quelles celles de D-077 (calcul actif =
 * CALCULATED/APPROVED/LOCKED, document émis = SENT/PAID) pour que les deux écrans ne
 * puissent pas se contredire.
 */
enum EncodingFinancialState: string
{
    /**
     * La mission n'est pas encore éligible : seul VALIDATED l'est
     * (MissionEncodingWorkflowService::isBillable()). Ce n'est pas une anomalie — c'est
     * l'état normal de tout ce qui n'a pas fini son cycle d'encodage.
     */
    case NOT_CALCULABLE = 'NOT_CALCULABLE';

    /** Éligible, mais personne n'a encore lancé le calcul (jamais automatique, D-073). */
    case TO_CALCULATE = 'TO_CALCULATE';

    /** Un calcul actif existe, aucun document émis. */
    case CALCULATED = 'CALCULATED';

    /** Facture firme et/ou décompte instrumentiste émis (SENT/PAID, cf. D-077). */
    case DOCUMENTED = 'DOCUMENTED';

    /** Tous les documents rattachés sont PAID. */
    case PAID = 'PAID';

    /** La dernière tentative de calcul a échoué sur des anomalies (tarif manquant, etc.). */
    case ANOMALY = 'ANOMALY';

    public function label(): string
    {
        return match ($this) {
            self::NOT_CALCULABLE => 'Pas encore calculable',
            self::TO_CALCULATE   => 'À calculer',
            self::CALCULATED     => 'Calculé',
            self::DOCUMENTED     => 'Facturé / Décompté',
            self::PAID           => 'Payé',
            self::ANOMALY        => 'Anomalie',
        };
    }

    /** Seule l'anomalie réclame une intervention : le reste est un avancement normal. */
    public function isBlocking(): bool
    {
        return $this === self::ANOMALY;
    }
}
