<?php

namespace App\Controller\Api;

use App\Dto\Request\AdminChangeRoleRequest;
use App\Dto\Request\AdminCreateUserRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\UserAdministrationVoter;
use App\Service\UserAdministrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/admin/users')]
final class AdminUserController extends AbstractController
{
    use AdminResponseTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAdministrationService $adminService,
        private readonly EntityManagerInterface $em,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    /** GET /api/admin/users */
    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::LIST);

        $search      = $request->query->getString('search', '');
        $role        = $request->query->getString('role', '');
        $siteId      = $request->query->getInt('siteId', 0) ?: null;
        $activeParam = $request->query->get('active');
        $active      = $activeParam !== null ? in_array($activeParam, ['1', 'true'], true) : null;

        $items = $this->users->findAllUsers(
            search: $search !== '' ? $search : null,
            role:   $role   !== '' ? $role   : null,
            active: $active,
            siteId: $siteId,
        );

        return $this->json([
            'items' => array_map([$this, 'toListItem'], $items),
            'total' => count($items),
        ]);
    }

    /** GET /api/admin/users/{id} */
    #[Route('/{id}', name: 'api_admin_users_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::VIEW);

        return $this->json($this->toDetailPayload($this->findUserOr404($id)));
    }

    /** POST /api/admin/users */
    #[Route('', name: 'api_admin_users_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::CREATE);

        /** @var AdminCreateUserRequest $dto */
        $dto  = $this->deserialize($request->getContent(), AdminCreateUserRequest::class);
        $user = $this->adminService->createUser($dto, $admin);

        $warnings = [];
        if ($user->getInvitationLastSentAt() === null) {
            $warnings[] = [
                'code'    => 'INVITATION_EMAIL_NOT_SENT',
                'message' => 'User created but the invitation email could not be queued.',
            ];
        }

        return $this->json(['user' => $this->toDetailPayload($user), 'warnings' => $warnings], Response::HTTP_CREATED);
    }

    /** PATCH /api/admin/users/{id} — mise à jour des champs d'identité */
    #[Route('/{id}', name: 'api_admin_users_patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::UPDATE);

        $user = $this->findUserOr404($id);
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON');
        }

        if (array_key_exists('firstname', $data)) {
            $user->setFirstname($data['firstname'] !== '' ? (string) $data['firstname'] : null);
        }
        if (array_key_exists('lastname', $data)) {
            $user->setLastname($data['lastname'] !== '' ? (string) $data['lastname'] : null);
        }
        if (array_key_exists('phone', $data)) {
            $user->setPhone($data['phone'] !== '' ? (string) $data['phone'] : null);
        }

        $this->em->flush();

        return $this->json($this->toDetailPayload($user));
    }

    /** POST /api/admin/users/{id}/suspend */
    #[Route('/{id}/suspend', name: 'api_admin_users_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::SUSPEND);

        $user = $this->adminService->suspendUser($this->findUserOr404($id), $admin);

        return $this->json(['id' => $user->getId(), 'active' => $user->isActive()]);
    }

    /** POST /api/admin/users/{id}/activate */
    #[Route('/{id}/activate', name: 'api_admin_users_activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::ACTIVATE);

        $user = $this->adminService->activateUser($this->findUserOr404($id), $admin);

        return $this->json(['id' => $user->getId(), 'active' => $user->isActive()]);
    }

    /** POST /api/admin/users/{id}/change-role */
    #[Route('/{id}/change-role', name: 'api_admin_users_change_role', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeRole(int $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::CHANGE_ROLE);

        /** @var AdminChangeRoleRequest $dto */
        $dto  = $this->deserialize($request->getContent(), AdminChangeRoleRequest::class);
        $user = $this->adminService->changeRole($this->findUserOr404($id), (string) $dto->newRole, $admin);

        return $this->json($this->toDetailPayload($user));
    }

    /** POST /api/admin/users/{id}/resend-invitation */
    #[Route('/{id}/resend-invitation', name: 'api_admin_users_resend_invitation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendInvitation(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::RESEND_INVITATION);

        $user = $this->adminService->resendInvitation($this->findUserOr404($id), $admin);

        return $this->json([
            'id'                   => $user->getId(),
            'invitationStatus'     => UserAdministrationService::computeInvitationStatus($user),
            'invitationExpiresAt'  => $user->getInvitationExpiresAt()?->format(\DateTimeInterface::ATOM),
            'invitationLastSentAt' => $user->getInvitationLastSentAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** POST /api/admin/users/{id}/site-memberships */
    #[Route('/{id}/site-memberships', name: 'api_admin_users_add_site', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addSite(int $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::ADD_SITE);

        $data   = json_decode($request->getContent(), true);
        $siteId = isset($data['siteId']) ? (int) $data['siteId'] : 0;
        if ($siteId <= 0) {
            throw new UnprocessableEntityHttpException('siteId is required and must be positive');
        }

        $membership = $this->adminService->addSiteMembership($this->findUserOr404($id), $siteId, $admin);

        return $this->json([
            'id'       => $membership->getId(),
            'site'     => ['id' => $membership->getSite()?->getId(), 'name' => $membership->getSite()?->getName()],
            'siteRole' => $membership->getSiteRole(),
        ], Response::HTTP_CREATED);
    }

    /** DELETE /api/admin/users/{id}/site-memberships/{membershipId} */
    #[Route('/{id}/site-memberships/{membershipId}', name: 'api_admin_users_remove_site', methods: ['DELETE'], requirements: ['id' => '\d+', 'membershipId' => '\d+'])]
    public function removeSite(int $id, int $membershipId, #[CurrentUser] User $admin): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::REMOVE_SITE);

        $this->adminService->removeSiteMembership($this->findUserOr404($id), $membershipId, $admin);

        return $this->json(['id' => $membershipId, 'deleted' => true]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function findUserOr404(int $id): User
    {
        $user = $this->users->findUserById($id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException('User not found');
        }
        return $user;
    }

    private function toListItem(User $u): array
    {
        return [
            'id'               => $u->getId(),
            'email'            => $u->getEmail(),
            'firstname'        => $u->getFirstname(),
            'lastname'         => $u->getLastname(),
            'displayName'      => $this->buildDisplayName($u),
            'role'             => $this->buildBusinessRole($u),
            'active'           => $u->isActive(),
            'invitationStatus' => UserAdministrationService::computeInvitationStatus($u),
            'sites'            => $this->buildSiteSummaries($u),
        ];
    }

    private function toDetailPayload(User $u): array
    {
        return [
            'id'                   => $u->getId(),
            'email'                => $u->getEmail(),
            'firstname'            => $u->getFirstname(),
            'lastname'             => $u->getLastname(),
            'phone'                => $u->getPhone(),
            'displayName'          => $this->buildDisplayName($u),
            'role'                 => $this->buildBusinessRole($u),
            'active'               => $u->isActive(),
            'invitationStatus'     => UserAdministrationService::computeInvitationStatus($u),
            'invitationExpiresAt'  => $u->getInvitationExpiresAt()?->format(\DateTimeInterface::ATOM),
            'invitationLastSentAt' => $u->getInvitationLastSentAt()?->format(\DateTimeInterface::ATOM),
            'siteMemberships'      => array_map(static fn($m) => [
                'id'       => $m->getId(),
                'site'     => ['id' => $m->getSite()?->getId(), 'name' => $m->getSite()?->getName()],
                'siteRole' => $m->getSiteRole(),
            ], $u->getSiteMemberships()->toArray()),
        ];
    }

    private function buildSiteSummaries(User $u): array
    {
        $sites = [];
        foreach ($u->getSiteMemberships() as $m) {
            if ($m->getSite() !== null) {
                $sites[] = ['id' => $m->getSite()->getId(), 'name' => $m->getSite()->getName()];
            }
        }
        return $sites;
    }

    private function deserialize(string $content, string $class): object
    {
        try {
            /** @var object $dto */
            $dto = $this->serializer->deserialize($content, $class, 'json');
        } catch (SerializerExceptionInterface $e) {
            throw new BadRequestHttpException('Invalid JSON payload', $e);
        }

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        return $dto;
    }
}
