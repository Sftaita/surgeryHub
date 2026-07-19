<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §6 du lot : un filtre invalide (période
 * incohérente, id inconnu, devise malformée, granularité/tri hors whitelist) produit
 * une erreur structurée, jamais une valeur devinée ou silencieusement ignorée.
 */
final class InvalidStatisticsFilterException extends UnprocessableEntityHttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
