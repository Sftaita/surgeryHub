<?php

namespace App\Dto\Request\Response;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 7 — modèle de lecture unifié de l'écran
 * d'encodage : une seule liste ordonnée par orderIndex mêlant MissionIntervention
 * (kind=INTERVENTION) et MissionInterventionDraft (kind=DRAFT) encore utiles à
 * l'instrumentiste, pour que le frontend n'ait pas à fusionner deux listes indépendantes
 * lui-même. Voir MissionEncodingService::buildEncodingDto()/mapInterventionToEntry()/
 * mapDraftToEntry() pour les règles de construction (drafts CONVERTED/MATERIAL_REASSIGNED
 * jamais dupliqués, KEPT_AS_HISTORY masqué si vide).
 *
 * `interventionType`/`requestId`/`requestedFirmNameSnapshot` sont spécifiques à un seul
 * `kind` (toujours null pour l'autre) plutôt que deux classes distinctes : une union
 * discriminée par champ `kind` reste plus simple à consommer côté frontend qu'un
 * polymorphisme de classes DTO, pour une liste qui ne porte que quelques champs
 * optionnels en plus des champs communs.
 */
final class MissionEncodingEntryDto
{
    /**
     * @param MissionEncodingMaterialLineDto[] $materialLines matériel catalogue attaché à
     *        cette cible (via MaterialLine::attachmentTarget()), jamais dupliqué ailleurs
     * @param MissionEncodingMaterialItemRequestDto[] $materialItemRequests demandes de
     *        matériel PENDING attachées à cette cible (via MaterialItemRequest::attachmentTarget())
     *
     * `status` : pour kind=INTERVENTION, toujours "CATALOGUED" (MaterialLineBillingState,
     * une MissionIntervention réelle est toujours cataloguée) ; pour kind=DRAFT, le statut
     * brut du draft ("OPEN" ou "KEPT_AS_HISTORY" — CONVERTED/MATERIAL_REASSIGNED ne
     * produisent jamais d'entrée, voir mapDraftToEntry()), volontairement le statut brut
     * plutôt que sa MaterialLineBillingState pour que le frontend distingue explicitement
     * "OPEN" (modifiable) de "KEPT_AS_HISTORY" (à afficher comme "Conservé comme
     * historique" ou équivalent).
     * `readOnly` : dérivé de `!target->acceptsNewMaterial()` (toujours false pour une
     * intervention réelle, true pour un draft KEPT_AS_HISTORY).
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly ?int $requestId,
        public readonly int $orderIndex,
        public readonly string $label,
        public readonly ?InterventionTypeSlimDto $interventionType,
        public readonly ?FirmSlimDto $firm,
        public readonly ?string $requestedFirmNameSnapshot,
        public readonly string $status,
        public readonly bool $readOnly,
        public readonly array $materialLines,
        public readonly array $materialItemRequests,
        /**
         * Refonte Catalogue/Prestations (D-092) — toujours null pour kind=DRAFT (donnée
         * spécifique à une MissionIntervention réelle). Jamais financière.
         */
        public readonly ?bool $representativePresent = null,
    ) {}
}
