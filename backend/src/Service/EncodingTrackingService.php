<?php

namespace App\Service;

use App\Dto\EncodingStateFacts;
use App\Dto\EncodingTrackingItem;
use App\Dto\EncodingTrackingSummary;
use App\Dto\FinancialStatisticsFilter;
use App\Dto\MissionFinancialFacts;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EncodingFinancialState;
use App\Enum\EncodingState;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Repository\EncodingTrackingRepository;

/**
 * Suivi des encodages (D-118) — orchestration du cockpit opérationnel manager.
 *
 * Ne contient aucune règle métier propre : la dérivation d'état vient de
 * EncodingStateResolver, les heures de MissionExecutionService::resolveEffectiveDuration(),
 * le statut financier de EncodingFinancialStateResolver, les filtres de
 * MissionPopulationClauseBuilder. Ce service assemble, il ne décide pas.
 *
 * Budget de requêtes, indépendant du nombre de missions :
 *  - résumé : 1 requête de faits + 1 requête d'anomalies ;
 *  - liste  : 1 comptage + 1 requête d'ids + 1 hydratation + 1 faits + 1 financier
 *             + 1 derniers échecs de calcul (D-138, seulement si la page en contient).
 * Aucun N+1 : les compteurs viennent d'agrégats, jamais d'un parcours de collections.
 */
final class EncodingTrackingService
{
    public function __construct(
        private readonly EncodingTrackingRepository $repository,
        private readonly EncodingStateResolver $stateResolver,
        private readonly EncodingFinancialStateResolver $financialStateResolver,
        private readonly MissionExecutionService $executionService,
        private readonly FinancialCalculationAnomalyExplainer $anomalyExplainer,
        private readonly FinancialCalculationService $financialCalculationService,
    ) {}

    /**
     * Ventilation de toute la période — jamais de la seule page affichée.
     *
     * $now est figé une fois pour toute la ventilation : sans ça, une mission qui se
     * termine pendant le parcours basculerait de UPCOMING à TO_ENCODE en cours de route
     * et les totaux ne seraient plus cohérents entre eux.
     */
    public function summarize(
        FinancialStatisticsFilter $filter,
        ?MissionType $missionType = null,
        ?\DateTimeImmutable $now = null,
    ): EncodingTrackingSummary {
        $now ??= new \DateTimeImmutable();
        $facts = $this->repository->fetchFactsForPeriod($filter, $missionType);

        $countsByState = [];
        $staleInProgress = 0;

        foreach ($facts as $fact) {
            $state = $this->stateResolver->resolve($fact, $now);
            $countsByState[$state->value] = ($countsByState[$state->value] ?? 0) + 1;

            if ($state === EncodingState::IN_PROGRESS && $fact->endAt !== null && $fact->endAt <= $now) {
                ++$staleInProgress;
            }
        }

        $anomalies = count($this->repository->findMissionsWithFailedCalculation(array_keys($facts)));

        return EncodingTrackingSummary::fromStateCounts($countsByState, $staleInProgress, $anomalies);
    }

    /**
     * Page de missions de la période.
     *
     * $encodingStates filtre APRÈS dérivation (l'état n'existe pas en base, il ne peut pas
     * être poussé en SQL). La pagination porte donc sur la population non filtrée par état
     * — c'est assumé : le cockpit filtre principalement par période et par personne, et le
     * filtre d'état sert à isoler une vue courte ("à traiter"), pas à paginer un mois.
     *
     * @param EncodingState[] $encodingStates liste vide = tous les états
     * @return array{items: EncodingTrackingItem[], total: int, page: int, limit: int}
     */
    public function list(
        FinancialStatisticsFilter $filter,
        int $page = 1,
        int $limit = 50,
        array $encodingStates = [],
        ?MissionType $missionType = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();

        ['ids' => $ids, 'total' => $total] = $this->repository->fetchPageIds($filter, $page, $limit, $missionType);

        if (count($ids) === 0) {
            return ['items' => [], 'total' => $total, 'page' => $page, 'limit' => $limit];
        }

        $facts = $this->repository->fetchFactsForIds($ids);
        $missions = $this->repository->hydrateMissionsForDisplay($ids);
        $financialFacts = $this->repository->fetchFinancialFacts($ids);
        // D-138 — un seul chargement des derniers échecs, limité aux missions qui en ont un
        // non résolu : la liste explique chaque « Anomalie » sans requête par mission.
        $failures = $this->anomalyExplainer->latestFailures(array_keys(array_filter(
            $financialFacts,
            static fn (MissionFinancialFacts $f) => $f->hasUnresolvedCalculationFailure,
        )));

        $items = [];
        foreach ($ids as $id) {
            $mission = $missions[$id] ?? null;
            $fact = $facts[$id] ?? null;
            if ($mission === null || $fact === null) {
                continue;
            }

            $state = $this->stateResolver->resolve($fact, $now);
            if (count($encodingStates) > 0 && !in_array($state, $encodingStates, true)) {
                continue;
            }

            $isStale = $state === EncodingState::IN_PROGRESS && $fact->endAt !== null && $fact->endAt <= $now;

            $items[] = $this->buildItem($mission, $fact, $state, $isStale, $financialFacts[$id] ?? MissionFinancialFacts::none(), $failures[$id]['anomalies'] ?? []);
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    private function buildItem(
        Mission $mission,
        EncodingStateFacts $fact,
        EncodingState $state,
        bool $isStale,
        MissionFinancialFacts $financialFacts,
        array $rawFailureAnomalies = [],
    ): EncodingTrackingItem {
        // Source canonique unique des heures — jamais recalculée ici (D-071).
        $effective = $this->executionService->resolveEffectiveDuration($mission);

        $start = $mission->getStartAt();
        $end = $mission->getEndAt();
        $plannedMinutes = ($start !== null && $end !== null)
            ? max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60))
            : 0;

        $financialState = $this->financialStateResolver->resolve($state, $financialFacts);

        $surgeon = $mission->getSurgeon();
        $instrumentist = $mission->getInstrumentist();
        $site = $mission->getSite();

        return new EncodingTrackingItem(
            missionId: (int) $mission->getId(),
            startAt: $start,
            endAt: $end,
            missionType: $mission->getType(),
            missionStatus: $mission->getStatus(),
            encodingState: $state,
            instrumentistId: $instrumentist?->getId(),
            instrumentistName: $this->displayName($instrumentist),
            instrumentistPhotoPath: $instrumentist?->getProfilePicturePath(),
            surgeonId: $surgeon?->getId(),
            surgeonName: $surgeon !== null ? $surgeon->getDrName() : null,
            surgeonPhotoPath: $surgeon?->getProfilePicturePath(),
            siteId: $site?->getId(),
            siteName: $site?->getName(),
            plannedMinutes: $plannedMinutes,
            effectiveMinutes: $effective->minutes,
            effectiveSource: $effective->source,
            interventionCount: $fact->interventionCount,
            encodedInterventionCount: $fact->encodedInterventionCount,
            materialLineCount: $fact->activeMaterialLineCount,
            submittedWithoutMaterial: $mission->isSubmittedWithoutMaterial() === true,
            hasNoMaterialJustification: $mission->getNoMaterialComment() !== null && trim($mission->getNoMaterialComment()) !== '',
            isStale: $isStale,
            financialState: $financialState,
            financialAnomalyReasons: $financialState === EncodingFinancialState::ANOMALY
                ? $this->anomalyExplainer->summarize($rawFailureAnomalies)
                : [],
        );
    }

