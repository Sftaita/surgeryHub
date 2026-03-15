<?php

namespace App\Controller\Api;

use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\User;
use App\Service\MaterialItemMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/material-item-requests')]
final class MaterialItemRequestManagerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialItemMapper $mapper,
    ) {}

    /**
     * GET /api/material-item-requests
     * Liste toutes les demandes (manager).
     * Filtres optionnels : status (PENDING|RESOLVED|IGNORED)
     */
    #[Route('', name: 'api_material_item_requests_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
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
        $req = $this->em->getRepository(MaterialItemRequest::class)->find($id);
        if (!$req instanceof MaterialItemRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
            return $this->json(['message' => 'Request is not pending'], Response::HTTP_CONFLICT);
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

        // 1. Associer la demande au matériel
        $req->setMaterialItem($mi);
        $req->setStatus(MaterialItemRequest::STATUS_RESOLVED);

        // 2. Créer une MaterialLine sur la mission (réconciliation)
        $line = new MaterialLine();
        $line->setMission($req->getMission());
        $line->setMissionIntervention($req->getMissionIntervention());
        $line->setItem($mi);
        $line->setQuantity('1.00');
        $line->setComment($req->getComment());
        $line->setCreatedBy($user);

        $this->em->persist($line);
        $this->em->flush();

        return $this->json([
            'request'      => $this->serialize($req),
            'materialLine' => ['id' => $line->getId()],
        ]);
    }

    /**
     * POST /api/material-item-requests/{id}/ignore
     * Ignore une demande.
     */
    #[Route('/{id}/ignore', name: 'api_material_item_requests_ignore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ignore(int $id): JsonResponse
    {
        $req = $this->em->getRepository(MaterialItemRequest::class)->find($id);
        if (!$req instanceof MaterialItemRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
            return $this->json(['message' => 'Request is not pending'], Response::HTTP_CONFLICT);
        }

        $req->setStatus(MaterialItemRequest::STATUS_IGNORED);
        $this->em->flush();

        return $this->json($this->serialize($req));
    }

    private function serialize(MaterialItemRequest $r): array
    {
        $mission = $r->getMission();
        $by      = $r->getCreatedBy();
        $mi      = $r->getMaterialItem();

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
        ];
    }
}
