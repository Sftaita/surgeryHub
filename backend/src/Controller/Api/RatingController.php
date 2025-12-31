<?php

namespace App\Controller\Api;

use App\Dto\Request\InstrumentistRatingRequest;
use App\Dto\Request\SurgeonRatingRequest;
use App\Security\Voter\RatingVoter;
use App\Service\MissionService;
use App\Service\RatingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/missions')]
class RatingController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly RatingService $ratingService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/{id}/instrumentist-rating', name: 'api_rating_instrumentist', methods: ['POST'])]
    public function rateInstrumentist(int $id, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(RatingVoter::RATE_INSTRUMENTIST, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), InstrumentistRatingRequest::class);
        $instrumentist = $this->ratingService->getInstrumentistForMission($mission);
        $rating = $this->ratingService->rateInstrumentist($mission, $this->getUser(), $instrumentist, $dto);

        return $this->json($rating, JsonResponse::HTTP_CREATED, [], ['groups' => 'rating:read']);
    }

    #[Route('/{id}/surgeon-rating', name: 'api_rating_surgeon', methods: ['POST'])]
    public function rateSurgeon(int $id, Request $request): JsonResponse
    {
        $mission = $this->missionService->getOr404($id);
        $this->denyAccessUnlessGranted(RatingVoter::RATE_SURGEON, $mission);

        $dto = $this->deserializeAndValidate($request->getContent(), SurgeonRatingRequest::class);
        $rating = $this->ratingService->rateSurgeon($mission, $this->getUser(), $mission->getSurgeon(), $dto);

        return $this->json($rating, JsonResponse::HTTP_CREATED, [], ['groups' => 'rating:read']);
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
