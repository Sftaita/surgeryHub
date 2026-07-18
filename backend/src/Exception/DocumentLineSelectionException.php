<?php

namespace App\Exception;

use App\Dto\DocumentLineSelectionAnomaly;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — §28 du lot : si une seule ligne
 * sélectionnée est devenue indisponible entre prévisualisation et création, aucun
 * document n'est créé, aucune ligne rattachée, aucun calcul verrouillé. Collecte TOUTES
 * les anomalies en un seul rapport (jamais un échec sur la première ligne), miroir exact
 * de FinancialCalculationAnomaliesException (Lot 3).
 */
final class DocumentLineSelectionException extends UnprocessableEntityHttpException
{
    /** @var DocumentLineSelectionAnomaly[] */
    private array $anomalies;

    /** @param DocumentLineSelectionAnomaly[] $anomalies */
    public function __construct(array $anomalies)
    {
        $this->anomalies = $anomalies;

        parent::__construct(sprintf(
            '%d ligne(s) sélectionnée(s) ne sont plus éligibles : %s',
            count($anomalies),
            implode(', ', array_map(static fn (DocumentLineSelectionAnomaly $a) => $a->code, $anomalies)),
        ));
    }

    /** @return DocumentLineSelectionAnomaly[] */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }
}
