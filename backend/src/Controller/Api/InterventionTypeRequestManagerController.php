<?php

namespace App\Controller\Api;

use App\Entity\InterventionTypeRequest;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionInterventionDraftIgnoreStrategy;
use App\Exception\InterventionTypeRequestWithoutDraftException;
use App\Message\CatalogueRequestProcessedMessage;
use App\Security\Voter\BillingVoter;
use App\Service\ActiveFirmResolver;
use App\Service\ActiveInterventionTypeResolver;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lot 5 (D-068) — miroir de MaterialItemRequestManagerController.
 *
 * EPIC Revue instrumentiste, Lot 3, commit 5 — resolve() ne construit plus la
 * MissionIntervention lui-même : résout uniquement les entités de référence
 * (InterventionType, Firm) puis délègue toute la transition à
 * MissionInterventionDraftService::resolve(), seul propriétaire du cycle de vie du
 * draft.
 *
 * Commit 6 — ignore() suit désormais le même principe : ne résout que la stratégie et
 * l'éventuelle intervention cible (existence uniquement — l'appartenance à la même
 * mission est un invariant métier vérifié par le service lui-même, sous verrou, même
 * raisonnement que la cohérence draft/request dans resolve()), puis délègue à
 * MissionInterventionDraftService::ignore().
 */
