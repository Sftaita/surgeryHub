<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionEncodingReopenRequest;
use App\Dto\Request\MissionEncodingRejectRequest;
use App\Entity\Mission;
use App\Entity\User;
use App\Security\Voter\MissionVoter;
use App\Service\MissionEncodingWorkflowService;
use App\Service\MissionMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Lot 7 (D-070) — cycle de vie de l'encodage. `complete` a délibérément deux entrées
 * équivalentes : `POST /api/missions/{id}/submit` (legacy, inchangé pour compatibilité
 * frontend) et `POST /api/missions/{id}/encoding/complete` (nouvelle organisation
 * cohérente demandée pour ce lot) — les deux délèguent au même
 * MissionEncodingWorkflowService::complete(), jamais deux implémentations.
 */
#[Route('/api/missions/{missionId}/encoding')]
final class MissionEncodingWorkflowController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEncodingWorkflowService $workflow,
        private readonly MissionMapper $mapper,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/start', name: 'api_missions_encoding_start', methods: ['POST'])]
    public function start(int $missionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->getMissionOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::ENCODING_START, $mission);

        $mission = $this->workflow->start($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), Response::HTTP_OK);
    }

    #[Route('/complete', name: 'api_missions_encoding_complete', methods: ['POST'])]
    public function complete(int $missionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->getMissionOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::SUBMIT, $mission);

        $mission = $this->workflow->complete($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), Response::HTTP_OK);
    }

    #[Route('/validate', name: 'api_missions_encoding_validate', methods: ['POST'])]
    public function validate(int $missionId, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->getMissionOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::ENCODING_VALIDATE, $mission);

        $mission = $this->workflow->validate($mission, $user);

        return $this->json($this->mapper->toDetailDto($mission, $user), Response::HTTP_OK);
    }

    #[Route('/reject', name: 'api_missions_encoding_reject', methods: ['POST'])]
    public function reject(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->getMissionOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::ENCODING_REJECT, $mission);

        /** @var MissionEncodingRejectRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionEncodingRejectRequest::class);

        $mission = $this->workflow->reject($mission, $user, $dto->comment);

        return $this->json($this->mapper->toDetailDto($mission, $user), Response::HTTP_OK);
    }

    #[Route('/reopen', name: 'api_missions_encoding_reopen', methods: ['POST'])]
    public function reopen(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->getMissionOr404($missionId);
        $this->denyAccessUnlessGranted(MissionVoter::ENCODING_REOPEN, $mission);

        /** @var MissionEncodingReopenRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionEncodingReopenRequest::class);

        $mission = $this->workflow->reopen($mission, $user, $dto->comment);

        return $this->json($this->mapper->toDetailDto($mission, $user), Response::HTTP_OK);
    }

    private function getMissionOr404(int $id): Mission
    {
        $mission = $this->em->find(Mission::class, $id);
        if (!$mission instanceof Mission) {
            throw new NotFoundHttpException('Mission not found');
        }
        return $mission;
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize($json === '' ? '{}' : $json, $class, 'json');

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        return $dto;
    }
}
