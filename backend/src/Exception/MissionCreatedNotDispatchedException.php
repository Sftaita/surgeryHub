<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-156 lot 1 — `POST /api/missions` with a dispatch choice: the dispatch was validated
 * before creating the mission, but failed right after (eligibility changed in between).
 * The mission exists, as DRAFT — `missionId` lets the client open it instead of creating a
 * duplicate on retry. Never a silent half-success.
 */
final class MissionCreatedNotDispatchedException extends ConflictHttpException
{
    public const CODE = 'MISSION_CREATED_NOT_DISPATCHED';

    public function __construct(private readonly int $missionId, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('La mission a été créée en brouillon, mais sa publication a échoué : %s', $reason),
            $previous,
        );
    }

    public function getErrorCode(): string
    {
        return self::CODE;
    }

    /** @return array{missionId:int} */
    public function getExtra(): array
    {
        return ['missionId' => $this->missionId];
    }
}
