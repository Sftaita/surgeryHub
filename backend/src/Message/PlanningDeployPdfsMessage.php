<?php

namespace App\Message;

/**
 * Dispatched after a planning deploy completes (DB operations done).
 * The handler generates PDFs, sends emails, and creates notifications asynchronously,
 * so the HTTP deploy request can return immediately without timing out.
 *
 * Idempotence: the handler checks PlanningDeployment.status == DONE before processing.
 * If deploymentId is null (legacy path without versionId), idempotence is skipped.
 *
 * V1 limitation: a crash between PROCESSING and DONE leaves the deployment in PROCESSING
 * on retry, which re-executes the handler. Partial sends (e.g. some emails already sent)
 * may be duplicated. A per-recipient send log would fully address this but is deferred.
 */
final class PlanningDeployPdfsMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int   $siteId,
        public readonly int    $deployedById,
        public readonly ?int   $deploymentId,       // null = legacy (no idempotence guard)
        public readonly array  $openUncoveredIds,   // Mission IDs published as pool (no instrumentist)
    ) {}
}
