<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-123 — transition de statut de facture refusée par le cycle de vie strict
 * GENERATED → SENT → PAID (ex. marquer payée une facture non envoyée, annulée ou déjà
 * payée). Toujours un 409 métier explicite, jamais une erreur générique.
 */
final class InvoiceStatusTransitionException extends ConflictHttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
