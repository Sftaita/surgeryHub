<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §21 du lot : une correction ne peut
 * être créée que sur un document racine SENT ou PAID. Un document GENERATED doit
 * utiliser FirmInvoiceService::cancel()/InstrumentistStatementService::cancel()
 * (Lot 4), jamais une correction.
 */
final class CorrectionNotEligibleException extends ConflictHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Ce document ne peut pas recevoir de correction dans son état actuel.');
    }
}
