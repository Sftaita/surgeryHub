<?php

namespace App\Security\Session;

/** D-157 — the session cannot be restored: the client must authenticate again (HTTP 401). */
final class SessionRefreshException extends \RuntimeException
{
    public const NOT_FOUND = 'NOT_FOUND';         // unknown, revoked or pruned token
    public const EXPIRED = 'EXPIRED';             // inactivity window elapsed
    public const REUSED = 'REUSED';               // replay of an already rotated token: session revoked
    public const USER_INACTIVE = 'USER_INACTIVE'; // account suspended or deleted

    public function __construct(
        public readonly string $reason,
        public readonly ?string $familyId = null,
        public readonly ?int $rowId = null,
        public readonly bool $revokeSession = false,
    ) {
        parent::__construct('Session refresh refused: '.$reason);
    }
}
