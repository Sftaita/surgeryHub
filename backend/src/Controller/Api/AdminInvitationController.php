<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\UserAdministrationVoter;
use App\Service\UserAdministrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/invitations')]
final class AdminInvitationController extends AbstractController
{
    use AdminResponseTrait;

    private const VALID_STATUSES = ['pending', 'expired', 'used', 'email_not_sent', 'none'];

    public function __construct(
        private readonly UserRepository $users,
    ) {}

    /**
     * GET /api/admin/invitations
     *
     * Filtres supportés :
     *   ?status=pending|expired|used|email_not_sent|none   (peut être répété : ?status[]=pending&status[]=expired)
     */
    #[Route('', name: 'api_admin_invitations_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::LIST);

        // all('status') always returns an array (scalar ?status=x → ['x'], repeated ?status[]=x → ['x','y'])
        $raw = $request->query->all('status');
        $requestedStatuses = array_values(array_filter(
            array_map('strval', $raw),
            static fn(string $s) => in_array($s, self::VALID_STATUSES, true),
        ));

        $allUsers = $this->users->findAllUsers();

        $items = [];
        foreach ($allUsers as $user) {
            $status = UserAdministrationService::computeInvitationStatus($user);
            if (count($requestedStatuses) > 0 && !in_array($status, $requestedStatuses, true)) {
                continue;
            }
            $items[] = $this->toPayload($user, $status);
        }

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    private function toPayload(User $u, string $status): array
    {
        return [
            'id'                   => $u->getId(),
            'email'                => $u->getEmail(),
            'displayName'          => $this->buildDisplayName($u),
            'role'                 => $this->buildBusinessRole($u),
            'active'               => $u->isActive(),
            'invitationStatus'     => $status,
            'invitationExpiresAt'  => $u->getInvitationExpiresAt()?->format(\DateTimeInterface::ATOM),
            'invitationLastSentAt' => $u->getInvitationLastSentAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

}
