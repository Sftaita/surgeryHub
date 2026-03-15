<?php

namespace App\Controller\Api;

use App\Dto\Request\AddInstrumentistSiteMembershipRequest;
use App\Dto\Request\CreateSurgeonRequest;
use App\Entity\User;
use App\Security\Voter\SurgeonVoter;
use App\Service\NotificationService;
use App\Service\SurgeonServiceManager;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/surgeons')]
final class SurgeonController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SurgeonServiceManager $service,
        private readonly NotificationService $notifications,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'api_surgeons_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonVoter::LIST, User::class);

        $q = $request->query->getString('q', '');
        $activeParam = $request->query->get('active');
        $activeOnly = $activeParam === null ? true : in_array($activeParam, ['1', 'true', true], true);

        $items = $this->users->findSurgeons($q !== '' ? $q : null, $activeOnly);

        $payload = array_map(static function (User $u): array {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();
            $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

            return [
                'id' => $u->getId(),
                'email' => $u->getEmail(),
                'firstname' => $firstname,
                'lastname' => $lastname,
                'active' => $u->isActive(),
                'displayName' => $name !== '' ? $name : (string) $u->getEmail(),
                'profilePicturePath' => $u->getProfilePicturePath(),
            ];
        }, $items);

        return $this->json(['items' => $payload, 'total' => count($payload)]);
    }

    #[Route('/{id}', name: 'api_surgeons_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonVoter::VIEW, User::class);

        $surgeon = $this->users->findSurgeonById($id);
        if (!$surgeon instanceof User) {
            return $this->json(['message' => 'Surgeon not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->buildProfilePayload($surgeon));
    }

    #[Route('', name: 'api_surgeons_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonVoter::CREATE, User::class);

        /** @var CreateSurgeonRequest $dto */
        $dto = $this->serializer->deserialize($request->getContent(), CreateSurgeonRequest::class, 'json');

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = ['field' => $v->getPropertyPath(), 'message' => $v->getMessage()];
            }
            return $this->json(['message' => 'Validation failed', 'violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $surgeon = $this->service->createSurgeon($dto);

        $warnings = [];
        try {
            $this->notifications->sendSurgeonInvitation($surgeon);
        } catch (\Throwable) {
            $warnings[] = ['code' => 'INVITATION_EMAIL_NOT_SENT', 'message' => 'Surgeon created but invitation email could not be queued.'];
        }

        $firstname = $surgeon->getFirstname();
        $lastname = $surgeon->getLastname();
        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        return $this->json([
            'surgeon' => [
                'id' => $surgeon->getId(),
                'email' => $surgeon->getEmail(),
                'firstname' => $firstname,
                'lastname' => $lastname,
                'displayName' => $name !== '' ? $name : (string) $surgeon->getEmail(),
                'active' => $surgeon->isActive(),
                'siteIds' => $dto->siteIds,
                'invitationExpiresAt' => $surgeon->getInvitationExpiresAt()?->format(\DateTimeInterface::ATOM),
            ],
            'warnings' => $warnings,
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/planning', name: 'api_surgeons_planning', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function planning(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonVoter::VIEW, User::class);

        $surgeon = $this->users->findSurgeonById($id);
        if (!$surgeon instanceof User) {
            return $this->json(['message' => 'Surgeon not found'], Response::HTTP_NOT_FOUND);
        }

        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        if (!is_string($fromStr) || $fromStr === '') {
            return $this->json(['message' => 'Query parameter "from" is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_string($toStr) || $toStr === '') {
            return $this->json(['message' => 'Query parameter "to" is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
        } catch (\Exception) {
            return $this->json(['message' => 'Invalid from datetime format (ISO 8601 expected)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $to = new \DateTimeImmutable($toStr);
        } catch (\Exception) {
            return $this->json(['message' => 'Invalid to datetime format (ISO 8601 expected)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($from >= $to) {
            return $this->json(['message' => 'from must be strictly before to'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->service->getPlanning($surgeon, $from, $to));
    }

    #[Route('/{id}/site-memberships', name: 'api_surgeons_add_site_membership', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addSiteMembership(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonVoter::ADD_SITE_MEMBERSHIP, User::class);

        $surgeon = $this->users->findSurgeonById($id);
        if (!$surgeon instanceof User) {
            return $this->json(['message' => 'Surgeon not found'], Response::HTTP_NOT_FOUND);
        }

        /** @var AddInstrumentistSiteMembershipRequest $dto */
        $dto = $this->serializer->deserialize($request->getContent(), AddInstrumentistSiteMembershipRequest::class, 'json');

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = ['field' => $v->getPropertyPath(), 'message' => $v->getMessage()];
            }
            return $this->json(['message' => 'Validation failed', 'violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $membership = $this->service->addSiteMembership($surgeon, $dto);

        return $this->json([
            'id' => $membership->getId(),
            'site' => ['id' => $membership->getSite()?->getId(), 'name' => $membership->getSite()?->getName()],
            'siteRole' => $membership->getSiteRole(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/site-memberships/{membershipId}', name: 'api_surgeons_delete_site_membership', methods: ['DELETE'], requirements: ['id' => '\d+', 'membershipId' => '\d+'])]
    public function deleteSiteMembership(int $id, int $membershipId): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonVoter::DELETE_SITE_MEMBERSHIP, User::class);

        $surgeon = $this->users->findSurgeonById($id);
        if (!$surgeon instanceof User) {
            return $this->json(['message' => 'Surgeon not found'], Response::HTTP_NOT_FOUND);
        }

        $this->service->deleteSiteMembership($surgeon, $membershipId);

        return $this->json(['id' => $membershipId, 'deleted' => true]);
    }

    private function buildProfilePayload(User $surgeon): array
    {
        $firstname = $surgeon->getFirstname();
        $lastname = $surgeon->getLastname();
        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        $siteMemberships = [];
        foreach ($surgeon->getSiteMemberships() as $membership) {
            $site = $membership->getSite();
            if (!$site || $site->getId() === null) continue;
            $siteMemberships[] = [
                'id' => $membership->getId(),
                'site' => ['id' => $site->getId(), 'name' => $site->getName()],
                'siteRole' => $membership->getSiteRole(),
            ];
        }

        return [
            'id' => $surgeon->getId(),
            'email' => $surgeon->getEmail(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'displayName' => $name !== '' ? $name : (string) $surgeon->getEmail(),
            'active' => $surgeon->isActive(),
            'profilePicturePath' => $surgeon->getProfilePicturePath(),
            'siteMemberships' => $siteMemberships,
        ];
    }
}
