<?php

namespace App\Controller\Api;

use App\Dto\Request\ExportSurgeonActivityRequest;
use App\Security\Voter\ExportVoter;
use App\Service\ExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/exports')]
class ExportController extends AbstractController
{
    public function __construct(
        private readonly ExportService $exportService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/surgeon-activity', name: 'api_export_surgeon_activity', methods: ['POST'])]
    public function exportSurgeonActivity(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(ExportVoter::SURGEON_ACTIVITY, $user);

        $dto = $this->deserializeAndValidate($request->getContent(), ExportSurgeonActivityRequest::class);
        $result = $this->exportService->exportSurgeonActivity($user, $dto);

        return $this->json($result, JsonResponse::HTTP_OK, [], ['groups' => 'export:read']);
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
}
