<?php

namespace App\Enum;

/**
 * D-115bis follow-up — provenance of PlanningVersion::$scopeSiteIds for a group-scoped
 * ($site === null) draft. Null (not this enum) for a single-site draft, where $scopeSiteIds
 * itself is irrelevant. Deliberately three explicit states rather than a boolean: a
 * RECONSTRUCTED scope is never promoted to "certain" on its own — only an explicit manager
 * confirmation (PlanningDraftService::confirmScope()) can do that, never the passage of time
 * or a later reopen().
 */
enum PlanningVersionScopeSource: string
{
    /** Captured live from SiteGroupMembership at generate() time — always exact, always certain. */
    case SNAPSHOT = 'SNAPSHOT';

    /**
     * Reconstructed after the fact from this draft's own persisted DRAFT Missions —
     * migration backfill or reopen()'s self-heal fallback, both the same mechanism. Cannot
     * be certified complete: a site with only SKIPPED occurrences leaves no Mission to
     * reconstruct from, so it would silently be missing. Blocks every draft-mutating action
     * (PlanningDraftService::update(), deploy) until a manager reviews and confirms it.
     */
    case RECONSTRUCTED = 'RECONSTRUCTED';

    /** A RECONSTRUCTED scope a manager has explicitly reviewed (and possibly corrected) via confirmScope(). */
    case CONFIRMED = 'CONFIRMED';
}
