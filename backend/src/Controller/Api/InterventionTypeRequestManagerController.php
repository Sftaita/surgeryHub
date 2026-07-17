<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionInterventionCreateRequest;
use App\Entity\InterventionTypeRequest;
use App\Security\Voter\BillingVoter;
use App\Service\InterventionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot 5 (D-068) — miroir de MaterialItemRequestManagerController.
 */
#[Route('/api/intervention-type-requests')]
final class InterventionTypeRequestManagerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InterventionService $interventionService,
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
     * par le manager via POST /api/intervention-types) et crée la MissionIntervention
     * réelle sur la mission d'origine — impossible de l'avoir créée avant, faute de type.
     * Body: { interventionTypeId: int, primaryFirmId?: int }
     */
    #[Route('/{id}/resolve', name: 'api_intervention_type_requests_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolve(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(InterventionTypeRequest::class)->find($id);
        if (!$req instanceof InterventionTypeRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        if ($req->getStatus() !== InterventionTypeRequest::STATUS_PENDING) {
            return $this->json(['message' => 'Request is not pending'], Response::HTTP_CONFLICT);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $interventionTypeId = $body['interventionTypeId'] ?? null;
        if (!$interventionTypeId) {
            return $this->json(['message' => 'interventionTypeId is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mission = $req->getMission();
        $orderIndex = count($mission->getInterventions());

        $createDto = new MissionInterventionCreateRequest();
        $createDto->interventionTypeId = (int) $interventionTypeId;
        $createDto->primaryFirmId = isset($body['primaryFirmId']) && $body['primaryFirmId'] !== null
            ? (int) $body['primaryFirmId']
            : null;
        $createDto->orderIndex = $orderIndex;

        // Validation (type actif, firme active) déléguée à InterventionService::create() —
        // ses exceptions dédiées (INTERVENTION_TYPE_NOT_FOUND, etc.) sont déjà mappées par
        // ApiExceptionSubscriber, pas besoin de les dupliquer ici.
        $intervention = $this->interventionService->create($mission, $createDto);

        $req->setResolvedInterventionType($intervention->getInterventionType());
        $req->setStatus(InterventionTypeRequest::STATUS_RESOLVED);
        $this->em->flush();

        return $this->json([
            'request'     => $this->serialize($req),
            'intervention' => ['id' => $intervention->getId()],
        ]);
    }

    /**
     * POST /api/intervention-type-requests/{id}/ignore
     */
    #[Route('/{id}/ignore', name: 'api_intervention_type_requests_ignore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ignore(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $req = $this->em->getRepository(InterventionTypeRequest::class)->find($id);
        if (!$req instanceof InterventionTypeRequest) {
            return $this->json(['message' => 'Request not found'], Response::HTTP_NOT_FOUND);
        }

        if ($req->getStatus() !== InterventionTypeRequest::STATUS_PENDING) {
            return $this->json(['message' => 'Request is not pending'], Response::HTTP_CONFLICT);
        }

        $req->setStatus(InterventionTypeRequest::STATUS_IGNORED);
        $this->em->flush();

        return $this->json($this->serialize($req));
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
