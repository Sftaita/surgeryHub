<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * D-154 — optimistic concurrency on encoding writes (instrumentist, surgeon, manager on the
 * same mission). Stable codes, mapped as-is to `error.code` by ApiExceptionSubscriber:
 *
 * - ENCODING_CONCURRENT_MODIFICATION (409) — the element changed since it was read (If-Match
 *   differs from its version, or a concurrent flush won the race): nothing is overwritten,
 *   the client reloads the encoding.
 * - ENCODING_VERSION_REQUIRED (428) — no usable If-Match where it is mandatory (the
 *   surgeon's writes; other clients send it, optional only for older clients).
 */
class EncodingConcurrencyException extends HttpException
{
    public const CONCURRENT_MODIFICATION = 'ENCODING_CONCURRENT_MODIFICATION';
    public const VERSION_REQUIRED = 'ENCODING_VERSION_REQUIRED';

    public const SUBJECT_INTERVENTION = 'intervention';
    public const SUBJECT_MATERIAL_LINE = 'material_line';

    private const MODIFIED = [
        self::SUBJECT_INTERVENTION => "Cette intervention a été modifiée entre-temps par une autre personne. L'encodage a été rechargé : vérifiez-le puis recommencez si nécessaire.",
        self::SUBJECT_MATERIAL_LINE => "Cette ligne de matériel a été modifiée entre-temps par une autre personne. L'encodage a été rechargé : vérifiez-la puis recommencez si nécessaire.",
    ];

    private function __construct(int $status, private readonly string $errorCode, string $message)
    {
        parent::__construct($status, $message);
    }

    public static function modified(string $subject): self
    {
        return new self(409, self::CONCURRENT_MODIFICATION, self::MODIFIED[$subject] ?? self::MODIFIED[self::SUBJECT_INTERVENTION]);
    }

    public static function versionRequired(): self
    {
        return new self(428, self::VERSION_REQUIRED, "La version de l'élément est manquante : rechargez l'encodage puis recommencez.");
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
