<?php

namespace App\Enum;

/**
 * H1 (D-126) — who/what caused an ActivityEvent.
 *
 *   USER           — a real person acting through the API.
 *   SYSTEM         — the technical system user (system@surgicalhub.internal) or an
 *                    automated reaction with no human actor.
 *   WORKER         — a Messenger handler with no human actor of its own.
 *   SCHEDULED_TASK — a console command (cron or manual run).
 */
enum ActivityActorType: string
{
    case USER           = 'USER';
    case SYSTEM         = 'SYSTEM';
    case WORKER         = 'WORKER';
    case SCHEDULED_TASK = 'SCHEDULED_TASK';
}
