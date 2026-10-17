<?php

namespace App\Enum;

/** D-152 — who created a surgeon–instrumentist incompatibility, and through which path. */
enum IncompatibilityOrigin: string
{
    /** « Refuser et bloquer pour mes futures missions » (durable refusal, D-151/D-152). */
    case SURGEON_REFUSAL = 'SURGEON_REFUSAL';
    /** Blocked by the surgeon from his profile. */
    case SURGEON = 'SURGEON';
    /** Created by a manager from the surgeon's or the instrumentist's file. */
    case MANAGER = 'MANAGER';

    /** The surgeon may lift what he created himself; a manager's block is the managers' (D-152). */
    public function isSurgeonOwned(): bool
    {
        return $this !== self::MANAGER;
    }
}
