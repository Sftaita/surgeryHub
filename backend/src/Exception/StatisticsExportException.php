<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * D-186 — refus explicite d'un export PDF des statistiques financières, jamais une
 * troncature silencieuse. Codes stables, mappés tels quels en `error.code` par
 * ApiExceptionSubscriber :
 *
 * - STATISTICS_EXPORT_TOO_LARGE  (422) — plus de lignes que le moteur PDF ne sait rendre
 *                                        dans les limites du serveur : affiner les filtres.
 * - STATISTICS_EXPORT_DATA_CHANGED (409) — les données ont changé pendant la lecture page par
 *                                        page (total, totaux ou lignes incohérents) : relancer.
 */
final class StatisticsExportException extends HttpException
{
    public const TOO_LARGE = 'STATISTICS_EXPORT_TOO_LARGE';
    public const DATA_CHANGED = 'STATISTICS_EXPORT_DATA_CHANGED';

    /** @param array<string, mixed> $extra */
    private function __construct(int $status, private readonly string $errorCode, string $message, private readonly array $extra = [])
    {
        parent::__construct($status, $message);
    }

    public static function tooLarge(int $rowCount, int $maxRows): self
    {
        return new self(422, self::TOO_LARGE, sprintf(
            'Export trop volumineux : %d lignes pour un maximum de %d par PDF. Affinez la période ou les filtres.',
            $rowCount,
            $maxRows,
        ), ['rowCount' => $rowCount, 'maxRows' => $maxRows]);
    }

    public static function dataChanged(): self
    {
        return new self(409, self::DATA_CHANGED, 'Les données ont changé pendant la préparation de l\'export. Relancez l\'export.');
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
