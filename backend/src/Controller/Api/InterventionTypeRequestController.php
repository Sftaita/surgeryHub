<?php

namespace App\Controller\Api;

use App\Dto\Request\InterventionTypeRequestCreateRequest;
use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\InterventionTypeRequestService;
use App\Service\MissionEncodingGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Lot 5 (D-068) — "demande de nouveau type", miroir de MaterialItemRequestController.
 * Aucune MissionIntervention n'est créée ici : c'est le manager qui, en résolvant la
 * demande (InterventionTypeRequestManagerController::resolve), crée l'intervention
 * réelle avec le type choisi/créé.
 */
#[Route('/api/missions/{missionId}/intervention-type-requests')]
class InterventionTypeRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InterventionTypeRequestService $service,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var InterventionTypeRequestCreateRequest $dto */
        $dto = $this->serializer->deserialize(
            $request->getContent(),
            InterventionTypeRequestCreateRequest::class,
            'json'
        );

        $req = $this->service->create($mission, $dto, $user);

        return $this->json(['id' => $req->getId()], Response::HTTP_CREATED);
    }
}
