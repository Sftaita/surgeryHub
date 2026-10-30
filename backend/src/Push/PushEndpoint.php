<?php

namespace App\Push;

/** D-175 — empreintes et jetons Web Push. Un endpoint complet n'est jamais journalisé ni conservé hors push_subscription. */
final class PushEndpoint
{
    public static function fingerprint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** Jeton aléatoire base64url (sans remplissage) : 16 octets pour un accusé, 32 pour un jeton d'appareil. */
    public static function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormedToken(mixed $token, int $minLength = 22, int $maxLength = 64): bool
    {
        return is_string($token) && preg_match(sprintf('/^[A-Za-z0-9_-]{%d,%d}$/', $minLength, $maxLength), $token) === 1;
    }
}
