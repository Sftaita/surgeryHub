<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialLineType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\PricingRuleType;
use App\Dto\DocumentLineSelectionAnomaly;
use App\Exception\DocumentAlreadyIssuedException;
use App\Exception\DocumentCannotReleaseLinesException;
use App\Exception\DocumentLineSelectionException;
use Doctrine\DBAL\LockMode;
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
 *
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — preview()/generate()/markSent()/
 * markPaid() ci-dessus sont le chemin LEGACY, conservés strictement inchangés (ils
 * recalculent eux-mêmes les montants depuis PricingRule — encore le seul chemin utilisé
 * par le frontend actuel, voir FirmInvoiceServiceLot1AdaptationTest). Les nouvelles
 * méthodes ci-dessous (previewEligibleLines()/createFromEligibleLines()/cancel())
 * consomment exclusivement des FinancialCalculationLine déjà valorisées (Lot 3) —
 * jamais de PricingRuleResolver, jamais de recalcul. Les deux chemins coexistent
 * (§18 du lot) et ne sont jamais mélangés au sein d'un même document
 * (FirmInvoiceLine::isLegacy()).
 */
class FirmInvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $financialCalculationService,
        private readonly AuditService $audit,
    ) {}

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

    // ═══════════════════════════════════════════════════════════════════════
    // EPIC Exécution & Valorisation, Lot 4 (D-074) — chemin NOUVEAU, consomme
    // exclusivement des FinancialCalculationLine déjà valorisées (Lot 3).
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * §6.1 du lot — lecture seule, ne réserve rien. FIRM_INTERVENTION_FEE/
     * FIRM_MATERIAL_FEE uniquement, bénéficiaire = $firm, calcul APPROVED ou LOCKED
     * (jamais CALCULATED/SUPERSEDED/CANCELLED), devise = $currency, jamais déjà
     * rattachée à une facture. Période filtrée sur FinancialCalculationLine.effectiveAt
     * (convention centralisée, identique à InstrumentistStatementService — §7.2).
     */
    public function previewEligibleLines(Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $lines = $this->findEligibleFirmLines($firm, $currency, $periodStart, $periodEnd);

        return [
            'firm' => ['id' => $firm->getId(), 'name' => $firm->getName()],
            'currency' => $currency,
            'period' => ['start' => $periodStart->format('Y-m-d'), 'end' => $periodEnd->format('Y-m-d')],
            'lines' => array_map($this->serializeEligibleLine(...), $lines),
            'totalAmount' => $this->sumLineTotals($lines),
        ];
    }

    /**
     * §16 du lot — seul point d'entrée pour la création d'une facture à partir de
     * FinancialCalculationLine. Ne fait jamais confiance à previewEligibleLines() :
     * reverrouille chaque FinancialCalculation référencé (ordre croissant d'id — évite
     * les deadlocks entre générations concurrentes portant sur des ensembles de lignes
     * qui se recoupent, §14/§22) et revérifie individuellement chaque ligne sélectionnée
     * sous ce verrou. Une seule ligne devenue inéligible annule toute la création
     * (§28) — aucune persistance partielle. Verrouille chaque calcul concerné
     * (APPROVED → LOCKED, idempotent si déjà LOCKED — §10/§30) dans la même transaction.
     */
    public function createFromEligibleLines(
        Firm $firm,
        string $currency,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        array $selectedFinancialCalculationLineIds,
        User $actor,
    ): FirmInvoice {
        $result = null;

        $this->em->wrapInTransaction(function () use (&$result, $firm, $currency, $periodStart, $periodEnd, $selectedFinancialCalculationLineIds, $actor): void {
            ['lines' => $lines, 'missingIds' => $missingIds] = $this->lockAndReloadSelectedLines($selectedFinancialCalculationLineIds);

            $anomalies = $this->validateFirmLineSelection($lines, $firm, $currency, $periodStart, $periodEnd);
            foreach ($missingIds as $missingId) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_ELIGIBLE', sprintf('La ligne #%d est introuvable.', $missingId), ['financialCalculationLineId' => $missingId]);
            }
            if (count($anomalies) > 0) {
                throw new DocumentLineSelectionException($anomalies);
            }

            $invoice = new FirmInvoice();
            $invoice->setFirm($firm);
            $invoice->setCurrency($currency);
            $invoice->setPeriodStart($periodStart);
            $invoice->setPeriodEnd($periodEnd);
            $invoice->setStatus(InvoiceStatus::GENERATED);
            $invoice->setGeneratedAt(new \DateTimeImmutable());
            $invoice->setLegacySource(false);
            $invoice->setBillingEmailTo($firm->getBillingEmail());
            $invoice->setBillingEmailCc($firm->getBillingEmailCc());
            $invoice->setNumber($this->generateNumber($periodStart));
            // Persisté AVANT la boucle : FinancialCalculationService::lock() flush()
            // en interne à chaque itération (verrouillage d'un calcul déjà APPROVED) —
            // $invoice doit déjà être connue de l'UnitOfWork, sinon Doctrine refuse de
            // cascader la persistance des FirmInvoiceLine qui la référencent.
            $this->em->persist($invoice);

            $total = '0.00';
            $lockedCalculationIds = [];

            foreach ($lines as $line) {
                $invoiceLine = $this->hydrateFromFinancialLine($line);
                $invoice->addLine($invoiceLine);
                $this->em->persist($invoiceLine);
                $total = number_format((float) $total + (float) $line->getTotalAmount(), 2, '.', '');

                $calculation = $line->getFinancialCalculation();
                if ($calculation->getStatus() !== FinancialCalculationStatus::LOCKED) {
                    $this->financialCalculationService->lock($calculation, $actor);
                }
                $lockedCalculationIds[$calculation->getId()] = true;
            }

            $invoice->setTotalAmount($total);
            $this->em->flush();

            $this->audit->recordGlobal($actor, AuditEventType::FIRM_INVOICE_CREATED_FROM_CALCULATION, [
                'firmInvoiceId' => $invoice->getId(),
                'firmId' => $firm->getId(),
                'currency' => $currency,
                'periodStart' => $periodStart->format('Y-m-d'),
                'periodEnd' => $periodEnd->format('Y-m-d'),
                'financialCalculationLineIds' => array_map(static fn (FinancialCalculationLine $l) => $l->getId(), $lines),
                'financialCalculationIds' => array_keys($lockedCalculationIds),
                'totalAmount' => $total,
            ]);
            $this->em->flush();

            $result = $invoice;
        });

        return $result;
    }

    /**
     * §12/§13 du lot — GENERATED → CANCELLED uniquement (le seul état atteint avant
     * envoi dans ce produit, voir docblock de classe : GENERATED n'est jamais un simple
     * brouillon transitoire, c'est déjà le document définitif tant qu'il n'a pas été
     * envoyé). Libère physiquement les lignes documentaires rattachées à une
     * FinancialCalculationLine (contrainte UNIQUE levée, ligne à nouveau sélectionnable)
     * — ne déverrouille JAMAIS le FinancialCalculation associé (§10 : politique
     * explicite, jamais un déverrouillage automatique). SENT/PAID : refusé (§12,
     * document déjà engagé vis-à-vis du tiers).
     */
    public function cancel(FirmInvoice $invoice, User $actor, ?string $reason = null): FirmInvoice
    {
        if ($invoice->getStatus() !== InvoiceStatus::GENERATED) {
            throw new DocumentAlreadyIssuedException(sprintf(
                'Seule une facture GENERATED peut être annulée (statut actuel : %s).',
                $invoice->getStatus()->value,
            ));
        }

        $releasedLineIds = [];
        foreach ($invoice->getLines() as $line) {
            $financialLine = $line->getFinancialCalculationLine();
            if ($financialLine !== null) {
                $releasedLineIds[] = $financialLine->getId();
            }
            $this->em->remove($line);
        }
        $invoice->getLines()->clear();
        $invoice->setStatus(InvoiceStatus::CANCELLED);

        $this->audit->recordGlobal($actor, AuditEventType::FIRM_INVOICE_CANCELLED, [
            'firmInvoiceId' => $invoice->getId(),
            'firmId' => $invoice->getFirm()?->getId(),
            'reason' => $reason,
            'releasedFinancialCalculationLineIds' => $releasedLineIds,
        ]);
        $this->em->flush();

        return $invoice;
    }

    /**
     * §22 — verrouille chaque FinancialCalculation DISTINCT référencé par les lignes
     * sélectionnées, dans un ordre déterministe (id croissant) — la seule façon de
     * garantir qu'une deuxième création concurrente sur une ligne partagée attend
     * réellement. `refresh()` (pas `em->clear()`) recharge l'état de chaque calcul
     * verrouillé sans détacher $firm/$actor de l'appelant, qui restent des références
     * valides après cet appel — l'éligibilité elle-même est revérifiée par une requête
     * SQL fraîche dans validateFirmLineSelection()/isLineAlreadyAssigned(), jamais en
     * relisant une collection en mémoire potentiellement périmée.
     *
     * @param int[] $lineIds
     * @return array{lines: FinancialCalculationLine[], missingIds: int[]}
     */
    private function lockAndReloadSelectedLines(array $lineIds): array
    {
        $lines = [];
        $missingIds = [];
        foreach (array_unique($lineIds) as $id) {
            $line = $this->em->find(FinancialCalculationLine::class, $id);
            if ($line !== null) {
                $lines[] = $line;
            } else {
                $missingIds[] = $id;
            }
        }

        $calculationIds = [];
        foreach ($lines as $line) {
            $calculationIds[$line->getFinancialCalculation()->getId()] = true;
        }
        $sortedCalculationIds = array_keys($calculationIds);
        sort($sortedCalculationIds);

        foreach ($sortedCalculationIds as $calculationId) {
            $calculation = $this->em->find(FinancialCalculation::class, $calculationId);
            $this->em->lock($calculation, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($calculation);
        }

        return ['lines' => $lines, 'missingIds' => $missingIds];
    }

    /**
     * @param FinancialCalculationLine[] $lines
     * @return DocumentLineSelectionAnomaly[]
     */
    private function validateFirmLineSelection(array $lines, Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $anomalies = [];

        foreach ($lines as $line) {
            $context = ['financialCalculationLineId' => $line->getId()];

            if ($line->getBeneficiaryType() !== FinancialBeneficiaryType::FIRM || $line->getBeneficiaryFirm()?->getId() !== $firm->getId()) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_BENEFICIARY_MISMATCH', sprintf('La ligne #%d ne concerne pas la firme %d.', $line->getId(), $firm->getId()), $context);
                continue;
            }
            if ($line->getCurrency() !== strtoupper($currency)) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_CURRENCY_MISMATCH', sprintf('La ligne #%d est en %s, pas %s.', $line->getId(), $line->getCurrency(), $currency), $context);
                continue;
            }
            if ($line->getEffectiveAt() < $periodStart || $line->getEffectiveAt() > $periodEnd) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_ELIGIBLE', sprintf('La ligne #%d est hors période.', $line->getId()), $context);
                continue;
            }
            if (!in_array($line->getFinancialCalculation()->getStatus(), [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED], true)) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_CALCULATION_NOT_APPROVED', sprintf('Le calcul de la ligne #%d n\'est ni APPROVED ni LOCKED.', $line->getId()), $context);
                continue;
            }
            if ($this->isLineAlreadyAssigned($line)) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_ALREADY_ASSIGNED', sprintf('La ligne #%d est déjà rattachée à un document.', $line->getId()), $context);
            }
        }

        return $anomalies;
    }

    /**
     * Requête SQL fraîche (jamais l'association inverse potentiellement périmée en
     * mémoire) — la seule vérification fiable sous verrou pour une ligne déjà rattachée
     * par une transaction concurrente entre-temps committée (§14/§22).
     */
    private function isLineAlreadyAssigned(FinancialCalculationLine $line): bool
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(fil.id)')
            ->from(FirmInvoiceLine::class, 'fil')
            ->where('fil.financialCalculationLine = :line')
            ->setParameter('line', $line)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /** @return FinancialCalculationLine[] */
    private function findEligibleFirmLines(Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        return $this->em->createQueryBuilder()
            ->select('l')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'fc')
            ->leftJoin('l.firmInvoiceLine', 'fil')
            ->where('l.beneficiaryType = :beneficiaryType')
            ->andWhere('l.beneficiaryFirm = :firm')
            ->andWhere('l.currency = :currency')
            ->andWhere('fc.status IN (:statuses)')
            ->andWhere('fil.id IS NULL')
            ->andWhere('l.effectiveAt >= :start')
            ->andWhere('l.effectiveAt <= :end')
            ->setParameter('beneficiaryType', FinancialBeneficiaryType::FIRM)
            ->setParameter('firm', $firm)
            ->setParameter('currency', strtoupper($currency))
            ->setParameter('statuses', [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED])
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd)
            ->orderBy('l.effectiveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function hydrateFromFinancialLine(FinancialCalculationLine $line): FirmInvoiceLine
    {
        $invoiceLine = new FirmInvoiceLine();
        $invoiceLine->setMission($this->resolveMissionForLine($line));
        $invoiceLine->setFinancialCalculationLine($line);
        $invoiceLine->setMissionIntervention($line->getMissionIntervention());
        $invoiceLine->setMaterialLine($line->getMaterialLine());
        $invoiceLine->setLineType($this->mapFinancialLineType($line->getLineType()));
        $invoiceLine->setDescriptionSnapshot($line->getDescriptionSnapshot());
        $invoiceLine->setFirmNameSnapshot((string) ($line->getSnapshot()['firmNameSnapshot'] ?? $line->getBeneficiaryFirm()?->getName() ?? ''));
        $invoiceLine->setUnitPrice($line->getUnitAmount());
        $invoiceLine->setQuantity($line->getQuantity());
        $invoiceLine->setTotalAmount($line->getTotalAmount());
        $invoiceLine->setCurrency($line->getCurrency());
        $invoiceLine->setUnitSnapshot($line->getLineType() === FinancialLineType::FIRM_MATERIAL_FEE ? 'pièce' : 'forfait');
        $invoiceLine->setSourceSnapshot($line->getSnapshot());
        return $invoiceLine;
    }

    private function resolveMissionForLine(FinancialCalculationLine $line): Mission
    {
        return $line->getMissionIntervention()?->getMission()
            ?? $line->getMaterialLine()?->getMission()
            ?? $line->getFinancialCalculation()->getMission();
    }

    private function mapFinancialLineType(FinancialLineType $type): PricingRuleType
    {
        return match ($type) {
            FinancialLineType::FIRM_INTERVENTION_FEE => PricingRuleType::INTERVENTION_FEE,
            FinancialLineType::FIRM_MATERIAL_FEE => PricingRuleType::MATERIAL_FEE,
            default => throw new \LogicException(sprintf('%s ne concerne pas une firme.', $type->value)),
        };
    }

    /** @param FinancialCalculationLine[] $lines */
    private function sumLineTotals(array $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $total = number_format((float) $total + (float) $line->getTotalAmount(), 2, '.', '');
        }
        return $total;
    }

    /** @return array<string, mixed> */
    private function serializeEligibleLine(FinancialCalculationLine $line): array
    {
        return [
            'id' => $line->getId(),
            'financialCalculationId' => $line->getFinancialCalculation()->getId(),
            'financialCalculationVersion' => $line->getFinancialCalculation()->getVersion(),
            'missionId' => $this->resolveMissionForLine($line)->getId(),
            'lineType' => $line->getLineType()->value,
            'descriptionSnapshot' => $line->getDescriptionSnapshot(),
            'quantity' => $line->getQuantity(),
            'unitAmount' => $line->getUnitAmount(),
            'totalAmount' => $line->getTotalAmount(),
            'currency' => $line->getCurrency(),
            'effectiveAt' => $line->getEffectiveAt()?->format('Y-m-d'),
        ];
    }
}
