<?php

namespace App\Controller\Api;

use App\Dto\EncodingTrackingItem;
use App\Dto\EncodingTrackingSummary;
use App\Security\Voter\BillingVoter;
use App\Service\EncodingTrackingRequestParser;
use App\Service\EncodingTrackingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi des encodages (D-118) — cockpit opérationnel manager.
 *
 * Répond à "qu'est-ce qui a été encodé, par qui, et qu'est-ce qui réclame mon
 * attention ?", volontairement distinct des statistiques financières (D-077) qui
 * répondent à "combien". Contrôleur fin : aucune règle métier ici.
 *
 * BillingVoter::MANAGE sur toutes les routes — mêmes droits que le reste de la
 * facturation, aucun accès instrumentiste à cette vue globale.
 *
 * Aucune donnée patient n'est exposée (cf. EncodingTrackingItem), et la consultation
 * n'est pas auditée — cohérent avec D-077 §28 : auditer une lecture polluerait le
 * journal sans valeur métier.
 */
#[Route('/api/billing/encoding-tracking')]
final class EncodingTrackingController extends AbstractController
{
    public function __construct(
        private readonly EncodingTrackingRequestParser $parser,
        private readonly EncodingTrackingService $service,
    ) {}

    /**
     * Résumé + page de missions en une seule réponse : le cockpit affiche toujours les
     * deux ensemble, deux endpoints imposeraient deux aller-retours et laisseraient les
     * KPI se désynchroniser de la liste entre deux rafraîchissements.
     *
     * Le résumé porte sur TOUTE la période, la liste sur la page demandée.
     */
    #[Route('', name: 'api_encoding_tracking_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $filter = $this->parser->parseFilter($request);
        $missionType = $this->parser->parseMissionType($request);
        $states = $this->parser->parseEncodingStates($request);
        ['page' => $page, 'limit' => $limit] = $this->parser->parsePagination($request);

        // Un instant unique partagé par le résumé et la liste : deux appels à "maintenant"
        // pourraient classer la même mission différemment dans les KPI et dans le tableau.
        $now = new \DateTimeImmutable();

        $summary = $this->service->summarize($filter, $missionType, $now);
        $result = $this->service->list($filter, $page, $limit, $states, $missionType, $now);

        return $this->json([
            'period' => [
                'from' => $filter->from->format(\DateTimeInterface::ATOM),
                'to' => $filter->to->format(\DateTimeInterface::ATOM),
            ],
            'summary' => $this->serializeSummary($summary),
            'items' => array_map($this->serializeItem(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    /**
     * Ventilation seule, sans liste — consommée par la page Statistiques financières pour
     * expliquer une période sans donnée financière (D-118, Étape 12). Même service, même
     * définition : les deux écrans ne peuvent pas se contredire.
     */
    #[Route('/summary', name: 'api_encoding_tracking_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $filter = $this->parser->parseFilter($request);
        $missionType = $this->parser->parseMissionType($request);

        return $this->json([
            'period' => [
                'from' => $filter->from->format(\DateTimeInterface::ATOM),
                'to' => $filter->to->format(\DateTimeInterface::ATOM),
            ],
            'summary' => $this->serializeSummary($this->service->summarize($filter, $missionType)),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeSummary(EncodingTrackingSummary $summary): array
    {
        return [
            'totalMissions' => $summary->totalMissions,
            'encodingExpected' => $summary->encodingExpected,
            'upcoming' => $summary->upcoming,
            'toEncode' => $summary->toEncode,
            'inProgress' => $summary->inProgress,
            'submitted' => $summary->submitted,
            'validated' => $summary->validated,
            'locked' => $summary->locked,
            'notApplicable' => $summary->notApplicable,
            'staleInProgress' => $summary->staleInProgress,
            'financialAnomalies' => $summary->financialAnomalies,
            // Dérivés exposés explicitement : le frontend ne doit jamais les recomposer,
            // sinon le badge de navigation et la liste pourraient diverger.
            'encoded' => $summary->encoded(),
            'missingEncoding' => $summary->missingEncoding(),
            'toTreat' => $summary->toTreat(),
            'hasFinanciallyEligibleMissions' => $summary->hasFinanciallyEligibleMissions(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeItem(EncodingTrackingItem $item): array
    {
        return [
            'missionId' => $item->missionId,
            'startAt' => $item->startAt?->format(\DateTimeInterface::ATOM),
            'endAt' => $item->endAt?->format(\DateTimeInterface::ATOM),
            'missionType' => $item->missionType?->value,
            'missionStatus' => $item->missionStatus->value,
            'encodingState' => $item->encodingState->value,
            'encodingStateLabel' => $item->encodingState->label(),
            'instrumentist' => $item->instrumentistId !== null
                ? ['id' => $item->instrumentistId, 'name' => $item->instrumentistName]
                : null,
            'surgeon' => $item->surgeonId !== null
                ? ['id' => $item->surgeonId, 'name' => $item->surgeonName]
                : null,
            'site' => $item->siteId !== null
                ? ['id' => $item->siteId, 'name' => $item->siteName]
                : null,
            'hours' => [
                'plannedMinutes' => $item->plannedMinutes,
                'effectiveMinutes' => $item->effectiveMinutes,
                'effectiveSource' => $item->effectiveSource->value,
                'hasRealHours' => $item->hasRealHours(),
            ],
            'encoding' => [
                'interventionCount' => $item->interventionCount,
                'materialLineCount' => $item->materialLineCount,
                'submittedWithoutMaterial' => $item->submittedWithoutMaterial,
                'hasNoMaterialJustification' => $item->hasNoMaterialJustification,
                // IN_PROGRESS + mission déjà terminée — même condition que
                // EncodingTrackingSummary::staleInProgress, exposée par mission pour la
                // vue "À traiter" (ajouté lors de l'intégration frontend, D-118).
                'isStale' => $item->isStale,
            ],
            'financial' => [
                'state' => $item->financialState->value,
                'label' => $item->financialState->label(),
                'isBlocking' => $item->financialState->isBlocking(),
            ],
        ];
    }
}
