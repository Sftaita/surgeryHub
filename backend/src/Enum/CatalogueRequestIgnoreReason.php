<?php

namespace App\Enum;

/**
 * Motif structuré saisi par le manager en ignorant une proposition catalogue
 * (MaterialItemRequest ou InterventionTypeRequest) — remplace l'ancien flip de statut
 * sans justification. `ALREADY_EXISTS` (intervention) et `MATERIAL_ALREADY_EXISTS`
 * (matériel) sont volontairement distincts bien que sémantiquement proches : chaque kind
 * de demande n'affiche que le motif "déjà existant" qui le concerne côté frontend, mais
 * le backend accepte les deux valeurs pour les deux kinds (pas de validation croisée —
 * l'UI guide déjà le choix, une restriction serveur supplémentaire n'apporterait rien).
 *
 * label() porte le seul wording de présentation FR : les messages/DTOs qui traversent les
 * frontières de service (CatalogueRequestProcessedMessage) transportent l'enum brut,
 * jamais un texte déjà traduit — voir NotificationService::catalogueRequestIgnoredNotifyInstrumentist().
 */
enum CatalogueRequestIgnoreReason: string
{
    case ALREADY_EXISTS          = 'ALREADY_EXISTS';
    case MATERIAL_ALREADY_EXISTS = 'MATERIAL_ALREADY_EXISTS';
    case DUPLICATE                = 'DUPLICATE';
    case INVALID_REQUEST          = 'INVALID_REQUEST';
    case OTHER                    = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::ALREADY_EXISTS          => 'Intervention déjà existante',
            self::MATERIAL_ALREADY_EXISTS => 'Matériel déjà existant',
            self::DUPLICATE                => 'Demande en doublon',
            self::INVALID_REQUEST          => 'Demande incorrecte',
            self::OTHER                    => 'Autre',
        };
    }
}
