<?php

namespace App\Message;

use App\Enum\CatalogueRequestIgnoreReason;
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
 *
 * Correctif workflow Demandes Catalogue (2026-09-04) — $ignoreReason/$explanation sont
 * non-null uniquement quand $accepted === false. $ignoreReason transporte le **code
 * métier stable** (l'enum), jamais un libellé déjà traduit : le wording de présentation
 * FR est produit uniquement par NotificationService/les templates, pas par les
 * controllers qui dispatchent ce message (qui restent de simples adaptateurs HTTP).
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
        public readonly ?CatalogueRequestIgnoreReason $ignoreReason = null,
        public readonly ?string $explanation = null,
    ) {}
}
