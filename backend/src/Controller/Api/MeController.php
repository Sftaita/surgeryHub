<?php
// src/Controller/Api/MeController.php

namespace App\Controller\Api;

use App\Dto\Request\Response\InstrumentistProfileResponse;
use App\Dto\Request\Response\MeResponse;
use App\Entity\SiteMembership;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api')]
final class MeController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $role = $this->extractBusinessRole($user->getRoles());

        $firstname = $this->nullIfBlank($user->getFirstname());
        $lastname = $this->nullIfBlank($user->getLastname());
        $profilePictureUrl = $this->buildAbsoluteUrl($user->getProfilePicturePath());

        $sites = [];
        /** @var SiteMembership $membership */
        foreach ($user->getSiteMemberships() as $membership) {
            $site = $membership->getSite();
            if (!$site || $site->getId() === null) {
                continue;
            }

            $sites[] = [
                'id' => (int) $site->getId(),
                'name' => (string) $site->getName(),
                'timezone' => (string) ($site->getTimezone() ?? 'Europe/Brussels'),
            ];
        }

        $activeSiteId = null;

        $instrumentistProfile = null;
        if ($role === 'INSTRUMENTIST') {
            $employmentType = $user->getEmploymentType()?->value;
            $defaultCurrency = $user->getDefaultCurrency() ?? 'EUR';

            $displayName = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
            if ($displayName === '') {
                $displayName = (string) $user->getEmail();
            }

            $instrumentistProfile = new InstrumentistProfileResponse(
                id: (int) $user->getId(),
                email: (string) $user->getEmail(),
                firstname: $firstname,
                lastname: $lastname,
                displayName: $displayName,
                active: $user->isActive(),
                employmentType: $employmentType,
                defaultCurrency: $defaultCurrency,
            );
        }

        $dto = new MeResponse(
            id: (int) $user->getId(),
            email: (string) $user->getEmail(),
            firstname: $firstname,
            lastname: $lastname,
            profilePictureUrl: $profilePictureUrl,
            role: $role,
            instrumentistProfile: $instrumentistProfile,
            sites: $sites,
            activeSiteId: $activeSiteId,
        );

        return $this->json($dto);
    }

    /**
     * @param string[] $roles
     */
    private function extractBusinessRole(array $roles): string
    {
        $roles = array_unique($roles);

        $priority = [
            'ROLE_ADMIN' => 'ADMIN',
            'ROLE_MANAGER' => 'MANAGER',
            'ROLE_SURGEON' => 'SURGEON',
            'ROLE_NURSE' => 'INSTRUMENTIST',
            'ROLE_INSTRUMENTIST' => 'INSTRUMENTIST',
        ];

        foreach ($priority as $symfonyRole => $businessRole) {
            if (\in_array($symfonyRole, $roles, true)) {
                return $businessRole;
            }
        }

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

    private function buildAbsoluteUrl(?string $path): ?string
    {
        $normalizedPath = $this->nullIfBlank($path);
        if ($normalizedPath === null) {
            return null;
        }

        if (str_starts_with($normalizedPath, 'http://') || str_starts_with($normalizedPath, 'https://')) {
            return $normalizedPath;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return $normalizedPath;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/') . '/' . ltrim($normalizedPath, '/');
    }
}