#[Route('/api/intervention-type-requests')]
final class InterventionTypeRequestManagerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionInterventionDraftService $draftService,
        private readonly ActiveInterventionTypeResolver $interventionTypeResolver,
        private readonly ActiveFirmResolver $firmResolver,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * GET /api/intervention-type-requests
     * Filtres optionnels : status (PENDING|RESOLVED|IGNORED)
     */
    #[Route('', name: 'api_intervention_type_requests_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $status = $request->query->get('status');

        $qb = $this->em->getRepository(InterventionTypeRequest::class)
            ->createQueryBuilder('r')
            ->leftJoin('r.mission', 'm')
            ->leftJoin('r.createdBy', 'u')
            ->leftJoin('r.resolvedInterventionType', 'it')
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        /** @var InterventionTypeRequest[] $requests */
        $requests = $qb->getQuery()->getResult();

        return $this->json([
            'items' => array_map(fn (InterventionTypeRequest $r) => $this->serialize($r), $requests),
            'total' => count($requests),
        ]);
    }

    /**
     * POST /api/intervention-type-requests/{id}/resolve
     * Résout une demande en la liant à un InterventionType (existant ou tout juste créé
     * par le manager via POST /api/intervention-types) : crée la MissionIntervention
     * réelle, repointe tout le matériel déjà déclaré par l'instrumentiste sur le draft,
     * transitionne la demande et le draft. Body: { interventionTypeId: int, firmId?: int }
     */
    #[Route('/{id}/resolve', name: 'api_intervention_type_requests_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolve(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(InterventionTypeRequest::class)->find($id);
        if (!$req instanceof InterventionTypeRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        $draft = $req->getDraft();
        if ($draft === null) {
            throw new InterventionTypeRequestWithoutDraftException(sprintf(
                'InterventionTypeRequest #%d has no associated MissionInterventionDraft and cannot be resolved through this workflow.',
                $req->getId(),
            ));
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $interventionTypeId = $body['interventionTypeId'] ?? null;
        if (!$interventionTypeId) {
            return $this->json(['message' => 'interventionTypeId is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Résolues AVANT MissionInterventionDraftService::resolve() : de simples lectures,
        // si l'une échoue rien n'est encore verrouillé/modifié (même principe que
        // InterventionTypeRequestController::create(), commit 3).
        $interventionType = $this->interventionTypeResolver->resolveActive((int) $interventionTypeId);
        $firmId = $body['firmId'] ?? null;
        $firm = $firmId !== null ? $this->firmResolver->resolveActive((int) $firmId) : null;

        $intervention = $this->draftService->resolve($draft, $interventionType, $firm, $user);

        // D-093 — prévient l'instrumentiste (jamais bloquant : dispatché après le succès
        // de la transaction métier, échec de notification isolé dans le handler async).
        $this->bus->dispatch(new CatalogueRequestProcessedMessage(
            kind: CatalogueRequestKind::INTERVENTION_TYPE,
            requestId: $req->getId(),
            accepted: true,
            recipientUserId: $req->getCreatedBy()->getId(),
            missionId: $draft->getMission()->getId(),
            label: $req->getLabel(),
            occurredAt: new \DateTimeImmutable(),
        ));

        return $this->json([
            'requestId' => $req->getId(),
            'draftId' => $draft->getId(),
            'missionInterventionId' => $intervention->getId(),
            'status' => $req->getStatus(),
            'draftStatus' => $draft->getStatus(),
            'orderIndex' => $intervention->getOrderIndex(),
        ]);
    }

    /**
     * POST /api/intervention-type-requests/{id}/ignore
     * Ignore une demande sans créer d'intervention réelle. Body :
     * { strategy?: "KEEP_AS_HISTORY" | "REASSIGN", missionInterventionId?: int }
     * strategy est requis dès que le draft porte du matériel (sinon 422) ;
     * missionInterventionId est requis (et uniquement pertinent) pour REASSIGN.
     */
    #[Route('/{id}/ignore', name: 'api_intervention_type_requests_ignore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ignore(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(InterventionTypeRequest::class)->find($id);
        if (!$req instanceof InterventionTypeRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        $draft = $req->getDraft();
        if ($draft === null) {
            throw new InterventionTypeRequestWithoutDraftException(sprintf(
                'InterventionTypeRequest #%d has no associated MissionInterventionDraft and cannot be ignored through this workflow.',
                $req->getId(),
            ));
        }

        $body = json_decode($request->getContent(), true) ?? [];

        $strategy = null;
        $strategyRaw = $body['strategy'] ?? null;
        if ($strategyRaw !== null) {
            $strategy = MissionInterventionDraftIgnoreStrategy::tryFrom($strategyRaw);
            if ($strategy === null) {
                return $this->json(['message' => 'Invalid strategy'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $reassignTarget = null;
        if ($strategy === MissionInterventionDraftIgnoreStrategy::REASSIGN) {
            $targetId = $body['missionInterventionId'] ?? null;
            if (!$targetId) {
                return $this->json(['message' => 'missionInterventionId is required for the REASSIGN strategy'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            // Existence uniquement : l'appartenance à la même mission est vérifiée par
            // MissionInterventionDraftService::ignore() lui-même, sous verrou (voir
            // docblock de la méthode).
            $reassignTarget = $this->em->find(MissionIntervention::class, (int) $targetId);
            if (!$reassignTarget instanceof MissionIntervention) {
                return $this->json(['message' => 'Mission intervention not found'], Response::HTTP_NOT_FOUND);
            }
        }

        $this->draftService->ignore($draft, $strategy, $reassignTarget, $user);

        $this->bus->dispatch(new CatalogueRequestProcessedMessage(
            kind: CatalogueRequestKind::INTERVENTION_TYPE,
            requestId: $req->getId(),
            accepted: false,
            recipientUserId: $req->getCreatedBy()->getId(),
            missionId: $draft->getMission()->getId(),
            label: $req->getLabel(),
            occurredAt: new \DateTimeImmutable(),
        ));

        return $this->json([
            'requestId' => $req->getId(),
            'draftId' => $draft->getId(),
            'status' => $req->getStatus(),
            'draftStatus' => $draft->getStatus(),
            'missionInterventionId' => $draft->getResolvedMissionIntervention()?->getId(),
        ]);
    }

    private function serialize(InterventionTypeRequest $r): array
    {
        $mission = $r->getMission();
        $by = $r->getCreatedBy();
        $type = $r->getResolvedInterventionType();

        return [
            'id' => $r->getId(),
            'status' => $r->getStatus(),
            'label' => $r->getLabel(),
            'suggestedCode' => $r->getSuggestedCode(),
            'comment' => $r->getComment(),
            'createdAt' => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'mission' => $mission ? [
                'id' => $mission->getId(),
                'site' => $mission->getSite()?->getName(),
            ] : null,
            'requestedBy' => $by ? [
                'id' => $by->getId(),
                'displayName' => trim(($by->getFirstname() ?? '') . ' ' . ($by->getLastname() ?? '')),
            ] : null,
            'resolvedInterventionType' => $type ? [
                'id' => $type->getId(),
                'code' => $type->getCode(),
                'label' => $type->getLabel(),
            ] : null,
        ];
    }
}
