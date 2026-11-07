<?php

namespace App\Security\Session;

use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * D-157 — an access token bound to a device session (claim `sid`) is refused as soon as that
 * session no longer exists: logout of this device, revocation of every session of the user
 * (suspension, `app:auth:revoke-sessions`, email change) or replay detection. Without this, a
 * revoked device kept a working access token until it expired (≤ 1 h).
 *
 * The session lives as long as one of its rows does (rotation keeps the family id; only rotated
 * generations are pruned, never the current one). One indexed lookup per authenticated request.
 *
 * Access tokens without `sid` are those issued before D-157 (expire within the hour) or by test
 * helpers; every token issued by the application now carries one (DeviceSessionService::accessToken()).
 * The client answers the resulting 401 "Invalid JWT Token" with a refresh, which is refused too:
 * the user is logged out with the "session expired" message.
 */
#[AsEventListener(event: Events::JWT_DECODED)]
final class SessionAccessTokenListener
{
    public function __construct(private readonly Connection $connection) {}

    public function __invoke(JWTDecodedEvent $event): void
    {
        $sessionId = $event->getPayload()[DeviceSessionService::SESSION_CLAIM] ?? null;
        if ($sessionId === null) {
            return;
        }

        $open = is_string($sessionId) && $sessionId !== '' && false !== $this->connection->fetchOne(
            'SELECT 1 FROM refresh_tokens WHERE family_id = ? LIMIT 1',
            [$sessionId],
        );
        if (!$open) {
            $event->markAsInvalid();
        }
    }
}
