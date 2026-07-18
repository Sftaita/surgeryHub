<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** EPIC Exécution & Valorisation, Lot 5 (D-075) — un paiement ne peut être enregistré que sur un document SENT (émis). */
final class DocumentNotIssuedException extends ConflictHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Ce document doit être émis (SENT) avant de pouvoir recevoir un paiement.');
    }
}
