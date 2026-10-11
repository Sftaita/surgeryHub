<?php

namespace App\Enum;

/**
 * H5b (D-132) — what a GeneratedDocument IS, for its future retention policy.
 *
 *   IMMUTABLE_EVIDENCE — the exact bytes a person received (planning of a publication, of a
 *                        republication, of a manual resend; a financial document sent). Never
 *                        purged automatically while no business / legal retention rule exists;
 *                        referenced by outbound_notification.payload.attachments and reused,
 *                        never re-rendered, when the same document is sent again.
 *   REGENERABLE        — produced only for transport / convenience, reproducible without any
 *                        loss of proof (none today). May get a retention later.
 *
 * No purge exists (H5b): the class only makes the future policy possible.
 */
enum DocumentRetentionClass: string
{
    case IMMUTABLE_EVIDENCE = 'IMMUTABLE_EVIDENCE';
    case REGENERABLE        = 'REGENERABLE';
}
