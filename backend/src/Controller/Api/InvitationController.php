<?php

namespace App\Controller\Api;

use App\Dto\Request\InvitationCompleteRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ProfilePictureStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/invitations')]
final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ProfilePictureStorage $profilePictureStorage,
    ) {
    }

    /**
     * GET /api/invitations/{token}
     * - Endpoint public de lecture/validation d’un token d’invitation
     * - Ne modifie aucun état
     * - N’expose des données de préremplissage que si le token est encore valide
     */
    #[Route('/{token}', name: 'api_invitations_get', methods: ['GET'])]
    public function getOne(string $token): JsonResponse
    {
        $normalizedToken = trim($token);

        if ($normalizedToken === '' || preg_match('/^[a-f0-9]{64}$/i', $normalizedToken) !== 1) {
            return $this->json([
                'status' => 'invalid',
                'valid' => false,
            ]);
        }

        $instrumentist = $this->users->findInstrumentistByInvitationToken($normalizedToken);

        if (!$instrumentist instanceof User) {
            return $this->json([
                'status' => 'invalid',
                'valid' => false,
            ]);
        }

        if ($instrumentist->getPassword() !== null) {
            return $this->json([
                'status' => 'used',
                'valid' => false,
            ]);
        }

        $expiresAt = $instrumentist->getInvitationExpiresAt();
        if (!$expiresAt instanceof \DateTimeImmutable || $expiresAt <= new \DateTimeImmutable()) {
            return $this->json([
                'status' => 'expired',
                'valid' => false,
            ]);
        }

        $firstname = $instrumentist->getFirstname();
        $lastname = $instrumentist->getLastname();

        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
        $displayName = $name !== '' ? $name : (string) $instrumentist->getEmail();

        return $this->json([
            'status' => 'valid',
            'valid' => true,
            'invitation' => [
                'email' => (string) $instrumentist->getEmail(),
                'firstname' => $firstname,
                'lastname' => $lastname,
                'displayName' => $displayName,
                'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/complete', name: 'api_invitations_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $dto = $this->buildCompletionDto($request);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        if ($dto->password !== $dto->confirmPassword) {
            throw new UnprocessableEntityHttpException('confirmPassword: Passwords do not match');
        }

        $profilePicture = $request->files->get('profilePicture');
        if ($profilePicture !== null && !$profilePicture instanceof UploadedFile) {
            throw new BadRequestHttpException('Invalid profilePicture upload');
        }

        if ($profilePicture instanceof UploadedFile) {
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
        }

        $user = $this->users->findInstrumentistByInvitationToken($dto->token);

        if (!$user instanceof User) {
            throw new NotFoundHttpException('Invitation not found');
        }

        if ($user->getInvitationExpiresAt() !== null && $user->getInvitationExpiresAt() <= new \DateTimeImmutable()) {
            throw new ConflictHttpException('Invitation expired');
        }

        if ($user->getPassword() !== null) {
            throw new ConflictHttpException('Invitation already used');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $dto->password);

        $user
            ->setFirstname($dto->firstname)
            ->setLastname($dto->lastname)
            ->setPhone($dto->phone)
            ->setCompanyName($this->normalizeNullableString($dto->companyName))
            ->setVatNumber($this->normalizeNullableString($dto->vatNumber))
            ->setPassword($hashedPassword)
            ->setInvitationToken(null)
            ->setInvitationExpiresAt(null);

        if ($profilePicture instanceof UploadedFile) {
            $publicPath = $this->profilePictureStorage->replaceUserProfilePicture($user, $profilePicture);
            $user->setProfilePicturePath($publicPath);
        }

        $this->em->flush();

        return $this->json([
            'status' => 'account_completed',
        ]);
    }

    private function buildCompletionDto(Request $request): InvitationCompleteRequest
    {
        $contentType = $request->getContentTypeFormat();

        if ($contentType === 'json') {
            try {
                /** @var InvitationCompleteRequest $dto */
                $dto = $this->serializer->deserialize($request->getContent(), InvitationCompleteRequest::class, 'json');
            } catch (SerializerExceptionInterface $e) {
                throw new BadRequestHttpException('Invalid JSON payload', $e);
            }

            return $dto;
        }

        $dto = new InvitationCompleteRequest();
        $dto->token = trim((string) $request->request->get('token', ''));
        $dto->firstname = trim((string) $request->request->get('firstname', ''));
        $dto->lastname = trim((string) $request->request->get('lastname', ''));
        $dto->phone = trim((string) $request->request->get('phone', ''));
        $dto->companyName = $this->normalizeNullableString($request->request->get('companyName'));
        $dto->vatNumber = $this->normalizeNullableString($request->request->get('vatNumber'));
        $dto->password = (string) $request->request->get('password', '');
        $dto->confirmPassword = (string) $request->request->get('confirmPassword', '');

        return $dto;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}