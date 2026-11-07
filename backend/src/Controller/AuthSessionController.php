<?php

namespace App\Controller;

use App\Security\Session\DeviceSessionService;
use App\Security\Session\SessionRefreshException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-157 — refresh and logout of a device session. These two routes sit on a firewall without
 * JWT authenticator (security.yaml `auth_session`): the access token the client may still send
 * (usually expired, that is precisely why it refreshes) is ignored instead of answering
 * "Expired JWT Token" before the refresh token is even read — the cause of the logouts.
 *
 * Responses keep the historical Gesdinet shape: `{token, refresh_token}`, errors `{code, message}`.
 */
final class AuthSessionController
{
    public function __construct(
        private readonly DeviceSessionService $sessions,
    ) {}

    #[Route('/api/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $presented = self::refreshTokenFrom($request);
        if ($presented === null) {
            return new JsonResponse(['code' => 401, 'message' => 'JWT Refresh Token Not Found'], 401);
        }

        try {
            $session = $this->sessions->refresh($presented);
        } catch (SessionRefreshException) {
            return new JsonResponse(['code' => 401, 'message' => 'Invalid JWT Refresh Token'], 401);
        }

        return new JsonResponse([
            'token'         => $this->sessions->accessToken($session['user'], $session['sessionId']),
            'refresh_token' => $session['refreshToken'],
        ]);
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $presented = self::refreshTokenFrom($request);
        if ($presented !== null) {
            $this->sessions->revoke($presented);
        }

        // Always 200: a logout never tells whether the token existed.
        return new JsonResponse(['code' => 200, 'message' => 'Session closed.']);
    }

    private static function refreshTokenFrom(Request $request): ?string
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $value = is_array($body) ? ($body['refresh_token'] ?? null) : null;
        $value ??= $request->request->get('refresh_token');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
