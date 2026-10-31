<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-124 — refus métier (409) d'une reprise/libération de salle, levé par
 * `ReleasedRoomSlotTakeoverService` après relecture sous verrou pessimiste. Un seul type,
 * plusieurs codes stables, mappés tels quels en `error.code` par ApiExceptionSubscriber :
 *
 * - ROOM_SLOT_ALREADY_TAKEN     — un autre chirurgien a gagné la course (`takenBy` renseigné).
 * - ROOM_SLOT_NOT_AVAILABLE     — plus reprenable : créneau passé, ou chirurgien libérant de
 *                                 nouveau présent (absence supprimée/réduite).
 * - ROOM_SLOT_SURGEON_CONFLICT  — le repreneur a déjà une Mission active qui chevauche
 *                                 (hors « double salle » du même site, D-119).
 * - ROOM_SLOT_SURGEON_ABSENT    — le repreneur est lui-même absent ce jour-là.
 * - ROOM_SLOT_SCHEDULE_UNKNOWN  — aucun horaire connu pour créer la Mission (jamais inventé).
 * - ROOM_SLOT_NOT_RELEASABLE    — libération impossible (pas/plus repris, Mission démarrée).
 *
 * `extra` est fusionné dans le corps d'erreur — jamais de donnée patient.
 */
class ReleasedRoomSlotConflictException extends ConflictHttpException
{
    public const ALREADY_TAKEN = 'ROOM_SLOT_ALREADY_TAKEN';
    public const NOT_AVAILABLE = 'ROOM_SLOT_NOT_AVAILABLE';
    public const SURGEON_CONFLICT = 'ROOM_SLOT_SURGEON_CONFLICT';
    public const SURGEON_ABSENT = 'ROOM_SLOT_SURGEON_ABSENT';
    public const SCHEDULE_UNKNOWN = 'ROOM_SLOT_SCHEDULE_UNKNOWN';
    public const NOT_RELEASABLE = 'ROOM_SLOT_NOT_RELEASABLE';

    /** @param array<string, mixed> $extra */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
