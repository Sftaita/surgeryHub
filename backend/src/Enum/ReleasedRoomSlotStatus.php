<?php

namespace App\Enum;

/**
 * Statut d'un `ReleasedOperatingRoomSlot`.
 *
 * - AVAILABLE — Lot D : créneau BLOCK réellement libéré par l'absence du chirurgien prévu,
 *   visible par les chirurgiens affiliés au site.
 * - CLAIMED — D-124 (« Reprendre une salle libérée ») : un autre chirurgien a repris la salle ;
 *   une Mission OPEN a été créée pour lui dans la même transaction
 *   (`ReleasedOperatingRoomSlot::$takeoverMission`). Retour à AVAILABLE possible via
 *   `ReleasedRoomSlotTakeoverService::release()` (désistement) — l'historique complet reste
 *   dans `AuditEvent` (ROOM_SLOT_TAKEN_OVER / ROOM_SLOT_REOPENED), jamais sur la ligne.
 *
 * Colonne VARCHAR : ajouter un cas ne demande aucune migration.
 */
enum ReleasedRoomSlotStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case CLAIMED = 'CLAIMED';
}
