<?php

namespace App\Dto;

use App\Enum\EffectiveDurationSource;
use App\Enum\EncodingFinancialState;
use App\Enum\EncodingState;
use App\Enum\MissionStatus;
use App\Enum\MissionType;

/**
 * Suivi des encodages (D-118) — une ligne de la table de suivi.
 *
 * Aucune donnée patient : ni nom, ni identifiant, ni motif d'intervention. Seuls
 * l'horaire, les intervenants professionnels, le site et des compteurs sont exposés.
 *
 * Contrat des heures (tranché avec le métier lors de ce chantier) : `plannedMinutes` et
 * `effectiveMinutes` sont exposés côte à côte, avec `effectiveSource` qui dit lequel des
 * deux a réellement servi. Il n'existe volontairement PAS de champ "encodedMinutes" :
 * resolveEffectiveDuration() peut retomber sur le planifié, et nommer ce résultat
 * "encodé" laisserait croire à une saisie qui n'a jamais eu lieu.
 *
 * SUBMITTED ne conditionne pas l'exposition des heures réelles : le workflow d'encodage
 * ("l'instrumentiste a-t-il déclaré avoir fini ?") et la source temporelle ("dispose-t-on
 * de données réelles ?") sont deux axes indépendants. Voir l'écart documenté avec
 * "Planning Instrumentiste v1.0" §5.4 dans l'ADR.
 */
final readonly class EncodingTrackingItem
{
    public function __construct(
        public int $missionId,
        public ?\DateTimeImmutable $startAt,
        public ?\DateTimeImmutable $endAt,
        public ?MissionType $missionType,
        public MissionStatus $missionStatus,
        public EncodingState $encodingState,

        public ?int $instrumentistId,
        public ?string $instrumentistName,
        public ?int $surgeonId,
        public ?string $surgeonName,
        public ?int $siteId,
        public ?string $siteName,

        /** Durée planifiée (Mission.endAt - Mission.startAt), toujours calculable. */
        public int $plannedMinutes,
        /** Durée retenue par MissionExecutionService::resolveEffectiveDuration(). */
        public int $effectiveMinutes,
        /** PLANNED signale que $effectiveMinutes n'est qu'un repli, pas une saisie réelle. */
        public EffectiveDurationSource $effectiveSource,

        public int $interventionCount,
        public int $materialLineCount,
        /** Soumission close sans aucune ligne de matériel active (D-080). */
        public bool $submittedWithoutMaterial,
        /** Une justification d'absence de matériel a été fournie (le texte n'est pas exposé ici). */
        public bool $hasNoMaterialJustification,

        /**
         * Ajouté lors de l'intégration frontend (D-118) : IN_PROGRESS alors que la mission
         * est déjà terminée chronologiquement — même condition que
         * EncodingTrackingSummary::staleInProgress, exposée ici par mission pour que la vue
         * "À traiter" puisse lister ces missions sans réimplémenter la comparaison
         * endAt <= now côté frontend (ce serait dupliquer une règle métier).
         */
        public bool $isStale,

        public EncodingFinancialState $financialState,
    ) {}

    /** L'écart entre planifié et réel n'a de sens que si le réel existe. */
    public function hasRealHours(): bool
    {
        return $this->effectiveSource !== EffectiveDurationSource::PLANNED;
    }
}
