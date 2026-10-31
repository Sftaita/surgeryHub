<?php

namespace App\Enum;

/**
 * H3 (D-129) — why an otherwise ELIGIBLE person is deliberately not solicited for one
 * opening episode. Contextual only: never stored on the person, never an eligibility rule
 * (MissionEligibilityService keeps answering "could she take it?"); at the next episode
 * she is a candidate again.
 */
enum OpenPoolExclusionReason: string
{
    /** A manager just removed her from this very mission (D-127). */
    case REMOVED_BY_MANAGER = 'REMOVED_BY_MANAGER';
    /** She already received a personal offer for it (D-124 room take-over offer). */
    case PERSONAL_OFFER_SENT = 'PERSONAL_OFFER_SENT';
    /** D-177 — she just declined her room take-over priority on this very mission. */
    case PRIORITY_DECLINED = 'PRIORITY_DECLINED';
}
