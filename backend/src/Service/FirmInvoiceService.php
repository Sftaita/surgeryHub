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
use App\Enum\FirmBillingLineEventType;
use App\Enum\FirmBillingReason;
use App\Service\FirmBilling\FirmBillingLineEventRecorder;
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
use Doctrine\ORM\Query;

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
        private readonly FirmBillingLineEventRecorder $lineEvents,
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
        $this->lineEvents->recordForInvoice($invoice, FirmBillingLineEventType::INVOICE_SENT, $actor);

        $this->em->flush();
        return $invoice;
    }

    /**
     * D-123 — cycle de vie strict GENERATED → SENT → PAID : seule une facture ENVOYÉE peut
     * être marquée entièrement réglée. GENERATED (jamais envoyée), CANCELLED et PAID (déjà
     * réglée) sont refusés par un 409 métier explicite. Les paiements partiels (API
     * payments, D-075) restent un mécanisme distinct, jamais fusionné ici.
     */
    public function markPaid(FirmInvoice $invoice, ?User $actor = null): FirmInvoice
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
        $this->lineEvents->recordForInvoice($invoice, FirmBillingLineEventType::INVOICE_PAID, $actor);
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
    // ── D-135 — vrai brouillon de facture firme ──────────────────────────────
    //
    // DRAFT → (generateDraft) → GENERATED → SENT → PAID, ou DRAFT → (abandonDraft).
    // Un brouillon n'a PAS de numéro (attribué à la génération, aucun trou) et ne
    // verrouille AUCUN calcul (verrou posé à la génération) : une ligne peut donc devenir
    // obsolète si son calcul est recalculé/annulé entre-temps — signalée
    // (staleDraftLineIds()) et refusée à la génération. Une ligne n'appartient qu'à un
    // seul document à la fois (contrainte UNIQUE firm_invoice_line.financial_calculation_
    // line_id + contrôle par source métier). Chaque mouvement est journalisé dans
    // FirmBillingLineEvent (ADDED_TO_DRAFT / REMOVED_FROM_DRAFT / MOVED_TO_DRAFT).

    /** @param int[] $financialLineIds */
    public function createDraft(Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, array $financialLineIds, User $actor): FirmInvoice
    {
        $result = null;

        $this->em->wrapInTransaction(function () use (&$result, $firm, $currency, $periodStart, $periodEnd, $financialLineIds, $actor): void {
            $lines = $this->lockAndValidate($financialLineIds, $firm, $currency, $periodStart, $periodEnd, null);

            $draft = new FirmInvoice();
            $draft->setFirm($firm);
            $draft->setCurrency(strtoupper($currency));
            $draft->setPeriodStart($periodStart);
            $draft->setPeriodEnd($periodEnd);
            $draft->setStatus(InvoiceStatus::DRAFT);
            $draft->setLegacySource(false);
            $draft->setBillingEmailTo($firm->getBillingEmail());
            $draft->setBillingEmailCc($firm->getBillingEmailCc());
            $draft->setTotalAmount('0.00');
            $this->em->persist($draft);
            $this->em->flush(); // identifiant du brouillon connu avant journalisation

            $this->attachToDraft($draft, $lines, $actor, FirmBillingLineEventType::ADDED_TO_DRAFT);
            $this->em->flush();

            $result = $draft;
        });

        return $result;
    }

    /** @param int[] $financialLineIds */
    public function addLinesToDraft(FirmInvoice $draft, array $financialLineIds, User $actor): FirmInvoice
    {
        $this->em->wrapInTransaction(function () use ($draft, $financialLineIds, $actor): void {
            $this->lockDrafts([$draft]);
            $lines = $this->lockAndValidate($financialLineIds, $draft->getFirm(), $draft->getCurrency(), $draft->getPeriodStart(), $this->endOfDay($draft->getPeriodEnd()), $draft);
            $lines = array_values(array_filter($lines, fn (FinancialCalculationLine $l) => $this->currentDocumentFor($l)?->getId() !== $draft->getId()));

            $this->attachToDraft($draft, $lines, $actor, FirmBillingLineEventType::ADDED_TO_DRAFT);
            $this->em->flush();
        });

        return $draft;
    }

    public function removeLineFromDraft(FirmInvoice $draft, int $invoiceLineId, User $actor): FirmInvoice
    {
        $this->em->wrapInTransaction(function () use ($draft, $invoiceLineId, $actor): void {
            $this->lockDrafts([$draft]);
            $line = null;
            foreach ($draft->getLines() as $candidate) {
                if ($candidate->getId() === $invoiceLineId) {
                    $line = $candidate;
                }
            }
            if ($line === null) {
                throw new DocumentLineSelectionException([new DocumentLineSelectionAnomaly('DRAFT_LINE_NOT_FOUND', sprintf('La ligne #%d ne figure pas dans ce brouillon.', $invoiceLineId), ['invoiceLineId' => $invoiceLineId])]);
            }

            $this->lineEvents->recordForLine($line, $draft, FirmBillingLineEventType::REMOVED_FROM_DRAFT, $actor);
            $this->detachDraftLine($draft, $line);
            $this->em->flush();
            $this->refreshDraftTotal($draft);
            $this->em->flush();
        });

        return $draft;
    }

    /**
     * « Déplacer vers… » : chaque ligne quitte son brouillon d'origine et rejoint $target
     * dans la MÊME transaction (un seul événement MOVED_TO_DRAFT, origine en détails).
     * Une ligne libre est simplement ajoutée ; une ligne d'un document émis est refusée.
     *
     * @param int[] $financialLineIds
     */
    public function moveLinesToDraft(FirmInvoice $target, array $financialLineIds, User $actor): FirmInvoice
    {
        // Découverte des brouillons d'origine AVANT la transaction (aucun instantané figé),
        // puis revérification sous verrou : un brouillon généré/abandonné entre-temps est refusé.
        $sources = $this->draftSourcesFor($target, $financialLineIds);

        $this->em->wrapInTransaction(function () use ($target, $financialLineIds, $actor, $sources): void {
            $this->lockDrafts([$target, ...array_values($sources)]);
            $moves = $this->draftSourcesFor($target, $financialLineIds, withLines: true);
            foreach ($moves as ['from' => $from]) {
                if (!isset($sources[$from->getId()])) {
                    throw new InvoiceStatusTransitionException('Les brouillons ont changé pendant le déplacement : rechargez et recommencez.');
                }
            }

            // Retrait des brouillons d'origine (sans événement REMOVED : le déplacement est UN fait).
            $movedFrom = [];
            foreach ($moves as $fclId => ['line' => $fcl, 'from' => $from]) {
                foreach ($from->getLines()->toArray() as $invoiceLine) {
                    if ($invoiceLine->getFinancialCalculationLine()?->getId() === $fcl->getId()
                        || $this->sameSource($invoiceLine, $fcl)) {
                        $this->detachDraftLine($from, $invoiceLine);
                        $movedFrom[$fclId] = $from;
                    }
                }
            }
            $this->em->flush();
            foreach ($sources as $from) {
                $this->refreshDraftTotal($from);
            }

            $lines = $this->lockAndValidate($financialLineIds, $target->getFirm(), $target->getCurrency(), $target->getPeriodStart(), $this->endOfDay($target->getPeriodEnd()), $target);
            $lines = array_values(array_filter($lines, fn (FinancialCalculationLine $l) => $this->currentDocumentFor($l)?->getId() !== $target->getId()));

            foreach ($lines as $fcl) {
                $from = $movedFrom[$fcl->getId()] ?? null;
                $invoiceLine = $this->attachOne($target, $fcl);
                $this->em->flush();
                $this->lineEvents->recordForLine(
                    $invoiceLine,
                    $target,
                    $from !== null ? FirmBillingLineEventType::MOVED_TO_DRAFT : FirmBillingLineEventType::ADDED_TO_DRAFT,
                    $actor,
                    $from !== null ? ['fromInvoiceId' => $from->getId(), 'fromFirmName' => $from->getFirm()?->getName()] : null,
                );
            }
            $this->refreshDraftTotal($target);
            $this->em->flush();
        });

        return $target;
    }

    /**
     * Brouillon d'origine de chaque ligne à déplacer vers $target (lignes libres ignorées).
     * Une ligne d'un document émis est refusée.
     *
     * @param int[] $financialLineIds
     * @return array<int, mixed> par id de brouillon (FirmInvoice), ou par id de ligne ({line, from}) si $withLines
     */
    private function draftSourcesFor(FirmInvoice $target, array $financialLineIds, bool $withLines = false): array
    {
        $result = [];
        foreach (array_unique(array_map('intval', $financialLineIds)) as $id) {
            $fcl = $this->em->find(FinancialCalculationLine::class, $id);
            $from = $fcl !== null ? $this->currentDocumentFor($fcl) : null;
            if ($from === null || $from->getId() === $target->getId()) {
                continue;
            }
            if ($from->getStatus() !== InvoiceStatus::DRAFT) {
                throw new DocumentLineSelectionException([new DocumentLineSelectionAnomaly('FINANCIAL_LINE_ALREADY_ASSIGNED', sprintf('La ligne #%d appartient à une facture émise : elle ne peut plus être déplacée.', $id), ['financialCalculationLineId' => $id, 'invoiceId' => $from->getId()])]);
            }
            if ($withLines) {
                $result[$id] = ['line' => $fcl, 'from' => $from];
            } else {
                $result[$from->getId()] = $from;
            }
        }
        return $result;
    }

    /** DRAFT → GENERATED : numéro, verrouillage des calculs, revalidation complète sous verrou. */
    public function generateDraft(FirmInvoice $draft, User $actor): FirmInvoice
    {
        $this->em->wrapInTransaction(function () use ($draft, $actor): void {
            $this->lockDrafts([$draft]);
            if ($draft->getLines()->count() === 0) {
                throw new DocumentLineSelectionException([new DocumentLineSelectionAnomaly('DRAFT_EMPTY', 'Le brouillon ne contient aucune ligne.', ['invoiceId' => $draft->getId()])]);
            }

            $financialLineIds = [];
            $anomalies = [];
            foreach ($draft->getLines() as $invoiceLine) {
                $fcl = $invoiceLine->getFinancialCalculationLine();
                if ($fcl === null) {
                    $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_STALE', sprintf('La ligne #%d n\'a plus de ligne financière : retirez-la du brouillon.', $invoiceLine->getId()), ['invoiceLineId' => $invoiceLine->getId()]);
                    continue;
                }
                $financialLineIds[] = $fcl->getId();
            }
            ['lines' => $lines] = $this->lockAndReloadSelectedLines($financialLineIds);
            foreach ($lines as $fcl) {
                if (!in_array($fcl->getFinancialCalculation()->getStatus(), [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED], true)) {
                    $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_STALE', sprintf(
                        'Le calcul de la ligne #%d a été recalculé ou annulé depuis son ajout au brouillon : retirez-la puis ajoutez la ligne à jour.', $fcl->getId(),
                    ), ['financialCalculationLineId' => $fcl->getId()]);
                }
            }
            $anomalies = [...$anomalies, ...$this->validateFirmLineSelection($lines, $draft->getFirm(), $draft->getCurrency(), $draft->getPeriodStart(), $this->endOfDay($draft->getPeriodEnd()), $draft)];
            if ($anomalies !== []) {
                throw new DocumentLineSelectionException($anomalies);
            }

            $draft->setNumber($this->generateNumber($draft->getPeriodStart()));
            $draft->setStatus(InvoiceStatus::GENERATED);
            $draft->setGeneratedAt(new \DateTimeImmutable());
            $this->refreshDraftTotal($draft);

            $lockedCalculationIds = [];
            foreach ($lines as $fcl) {
                $calculation = $fcl->getFinancialCalculation();
                if ($calculation->getStatus() !== FinancialCalculationStatus::LOCKED) {
                    $this->financialCalculationService->lock($calculation, $actor);
                }
                $lockedCalculationIds[$calculation->getId()] = true;
            }
            $this->em->flush();

            $this->audit->recordGlobal($actor, AuditEventType::FIRM_INVOICE_CREATED_FROM_CALCULATION, [
                'firmInvoiceId' => $draft->getId(),
                'firmId' => $draft->getFirm()?->getId(),
                'currency' => $draft->getCurrency(),
                'periodStart' => $draft->getPeriodStart()?->format('Y-m-d'),
                'periodEnd' => $draft->getPeriodEnd()?->format('Y-m-d'),
                'financialCalculationLineIds' => $financialLineIds,
                'financialCalculationIds' => array_keys($lockedCalculationIds),
                'totalAmount' => $draft->getTotalAmount(),
                'fromDraft' => true,
            ]);
            $this->lineEvents->recordForInvoice($draft, FirmBillingLineEventType::INVOICE_GENERATED, $actor);
            $this->em->flush();
        });

        return $draft;
    }

    /** Abandon d'un brouillon : lignes libérées (REMOVED_FROM_DRAFT), document conservé ABANDONED (D-137), sans numéro. */
    public function abandonDraft(FirmInvoice $draft, User $actor, ?string $reason = null): FirmInvoice
    {
        $this->em->wrapInTransaction(function () use ($draft, $actor, $reason): void {
            $this->lockDrafts([$draft]);
            $releasedLineIds = [];
            foreach ($draft->getLines() as $line) {
                $this->lineEvents->recordForLine($line, $draft, FirmBillingLineEventType::REMOVED_FROM_DRAFT, $actor, ['draftAbandoned' => true, 'reason' => $reason]);
                $releasedLineIds[] = $line->getFinancialCalculationLine()?->getId();
            }
            foreach ($draft->getLines()->toArray() as $line) {
                $this->detachDraftLine($draft, $line);
            }
            $draft->setTotalAmount('0.00');
            $draft->setStatus(InvoiceStatus::ABANDONED);

            $this->audit->recordGlobal($actor, AuditEventType::FIRM_INVOICE_CANCELLED, [
                'firmInvoiceId' => $draft->getId(),
                'firmId' => $draft->getFirm()?->getId(),
                'reason' => $reason,
                'draftAbandoned' => true,
                'releasedFinancialCalculationLineIds' => array_values(array_filter($releasedLineIds)),
            ]);
            $this->em->flush();
        });

        return $draft;
    }

    /**
     * Lignes d'un brouillon devenues obsolètes : leur calcul n'est plus APPROVED/LOCKED
     * (recalculé → SUPERSEDED, annulé, ou repassé CALCULATED par un recalcul).
     *
     * @return array<int, true> ids de FirmInvoiceLine
     */
    public function staleDraftLineIds(FirmInvoice $draft): array
    {
        if ($draft->getStatus() !== InvoiceStatus::DRAFT) {
            return [];
        }
        $stale = [];
        foreach ($draft->getLines() as $line) {
            $status = $line->getFinancialCalculationLine()?->getFinancialCalculation()?->getStatus();
            if (!in_array($status, [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED], true)) {
                $stale[(int) $line->getId()] = true;
            }
        }
        return $stale;
    }

    /**
     * Requête fraîche (jamais l'association inverse, potentiellement périmée en mémoire).
     * Document STANDARD non annulé qui contient actuellement la ligne — par la ligne
     * financière elle-même, ou par sa source métier (une version antérieure de la même
     * intervention/du même matériel restée dans un brouillon).
     */
    public function currentDocumentFor(FinancialCalculationLine $line): ?FirmInvoice
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i')
            ->from(FirmInvoice::class, 'i')
            ->join('i.lines', 'fil')
            ->where('i.documentType = :standard')
            ->andWhere('i.status NOT IN (:inactive)')
            ->setParameter('standard', FinancialDocumentType::STANDARD)
            ->setParameter('inactive', [InvoiceStatus::CANCELLED, InvoiceStatus::ABANDONED])
            ->setMaxResults(1);

        $or = ['fil.financialCalculationLine = :line'];
        $qb->setParameter('line', $line);
        if ($line->getMaterialLine() !== null) {
            $or[] = 'fil.materialLine = :material';
            $qb->setParameter('material', $line->getMaterialLine());
        } elseif ($line->getMissionIntervention() !== null) {
            $or[] = 'fil.missionIntervention = :intervention';
            $qb->setParameter('intervention', $line->getMissionIntervention());
        }
        $qb->andWhere(implode(' OR ', $or));

        return $qb->getQuery()->getResult()[0] ?? null;
    }

    /** @param int[] $financialLineIds @return FinancialCalculationLine[] */
    private function lockAndValidate(array $financialLineIds, Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, ?FirmInvoice $ownDraft): array
    {
        if ($financialLineIds === []) {
            throw new DocumentLineSelectionException([new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_ELIGIBLE', 'Aucune ligne sélectionnée.', [])]);
        }
        ['lines' => $lines, 'missingIds' => $missingIds] = $this->lockAndReloadSelectedLines(array_map('intval', $financialLineIds));
        $anomalies = $this->validateFirmLineSelection($lines, $firm, $currency, $periodStart, $periodEnd, $ownDraft);
        foreach ($missingIds as $missingId) {
            $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_ELIGIBLE', sprintf('La ligne #%d est introuvable.', $missingId), ['financialCalculationLineId' => $missingId]);
        }
        if ($anomalies !== []) {
            throw new DocumentLineSelectionException($anomalies);
        }
        return $lines;
    }

    /** @param FinancialCalculationLine[] $lines */
    private function attachToDraft(FirmInvoice $draft, array $lines, User $actor, FirmBillingLineEventType $type): void
    {
        foreach ($lines as $fcl) {
            $invoiceLine = $this->attachOne($draft, $fcl);
            $this->em->flush();
            $this->lineEvents->recordForLine($invoiceLine, $draft, $type, $actor);
        }
        $this->refreshDraftTotal($draft);
    }

    private function attachOne(FirmInvoice $draft, FinancialCalculationLine $fcl): FirmInvoiceLine
    {
        $invoiceLine = $this->hydrateFromFinancialLine($fcl);
        $draft->addLine($invoiceLine);
        $this->em->persist($invoiceLine);
        return $invoiceLine;
    }

    /** Verrou pessimiste des brouillons (ordre d'id croissant) puis garde DRAFT relue sous verrou. @param FirmInvoice[] $drafts */
    private function lockDrafts(array $drafts): void
    {
        $ids = array_map(static fn (FirmInvoice $d) => (int) $d->getId(), $drafts);
        foreach ($this->lockFresh(FirmInvoice::class, $ids) as $d) {
            if ($d->getStatus() !== InvoiceStatus::DRAFT || $d->getDocumentType() !== FinancialDocumentType::STANDARD) {
                throw new InvoiceStatusTransitionException(sprintf(
                    'Le document #%d n\'est plus un brouillon (statut %s) : il ne se modifie plus comme un brouillon.', $d->getId(), $d->getStatus()->value,
                ));
            }
        }
    }

    /**
     * Revue PR #1 — verrou ET lecture de l'état COURANT en une requête :
     * `SELECT … FOR UPDATE` (lecture courante InnoDB) avec HINT_REFRESH pour écraser
     * l'entité déjà gérée. Jamais `lock()` puis `refresh()` : en REPEATABLE READ, le
     * `refresh()` relit l'instantané de la transaction, donc un recalcul ou une
     * génération validés pendant l'attente du verrou resteraient invisibles.
     * Ordre d'id croissant (pas d'interblocage entre deux appelants).
     *
     * @template T of object
     * @param class-string<T> $class
     * @param int[] $ids
     * @return T[]
     */
    private function lockFresh(string $class, array $ids, int $lockMode = LockMode::PESSIMISTIC_WRITE): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        sort($ids);

        return $this->em->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->setLockMode($lockMode)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    private function detachDraftLine(FirmInvoice $draft, FirmInvoiceLine $line): void
    {
        $line->getFinancialCalculationLine()?->releaseFirmInvoiceLine($line);
        $draft->getLines()->removeElement($line);
        $this->em->remove($line);
    }

    private function refreshDraftTotal(FirmInvoice $draft): void
    {
        $total = 0.0;
        foreach ($draft->getLines() as $line) {
            $total += (float) $line->getTotalAmount();
        }
        $draft->setTotalAmount(number_format(round($total, 2), 2, '.', ''));
    }

    private function sameSource(FirmInvoiceLine $invoiceLine, FinancialCalculationLine $fcl): bool
    {
        if ($fcl->getMaterialLine() !== null) {
            return $invoiceLine->getMaterialLine()?->getId() === $fcl->getMaterialLine()->getId();
        }
        return $fcl->getMissionIntervention() !== null && $invoiceLine->getMissionIntervention()?->getId() === $fcl->getMissionIntervention()->getId();
    }

    private function endOfDay(?\DateTimeImmutable $day): \DateTimeImmutable
    {
        return ($day ?? new \DateTimeImmutable())->setTime(23, 59, 59);
    }

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

        // D-134 — journalisé AVANT la suppression des lignes snapshot : la ligne redevient
        // libre, son passage par cette facture reste visible dans son historique.
        $this->lineEvents->recordForInvoice($invoice, FirmBillingLineEventType::INVOICE_CANCELLED, $actor, ['reason' => $reason]);

        $releasedLineIds = [];
        foreach ($invoice->getLines() as $line) {
            $financialLine = $line->getFinancialCalculationLine();
            if ($financialLine !== null) {
                $releasedLineIds[] = $financialLine->getId();
                // Côté inverse en mémoire resynchronisé (sinon un flush ultérieur dans le même
                // EntityManager voit une ligne supprimée encore référencée).
                $financialLine->releaseFirmInvoiceLine($line);
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
     * SQL fraîche dans validateFirmLineSelection()/currentDocumentFor(), jamais en
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
        $missionIds = [];
        foreach ($lines as $line) {
            $calculationIds[] = (int) $line->getFinancialCalculation()->getId();
            $missionIds[] = (int) $line->getFinancialCalculation()->getMission()?->getId();
        }
        // Même ordre que FinancialCalculationService::recalculate() (mission puis calcul) :
        // statut de mission courant (encodage rouvert) et statut de calcul courant
        // (SUPERSEDED / LOCKED validés par une autre transaction pendant l'attente).
        $this->lockFresh(Mission::class, $missionIds, LockMode::PESSIMISTIC_READ);
        $this->lockFresh(FinancialCalculation::class, $calculationIds);

        return ['lines' => $lines, 'missingIds' => $missingIds];
    }

    /**
     * @param FinancialCalculationLine[] $lines
     * @return DocumentLineSelectionAnomaly[]
     */
    private function validateFirmLineSelection(array $lines, Firm $firm, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, ?FirmInvoice $ownDraft = null): array
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
            // Revue PR #1 — mêmes règles que la worklist (D-133) : le backend refuse ce que
            // l'écran présente comme non facturable, même via un appel API direct.
            if ($line->getFinancialCalculation()->getMission()?->getStatus() !== MissionStatus::VALIDATED) {
                $anomalies[] = new DocumentLineSelectionAnomaly('MISSION_NOT_VALIDATED', sprintf(
                    'La ligne #%d appartient à une mission dont l\'encodage a été rouvert : elle doit être revalidée avant facturation.', $line->getId(),
                ), $context);
                continue;
            }
            $zero = FirmBillingReason::forZeroAmountLine($line);
            if ($zero !== null) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_BILLABLE', sprintf(
                    'La ligne #%d n\'est pas facturable : %s.', $line->getId(), mb_strtolower($zero[0]->label()),
                ), $context + ['reasonCode' => $zero[0]->value]);
                continue;
            }
            $document = $this->currentDocumentFor($line);
            if ($document !== null && $document->getId() !== $ownDraft?->getId()) {
                if ($document->getStatus() === InvoiceStatus::DRAFT) {
                    // D-135 — jamais d'ajout silencieux à un second brouillon : l'appelant doit
                    // la retirer du premier ou la déplacer explicitement (moveLinesToDraft()).
                    $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_IN_DRAFT', sprintf(
                        'La ligne #%d est déjà dans le brouillon %s #%d : retirez-la ou déplacez-la.',
                        $line->getId(), $document->getFirm()?->getName() ?? '', $document->getId(),
                    ), $context + ['draftId' => $document->getId()]);
                } else {
                    $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_ALREADY_ASSIGNED', sprintf('La ligne #%d est déjà rattachée à un document.', $line->getId()), $context + ['invoiceId' => $document->getId()]);
                }
            }
        }

        return $anomalies;
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
