<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — un document SENT ou PAID est un point de
 * non-retour dans ce lot : ni annulé, ni supprimé, ni ses lignes libérées (§12/§13).
 */
final class DocumentAlreadyIssuedException extends ConflictHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Ce document a déjà été émis et ne peut plus être modifié ou supprimé.');
    }
}
