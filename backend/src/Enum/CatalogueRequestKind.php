<?php

namespace App\Enum;

/**
 * D-093 — distingue les deux natures de proposition catalogue portées par le même
 * couple de NotificationType (CATALOGUE_REQUEST_RESOLVED/IGNORED) : une seule
 * préférence de canal pour l'instrumentiste ("préviens-moi de l'issue de mes
 * propositions catalogue"), jamais deux catégories séparées intervention/matériel.
 */
enum CatalogueRequestKind: string
{
    case INTERVENTION_TYPE = 'INTERVENTION_TYPE';
    case MATERIAL_ITEM = 'MATERIAL_ITEM';
}
