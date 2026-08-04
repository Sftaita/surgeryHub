<?php

namespace App\Message;

use App\Enum\CatalogueRequestKind;

/**
 * D-093 — dispatché après qu'un manager résout ou ignore une InterventionTypeRequest
 * (via MissionInterventionDraftService::resolve()/ignore()) ou une MaterialItemRequest
 * (via MaterialItemRequestManagerController::resolve()/ignore()) proposée par un
 * instrumentiste pendant l'encodage. Prévient l'instrumentiste de l'issue — accepté
 * (ajouté au catalogue) ou non retenu.
 *
 * Routing: async (messenger.yaml). Handler: CatalogueRequestProcessedMessageHandler.
 *
 * $label est un instantané au moment du traitement (jamais re-résolu dans le handler) —
 * la demande reste lisible même si elle est ensuite modifiée/supprimée entre le
 * dispatch et le traitement du message. Aucune donnée patient.
 */
final class CatalogueRequestProcessedMessage
{
    public function __construct(
        public readonly CatalogueRequestKind $kind,
        public readonly int $requestId,
        public readonly bool $accepted,
        public readonly int $recipientUserId,
        public readonly int $missionId,
        public readonly string $label,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
