<?php

namespace App\Exception;

use App\Dto\FinancialCalculationAnomaly;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — le moteur n'invente jamais de tarif
 * (§14 du lot). Toutes les anomalies sont collectées avant d'échouer — jamais un échec
 * au premier élément trouvé — et AUCUNE version partielle CALCULATED n'est persistée.
 *
 * Mapped to error.code = 'FINANCIAL_CALCULATION_ANOMALIES' by ApiExceptionSubscriber.
 */
class FinancialCalculationAnomaliesException extends UnprocessableEntityHttpException
{
    /** @param FinancialCalculationAnomaly[] $anomalies */
    public function __construct(private readonly array $anomalies)
    {
        parent::__construct(sprintf('%d anomalie(s) bloquante(s) détectée(s) — aucun calcul persisté.', count($anomalies)));
    }

    /** @return FinancialCalculationAnomaly[] */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }
}
