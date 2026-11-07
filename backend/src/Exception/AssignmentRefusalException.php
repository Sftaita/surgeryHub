<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-151 — business refusal (409) of a surgeon's refusal of an affectation, or of a request
 * for a manager intervention, decided under the mission lock. Stable codes, mapped as-is to
 * `error.code` by ApiExceptionSubscriber:
 *
 * - ASSIGNMENT_SUPERSEDED     — the mission has since been given to someone else: an earlier
 *                               affectation can never be refused.
 * - ASSIGNMENT_ALREADY_ENDED  — this affectation no longer exists (already refused, released…).
 * - ASSIGNMENT_NOT_REFUSABLE  — not a voluntary acceptance (manager / planning affectation).
 * - REFUSAL_WINDOW_EXPIRED    — more than 24 h since the acceptance, or the mission has started.
 * - REFUSAL_REQUIRES_MANAGER  — less than 12 h before the start, or encoding / financial data
 *                               exists: the surgeon asks the managers to intervene instead.
 * - REVIEW_NOT_POSSIBLE       — no current affectation to ask the managers about.
 */
class AssignmentRefusalException extends ConflictHttpException
{
    public const ASSIGNMENT_SUPERSEDED    = 'ASSIGNMENT_SUPERSEDED';
    public const ASSIGNMENT_ALREADY_ENDED = 'ASSIGNMENT_ALREADY_ENDED';
    public const NOT_REFUSABLE            = 'ASSIGNMENT_NOT_REFUSABLE';
    public const WINDOW_EXPIRED           = 'REFUSAL_WINDOW_EXPIRED';
    public const REQUIRES_MANAGER         = 'REFUSAL_REQUIRES_MANAGER';
    public const REVIEW_NOT_POSSIBLE      = 'REVIEW_NOT_POSSIBLE';

    private const MESSAGES = [
        self::ASSIGNMENT_SUPERSEDED    => "Cette affectation n'est plus d'actualité : la mission a été attribuée entre-temps.",
        self::ASSIGNMENT_ALREADY_ENDED => "Cette affectation n'existe plus.",
        self::NOT_REFUSABLE            => "Cette affectation n'a pas été acceptée par l'instrumentiste : demandez l'intervention du manager.",
        self::WINDOW_EXPIRED           => "Le délai de refus est écoulé : demandez l'intervention du manager.",
        self::REQUIRES_MANAGER         => "Cette mission doit être traitée par le manager : demandez son intervention.",
        self::REVIEW_NOT_POSSIBLE      => "Aucune affectation en cours sur cette mission.",
    ];

    public function __construct(private readonly string $errorCode)
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? 'Refus impossible.');
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
