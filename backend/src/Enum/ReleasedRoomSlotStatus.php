<?php

namespace App\Enum;

/**
 * Statut d'un `ReleasedOperatingRoomSlot` (Lot D — « Salles libérées »). Seul AVAILABLE est
 * produit par le Lot D : REQUESTED/ASSIGNED/CANCELLED_BY_BLOCK_MANAGEMENT appartiennent au
 * futur Lot E (« Je suis intéressé » / attribution) et ne sont volontairement pas définis ici
 * tant qu'aucun comportement ne les émet — les ajouter plus tard est un changement PHP pur,
 * sans migration (colonne déjà une chaîne).
 */
enum ReleasedRoomSlotStatus: string
{
    case AVAILABLE = 'AVAILABLE';
}
