<?php

namespace App\Activity;

use App\Enum\ActivityDomain;
use App\Enum\ActivityEventType;
use App\Enum\AuditEventType;
use App\Enum\UserAuditEventType;

/**
 * H1 (D-126) — the closed set of eventType values the journal accepts, and the domain of
 * each mirrored audit type. Anything else is refused (an unknown type is a programming
 * error, not something to store as free text).
 */
final class ActivityEventTypeRegistry
{
    public static function isKnown(\BackedEnum $type): bool
    {
        return $type instanceof ActivityEventType
            || $type instanceof AuditEventType
            || $type instanceof UserAuditEventType;
    }

    public static function domainOf(\BackedEnum $type): ActivityDomain
    {
        if ($type instanceof ActivityEventType) {
            return $type->domain();
        }
        if ($type instanceof UserAuditEventType) {
            return ActivityDomain::USERS;
        }
        if (!$type instanceof AuditEventType) {
            throw new \InvalidArgumentException(sprintf('Unknown activity event type %s.', $type::class));
        }

        $v = $type->value;

        return match (true) {
            $v === 'ABSENCE_DAY_REMOVED'                                   => ActivityDomain::ABSENCES,
            str_starts_with($v, 'ROOM_SLOT_')                              => ActivityDomain::ROOMS,
            str_starts_with($v, 'PRICING_RULE_'),
            str_starts_with($v, 'INSTRUMENTIST_RATE_')                     => ActivityDomain::PRICING,
            str_starts_with($v, 'FINANCIAL_CALCULATION_'),
            str_starts_with($v, 'FIRM_INVOICE_'),
            str_starts_with($v, 'INSTRUMENTIST_STATEMENT_'),
            str_starts_with($v, 'CREDIT_NOTE_'),
            str_starts_with($v, 'DEBIT_NOTE_'),
            $v === 'FINANCIAL_CORRECTION_ISSUED'                           => ActivityDomain::BILLING,
            str_starts_with($v, 'DOCUMENT_'),
            $v === 'REFUND_RECORDED'                                       => ActivityDomain::PAYMENTS,
            str_starts_with($v, 'MISSION_ENCODING_'),
            str_starts_with($v, 'MISSION_INTERVENTION_DRAFT_'),
            str_starts_with($v, 'ENCODING_ANOMALY_'),
            str_starts_with($v, 'MISSION_EXECUTION_')                      => ActivityDomain::ENCODING,
            str_starts_with($v, 'INTERVENTION_TYPE_')                      => ActivityDomain::CATALOGUE,
            str_starts_with($v, 'MATERIAL_ITEM_')                          => ActivityDomain::MATERIAL,
            str_starts_with($v, 'PLANNING_')                               => ActivityDomain::PLANNING,
            default                                                        => ActivityDomain::MISSIONS,
        };
    }
}
