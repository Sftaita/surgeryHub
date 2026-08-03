<?php

namespace App\Message;

/**
 * Dispatched after MissionService::publish() (DRAFT → OPEN) succeeds.
 *
 * Routing: async (messenger.yaml). Handler: MissionPublishedMessageHandler.
 *
 * Point 8 (audit UX) — replaces the synchronous `WebPushService::sendToSiteInstrumentists()`
 * call that used to live directly in MissionController::publish() (known tech debt,
 * D-081 §"envoi push synchrone dans MissionController::publish()") and adds the surgeon
 * notification the manager-created OPEN mission never triggered before this lot.
 *
 * No patient data in payload (mirrors MissionLifecycleChangedMessage's own rule) — the
 * mission is reloaded fresh from the DB in the handler, this message only carries the id.
 */
final class MissionPublishedMessage
{
    public function __construct(
        public readonly int $missionId,
        public readonly int $actorId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
