<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/surgeons')]
final class SurgeonController extends AbstractController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * GET /api/surgeons
     * - Protégé par security.yaml (ROLE_MANAGER/ROLE_ADMIN)
     * - Retourne une liste "slim" sans données financières
     */
    #[Route('', name: 'api_surgeons_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = $request->query->getString('q', '');
        $active = $request->query->get('active', '1'); // "1" par défaut
        $activeOnly = ($active === '1' || $active === 1 || $active === true || $active === 'true');

        $items = $this->users->findSurgeons($q !== '' ? $q : null, $activeOnly);

        $payload = array_map(static function ($u): array {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();
            $name = trim((string)($firstname ?? '') . ' ' . (string)($lastname ?? ''));
            $displayName = $name !== '' ? $name : (string) $u->getEmail();

            return [
                'id' => $u->getId(),
                'email' => $u->getEmail(),
                'firstname' => $firstname,
                'lastname' => $lastname,
                'active' => $u->isActive(),
                'displayName' => $displayName,
            ];
        }, $items);

        return $this->json([
            'items' => $payload,
            'total' => count($payload),
        ]);
    }
}
