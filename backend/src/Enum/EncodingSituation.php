<?php

namespace App\Enum;

/**
 * D-143 — où en est l'encodage d'une mission dont la soumission est encore attendue.
 * Dérivé uniquement du statut réel et des données enregistrées
 * (EncodingCompletenessService) — jamais une supposition sur le matériel ou les
 * interventions qu'« il aurait fallu » encoder.
 */
enum EncodingSituation: string
{
    /** Rien n'a encore été enregistré : ni heures, ni intervention, ni matériel. */
    case NOT_STARTED = 'NOT_STARTED';
    /** Un brouillon existe mais les heures réelles (début ET fin) manquent encore. */
    case HOURS_MISSING = 'HOURS_MISSING';
    /** Brouillon commencé, heures réelles complètes, mais encodage jamais soumis. */
    case NOT_SUBMITTED = 'NOT_SUBMITTED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED   => 'Encodage non commencé',
            self::HOURS_MISSING => 'Brouillon enregistré — heures réelles manquantes',
            self::NOT_SUBMITTED => 'Brouillon enregistré — non soumis',
        };
    }
}