    /**
     * D-138 — détail du statut financier d'UNE mission pour le tiroir du suivi : même
     * état que la liste (mêmes faits, même résolveur), et, en ANOMALY, chaque anomalie du
     * dernier échec audité expliquée et localisée (FinancialCalculationAnomalyExplainer).
     * Une anomalie affichée a donc toujours son explication, et rien n'est inventé hors
     * ANOMALY. null = mission inconnue.
     *
     * @return array<string, mixed>|null
     */
    public function explainFinancialState(int $missionId, ?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable();

        $fact = $this->repository->fetchFactsForIds([$missionId])[$missionId] ?? null;
        $mission = $this->repository->hydrateMissionsForDisplay([$missionId])[$missionId] ?? null;
        if ($fact === null || $mission === null) {
            return null;
        }

        $encodingState = $this->stateResolver->resolve($fact, $now);
        $financialFacts = $this->repository->fetchFinancialFacts([$missionId])[$missionId] ?? MissionFinancialFacts::none();
        $financialState = $this->financialStateResolver->resolve($encodingState, $financialFacts);

        $anomalies = [];
        $newAnomalies = [];
        $recalculation = null;
        $failedAt = null;
        $effectiveAt = null;
        $retry = null;
        if ($financialState === EncodingFinancialState::ANOMALY) {
            $failure = $this->anomalyExplainer->latestFailures([$missionId])[$missionId] ?? null;
            $effectiveAt = $failure['effectiveAt'] ?? $this->financialCalculationService->resolveEffectiveAt($mission);
            $failedAt = $failure['failedAt'] ?? null;
            // D-141 — l'échec audité explique l'état ; l'évaluation actuelle du moteur dit ce
            // qui est corrigé, ce qui est apparu depuis, et si un recalcul aboutirait.
            $outlook = $this->anomalyExplainer->explainWithOutlook($failure['anomalies'] ?? [], $mission, $effectiveAt);
            $anomalies = $outlook['anomalies'];
            $newAnomalies = $outlook['newAnomalies'];
            $recalculation = $outlook['recalculation'];
            $retry = $this->retryAction($mission);
        }

        return [
            'missionId' => $missionId,
            'state' => $financialState->value,
            'label' => $financialState->label(),
            'isBlocking' => $financialState->isBlocking(),
            'failedAt' => $failedAt?->format(\DateTimeInterface::ATOM),
            'effectiveAt' => $effectiveAt?->format('Y-m-d'),
            'anomalies' => $anomalies,
            'newAnomalies' => $newAnomalies,
            'recalculation' => $recalculation,
            'retry' => $retry,
        ];
    }

    /**
     * Relance possible d'un calcul après correction — mêmes préconditions que
     * FinancialCalculationService (mission VALIDATED, jamais un calcul LOCKED) : le moteur
     * reste seul juge, ceci évite seulement d'offrir un bouton voué au refus. Premier calcul
     * → POST /api/missions/{id}/financial-calculations ; recalcul d'un calcul actif →
     * POST /api/financial-calculations/{id}/recalculate.
     *
     * @return array{kind: string, calculationId: ?int}|null
     */
    private function retryAction(Mission $mission): ?array
    {
        if ($mission->getStatus() !== MissionStatus::VALIDATED || $mission->getInstrumentist() === null) {
            return null;
        }
        $active = $this->financialCalculationService->findActiveCalculation($mission);
        if ($active === null) {
            return ['kind' => 'CALCULATE', 'calculationId' => null];
        }
        if ($active->getStatus() === FinancialCalculationStatus::LOCKED) {
            return null;
        }

        return ['kind' => 'RECALCULATE', 'calculationId' => $active->getId()];
    }

    /** D-121 — voir EncodingTrackingRepository::countPendingEncodingValidation(). */
    public function countPendingValidation(): int
    {
        return $this->repository->countPendingEncodingValidation();
    }

    /** Chirurgiens exceptés (getDrName()), un nom d'affichage simple avec repli e-mail. */
    private function displayName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
