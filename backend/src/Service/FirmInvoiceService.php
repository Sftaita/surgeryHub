<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot 1 — adaptation minimale (Stratégie A, contrôle final du 2026-07-16) : ce service
 * appelait encore PricingRuleType::IMPLANT_FEE et PricingRule::getInterventionCode(),
 * tous deux supprimés par l'évolution du modèle. Le rapprochement se fait désormais via
 * InterventionType.code (recherché depuis MissionIntervention.code, inchangé — Lot 5
 * ajoutera une vraie relation) et PricingRuleType::MATERIAL_FEE. isImplant() n'est plus
 * un filtre financier (voir docs/decisions.md D-067) : un matériel non-implant peut
 * désormais être facturé si une règle existe. Le pipeline complet de facturation
 * (génération réelle, statut VALIDATED atteignable) reste un chantier séparé — cette
 * adaptation ne fait que réparer la compilation/l'exécution sur le nouveau modèle.
 */
class FirmInvoiceService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Retourne les lignes facturables pour une firme + période donnée.
     * Exclut les interventions/matériel déjà dans une facture GENERATED+.
     */
    public function preview(Firm $firm, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $rules = $this->getActiveRules($firm);
        if (empty($rules)) {
            return ['firm' => ['id' => $firm->getId(), 'name' => $firm->getName()], 'lines' => [], 'totalAmount' => 0.0];
        }

        $missions = $this->findValidatedMissions($periodStart, $periodEnd);
        $alreadyBilledInterventionIds = $this->getAlreadyBilledInterventionIds($firm);
        $alreadyBilledMaterialLineIds = $this->getAlreadyBilledMaterialLineIds($firm);

        $lines = [];

        foreach ($missions as $mission) {
            foreach ($mission->getInterventions() as $intervention) {
                if (in_array($intervention->getId(), $alreadyBilledInterventionIds, true)) {
                    continue;
                }
                $rule = $this->findInterventionRule($rules, $intervention->getCode(), $mission->getStartAt());
                if ($rule === null) {
                    continue;
                }
                $lines[] = $this->buildInterventionPreviewLine($mission, $intervention, $rule);
            }

            foreach ($mission->getMaterialLines() as $materialLine) {
                // isImplant() n'est plus un filtre financier (D-067) — retiré ici.
                if ($materialLine->getItem()->getFirm()->getId() !== $firm->getId()) {
                    continue;
                }
                if (in_array($materialLine->getId(), $alreadyBilledMaterialLineIds, true)) {
                    continue;
                }
                $rule = $this->findMaterialRule($rules, $materialLine->getItem()->getId(), $mission->getStartAt());
                if ($rule === null) {
                    continue;
                }
                $lines[] = $this->buildMaterialPreviewLine($mission, $materialLine, $rule);
            }
        }

        $total = array_sum(array_column($lines, 'totalAmount'));

        return [
            'firm' => ['id' => $firm->getId(), 'name' => $firm->getName()],
            'period' => ['start' => $periodStart->format('Y-m-d'), 'end' => $periodEnd->format('Y-m-d')],
            'lines' => $lines,
            'totalAmount' => round($total, 2),
        ];
    }

    /**
     * Génère une facture définitive (snapshot + numéro + verrouillage).
     */
    public function generate(
        Firm $firm,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        array $selectedInterventionIds,
        array $selectedMaterialLineIds
    ): FirmInvoice {
        $rules = $this->getActiveRules($firm);
        $missions = $this->findValidatedMissions($periodStart, $periodEnd);
        $alreadyBilledInterventionIds = $this->getAlreadyBilledInterventionIds($firm);
        $alreadyBilledMaterialLineIds = $this->getAlreadyBilledMaterialLineIds($firm);

        $invoice = new FirmInvoice();
        $invoice->setFirm($firm);
        $invoice->setPeriodStart($periodStart);
        $invoice->setPeriodEnd($periodEnd);
        $invoice->setStatus(InvoiceStatus::GENERATED);
        $invoice->setGeneratedAt(new \DateTimeImmutable());
        $invoice->setBillingEmailTo($firm->getBillingEmail());
        $invoice->setBillingEmailCc($firm->getBillingEmailCc());

        $total = 0.0;

        foreach ($missions as $mission) {
            foreach ($mission->getInterventions() as $intervention) {
                if (!in_array($intervention->getId(), $selectedInterventionIds, true)) {
                    continue;
                }
                if (in_array($intervention->getId(), $alreadyBilledInterventionIds, true)) {
                    continue;
                }
                $rule = $this->findInterventionRule($rules, $intervention->getCode(), $mission->getStartAt());
                if ($rule === null) {
                    continue;
                }

                $lineData = $this->buildInterventionPreviewLine($mission, $intervention, $rule);
                $line = $this->createLine($mission, $lineData);
                $line->setMissionIntervention($intervention);
                $invoice->addLine($line);
                $total += (float) $lineData['totalAmount'];
            }

            foreach ($mission->getMaterialLines() as $materialLine) {
                if (!in_array($materialLine->getId(), $selectedMaterialLineIds, true)) {
                    continue;
                }
                // isImplant() n'est plus un filtre financier (D-067) — retiré ici.
                if ($materialLine->getItem()->getFirm()->getId() !== $firm->getId()) {
                    continue;
                }
                if (in_array($materialLine->getId(), $alreadyBilledMaterialLineIds, true)) {
                    continue;
                }
                $rule = $this->findMaterialRule($rules, $materialLine->getItem()->getId(), $mission->getStartAt());
                if ($rule === null) {
                    continue;
                }

                $lineData = $this->buildMaterialPreviewLine($mission, $materialLine, $rule);
                $line = $this->createLine($mission, $lineData);
                $line->setMaterialLine($materialLine);
                $invoice->addLine($line);
                $total += (float) $lineData['totalAmount'];
            }
        }

        $invoice->setTotalAmount((string) round($total, 2));
        $invoice->setNumber($this->generateNumber($periodStart));

        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    public function markSent(FirmInvoice $invoice): FirmInvoice
    {
        if ($invoice->getStatus() !== InvoiceStatus::GENERATED) {
            throw new \DomainException('La facture doit être en statut GENERATED pour être envoyée.');
        }
        $invoice->setStatus(InvoiceStatus::SENT);
        $invoice->setSentAt(new \DateTimeImmutable());
        $this->em->flush();
        return $invoice;
    }

    public function markPaid(FirmInvoice $invoice): FirmInvoice
    {
        if ($invoice->getStatus() === InvoiceStatus::PAID) {
            return $invoice;
        }
        $invoice->setStatus(InvoiceStatus::PAID);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();
        return $invoice;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return PricingRule[] */
    private function getActiveRules(Firm $firm): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(PricingRule::class, 'r')
            ->leftJoin('r.materialItem', 'mi')
            ->where('r.firm = :firm')
            ->andWhere('r.active = true')
            ->setParameter('firm', $firm)
            ->getQuery()
            ->getResult();
    }

    /** @return Mission[] */
    private function findValidatedMissions(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->em->createQueryBuilder()
            ->select('m', 'interventions', 'materialLines', 'item', 'itemFirm')
            ->from(Mission::class, 'm')
            ->leftJoin('m.interventions', 'interventions')
            ->leftJoin('m.materialLines', 'materialLines')
            ->leftJoin('materialLines.item', 'item')
            ->leftJoin('item.firm', 'itemFirm')
            ->where('m.status = :status')
            ->andWhere('m.startAt >= :start')
            ->andWhere('m.startAt <= :end')
            ->setParameter('status', MissionStatus::VALIDATED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function getAlreadyBilledInterventionIds(Firm $firm): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.missionIntervention) as itvId')
            ->from(FirmInvoiceLine::class, 'l')
            ->join('l.invoice', 'inv')
            ->where('inv.firm = :firm')
            ->andWhere('inv.status IN (:statuses)')
            ->andWhere('l.missionIntervention IS NOT NULL')
            ->setParameter('firm', $firm)
            ->setParameter('statuses', [InvoiceStatus::GENERATED, InvoiceStatus::SENT, InvoiceStatus::PAID])
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'itvId');
    }

    private function getAlreadyBilledMaterialLineIds(Firm $firm): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.materialLine) as mlId')
            ->from(FirmInvoiceLine::class, 'l')
            ->join('l.invoice', 'inv')
            ->where('inv.firm = :firm')
            ->andWhere('inv.status IN (:statuses)')
            ->andWhere('l.materialLine IS NOT NULL')
            ->setParameter('firm', $firm)
            ->setParameter('statuses', [InvoiceStatus::GENERATED, InvoiceStatus::SENT, InvoiceStatus::PAID])
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'mlId');
    }

    /**
     * Rapproche par InterventionType.code (via MissionIntervention.code, texte libre
     * inchangé jusqu'au Lot 5) plutôt que par l'ancien PricingRule.interventionCode
     * supprimé. Filtre aussi par date de validité (coversDate) — absent avant l'ajout de
     * validFrom/validTo dans ce lot, corrigé au passage.
     */
    private function findInterventionRule(array $rules, string $code, \DateTimeImmutable $missionDate): ?PricingRule
    {
        foreach ($rules as $rule) {
            if (
                $rule->getRuleType() === PricingRuleType::INTERVENTION_FEE
                && $rule->getInterventionType()?->getCode() === $code
                && $rule->coversDate($missionDate)
            ) {
                return $rule;
            }
        }
        return null;
    }

    private function findMaterialRule(array $rules, int $materialItemId, \DateTimeImmutable $missionDate): ?PricingRule
    {
        foreach ($rules as $rule) {
            if (
                $rule->getRuleType() === PricingRuleType::MATERIAL_FEE
                && $rule->getMaterialItem()?->getId() === $materialItemId
                && $rule->coversDate($missionDate)
            ) {
                return $rule;
            }
        }
        return null;
    }

    private function buildInterventionPreviewLine(Mission $mission, MissionIntervention $intervention, PricingRule $rule): array
    {
        $qty = 1.0;
        $unitPrice = (float) $rule->getUnitPrice();
        return [
            'missionId' => $mission->getId(),
            'missionDate' => $mission->getStartAt()->format('Y-m-d'),
            'interventionId' => $intervention->getId(),
            'materialLineId' => null,
            'lineType' => PricingRuleType::INTERVENTION_FEE->value,
            'descriptionSnapshot' => sprintf('[%s] %s', $intervention->getCode(), $intervention->getLabel()),
            'firmNameSnapshot' => $rule->getFirm()->getName(),
            'unitPrice' => $unitPrice,
            'quantity' => $qty,
            'totalAmount' => round($qty * $unitPrice, 2),
        ];
    }

    private function buildMaterialPreviewLine(Mission $mission, MaterialLine $materialLine, PricingRule $rule): array
    {
        $qty = (float) $materialLine->getQuantity();
        $unitPrice = (float) $rule->getUnitPrice();
        return [
            'missionId' => $mission->getId(),
            'missionDate' => $mission->getStartAt()->format('Y-m-d'),
            'interventionId' => null,
            'materialLineId' => $materialLine->getId(),
            'lineType' => PricingRuleType::MATERIAL_FEE->value,
            'descriptionSnapshot' => sprintf(
                '%s — %s (Réf: %s)',
                $materialLine->getItem()->getLabel(),
                $materialLine->getItem()->getFirm()->getName(),
                $materialLine->getItem()->getReferenceCode() ?? '—'
            ),
            'firmNameSnapshot' => $rule->getFirm()->getName(),
            'unitPrice' => $unitPrice,
            'quantity' => $qty,
            'totalAmount' => round($qty * $unitPrice, 2),
        ];
    }

    private function createLine(Mission $mission, array $data): FirmInvoiceLine
    {
        $line = new FirmInvoiceLine();
        $line->setMission($mission);
        $line->setLineType(PricingRuleType::from($data['lineType']));
        $line->setDescriptionSnapshot($data['descriptionSnapshot']);
        $line->setFirmNameSnapshot($data['firmNameSnapshot']);
        $line->setUnitPrice((string) $data['unitPrice']);
        $line->setQuantity((string) $data['quantity']);
        $line->setTotalAmount((string) $data['totalAmount']);
        return $line;
    }

    private function generateNumber(\DateTimeImmutable $periodStart): string
    {
        $year = (int) $periodStart->format('Y');

        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(FirmInvoice::class, 'i')
            ->where('i.number LIKE :pattern')
            ->setParameter('pattern', sprintf('FIRM-%d-%%', $year))
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('FIRM-%d-%03d', $year, $count + 1);
    }
}
