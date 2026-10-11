<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D-143 — refus (422) de soumettre/valider un encodage auquel il manque une donnée
 * obligatoire. Rendu par ApiExceptionSubscriber en `error.code = ENCODING_INCOMPLETE`,
 * `error.missing = [{code, label, section}]` : le frontend affiche exactement ce qui
 * manque et ouvre la section concernée, sans jamais rejouer la règle lui-même.
 *
 * Ne bloque QUE la transition : aucune donnée déjà encodée n'est touchée.
 */
class EncodingIncompleteException extends UnprocessableEntityHttpException
{
    public const CODE = 'ENCODING_INCOMPLETE';

    /** @param list<array{code: string, label: string, section: string}> $missing */
    public function __construct(string $message, private readonly array $missing)
    {
        parent::__construct($message);
    }

    /** @return list<array{code: string, label: string, section: string}> */
    public function getMissing(): array
    {
        return $this->missing;
    }
}
