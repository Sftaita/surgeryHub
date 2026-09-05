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
     * POST /api/material-item-requests/{id}/resolve
     * Résout une demande en la liant à un MaterialItem existant.
     * Crée une MaterialLine sur la mission concernée.
     * Body: { materialItemId: int }
     */
    #[Route('/{id}/resolve', name: 'api_material_item_requests_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolve(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(MaterialItemRequest::class)->find($id);
        if (!$req instanceof MaterialItemRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        $body           = json_decode($request->getContent(), true) ?? [];
        $materialItemId = $body['materialItemId'] ?? null;

        if (!$materialItemId) {
            return $this->json(['message' => 'materialItemId is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mi = $this->em->getRepository(MaterialItem::class)->find((int) $materialItemId);
        if (!$mi instanceof MaterialItem) {
            return $this->json(['message' => 'MaterialItem not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Verrouillage pessimiste + vérification PENDING délégués au service (§ 7 de
        // l'audit — deux managers ouvrant simultanément la même demande) ; une demande
        // déjà traitée lève MaterialItemRequestAlreadyProcessedException, traduite en 409
        // par ApiExceptionSubscriber.
        [$req, $line] = $this->requestService->resolve($req, $mi, $user);

        // D-093 — prévient l'instrumentiste (jamais bloquant, voir handler async).
        $this->bus->dispatch(new CatalogueRequestProcessedMessage(
            kind: CatalogueRequestKind::MATERIAL_ITEM,
            requestId: $req->getId(),
            accepted: true,
            recipientUserId: $req->getCreatedBy()->getId(),
            missionId: $req->getMission()->getId(),
            label: $req->getLabel(),
            occurredAt: new \DateTimeImmutable(),
        ));

        return $this->json([
            'request'      => $this->serialize($req),
            'materialLine' => ['id' => $line->getId()],
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

        return [
            'id'            => $r->getId(),
            'status'        => $r->getStatus(),
            'label'         => $r->getLabel(),
            'referenceCode' => $r->getReferenceCode(),
            'comment'       => $r->getComment(),
            'createdAt'     => $r->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'mission'       => $mission ? [
                'id'   => $mission->getId(),
                'site' => $mission->getSite()?->getName(),
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
