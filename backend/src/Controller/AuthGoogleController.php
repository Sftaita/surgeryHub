<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Google\GoogleIdTokenVerifier;
use App\Security\Google\GoogleTokenRejectedException;
use App\Security\Google\GoogleUnavailableException;
use App\Security\Session\DeviceSessionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Google Sign-In (no UI yet). D-157 hardening:
 * - disabled (404) while GOOGLE_OAUTH_CLIENT_ID is not configured;
 * - ID token checked by GoogleIdTokenVerifier (audience, issuer, expiry, verified email);
 * - existing, active accounts only: accounts are created by invitation, never by a Google login;
 * - same device session as the password login.
 */
final class AuthGoogleController
{
    #[Route('/api/auth/google', methods: ['POST'])]
    public function __invoke(
        Request $request,
        GoogleIdTokenVerifier $google,
        UserRepository $users,
        DeviceSessionService $sessions,
        LoggerInterface $logger,
    ): JsonResponse {
        if (!$google->isEnabled()) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $credential = $payload['credential'] ?? null;
        $rememberMe = (bool) ($payload['rememberMe'] ?? false);

        if (!is_string($credential) || $credential === '') {
            return new JsonResponse(['error' => 'Missing credential'], 400);
        }

        try {
            $email = $google->verify($credential);
        } catch (GoogleUnavailableException) {
            return new JsonResponse(['error' => 'Google unavailable'], 503);
        } catch (GoogleTokenRejectedException $e) {
            $logger->info('Google login refused', ['reason' => $e->getMessage()]);

            return new JsonResponse(['error' => 'Invalid Google token'], 401);
        }

        // Same answer for an unknown and a suspended account: no account enumeration.
        $user = $users->findOneByEmailInsensitive($email);
        if (!$user instanceof User || !$user->isActive()) {
            $logger->info('Google login refused', ['reason' => $user === null ? 'unknown_account' : 'inactive_account']);

            return new JsonResponse(['error' => 'Invalid Google token'], 401);
        }

        // D-157 — session d'appareil (refresh token glissant, rotation au refresh)
        $session = $sessions->open($user, $rememberMe);

        return new JsonResponse([
            'token' => $sessions->accessToken($user, $session),
            'refresh_token' => $session->getRefreshToken(),
        ]);
    }
}
