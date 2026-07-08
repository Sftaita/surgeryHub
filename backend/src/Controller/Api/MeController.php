<?php
// src/Controller/Api/MeController.php

namespace App\Controller\Api;

use App\Dto\Request\Response\InstrumentistProfileResponse;
use App\Dto\Request\Response\MeResponse;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Service\ProfilePictureStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class MeController extends AbstractController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ProfilePictureStorage $profilePictureStorage,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->json($this->buildMeResponse($user));
    }

    /**
     * POST /api/me/profile-picture
     * - Authentifié (IS_AUTHENTICATED_FULLY, via le catch-all /api de security.yaml).
     * - multipart/form-data, champ "profilePicture".
     * - Toujours "l'utilisateur courant" : aucune autorisation supplémentaire à vérifier
     *   (pas de Voter dédié — on ne modifie jamais que sa propre ressource).
     */
    #[Route('/me/profile-picture', name: 'api_me_profile_picture_upload', methods: ['POST'])]
    public function uploadProfilePicture(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $profilePicture = $request->files->get('profilePicture');
        if ($profilePicture === null) {
            throw new BadRequestHttpException('profilePicture file is required');
        }
        if (!$profilePicture instanceof UploadedFile) {
            throw new BadRequestHttpException('Invalid profilePicture upload');
        }

        $fileErrors = $this->validator->validate($profilePicture, [
            new Assert\Image(
                maxSize: '5M',
                mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                mimeTypesMessage: 'Only JPEG, PNG and WEBP images are allowed.',
            ),
        ]);

        if (count($fileErrors) > 0) {
            throw new UnprocessableEntityHttpException((string) $fileErrors);
        }

        $publicPath = $this->profilePictureStorage->replaceUserProfilePicture($user, $profilePicture);
        $user->setProfilePicturePath($publicPath);
        $this->em->flush();

        return $this->json($this->buildMeResponse($user));
    }

    private function buildMeResponse(User $user): MeResponse
    {
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

            $hourlyRate = $user->getHourlyRate();
            $consultationFee = $user->getConsultationFee();

            $siteMemberships = [];
            foreach ($user->getSiteMemberships() as $membership) {
                $site = $membership->getSite();
                if (!$site || $site->getId() === null) {
                    continue;
                }
                $siteMemberships[] = new \App\Dto\Request\Response\InstrumentistSiteMembershipResponse(
                    id: (int) $membership->getId(),
                    site: new \App\Dto\Request\Response\SiteSummaryResponse(
                        id: (int) $site->getId(),
                        name: (string) $site->getName(),
                    ),
                    siteRole: (string) ($membership->getSiteRole() ?? 'INSTRUMENTIST'),
                );
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
                hourlyRate: $hourlyRate !== null ? (string) $hourlyRate : null,
                consultationFee: $consultationFee !== null ? (string) $consultationFee : null,
                profilePicturePath: $profilePictureUrl,
                siteMemberships: $siteMemberships,
                specialties: $user->getSpecialties() ?? [],
            );
        }

        return new MeResponse(
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