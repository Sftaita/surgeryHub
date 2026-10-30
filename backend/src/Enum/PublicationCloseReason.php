<?php

namespace App\Enum;

/**
 * D-177 — why a TARGETED publication stopped granting its target anything, other than her
 * own refusal (kept as `MissionPublication::declinedAt`, D-125). Stored on the publication
 * (`close_reason`) — the publication row is the durable trace of the request, never deleted.
 */
enum PublicationCloseReason: string
{
    /** The target accepted (claim) — the mission is hers. */
    case ACCEPTED = 'ACCEPTED';
    /** Priority deadline reached without an answer (never treated as a refusal). */
    case EXPIRED = 'EXPIRED';
    /** The target became unable to take the mission (absence confirmed in database). */
    case RECIPIENT_UNAVAILABLE = 'RECIPIENT_UNAVAILABLE';
    /** A manager explicitly lifted the priority (urgency), with a justification. */
    case LIFTED_BY_MANAGER = 'LIFTED_BY_MANAGER';
    /** Someone else was assigned by a manager while the request was pending. */
    case SUPERSEDED = 'SUPERSEDED';
    /** The mission is no longer OPEN (cancelled, room released by its taker…). */
    case MISSION_CLOSED = 'MISSION_CLOSED';
}
