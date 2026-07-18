<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionExecutionDisputeCreateRequest;
use App\Dto\Request\MissionExecutionDisputeUpdateRequest;
use App\Dto\Request\ServiceUpdateRequest;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Security\Voter\MissionExecutionVoter;
use App\Service\MissionExecutionService;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Lot 1 (Exécution & Valorisation) — endpoints LEGACY conservés à l'identique pour ne
 * pas casser le frontend existant (EditServiceHoursDialog.tsx), délégués au nouveau
 * modèle MissionExecution/MissionExecutionService. `hours` (décimal, legacy) est
 * converti en actualDurationMinutes (entier) à l'entrée ; `consultationFeeApplied`/
 * `status` sont acceptés (compatibilité de payload) mais ignorés — ce sont les champs
 * financiers morts retirés par ce lot.
 *
 * Les nouveaux endpoints /api/missions/{id}/execution (MissionExecutionController)
 * sont la forme cible ; ceux-ci restent en place tant que le frontend n'a pas basculé.
 */
#[Route('/api')]
class ServiceController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly MissionExecutionService $executionService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/missions/{missionId}/service', name: 'api_service_update', methods: ['PATCH'])]
    public function updateService(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionExecutionVoter::UPDATE, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), ServiceUpdateRequest::class);

        // legacy: hours = durée décimale en heures ; nouveau modèle: minutes entières.
        $durationMinutes = $dto->hours !== null ? (int) round($dto->hours * 60) : null;

        $execution = $this->executionService->updateActuals(
            $mission,
            $user,
            null,
            null,
            $durationMinutes,
            $dto->hoursSource,
        );

        $group = $this->isManager() ? 'execution:read_manager' : 'execution:read';

        return $this->json($execution, JsonResponse::HTTP_OK, [], ['groups' => $group]);
    }

    #[Route('/services/{serviceId}/disputes', name: 'api_dispute_create', methods: ['POST'])]
    public function createDispute(int $serviceId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $execution = $this->executionService->getExecutionOr404($serviceId);
        $mission = $execution->getMission() ?? throw $this->createNotFoundException('Mission not found for execution');
        $this->denyAccessUnlessGranted(MissionExecutionVoter::DISPUTE_CREATE, $execution);

        $dto = $this->deserializeAndValidate($request->getContent(), MissionExecutionDisputeCreateRequest::class);
        $dispute = $this->executionService->openDispute($mission, $execution, $user, $dto->reasonCode, $dto->comment);

        return $this->json($dispute, JsonResponse::HTTP_CREATED, [], ['groups' => 'dispute:read']);
    }

    #[Route('/disputes', name: 'api_dispute_list', methods: ['GET'])]
    public function listDisputes(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MissionExecutionVoter::DISPUTE_MANAGE, new MissionExecutionDispute());
        $status = $request->query->get('status');
        $page = (int) ($request->query->get('page', 1));
        $limit = (int) ($request->query->get('limit', 20));

        $result = $this->executionService->listDisputes($status, $page, $limit);

        return $this->json($result, JsonResponse::HTTP_OK, [], ['groups' => 'dispute:read_manager']);
    }

    #[Route('/disputes/{id}', name: 'api_dispute_update', methods: ['PATCH'])]
    public function updateDispute(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dispute = $this->executionService->getDisputeOr404($id);
        $this->denyAccessUnlessGranted(MissionExecutionVoter::DISPUTE_MANAGE, $dispute);

        $dto = $this->deserializeAndValidate($request->getContent(), MissionExecutionDisputeUpdateRequest::class);
        $dispute = $this->executionService->updateDispute($dispute, $user, $dto->status, $dto->resolutionComment);

        return $this->json($dispute, JsonResponse::HTTP_OK, [], ['groups' => 'dispute:read_manager']);
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize($json, $class, 'json');
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw $this->createBadRequestException((string) $errors);
        }

        return $dto;
    }

    private function createBadRequestException(string $message): \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
    {
        return new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($message);
    }

    private function isManager(): bool
    {
        $roles = $this->getUser()?->getRoles() ?? [];
        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }
}
