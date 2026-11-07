<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * D-146 bis — refus explicite d'une création ou d'une résolution de demande catalogue
 * (matériel ou intervention). Rendu par ApiExceptionSubscriber en
 * `{error: {status, code, message, ...extra}}` : le frontend affiche le message et, le
 * cas échéant, les données utiles (firme déjà existante, doublons possibles, réponses
 * à compléter) sans jamais rejouer la règle lui-même.
 *
 * Toujours levée AVANT tout effet observable ou à l'intérieur d'une transaction : un
 * refus n'a jamais d'effet partiel (rollback complet).
 */
final class CatalogueRequestException extends HttpException
{
    public const FIRM_REQUIRED = 'FIRM_REQUIRED';
    public const FIRM_AMBIGUOUS = 'FIRM_AMBIGUOUS';
    public const FIRM_ALREADY_EXISTS = 'FIRM_ALREADY_EXISTS';
    public const QUANTITY_REQUIRED = 'QUANTITY_REQUIRED';
    public const LABEL_REQUIRED = 'LABEL_REQUIRED';
    public const MATERIAL_ITEM_REQUIRED = 'MATERIAL_ITEM_REQUIRED';
    public const MATERIAL_ITEM_DUPLICATE = 'MATERIAL_ITEM_DUPLICATE';
    public const MATERIAL_ITEM_POSSIBLE_DUPLICATE = 'MATERIAL_ITEM_POSSIBLE_DUPLICATE';
    public const INTERVENTION_TYPE_REQUIRED = 'INTERVENTION_TYPE_REQUIRED';
    public const INTERVENTION_TYPE_CODE_EXISTS = 'INTERVENTION_TYPE_CODE_EXISTS';
    public const OFFERING_DECISION_REQUIRED = 'OFFERING_DECISION_REQUIRED';
    public const MISSION_LOCKED = 'MISSION_LOCKED_FOR_CATALOGUE_RESOLUTION';

    /** @param array<string, mixed> $extra */
    public function __construct(
        int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $extra = [],
    ) {
        parent::__construct($statusCode, $message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
