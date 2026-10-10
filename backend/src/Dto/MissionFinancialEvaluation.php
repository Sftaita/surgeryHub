<?php

namespace App\Dto;

/**
 * Résultat de FinancialCalculationService::evaluate() : ce que le moteur produirait
 * MAINTENANT pour une mission (configuration et encodage actuels), sans rien persister ni
 * auditer. calculate()/recalculate() persistent exactement cette évaluation : il n'existe
 * qu'un seul chemin de résolution des tarifs.
 *
 * Jamais un montant validé : une évaluation n'est ni un calcul, ni une ligne financière.
 * Les consommateurs en lecture (explications d'anomalies, worklist) s'en servent pour dire
 * si une cause existe encore et quel tarif serait retenu — jamais pour afficher un montant
 * facturable.
 *
 * $outcomes : une entrée par élément valorisable, clé `MISSION_INTERVENTION:{id}` ou
 * `MATERIAL_LINE:{id}` —
 *   kind = LINE                  : une ligne FIRM serait produite (rule = règle résolue) ;
 *          FEE_NOT_APPLICABLE    : aucun forfait prévu (FirmServiceOffering.feeApplicable=false) ;
 *          MATERIAL_NOT_BILLABLE : matériel marqué « non facturable » ;
 *          ANOMALY               : l'élément bloque le calcul (anomalyCodes).
 */
final readonly class MissionFinancialEvaluation
{
    public const LINE = 'LINE';
    public const FEE_NOT_APPLICABLE = 'FEE_NOT_APPLICABLE';
    public const MATERIAL_NOT_BILLABLE = 'MATERIAL_NOT_BILLABLE';
    public const ANOMALY = 'ANOMALY';

    /**
     * @param list<array<string, mixed>>    $lineSpecs usage interne du moteur
     * @param FinancialCalculationAnomaly[] $anomalies
     * @param array<string, array{kind: string, rule: ?array, unitAmount: ?string, totalAmount: ?string, currency: ?string, adjustmentReason: ?string, anomalyCodes: list<string>}> $outcomes
     */
    public function __construct(
        public \DateTimeImmutable $effectiveAt,
        public array $lineSpecs,
        public array $anomalies,
        public array $outcomes,
    ) {}

    public function succeeds(): bool
    {
        return $this->anomalies === [];
    }

    /** @return array{kind: string, rule: ?array, unitAmount: ?string, totalAmount: ?string, currency: ?string, adjustmentReason: ?string, anomalyCodes: list<string>}|null */
    public function outcome(string $elementKey): ?array
    {
        return $this->outcomes[$elementKey] ?? null;
    }
}
