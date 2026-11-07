<?php

namespace App\Enum;

/**
 * H1 (D-126) — top-level grouping of an ActivityEvent, used by the future History timeline
 * filters. A domain is a display/filtering concern only — never a business decision input.
 *
 * PRICING / BILLING / PAYMENTS are the financial domains: a future contextual history
 * served to a non-Manager/Admin role must filter them out (see docs/decisions.md D-126).
 */
enum ActivityDomain: string
{
    case PLANNING      = 'PLANNING';
    case MISSIONS      = 'MISSIONS';
    case ABSENCES      = 'ABSENCES';
    case ROOMS         = 'ROOMS';
    case NOTIFICATIONS = 'NOTIFICATIONS';
    case USERS         = 'USERS';
    case CATALOGUE     = 'CATALOGUE';
    case MATERIAL      = 'MATERIAL';
    case PRICING       = 'PRICING';
    case ENCODING      = 'ENCODING';
    case BILLING       = 'BILLING';
    case PAYMENTS      = 'PAYMENTS';
    // H5b — data exports (ExportLog mirror).
    case EXPORTS       = 'EXPORTS';
    case SYSTEM        = 'SYSTEM';

    public function isFinancial(): bool
    {
        return in_array($this, [self::PRICING, self::BILLING, self::PAYMENTS], true);
    }
}
