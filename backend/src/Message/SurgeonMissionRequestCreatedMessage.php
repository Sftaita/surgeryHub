<?php

namespace App\Message;

/**
 * Lot 5 (D-099) — dispatché après qu'un chirurgien crée une SurgeonMissionRequest
 * (SurgeonMissionRequestController::create()). Prévient les managers/admins actifs
 * qu'une demande de mission attend leur traitement — même famille de raisonnement que
 * CatalogueRequestCreatedMessage.
 *
 * `recipientUserIds` est figé au moment de la création (contrairement à
 * CatalogueRequestCreatedMessage, qui recalcule au traitement) — cohérent avec
 * AbsenceSelfDeclaredMessage/PlanningAlertRaisedMessage : la liste des
 * managers/admins actifs à l'instant T est une capture suffisante pour une
 * notification informationnelle qui ne bloque rien.
 *
 * Aucune donnée patient, aucun montant financier.
 */
final class SurgeonMissionRequestCreatedMessage
{
    /** @param int[] $recipientUserIds */
    public function __construct(
        public readonly int $requestId,
        public readonly int $surgeonId,
        public readonly string $surgeonName,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $type,
        public readonly string $startAt,
        public readonly string $endAt,
        public readonly array $recipientUserIds,
    ) {
    }
}
