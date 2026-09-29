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
use App\Entity\User;
use App\Dto\EligibleLinesDiagnostic;
use App\Repository\EncodingTrackingRepository;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialDocumentType;
use App\Enum\FinancialLineType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\PricingRuleType;
use App\Dto\DocumentLineSelectionAnomaly;
use App\Exception\DocumentAlreadyIssuedException;
use App\Exception\DocumentCannotReleaseLinesException;
use App\Exception\DocumentLineSelectionException;
use App\Exception\InvoiceStatusTransitionException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) puis nettoyage architectural (D-121) —
 * ce service ne consomme plus que des FinancialCalculationLine déjà valorisées et
 * figées (Lot 3) : jamais de PricingRuleResolver, jamais de recalcul au moment de la
 * facturation. Le chemin LEGACY (preview()/generate(), qui relisait PricingRule et
 * recalculait les montants à la génération) a été supprimé — aucune facture n'avait
 * jamais été produite par ce chemin en production (voir D-121).
 */
class FirmInvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $financialCalculationService,
        private readonly AuditService $audit,
        private readonly EncodingTrackingRepository $encodingTrackingRepository,
    ) {}

    /**
     * Conservé pour compatibilité (POST /{id}/send, existant) — délègue à issue()
     * (Lot 5, D-075) pour la transition elle-même ; le contrôleur reste responsable de
     * l'envoi de l'email (inchangé).
     */
    public function markSent(FirmInvoice $invoice, User $actor): FirmInvoice
    {
        return $this->issue($invoice, $actor);
    }

    /**
     * EPIC Exécution & Valorisation, Lot 5 (D-075) — §12 du lot : émission explicite.
     * GENERATED → SENT uniquement ; attribue un numéro si celui-ci manque encore
     * (défensif — déjà assigné à la création dans ce système, legacy ET nouveau
     * chemin), date l'émission, audite, et empêche toute nouvelle modification
     * documentaire (SENT est un point de non-retour — voir cancel(), Lot 4).
     */
    public function issue(FirmInvoice $invoice, User $actor): FirmInvoice
    {
        if ($invoice->getStatus() !== InvoiceStatus::GENERATED) {
            throw new \DomainException('La facture doit être en statut GENERATED pour être envoyée.');
        }

        if ($invoice->getNumber() === null) {
            $invoice->setNumber($this->generateNumber($invoice->getPeriodStart(), $invoice->getDocumentType()));
        }

        $invoice->setStatus(InvoiceStatus::SENT);
        $invoice->setSentAt(new \DateTimeImmutable());

        $this->audit->recordGlobal($actor, AuditEventType::FIRM_INVOICE_ISSUED, [
            'firmInvoiceId' => $invoice->getId(),
            'firmId' => $invoice->getFirm()?->getId(),
            'number' => $invoice->getNumber(),
            'previousStatus' => InvoiceStatus::GENERATED->value,
            'newStatus' => InvoiceStatus::SENT->value,
        ]);

        $this->em->flush();
        return $invoice;
    }

    /**
     * D-123 — cycle de vie strict GENERATED → SENT → PAID : seule une facture ENVOYÉE peut
     * être marquée entièrement réglée. GENERATED (jamais envoyée), CANCELLED et PAID (déjà
     * réglée) sont refusés par un 409 métier explicite. Les paiements partiels (API
     * payments, D-075) restent un mécanisme distinct, jamais fusionné ici.
     */
    public function markPaid(FirmInvoice $invoice): FirmInvoice
    {
        $message = match ($invoice->getStatus()) {
            InvoiceStatus::SENT => null,
            InvoiceStatus::PAID => 'Cette facture est déjà marquée payée.',
            InvoiceStatus::CANCELLED => 'Une facture annulée ne peut pas être marquée payée.',
            InvoiceStatus::GENERATED => "La facture doit d'abord être envoyée avant d'être marquée payée.",
            default => sprintf('Une facture au statut %s ne peut pas être marquée payée.', $invoice->getStatus()->value),
        };
        if ($message !== null) {
            throw new InvoiceStatusTransitionException($message);
        }
        $invoice->setStatus(InvoiceStatus::PAID);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();
        return $invoice;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * EPIC Exécution & Valorisation, Lot 6 (D-076) — §19 du lot : préfixe distinct par
     * type documentaire (traçabilité — un numéro doit permettre de distinguer une
     * facture d'une note de crédit/débit d'un coup d'œil), même stratégie de comptage
     * COUNT(...)+1 filtrée par préfixe (le filet de sécurité reste la contrainte
     * UNIQUE en base, inchangée — voir D-074/D-075).
     */
    private function generateNumber(\DateTimeImmutable $periodStart, FinancialDocumentType $type = FinancialDocumentType::STANDARD): string
    {
        $year = (int) $periodStart->format('Y');
        $prefix = match ($type) {
            FinancialDocumentType::STANDARD => 'FIRM',
            FinancialDocumentType::CREDIT_NOTE => 'FIRM-CN',
            FinancialDocumentType::DEBIT_NOTE => 'FIRM-DN',
        };

        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(FirmInvoice::class, 'i')
            ->where('i.number LIKE :pattern')
            ->setParameter('pattern', sprintf('%s-%d-%%', $prefix, $year))
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s-%d-%03d', $prefix, $year, $count + 1);
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

        $result = [
            'firm' => ['id' => $firm->getId(), 'name' => $firm->getName()],
            'currency' => $currency,
            'period' => ['start' => $periodStart->format('Y-m-d'), 'end' => $periodEnd->format('Y-m-d')],
            'lines' => array_map($this->serializeEligibleLine(...), $lines),
            'totalAmount' => $this->sumLineTotals($lines),
        ];

        if (count($lines) === 0) {
            $result['diagnostic'] = $this->buildDiagnostic($firm, $currency, $periodStart, $periodEnd)->toArray();
        }

        return $result;
    }

    /**
     * Diagnostic explicatif (D-121, §6) — appelé uniquement quand `previewEligibleLines()`
     * ne trouve aucune ligne. Ne relance jamais le moteur de calcul/résolution tarifaire :
     * uniquement des COUNT() sur des données déjà persistées, plus une lecture de
     * l'historique d'audit des échecs de calcul (FINANCIAL_CALCULATION_FAILED) pour
     * distinguer "tarif manquant" sans deviner. Missions "concernant la firme" = ayant au
     * moins une intervention dont la firme principale est $firm ou un matériel dont la
     * firme est $firm — mêmes deux relations que FinancialCalculationService.
     */
    private function buildDiagnostic(Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): EligibleLinesDiagnostic
    {
        $missionIds = $this->findValidatedMissionIdsForFirm($firm, $periodStart, $periodEnd);
        $validatedMissionCount = count($missionIds);

        $byStatus = $this->countCalculationsByStatusForMissions($missionIds);
        $calculatedCount = $byStatus[FinancialCalculationStatus::CALCULATED->value] ?? 0;
        $approvedCount = $byStatus[FinancialCalculationStatus::APPROVED->value] ?? 0;
        $lockedCount = $byStatus[FinancialCalculationStatus::LOCKED->value] ?? 0;
        $calculationCount = $calculatedCount + $approvedCount + $lockedCount;

        $missingPricingCount = $missionIds === [] ? 0 : $this->countMissionsWithCalculationFailure($missionIds);
        $currencyMismatchCount = $this->countFirmLines($firm, $periodStart, $periodEnd, currency: null, excludeCurrency: strtoupper($currency), onlyUnassigned: false);
        $alreadyInvoicedCount = $this->countFirmLines($firm, $periodStart, $periodEnd, currency: strtoupper($currency), excludeCurrency: null, onlyUnassigned: false, onlyAssigned: true);

        $reasons = [];
        if ($validatedMissionCount === 0) {
            $reasons[] = 'NO_VALIDATED_MISSIONS';
        } elseif ($calculationCount === 0) {
            $reasons[] = 'NO_FINANCIAL_CALCULATIONS';
            if ($missingPricingCount > 0) {
                $reasons[] = 'MISSING_PRICING';
            }
        } else {
            if ($calculatedCount > 0) {
                $reasons[] = 'CALCULATIONS_PENDING_APPROVAL';
            }
            if ($missingPricingCount > 0) {
                $reasons[] = 'MISSING_PRICING';
            }
            if ($currencyMismatchCount > 0) {
                $reasons[] = 'CURRENCY_MISMATCH';
            }
            if ($alreadyInvoicedCount > 0) {
                $reasons[] = 'ALREADY_INVOICED';
            }
            if (($approvedCount + $lockedCount) > 0 && $reasons === []) {
                // Un calcul APPROVED/LOCKED existe pour une mission qui concerne $firm, mais
                // n'a produit aucune FinancialCalculationLine bénéficiaire=FIRM/$firm (ex.
                // FirmServiceOffering.feeApplicable=false — décision commerciale explicite,
                // voir FinancialCalculationService::resolveFirmInterventionLine()).
                $reasons[] = 'NO_LINES_FOR_BENEFICIARY';
            }
        }

        return new EligibleLinesDiagnostic(
            validatedMissionCount: $validatedMissionCount,
            calculationCount: $calculationCount,
            calculatedCount: $calculatedCount,
            approvedCount: $approvedCount,
            lockedCount: $lockedCount,
            missingPricingCount: $missingPricingCount,
            currencyMismatchCount: $currencyMismatchCount,
            alreadyInvoicedCount: $alreadyInvoicedCount,
            reasons: $reasons,
        );
    }

    /** @return int[] */
    private function findValidatedMissionIdsForFirm(Firm $firm, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT m.id')
            ->from(Mission::class, 'm')
            ->leftJoin('m.execution', 'exec')
            ->leftJoin('m.interventions', 'itv')
            ->leftJoin('m.materialLines', 'ml')
            ->leftJoin('ml.item', 'item')
            ->where('m.status = :status')
            ->andWhere('(itv.primaryFirm = :firm OR item.firm = :firm)')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) >= :start')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) <= :end')
            ->setParameter('status', MissionStatus::VALIDATED)
            ->setParameter('firm', $firm)
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    /** @param int[] $missionIds @return array<string, int> statut => nombre de calculs actifs */
    private function countCalculationsByStatusForMissions(array $missionIds): array
    {
        if ($missionIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('fc.status as status', 'COUNT(fc.id) as cnt')
            ->from(FinancialCalculation::class, 'fc')
            ->where('IDENTITY(fc.mission) IN (:missionIds)')
            ->andWhere('fc.status IN (:statuses)')
            ->setParameter('missionIds', $missionIds)
            ->setParameter('statuses', [
                FinancialCalculationStatus::CALCULATED,
                FinancialCalculationStatus::APPROVED,
                FinancialCalculationStatus::LOCKED,
            ])
            ->groupBy('fc.status')
            ->getQuery()
            ->getArrayResult();

        $byStatus = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof FinancialCalculationStatus ? $row['status']->value : $row['status'];
            $byStatus[$status] = (int) $row['cnt'];
        }
        return $byStatus;
    }

    /**
     * @param int[] $missionIds
     * Nombre de missions distinctes ayant un échec de calcul non résolu — réutilise
     * EncodingTrackingRepository::findMissionsWithFailedCalculation() (déjà utilisé par le
     * cockpit "Suivi des encodages") plutôt que de dupliquer la règle "résolu si un calcul
     * plus récent existe" (jamais un nouvel appel à calculate()/PricingRuleResolver ici,
     * uniquement l'historique déjà écrit par FinancialCalculationService::buildAndPersist()).
     */
    private function countMissionsWithCalculationFailure(array $missionIds): int
    {
        return count($this->encodingTrackingRepository->findMissionsWithFailedCalculation($missionIds));
    }

    /**
     * Compte les FinancialCalculationLine bénéficiaire=FIRM/$firm, calcul APPROVED/LOCKED,
     * dans la période — filtré soit sur une devise précise, soit sur "toute devise
     * différente de $excludeCurrency", et éventuellement restreint aux lignes déjà
     * rattachées à une facture (`onlyAssigned`).
     */
    private function countFirmLines(
        Firm $firm,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        ?string $currency,
        ?string $excludeCurrency,
        bool $onlyUnassigned,
        bool $onlyAssigned = false,
    ): int {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'fc')
            ->leftJoin('l.firmInvoiceLine', 'fil')
            ->where('l.beneficiaryType = :beneficiaryType')
            ->andWhere('l.beneficiaryFirm = :firm')
            ->andWhere('fc.status IN (:statuses)')
            ->andWhere('l.effectiveAt >= :start')
            ->andWhere('l.effectiveAt <= :end')
            ->setParameter('beneficiaryType', FinancialBeneficiaryType::FIRM)
            ->setParameter('firm', $firm)
            ->setParameter('statuses', [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED])
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd);

        if ($currency !== null) {
            $qb->andWhere('l.currency = :currency')->setParameter('currency', $currency);
        }
        if ($excludeCurrency !== null) {
            $qb->andWhere('l.currency != :excludeCurrency')->setParameter('excludeCurrency', $excludeCurrency);
        }
        if ($onlyUnassigned) {
            $qb->andWhere('fil.id IS NULL');
        }
        if ($onlyAssigned) {
            $qb->andWhere('fil.id IS NOT NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
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
