<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-181 — explicit refusal (409) to change the instrumentist of a published mission, with a
 * stable code (MissionReassignmentPolicy::*) and the context the client needs to explain it
 * or refresh. Never a silent partial change.
 */
final class MissionReassignmentException extends ConflictHttpException
{
    /** @param array<string,mixed> $extra */
    public function __construct(private readonly string $errorCode, string $message, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
