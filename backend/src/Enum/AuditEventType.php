<?php

namespace App\Enum;

enum AuditEventType: string
{
    case MISSION_DECLARED = 'MISSION_DECLARED';
    case MISSION_DECLARED_APPROVED = 'MISSION_DECLARED_APPROVED';
    case MISSION_DECLARED_REJECTED = 'MISSION_DECLARED_REJECTED';

    // Planning generation & deployment
    case PLANNING_GENERATED              = 'PLANNING_GENERATED';
    case PLANNING_UNASSIGNED_HANDLED     = 'PLANNING_UNASSIGNED_HANDLED';
    case PLANNING_DEPLOYED               = 'PLANNING_DEPLOYED';
    case PLANNING_ARCHIVED               = 'PLANNING_ARCHIVED';
    case MISSION_CREATED_FROM_PLANNING   = 'MISSION_CREATED_FROM_PLANNING';
    case MISSION_OPENED_FROM_PLANNING    = 'MISSION_OPENED_FROM_PLANNING';
    case MISSION_ASSIGNED_FROM_PLANNING  = 'MISSION_ASSIGNED_FROM_PLANNING';

    // Planning V2 alerts (Batch 4) — only written on an actual state change, never on an
    // idempotent no-op repeat of the same transition.
    case PLANNING_ALERT_ACKNOWLEDGED = 'PLANNING_ALERT_ACKNOWLEDGED';
    case PLANNING_ALERT_RESOLVED     = 'PLANNING_ALERT_RESOLVED';
    case PLANNING_ALERT_IGNORED      = 'PLANNING_ALERT_IGNORED';

    // Planning V2 alert actions (Batch 5) — the actual Mission mutation, paired with the
    // alert resolution it always triggers.
    case PLANNING_ALERT_REASSIGNED          = 'PLANNING_ALERT_REASSIGNED';
    case PLANNING_ALERT_OPENED_AS_AVAILABLE = 'PLANNING_ALERT_OPENED_AS_AVAILABLE';
}