<?php

namespace App\Exception;

/**
 * Task 11 — levée quand une fusion d'InterventionType rencontre un conflit structurel
 * qui empêcherait de préserver l'intégrité (ex : une firme a déjà une FirmServiceOffering
 * sur les DEUX types, la fusion violerait UNIQUE(firm, interventionType)). Aucune mutation
 * n'a lieu quand cette exception est levée — la fusion entière est annulée, pas partielle.
 */
final class InterventionTypeMergeConflictException extends \RuntimeException
{
    /**
     * @param list<string> $conflictingFirmNames
     */
    public function __construct(private readonly array $conflictingFirmNames)
    {
        parent::__construct(sprintf(
            'Fusion impossible : %d firme(s) ont déjà une prestation sur les deux types (%s). Résoudre manuellement avant de fusionner.',
            count($conflictingFirmNames),
            implode(', ', $conflictingFirmNames),
        ));
    }

    /**
     * @return list<string>
     */
    public function getConflictingFirmNames(): array
    {
        return $this->conflictingFirmNames;
    }
}
