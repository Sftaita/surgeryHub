<?php

namespace App\Exception;

use App\Dto\CorrectionLineAnomaly;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §30 du lot : si une seule ligne
 * corrective est invalide, aucun document correctif n'est créé, aucune ligne
 * persistée, aucun numéro attribué. Collecte TOUTES les anomalies en un seul rapport
 * (jamais un échec sur la première ligne) — miroir exact de
 * DocumentLineSelectionException (Lot 4).
 */
final class CorrectionValidationException extends UnprocessableEntityHttpException
{
    /** @var CorrectionLineAnomaly[] */
    private array $anomalies;

    /** @param CorrectionLineAnomaly[] $anomalies */
    public function __construct(array $anomalies)
    {
        $this->anomalies = $anomalies;

        parent::__construct(sprintf(
            '%d anomalie(s) détectée(s) dans la correction : %s',
            count($anomalies),
            implode(', ', array_map(static fn (CorrectionLineAnomaly $a) => $a->code, $anomalies)),
        ));
    }

    /** @return CorrectionLineAnomaly[] */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }
}
