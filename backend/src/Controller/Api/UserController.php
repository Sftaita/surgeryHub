<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('/{id}/specialties', methods: ['PATCH'])]
    public function patchSpecialties(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('PLANNING_MANAGE');

        $user = $this->userRepository->find($id);
        if (!$user) {
            return $this->json(['error' => ['message' => 'User not found']], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $specialties = $data['specialties'] ?? [];

        if (!is_array($specialties)) {
            return $this->json(['error' => ['message' => 'specialties must be an array']], Response::HTTP_BAD_REQUEST);
        }

        $user->setSpecialties(array_values(array_filter($specialties, 'is_string')));
        $this->em->flush();

        return $this->json(['id' => $user->getId(), 'specialties' => $user->getSpecialties()]);
    }
}
