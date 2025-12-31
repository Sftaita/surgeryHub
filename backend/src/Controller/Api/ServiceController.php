<?php

namespace App\Controller\Api;

use App\Dto\Request\ServiceDisputeCreateRequest;
use App\Dto\Request\ServiceDisputeUpdateRequest;
use App\Dto\Request\ServiceUpdateRequest;
use App\Entity\InstrumentistService;
use App\Enum\ServiceType;
use App\Security\Voter\ServiceVoter;
use App\Service\InstrumentistServiceManager;
use App\Service\MissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class ServiceController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly InstrumentistServiceManager $serviceManager,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/missions/{missionId}/service', name: 'api_service_update', methods: ['PATCH'])]
    public function updateService(int $missionId, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $service = $this->findOrCreateService($missionId);

        $this->denyAccessUnlessGranted(ServiceVoter::UPDATE, $service);

        $dto = $this->deserializeAndValidate($request->getContent(), ServiceUpdateRequest::class);
        $service = $this->serviceManager->updateService($service, $dto);

        $group = $this->isManager() ? 'service:read_manager' : 'service:read';

        return $this->json($service, JsonResponse::HTTP_OK, [], ['groups' => $group]);
    }

    #[Route('/services/{serviceId}/disputes', name: 'api_dispute_create', methods: ['POST'])]
    public function createDispute(int $serviceId, Request $request): JsonResponse
    {
        $service = $this->serviceManager->getServiceOr404($serviceId);
        $mission = $service->getMission() ?? throw $this->createNotFoundException('Mission not found for service');
        $this->denyAccessUnlessGranted(ServiceVoter::DISPUTE_CREATE, $service);

        $dto = $this->deserializeAndValidate($request->getContent(), ServiceDisputeCreateRequest::class);
        $dispute = $this->serviceManager->createDispute($mission, $service, $this->getUser(), $dto);

        return $this->json($dispute, JsonResponse::HTTP_CREATED, [], ['groups' => 'dispute:read']);
    }

    #[Route('/disputes', name: 'api_dispute_list', methods: ['GET'])]
    public function listDisputes(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(ServiceVoter::DISPUTE_MANAGE, new InstrumentistService());
        $status = $request->query->get('status');
        $page = (int) ($request->query->get('page', 1));
        $limit = (int) ($request->query->get('limit', 20));

        $result = $this->serviceManager->listDisputes($status, $page, $limit);

        return $this->json($result, JsonResponse::HTTP_OK, [], ['groups' => 'dispute:read_manager']);
    }

    #[Route('/disputes/{id}', name: 'api_dispute_update', methods: ['PATCH'])]
    public function updateDispute(int $id, Request $request): JsonResponse
    {
        $dispute = $this->serviceManager->getDisputeOr404($id);
        $this->denyAccessUnlessGranted(ServiceVoter::DISPUTE_MANAGE, $dispute);

        $dto = $this->deserializeAndValidate($request->getContent(), ServiceDisputeUpdateRequest::class);
        $dispute = $this->serviceManager->updateDispute($dispute, $dto);

        return $this->json($dispute, JsonResponse::HTTP_OK, [], ['groups' => 'dispute:read_manager']);
    }

    private function findOrCreateService(int $missionId): InstrumentistService
    {
        $mission = $this->missionService->getOr404($missionId);
        $service = $this->em->getRepository(InstrumentistService::class)->findOneBy(['mission' => $mission]);
        if ($service) {
            return $service;
        }

        $service = new InstrumentistService();
        $service->setMission($mission);
        $service->setServiceType($mission->getType() === null ? ServiceType::BLOCK : ServiceType::from($mission->getType()->value));
        $service->setEmploymentTypeSnapshot($mission->getInstrumentist()?->getEmploymentType());

        $this->em->persist($service);
        $this->em->flush();

        return $service;
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
