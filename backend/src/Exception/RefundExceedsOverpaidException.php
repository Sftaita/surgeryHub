<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** EPIC Exécution & Valorisation, Lot 6 (D-076) — §15 du lot : un remboursement ne peut jamais dépasser le trop-perçu réel du document racine. */
final class RefundExceedsOverpaidException extends UnprocessableEntityHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Le montant du remboursement dépasse le trop-perçu du document.');
    }
}
