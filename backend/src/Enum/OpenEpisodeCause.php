<?php

namespace App\Enum;

/**
 * H3 (D-129) — why a real mission became OPEN (one value per business path that opens a
 * mission to the pool). Recorded on OPEN_POOL_EVALUATED; part of the episode reference.
 */
enum OpenEpisodeCause: string
{
    /** A manager (or a planning-alert action) released the assigned instrumentist. */
    case RELEASED = 'RELEASED';
    /** The assigned instrumentist declared an absence (D-062 reaction). */
    case INSTRUMENTIST_ABSENCE = 'INSTRUMENTIST_ABSENCE';
    /** Uncovered missions published to the pool by a planning deployment. */
    case DEPLOYED = 'DEPLOYED';
    /** A manager published a mission to the pool (D-125 POOL mode). */
    case PUBLISHED_TO_POOL = 'PUBLISHED_TO_POOL';
    /** A surgeon took over a released room: a new OPEN mission (D-124). */
    case ROOM_TAKEN_OVER = 'ROOM_TAKEN_OVER';
    /** Planning Modification batch: instrumentist removed, or uncovered mission added. */
    case PLANNING_MODIFICATION = 'PLANNING_MODIFICATION';
    /** A cancelled mission restored as OPEN after its surgeon absence was removed (D-104). */
    case RESTORED_AFTER_ABSENCE = 'RESTORED_AFTER_ABSENCE';
    /** A manager chose « ouvrir au pool » on a planning alert for an already-OPEN mission. */
    case OPENED_FROM_ALERT = 'OPENED_FROM_ALERT';
}
