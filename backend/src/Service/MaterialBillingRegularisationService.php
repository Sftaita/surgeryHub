<?php

namespace App\Service;

use App\Entity\FinancialCalculation;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Exception\PricingRuleConflictException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-142 — vue de régularisation du catalogue matériel, en LECTURE SEULE.
 *
 * Pour chaque article : sa décision de facturation (non décidé / facturable / non
 * facturable), ses tarifs, ses utilisations dans les missions VALIDÉES et les missions
 * qu'il bloque ENCORE. « Bloque » est décidé exclusivement par le moteur :
 * FinancialCalculationService::evaluate() est rejoué, sans écriture ni audit, pour chaque
 * mission validée sans calcul actif qui utilise du matériel — jamais une seconde logique de
 * résolution des tarifs ici. Une mission dont le calcul est actif (CALCULATED, APPROVED,
 * LOCKED) n'est jamais réévaluée : une modification du catalogue ne la touche pas (aucun
 * recalcul automatique, historique figé).
 *
 * Les autres anomalies de la mission sont comptées à part (`otherBlockingAnomalyCount`) :
 * corriger un article ne présente jamais une mission comme débloquée tant qu'une autre
 * cause demeure.
 */
final class MaterialBillingRegularisationService
{
    public const UNDECIDED = 'UNDECIDED';
    public const BILLABLE_RATE_MISSING = 'BILLABLE_RATE_MISSING';
    public const BILLABLE = 'BILLABLE';
    public const NOT_BILLABLE = 'NOT_BILLABLE';

    private const STATUS_LABELS = [
        self::UNDECIDED => 'Non décidé',
        self::BILLABLE_RATE_MISSING => 'Tarif manquant',
        self::BILLABLE => 'Facturable',
        self::NOT_BILLABLE => 'Non facturable',
    ];

    private const ACTIVE_STATUSES = [
        FinancialCalculationStatus::CALCULATED,
        FinancialCalculationStatus::APPROVED,
        FinancialCalculationStatus::LOCKED,
    ];

    private const MATERIAL_ANOMALY_CODES = ['MISSING_FIRM_MATERIAL_RATE', 'CONFLICTING_FIRM_MATERIAL_RATE'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $financialCalculationService,
        private readonly PricingRuleResolver $pricingRuleResolver,
    ) {}

