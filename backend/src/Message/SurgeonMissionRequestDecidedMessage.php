<?php

namespace App\Message;

/**
 * Lot 5 (D-099) — dispatché après qu'un manager accepte ou refuse une
 * SurgeonMissionRequest. Prévient le chirurgien requester de l'issue — même famille de
 * raisonnement que CatalogueRequestProcessedMessage (push d'abord, repli email).
 *
 * `missionId` est non-null seulement si `accepted` (la Mission vient d'être créée) ;
 * `reviewComment` est non-null seulement si `!accepted` (obligatoire au refus, §11).
 */
final class SurgeonMissionRequestDecidedMessage
{
    public function __construct(
        public readonly int $requestId,
        public readonly int $surgeonId,
        public readonly bool $accepted,
        public readonly ?int $missionId,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $startAt,
        public readonly ?string $reviewComment,
    ) {
    }
}
