<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * D-152 — business refusal of an incompatibility creation / lifting. Stable codes, mapped as-is
 * to `error.code` by ApiExceptionSubscriber:
 *
 * - INCOMPATIBILITY_ALREADY_ACTIVE (409) — this pair is already incompatible (also a concurrent
 *                                          double creation, caught on the unique active key).
 * - INCOMPATIBILITY_ALREADY_LIFTED (409) — lifted in the meantime.
 * - INCOMPATIBILITY_INVALID_PAIR   (422) — not a surgeon / not an instrumentist / same person.
 * - INSTRUMENTIST_NOT_BLOCKABLE    (422) — a surgeon may only block active instrumentists of his
 *                                          sites, or eligible freelancers (D-152).
 */
class IncompatibilityException extends HttpException
{
    public const ALREADY_ACTIVE  = 'INCOMPATIBILITY_ALREADY_ACTIVE';
    public const ALREADY_LIFTED  = 'INCOMPATIBILITY_ALREADY_LIFTED';
    public const INVALID_PAIR    = 'INCOMPATIBILITY_INVALID_PAIR';
    public const NOT_BLOCKABLE   = 'INSTRUMENTIST_NOT_BLOCKABLE';

    private const DEFINITIONS = [
        self::ALREADY_ACTIVE => [409, 'Cette incompatibilité est déjà active.'],
        self::ALREADY_LIFTED => [409, 'Cette incompatibilité a déjà été levée.'],
        self::INVALID_PAIR   => [422, "Une incompatibilité relie un chirurgien et un instrumentiste."],
        self::NOT_BLOCKABLE  => [422, "Vous pouvez bloquer les instrumentistes actifs de vos établissements et les freelances."],
    ];

    public function __construct(private readonly string $errorCode)
    {
        [$status, $message] = self::DEFINITIONS[$errorCode] ?? [409, 'Opération impossible.'];
        parent::__construct($status, $message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