    /**
     * @return array{summary: array<string, int>, items: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        /** @var MaterialItem[] $items */
        $items = $this->em->createQueryBuilder()
            ->select('i', 'f')
            ->from(MaterialItem::class, 'i')
            ->join('i.firm', 'f')
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('i.label', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var MaterialLine[] $lines lignes de matériel des missions VALIDÉES */
        // L'exécution (OneToOne inverse, sinon une requête par mission), le site et le
        // chirurgien sont chargés avec les lignes : une requête pour tout l'historique.
        $lines = $this->em->createQueryBuilder()
            ->select('ml', 'm', 'item', 'ex', 'site', 'surgeon')
            ->from(MaterialLine::class, 'ml')
            ->join('ml.mission', 'm')
            ->join('ml.item', 'item')
            ->leftJoin('m.execution', 'ex')
            ->leftJoin('m.site', 'site')
            ->leftJoin('m.surgeon', 'surgeon')
            ->where('m.status = :validated')
            ->setParameter('validated', MissionStatus::VALIDATED)
            ->orderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();

        $missions = [];
        $linesByItem = [];
        foreach ($lines as $ml) {
            $mission = $ml->getMission();
            $missions[$mission->getId()] = $mission;
            $linesByItem[$ml->getItem()->getId()][] = $ml;
        }

        $calculated = $this->missionsWithActiveCalculation(array_keys($missions));
        $pending = array_values(array_diff(array_keys($missions), array_keys($calculated)));
        $this->preloadForEvaluation($pending);

        // Une seule évaluation par mission, partagée par tous les articles.
        $evaluations = [];
        foreach ($pending as $id) {
            $evaluations[$id] = $this->financialCalculationService->evaluate($missions[$id]);
        }
        $rulesByItem = $this->pricingRuleResolver->materialFeeRulesByItem();

        $today = new \DateTimeImmutable('today');
        $rows = [];
        $blockedMissions = [];
        foreach ($items as $item) {
            $row = $this->itemRow($item, $rulesByItem[$item->getId()] ?? [], $linesByItem[$item->getId()] ?? [], $calculated, $evaluations, $today);
            foreach ($row['missions'] as $m) {
                if ($m['blockedByThisItem']) {
                    $blockedMissions[$m['id']] = true;
                }
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b) => [$b['toConfigure'], $b['usage']['blockedMissionCount'], $b['usage']['lineCount'], $a['firm']['name'] ?? '', $a['label']]
            <=> [$a['toConfigure'], $a['usage']['blockedMissionCount'], $a['usage']['lineCount'], $b['firm']['name'] ?? '', $b['label']]);

        $pendingMissionCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Mission::class, 'm')
            ->where('m.status = :validated')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . FinancialCalculation::class . ' fc WHERE fc.mission = m AND fc.status IN (:active))')
            ->setParameter('validated', MissionStatus::VALIDATED)
            ->setParameter('active', self::ACTIVE_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();

        $count = static fn (string $status) => count(array_filter($rows, static fn (array $r) => $r['status'] === $status));

        return [
            'summary' => [
                'itemCount' => count($rows),
                'toConfigureCount' => count(array_filter($rows, static fn (array $r) => $r['toConfigure'])),
                'undecidedCount' => $count(self::UNDECIDED),
                'rateMissingCount' => $count(self::BILLABLE_RATE_MISSING),
                'billableCount' => $count(self::BILLABLE),
                'notBillableCount' => $count(self::NOT_BILLABLE),
                'blockedMissionCount' => count($blockedMissions),
                'pendingMissionCount' => $pendingMissionCount,
            ],
            'items' => $rows,
        ];
    }

    /**
     * @param \App\Entity\PricingRule[]                               $rules tarifs MATERIAL_FEE de l'article
     * @param MaterialLine[]                                         $itemLines
     * @param array<int, true>                                       $calculated
     * @param array<int, \App\Dto\MissionFinancialEvaluation>        $evaluations
     */
    private function itemRow(MaterialItem $item, array $rules, array $itemLines, array $calculated, array $evaluations, \DateTimeImmutable $today): array
    {
        try {
            $current = PricingRuleResolver::resolveMaterialFeeAmong($rules, $today);
            $conflict = false;
        } catch (PricingRuleConflictException) {
            $current = null;
            $conflict = true;
        }

        $byMission = [];
        foreach ($itemLines as $ml) {
            $byMission[$ml->getMission()->getId()][] = $ml;
        }

        $missionRows = [];
        $dates = [];
        foreach ($byMission as $missionId => $mLines) {
            $mission = $mLines[0]->getMission();
            $date = $this->financialCalculationService->resolveEffectiveAt($mission);
            $dates[] = $date->format('Y-m-d');
            $lineIds = array_map(static fn (MaterialLine $l) => $l->getId(), $mLines);

            $evaluation = $evaluations[$missionId] ?? null;
            $blocked = false;
            $other = 0;
            if ($evaluation !== null) {
                foreach ($evaluation->anomalies as $anomaly) {
                    $mine = in_array($anomaly->context['materialLineId'] ?? null, $lineIds, true);
                    if ($mine && in_array($anomaly->code, self::MATERIAL_ANOMALY_CODES, true)) {
                        $blocked = true;
                    } elseif (!$mine) {
                        $other++;
                    }
                }
            }

            $missionRows[] = [
                'id' => $missionId,
                'date' => $date->format('Y-m-d'),
                'site' => $mission->getSite()?->getName(),
                'surgeon' => $mission->getSurgeon() !== null ? trim(($mission->getSurgeon()->getFirstname() ?? '') . ' ' . ($mission->getSurgeon()->getLastname() ?? '')) : null,
                'lineCount' => count($mLines),
                'calculationState' => isset($calculated[$missionId]) ? 'CALCULATED' : 'PENDING',
                'blockedByThisItem' => $blocked,
                // Anomalies du moteur qui ne viennent pas de cet article : tant qu'il en
                // reste, corriger l'article ne suffit pas à rendre la mission calculable.
                'otherBlockingAnomalyCount' => $other,
            ];
        }
        usort($missionRows, static fn (array $a, array $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        $blockedRows = array_filter($missionRows, static fn (array $m) => $m['blockedByThisItem']);
        $status = $this->status($item, $rules !== [], count($blockedRows) > 0, $current !== null || $conflict);

        sort($dates);

        return [
            'id' => $item->getId(),
            'label' => $item->getLabel(),
            'referenceCode' => $item->getReferenceCode(),
            'unit' => $item->getUnit(),
            'isImplant' => $item->isImplant(),
            'active' => $item->isActive(),
            'firm' => ['id' => $item->getFirm()->getId(), 'name' => $item->getFirm()->getName()],
            'billingStatus' => $item->getBillingStatus()->value,
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status],
            'toConfigure' => in_array($status, [self::UNDECIDED, self::BILLABLE_RATE_MISSING], true),
            'currentRate' => $current !== null ? ['id' => $current->getId(), 'unitPrice' => $current->getUnitPrice(), 'currency' => $current->getCurrency()] : null,
            'pricingConflict' => $conflict,
            'rules' => PricingRuleResolver::snapshotRules($rules),
            'usage' => [
                'lineCount' => count($itemLines),
                'missionCount' => count($missionRows),
                'firstDate' => $dates[0] ?? null,
                'lastDate' => $dates !== [] ? $dates[count($dates) - 1] : null,
                'blockedMissionCount' => count($blockedRows),
                // Missions où cet article est la SEULE cause restante (selon le moteur).
                'onlyBlockerMissionCount' => count(array_filter($blockedRows, static fn (array $m) => $m['otherBlockingAnomalyCount'] === 0)),
            ],
            'missions' => $missionRows,
        ];
    }

    private function status(MaterialItem $item, bool $hasRules, bool $blocksMissions, bool $hasRateToday): string
    {
        if ($item->getBillingStatus() === MaterialBillingStatus::NOT_BILLABLE) {
            return self::NOT_BILLABLE;
        }
        if (!$hasRules && $item->getBillingStatus() === MaterialBillingStatus::UNSPECIFIED) {
            return self::UNDECIDED;
        }
        // Facturable (ou tarifé) : il manque un tarif s'il bloque une mission à sa date, ou
        // si aucun tarif ne s'applique aujourd'hui (aucune nouvelle prestation valorisable).
        if ($blocksMissions || !$hasRateToday) {
            return self::BILLABLE_RATE_MISSING;
        }

        return self::BILLABLE;
    }

    /**
     * Initialise en deux requêtes les collections que evaluate() parcourt (interventions et
     * lignes de matériel) pour les missions à évaluer — sinon deux requêtes par mission. Les
     * entités sont déjà dans l'unité de travail : seules leurs collections sont remplies.
     *
     * @param list<int> $missionIds
     */
    private function preloadForEvaluation(array $missionIds): void
    {
        if ($missionIds === []) {
            return;
        }
        foreach (['interventions', 'materialLines'] as $association) {
            $this->em->createQueryBuilder()
                ->select('m', 'a')
                ->from(Mission::class, 'm')
                ->leftJoin('m.' . $association, 'a')
                ->where('m.id IN (:ids)')
                ->setParameter('ids', $missionIds)
                ->getQuery()
                ->getResult();
        }
    }

    /** @return array<int, true> */
    private function missionsWithActiveCalculation(array $missionIds): array
    {
        if ($missionIds === []) {
            return [];
        }
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(fc.mission)')
            ->from(FinancialCalculation::class, 'fc')
            ->where('IDENTITY(fc.mission) IN (:ids)')
            ->andWhere('fc.status IN (:active)')
            ->setParameter('ids', $missionIds)
            ->setParameter('active', self::ACTIVE_STATUSES)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
