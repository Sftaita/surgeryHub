<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-182 — explicit refusal (409) to cancel a mission by a manager (simple cancellation or
 * cancellation as a duplicate replaced by an existing mission), with a stable code
 * (MissionCancellationPolicy::*) and the context the client needs to explain it. Nothing is
 * ever changed when it is thrown.
 */
final class MissionCancellationException extends ConflictHttpException
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
