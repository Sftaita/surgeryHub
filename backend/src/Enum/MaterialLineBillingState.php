<?php

namespace App\Enum;

/**
 * EPIC Revue instrumentiste, Lot 3 — état métier explicite consommé par le moteur
 * financier, dérivé de MaterialAttachmentTarget::billingEligibility() (jamais persisté
 * — voir revue de conception, docs/decisions.md : une colonne persistée devrait être
 * resynchronisée à chaque transition du draft, réintroduisant exactement la duplication
 * de logique que cette abstraction cherche à éliminer, avec un risque de désynchronisation
 * silencieuse). Le moteur financier (pas encore branché à ce stade) ne traite que
 * CATALOGUED.
 */
enum MaterialLineBillingState: string
{
    /** Intervention réelle (MissionIntervention), ou aucune cible du tout (matériel
     *  "libre" — sémantique préexistante déjà facturée, inchangée par ce lot). */
    case CATALOGUED = 'CATALOGUED';

    /** Draft OPEN, ou draft CONVERTED/MATERIAL_REASSIGNED interrogé directement (le
     *  matériel réel a déjà été repointé vers la cible redirigée — voir redirectTarget()). */
    case REQUEST_PENDING = 'REQUEST_PENDING';

    /** Draft KEPT_AS_HISTORY — jamais facturable, quel que soit le contexte. */
    case HISTORY_ONLY = 'HISTORY_ONLY';
}
