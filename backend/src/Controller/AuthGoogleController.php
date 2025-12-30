<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;

final class AuthGoogleController
{
    #[Route('/api/auth/google', methods: ['POST'])]
    public function __invoke(
        Request $request,
        HttpClientInterface $http,
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager,
        RefreshTokenManagerInterface $refreshTokenManager,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true) ?? [];
        $credential = $payload['credential'] ?? null;

        if (!$credential) {
            return new JsonResponse(['error' => 'Missing credential'], 400);
        }

        // Vérifie l'ID token auprès de Google
        $resp = $http->request('GET', 'https://oauth2.googleapis.com/tokeninfo', [
            'query' => ['id_token' => $credential],
        ]);

        if (200 !== $resp->getStatusCode()) {
            return new JsonResponse(['error' => 'Invalid Google token'], 401);
        }

        $info = $resp->toArray(false);
        $email = $info['email'] ?? null;
        $googleSub = $info['sub'] ?? null;

        if (!$email || !$googleSub) {
            return new JsonResponse(['error' => 'Google token missing fields'], 401);
        }

        // Trouver ou créer user
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            // Mot de passe random (non utilisé), mais champ requis si non nullable
            $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(16))));
            $user->setRoles(['ROLE_USER']);
            // si tu as un champ googleId :
            // $user->setGoogleId($googleSub);

            $em->persist($user);
            $em->flush();
        }

        // Générer JWT
        $token = $jwtManager->create($user);

        // Générer refresh token
        $refreshToken = $refreshTokenManager->create();
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setRefreshToken(bin2hex(random_bytes(64)));
        $refreshToken->setValid((new \DateTimeImmutable())->modify('+30 days'));
        $refreshTokenManager->save($refreshToken);

        return new JsonResponse([
            'token' => $token,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ]);
    }
}
