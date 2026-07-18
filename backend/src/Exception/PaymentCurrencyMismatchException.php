<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** EPIC Exécution & Valorisation, Lot 5 (D-075) — §11 du lot : la devise du paiement doit être strictement celle du document, aucune conversion. */
final class PaymentCurrencyMismatchException extends UnprocessableEntityHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'La devise du paiement ne correspond pas à celle du document.');
    }
}
