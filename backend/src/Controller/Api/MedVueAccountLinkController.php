<?php

namespace App\Controller\Api;

use App\Entity\MedVueAccountLink;
use App\Entity\User;
use App\Security\Voter\MedVueIntegrationVoter;
use App\Service\MedVue\MedVueAccountLinkService;
use App\Service\MedVue\MedVueLinkException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-140 — « Intégration MedVue » : état, association et révocation de la liaison d'un compte.
 *
 * Toujours adressé par l'identifiant du compte SurgicalHub visé ; l'utilisateur courant passe
 * son propre id, un ADMIN celui de la fiche ouverte. MedVueIntegrationVoter::MANAGE_LINK décide
 * (soi-même : les quatre rôles métier ; autrui : ROLE_ADMIN seul). Association et révocation sont
 * des endpoints dédiés, jamais un champ modifiable d'un PATCH générique.
 */
#[Route('/api/medvue-integration/users/{userId}/link', requirements: ['userId' => '\d+'])]
final class MedVueAccountLinkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MedVueAccountLinkService $service,
    ) {}

    #[Route('', name: 'api_medvue_link_show', methods: ['GET'])]
    public function show(int $userId): JsonResponse
    {
        $target = $this->target($userId);

        return $this->json($this->serialize($this->service->findActive($target)));
    }

    #[Route('', name: 'api_medvue_link_create', methods: ['POST'])]
    public function create(int $userId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $target = $this->target($userId);

        $data = json_decode($request->getContent(), true);
        $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : '';

        try {
            $link = $this->service->link($actor, $target, $code);
        } catch (MedVueLinkException $e) {
            return $this->failure($e);
        }

        return $this->json($this->serialize($link), 201);
    }

    #[Route('', name: 'api_medvue_link_revoke', methods: ['DELETE'])]
    public function revoke(int $userId, #[CurrentUser] User $actor): Response
    {
        $target = $this->target($userId);

        try {
            $this->service->revokeFromSurgicalHub($actor, $target);
        } catch (MedVueLinkException $e) {
            return $this->failure($e);
        }

        return new Response(null, 204);
    }

    private function target(int $userId): User
    {
        $target = $this->em->find(User::class, $userId);
        if ($target === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(MedVueIntegrationVoter::MANAGE_LINK, $target);

        return $target;
    }

    private function serialize(?MedVueAccountLink $link): array
    {
        $linkedBy = $link?->getLinkedBy();

        return [
            'configured' => $this->service->isConfigured(),
            'linked' => $link !== null,
            'linkedAt' => $link?->getLinkedAt()->format(\DateTimeInterface::ATOM),
            'linkedBy' => $linkedBy === null ? null : [
                'id' => $linkedBy->getId(),
                'displayName' => MedVueAccountLinkService::displayName($linkedBy),
            ],
        ];
    }

    private function failure(MedVueLinkException $e): JsonResponse
    {
        $response = $this->json(['error' => ['code' => $e->reason, 'message' => $e->getMessage()]], $e->httpStatus());
        if ($e->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $e->retryAfter);
        }

        return $response;
    }
}
