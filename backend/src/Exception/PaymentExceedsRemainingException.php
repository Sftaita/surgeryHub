<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** EPIC Exécution & Valorisation, Lot 5 (D-075) — §10 du lot : un surpaiement est toujours refusé, jamais accepté partiellement. */
final class PaymentExceedsRemainingException extends UnprocessableEntityHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Le montant du paiement dépasse le solde restant dû.');
    }
}
