<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\UserAdministrationVoter;
use App\Service\UserEmailChangeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserEmailChangeService $userEmailChangeService,
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

    /**
     * PATCH /api/users/{id}/email — manager/admin change the login email of any user
     * (instrumentist or surgeon). Form validation only here — every business rule
     * (emptiness, format, duplicate, same-as-current) lives in the service.
     */
    #[Route('/{id}/email', name: 'api_users_patch_email', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patchEmail(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::UPDATE_EMAIL);

        $target = $this->userRepository->find($id);
        if (!$target) {
            throw new NotFoundHttpException('User not found');
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['email']) || !is_string($data['email'])) {
            throw new BadRequestHttpException('"email" is required and must be a string.');
        }

        $result = $this->userEmailChangeService->changeEmail($actor, $target, $data['email']);

        return $this->json([
            'user' => $this->toUserPayload($result['user']),
            'warnings' => $result['warnings'],
        ]);
    }

    private function toUserPayload(User $user): array
    {
        $firstname = $user->getFirstname();
        $lastname = $user->getLastname();
        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'displayName' => $name !== '' ? $name : (string) $user->getEmail(),
            'profilePicturePath' => $user->getProfilePicturePath(),
        ];
    }
}
