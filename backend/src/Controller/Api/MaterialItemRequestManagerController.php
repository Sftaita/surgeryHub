<?php

namespace App\Controller\Api;

use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\User;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Enum\CatalogueRequestKind;
use App\Message\CatalogueRequestProcessedMessage;
use App\Security\Voter\BillingVoter;
use App\Service\MaterialItemMapper;
use App\Service\CatalogueResolutionGuard;
use App\Service\MaterialItemRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/material-item-requests')]
final class MaterialItemRequestManagerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialItemMapper $mapper,
        private readonly MessageBusInterface $bus,
        private readonly MaterialItemRequestService $requestService,
        private readonly CatalogueResolutionGuard $resolutionGuard,
    ) {}

    /**
     * GET /api/material-item-requests
     * Liste toutes les demandes (manager).
     * Filtres optionnels : status (PENDING|RESOLVED|IGNORED)
     */
    #[Route('', name: 'api_material_item_requests_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $status = $request->query->get('status');

        $qb = $this->em->getRepository(MaterialItemRequest::class)
            ->createQueryBuilder('r')
            ->leftJoin('r.mission', 'm')
            ->leftJoin('r.createdBy', 'u')
            ->leftJoin('r.materialItem', 'mi')
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        /** @var MaterialItemRequest[] $requests */
        $requests = $qb->getQuery()->getResult();

        return $this->json([
            'items' => array_map(fn (MaterialItemRequest $r) => $this->serialize($r), $requests),
            'total' => count($requests),
        ]);
    }

    /**
     * GET /api/material-item-requests/{id}/duplicate-candidates
     * D-146 bis — aide à la détection des doublons (lecture seule) : matériels actifs dont
     * le nom ou la référence ressemble à la demande, toutes firmes, firme proposée d'abord.
     */
    #[Route('/{id}/duplicate-candidates', name: 'api_material_item_requests_duplicate_candidates', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function duplicateCandidates(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(MaterialItemRequest::class)->find($id);
        if (!$req instanceof MaterialItemRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['items' => array_map(MaterialItemRequestService::itemSummary(...), $this->requestService->duplicateCandidates($req))]);
    }

    /**
     * POST /api/material-item-requests/{id}/resolve
     * D-146 bis — résolution ATOMIQUE (une seule transaction) :
     *  - associer un matériel existant : { materialItemId, quantity? } — sa firme et ses
     *    tarifs réels sont repris, jamais modifiés ;
     *  - OU créer la référence : { newItem: {label, unit, referenceCode?, isImplant?,
     *    firmId? | newFirmName?}, quantity?, confirmPossibleDuplicate? } — matériel
     *    `UNSPECIFIED`, aucun tarif ; firme créée seulement si le manager le demande.
     * `quantity` : quantité validée (défaut : celle de la demande). Crée la MaterialLine sur
     * la cible de la demande. Refus explicites : 409 mission verrouillée, doublon,
     * matériel incompatible ; 422 firme/quantité manquante.
     */
    #[Route('/{id}/resolve', name: 'api_material_item_requests_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolve(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(MaterialItemRequest::class)->find($id);
        if (!$req instanceof MaterialItemRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        // Verrouillage pessimiste, vérification PENDING, garde mission, création éventuelle
        // firme/matériel, ligne et traçabilité : tout est délégué au service, dans une seule
        // transaction (rien n'est partiellement enregistré en cas de refus).
        [$req, $line, $created] = $this->requestService->resolve($req, $body, $user);

        // D-093 — prévient l'instrumentiste (jamais bloquant, voir handler async).
        $this->bus->dispatch(new CatalogueRequestProcessedMessage(
            kind: CatalogueRequestKind::MATERIAL_ITEM,
            requestId: $req->getId(),
            accepted: true,
            recipientUserId: $req->getCreatedBy()->getId(),
            missionId: $req->getMission()->getId(),
            label: $req->getLabel(),
            occurredAt: new \DateTimeImmutable(),
            resolution: [
                'name'         => $line->getItem()->getLabel(),
                'reference'    => $line->getItem()->getReferenceCode() ?: null,
                'firm'         => $line->getItem()->getFirm()?->getName(),
                'quantity'     => $line->getQuantity(),
                'existingItem' => !$created['createdItem'],
            ],
        ));

        return $this->json([
            'request'      => $this->serialize($req),
            'materialLine' => ['id' => $line->getId(), 'quantity' => $line->getQuantity()],
            'createdFirm'  => $created['createdFirm'],
            'createdItem'  => $created['createdItem'],
        ]);
    }

    /**
     * POST /api/material-item-requests/{id}/ignore
     * Ignore une demande. Body : { reason: CatalogueRequestIgnoreReason, comment: string }
     * — motif et explication toujours obligatoires (jamais seulement pour OTHER).
     */
    #[Route('/{id}/ignore', name: 'api_material_item_requests_ignore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ignore(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(MaterialItemRequest::class)->find($id);
        if (!$req instanceof MaterialItemRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        $reason = CatalogueRequestIgnoreReason::tryFrom((string) ($body['reason'] ?? ''));
        if ($reason === null) {
            return $this->json(['message' => 'reason is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $comment = trim((string) ($body['comment'] ?? ''));
        if ($comment === '') {
            return $this->json(['message' => 'comment is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Verrouillage pessimiste + vérification PENDING délégués au service (même
        // raisonnement que resolve() ci-dessus).
        $req = $this->requestService->ignore($req, $reason, $comment, $user);

        $this->bus->dispatch(new CatalogueRequestProcessedMessage(
            kind: CatalogueRequestKind::MATERIAL_ITEM,
            requestId: $req->getId(),
            accepted: false,
            recipientUserId: $req->getCreatedBy()->getId(),
            missionId: $req->getMission()->getId(),
            label: $req->getLabel(),
            occurredAt: new \DateTimeImmutable(),
            ignoreReason: $reason,
            explanation: $comment,
        ));

        return $this->json($this->serialize($req));
    }

    private function serialize(MaterialItemRequest $r): array
    {
        $mission   = $r->getMission();
        $by        = $r->getCreatedBy();
        $mi        = $r->getMaterialItem();
        $decidedBy = $r->getDecidedBy();

        $proposedFirm = $r->getProposedFirm();
        $target       = $r->getMissionIntervention();
        $draft        = $r->getInterventionDraft();

        return [
            'id'            => $r->getId(),
            'status'        => $r->getStatus(),
            'label'         => $r->getLabel(),
            'referenceCode' => $r->getReferenceCode(),
            'comment'       => $r->getComment(),
            // D-146 bis — ce que l'instrumentiste a déclaré : firme proposée (existante ou
            // nom libre) et quantité. NULL pour les demandes antérieures (jamais recueilli).
            'proposedFirm'     => $proposedFirm ? ['id' => $proposedFirm->getId(), 'name' => $proposedFirm->getName(), 'active' => $proposedFirm->isActive()] : null,
            'proposedFirmName' => $r->getProposedFirmName(),
            'quantity'         => $r->getQuantity(),
            'target'           => $target ? [
                'kind'  => 'INTERVENTION',
                'id'    => $target->getId(),
                'label' => $target->getLabel(),
                'firm'  => $target->getPrimaryFirm() ? ['id' => $target->getPrimaryFirm()->getId(), 'name' => $target->getPrimaryFirm()->getName()] : null,
            ] : ($draft ? [
                'kind'  => 'DRAFT',
                'id'    => $draft->getId(),
                'label' => $draft->getLabel(),
                'firm'  => $draft->getRequestedFirm() ? ['id' => $draft->getRequestedFirm()->getId(), 'name' => $draft->getRequestedFirm()->getName()] : null,
            ] : null),
            'createdAt'     => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'mission'       => $mission ? [
                'id'   => $mission->getId(),
                'site' => $mission->getSite()?->getName(),
                'status' => $mission->getStatus()->value,
                // D-146 bis — la résolution est refusée sur une mission verrouillée ; le
                // manager le voit avant d'essayer (jamais une réouverture automatique).
                'locked' => $this->resolutionGuard->isLocked($mission),
                'reopenAvailable' => $this->resolutionGuard->isLocked($mission) && $this->resolutionGuard->reopenAvailable($mission),
            ] : null,
            'requestedBy'   => $by ? [
                'id'          => $by->getId(),
                'displayName' => trim(($by->getFirstname() ?? '') . ' ' . ($by->getLastname() ?? '')),
            ] : null,
            'materialItem'  => $mi ? $this->mapper->toSlim($mi) : null,
            'ignoreReason'  => $r->getIgnoreReason()?->value,
            'ignoreComment' => $r->getIgnoreComment(),
            'decidedBy'     => $decidedBy ? [
                'id'          => $decidedBy->getId(),
                'displayName' => trim(($decidedBy->getFirstname() ?? '') . ' ' . ($decidedBy->getLastname() ?? '')),
            ] : null,
            'decidedAt'     => $r->getDecidedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
