<?php

namespace App\Message;

use App\Enum\CatalogueRequestKind;

/**
 * Dispatché après qu'un instrumentiste crée une InterventionTypeRequest (via
 * InterventionTypeRequestController::create()) ou une MaterialItemRequest (via
 * MaterialItemRequestController::create()) pendant l'encodage. Prévient les
 * managers/admins qu'une proposition catalogue attend leur traitement.
 *
 * Routing: async (messenger.yaml). Handler: CatalogueRequestCreatedMessageHandler —
 * lui-même responsable de recalculer la liste des destinataires (managers/admins actifs)
 * au moment du traitement, jamais figée sur le message (cohérent avec
 * PlanningAlertRaisedMessage, où recipientUserIds EST figé — ici on ne fige rien car il
 * n'y a pas de logique d'impact par mission à capturer, juste "tous les managers actifs
 * au moment du traitement").
 *
 * $label est un instantané au moment de la création (jamais re-résolu dans le handler) —
 * la demande reste lisible même si elle est ensuite modifiée/supprimée entre le dispatch
 * et le traitement du message. Aucune donnée patient, aucun montant financier.
 */
final class CatalogueRequestCreatedMessage
{
    public function __construct(
        public readonly CatalogueRequestKind $kind,
        public readonly int $requestId,
        public readonly int $missionId,
        public readonly string $label,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
