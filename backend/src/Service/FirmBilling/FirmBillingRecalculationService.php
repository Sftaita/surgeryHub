<?php

namespace App\Service\FirmBilling;

use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FirmBillingReason;
use App\Enum\MissionStatus;
use App\Exception\FinancialCalculationAnomaliesException;
use App\Exception\FinancialCalculationIneligibleException;
use App\Service\FinancialCalculationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-133 — action groupée « Recalculer les éléments corrigés » / « Calculer les missions en
 * attente » de l'écran À corriger. N'ajoute AUCUNE règle : boucle sur
 * FinancialCalculationService::calculate()/recalculate() (seul moteur), mission par
 * mission, chacune dans sa propre transaction.
 *
 * Les gardes ci-dessous ne remplacent pas celles du moteur : elles évitent seulement de lui
 * soumettre une mission qu'il refuserait DANS sa transaction (une exception y ferme
 * l'EntityManager et interromprait le lot). Le moteur reste l'arbitre final.
 */
final class FirmBillingRecalculationService
{
    public const MAX_MISSIONS = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $financialCalculationService,
    ) {}

    /**
     * @param int[] $missionIds
     * @return array<int, array{missionId: int, outcome: string, message: string, issues: string[], calculationId: ?int}>
     */
    public function run(array $missionIds, User $actor): array
    {
        $results = [];
        $ids = array_values(array_unique(array_map('intval', $missionIds)));

        foreach ($ids as $position => $missionId) {
            if (!$this->em->isOpen()) {
                foreach (array_slice($ids, $position) as $remaining) {
                    $results[] = $this->result($remaining, 'NOT_PROCESSED', "Traitement interrompu : relancez l'action.");
                }
                break;
            }

            $mission = $this->em->find(Mission::class, $missionId);
            if ($mission === null) {
                $results[] = $this->result($missionId, 'SKIPPED', 'Mission introuvable.');
                continue;
            }
            if ($mission->getStatus() !== MissionStatus::VALIDATED) {
                $results[] = $this->result($missionId, 'SKIPPED', "L'encodage de la mission n'est pas validé.");
                continue;
            }
            if ($mission->getInstrumentist() === null) {
                $results[] = $this->result($missionId, 'SKIPPED', "Aucun instrumentiste n'est assigné à la mission.");
                continue;
            }

            $active = $this->em->getRepository(FinancialCalculation::class)->findOneBy(
                ['mission' => $mission, 'status' => [FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED]],
                ['version' => 'DESC'],
            );
            if ($active?->getStatus() === FinancialCalculationStatus::LOCKED) {
                $results[] = $this->result($missionId, 'SKIPPED', 'Le calcul est verrouillé par une facture : il ne peut plus être recalculé.');
                continue;
            }

            try {
                $calculation = $active === null
                    ? $this->financialCalculationService->calculate($mission, $actor)
                    : $this->financialCalculationService->recalculate($mission, $actor);
                $results[] = $this->result($missionId, 'CALCULATED', 'Calcul effectué — à approuver.', [], $calculation->getId());
            } catch (FinancialCalculationAnomaliesException $e) {
                $issues = [];
                foreach ($e->getAnomalies() as $anomaly) {
                    $issues[] = FirmBillingReason::fromEngineCode($anomaly->code)->label();
                }
                $results[] = $this->result($missionId, 'FAILED', 'Le calcul a encore échoué.', array_values(array_unique($issues)));
            } catch (FinancialCalculationIneligibleException) {
                $results[] = $this->result($missionId, 'SKIPPED', "La mission n'est pas éligible au calcul.");
            }
        }

        return $results;
    }

    private function result(int $missionId, string $outcome, string $message, array $issues = [], ?int $calculationId = null): array
    {
        return ['missionId' => $missionId, 'outcome' => $outcome, 'message' => $message, 'issues' => $issues, 'calculationId' => $calculationId];
    }
}
