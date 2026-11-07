<?php

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D-177 — the manager's explicit choice, when accepting a surgeon request, about the released
 * room it designates: take over room `slotId`, or declare that none of the matching rooms is
 * the one requested (`justification` mandatory, traced on SURGEON_MISSION_REQUEST_ACCEPTED).
 */
final class RoomSlotDecision
{
    public const TAKE_OVER = 'TAKE_OVER';
    public const NONE = 'NONE';

    private function __construct(
        public readonly string $decision,
        public readonly ?int $slotId,
        public readonly ?string $justification,
    ) {
    }

    public static function takeOver(int $slotId): self
    {
        return new self(self::TAKE_OVER, $slotId, null);
    }

    public static function none(?string $justification): self
    {
        $justification = $justification !== null ? trim($justification) : null;

        return new self(self::NONE, null, $justification !== '' ? $justification : null);
    }

    /** @param array<string, mixed>|null $data `{decision: TAKE_OVER|NONE, slotId?, justification?}` */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return match ($data['decision'] ?? null) {
            self::TAKE_OVER => isset($data['slotId']) && (int) $data['slotId'] > 0
                ? self::takeOver((int) $data['slotId'])
                : throw new UnprocessableEntityHttpException('roomSlot.slotId is required to take over a room.'),
            self::NONE => self::none(isset($data['justification']) ? mb_substr((string) $data['justification'], 0, 500) : null),
            default => throw new UnprocessableEntityHttpException('roomSlot.decision must be TAKE_OVER or NONE.'),
        };
    }
}
