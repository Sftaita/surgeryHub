<?php
// src/Controller/Api/MeController.php

namespace App\Controller\Api;

use App\Dto\Response\InstrumentistProfileResponse;
use App\Dto\Response\MeResponse;
use App\Entity\SiteMembership;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api')]
final class MeController extends AbstractController
{
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // 1) role métier conforme spec v2.1
        $role = $this->extractBusinessRole($user->getRoles());

        // 2) Normaliser firstname/lastname : null si vide / espaces
        $firstname = $this->nullIfBlank($user->getFirstname());
        $lastname  = $this->nullIfBlank($user->getLastname());

        // 3) sites[] via SiteMembership (format requis)
        $sites = [];
        /** @var SiteMembership $membership */
        foreach ($user->getSiteMemberships() as $membership) {
            $site = $membership->getSite();
            if (!$site || $site->getId() === null) {
                continue;
            }

            $sites[] = [
                'id'       => (int) $site->getId(),
                'name'     => (string) $site->getName(),
                'timezone' => (string) ($site->getTimezone() ?? 'Europe/Brussels'),
            ];
        }

        // 4) activeSiteId : vous n’avez pas de champ => null (spec OK)
        $activeSiteId = null;

        // 5) instrumentistProfile uniquement si role INSTRUMENTIST
        $instrumentistProfile = null;
        if ($role === 'INSTRUMENTIST') {
            $employmentType = $user->getEmploymentType()?->value; // EmploymentType enum -> string
            $defaultCurrency = $user->getDefaultCurrency() ?? 'EUR';

            $instrumentistProfile = new InstrumentistProfileResponse(
                employmentType: $employmentType ?? 'FREELANCER',
                defaultCurrency: $defaultCurrency,
            );
        }

        // 6) Ne jamais exposer de finance dans /api/me
        // => on ne met JAMAIS hourlyRate / consultationFee ici.

        $dto = new MeResponse(
            id: (int) $user->getId(),
            email: (string) $user->getEmail(),
            firstname: $firstname,
            lastname: $lastname,
            role: $role,
            instrumentistProfile: $instrumentistProfile,
            sites: $sites,
            activeSiteId: $activeSiteId,
        );

        return $this->json($dto);
    }

    /**
     * Convertit les rôles Symfony (ROLE_*) en rôle métier attendu par le front.
     *
     * Retourne uniquement : INSTRUMENTIST | SURGEON | MANAGER | ADMIN
     *
     * @param string[] $roles
     */
    private function extractBusinessRole(array $roles): string
    {
        $roles = array_unique($roles);

        // Ordre de priorité (RBAC)
        $priority = [
            'ROLE_ADMIN'   => 'ADMIN',
            'ROLE_MANAGER' => 'MANAGER',
            'ROLE_SURGEON' => 'SURGEON',

            // Votre legacy : ROLE_NURSE -> INSTRUMENTIST
            'ROLE_NURSE'   => 'INSTRUMENTIST',
            // si vous avez déjà ROLE_INSTRUMENTIST, mappez-le aussi :
            'ROLE_INSTRUMENTIST' => 'INSTRUMENTIST',
        ];

        foreach ($priority as $symfonyRole => $businessRole) {
            if (\in_array($symfonyRole, $roles, true)) {
                return $businessRole;
            }
        }

        // Ne pas renvoyer USER (interdit par la spec front)
        // Option “safe” : 403 plutôt que de router faux.
        throw $this->createAccessDeniedException('Missing business role (expected ADMIN/MANAGER/SURGEON/INSTRUMENTIST).');
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
