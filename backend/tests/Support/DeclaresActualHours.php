<?php

namespace App\Tests\Support;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Enum\HoursSource;

/**
 * D-143 (intégration) — une prestation n'est jamais valorisée sur les seuls horaires
 * planifiés (FinancialCalculationService : anomalie MISSING_ACTUAL_HOURS). Les fixtures
 * financières qui valorisaient une mission « sans exécution » déclarent donc, comme une
 * mission historique, une durée réelle égale à la plage planifiée — sans jamais fabriquer
 * de début ni de fin. Idempotent : ne touche pas une exécution déjà présente.
 *
 * Suppose `$this->em` (EntityManagerInterface), comme tous les tests qui l'utilisent.
 */
trait DeclaresActualHours
{
    private function declareActualHours(Mission $mission): ?MissionExecution
    {
        if ($mission->getExecution() !== null) {
            return $mission->getExecution();
        }
        if ($mission->getStartAt() === null || $mission->getEndAt() === null) {
            return null;
        }

        $minutes = (int) round(($mission->getEndAt()->getTimestamp() - $mission->getStartAt()->getTimestamp()) / 60);
        $execution = (new MissionExecution())
            ->setActualDurationMinutes(max(1, $minutes))
            ->setHoursSource(HoursSource::INSTRUMENTIST);
        $execution->setMission($mission);
        $mission->setExecution($execution);
        $this->em->persist($execution);
        $this->em->flush();

        return $execution;
    }
}
