<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — l'annulation d'un document SENT/PAID ne
 * libère jamais silencieusement ses lignes (§12) : une correction future passera par une
 * note de crédit / un document compensatoire, non développé dans ce lot.
 */
final class DocumentCannotReleaseLinesException extends ConflictHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Les lignes de ce document ne peuvent pas être libérées après émission.');
    }
}
