<?php

namespace App\Service;

use App\Dto\CorrectionLineAnomaly;
use App\Dto\CorrectionLineInput;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\InstrumentistStatement;
use App\Entity\InstrumentistStatementLine;
use App\Entity\Mission;
use App\Entity\Payment;
use App\Entity\PayableDocument;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\CorrectionReasonCode;
use App\Enum\FinancialDocumentType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentMethod;
use App\Enum\PricingRuleType;
use App\Enum\StatementLineType;
use App\Exception\CorrectionNotEligibleException;
use App\Exception\CorrectionValidationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — seul point d'entrée pour les
 * corrections financières additives (§20 du lot), commun à FirmInvoice et
 * InstrumentistStatement. N'écrit JAMAIS : un document/ligne/calcul déjà émis, un
 * Payment existant. Ne recalcule JAMAIS un tarif (aucun PricingRuleResolver/
 * InstrumentistRateResolver/résolution de durée/accès User.hourlyRate — §11/§34) — les
 * montants correctifs sont soit explicites (validés par le manager, référence + motif
 * obligatoires), soit repris tels quels d'une FinancialCalculationLine déjà valorisée
 * par le Lot 3 (jamais recalculée ici).
 *
 * §6 du lot — une correction est TOUJOURS rattachée directement au document STANDARD
 * racine (jamais une correction de correction) : simplifie l'audit et le calcul net.
 */
final class FinancialCorrectionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FirmInvoiceService $firmInvoiceService,
        private readonly InstrumentistStatementService $statementService,
        private readonly DocumentPaymentService $paymentService,
        private readonly AuditService $audit,
    ) {}

    /** @param CorrectionLineInput[] $lineInputs */
    public function createCreditNote(PayableDocument $root, array $lineInputs, ?string $comment, User $actor): PayableDocument
    {
        return $this->createCorrection($root, FinancialDocumentType::CREDIT_NOTE, $lineInputs, $comment, $actor);
    }

    /** @param CorrectionLineInput[] $lineInputs */
    public function createDebitNote(PayableDocument $root, array $lineInputs, ?string $comment, User $actor): PayableDocument
    {
        return $this->createCorrection($root, FinancialDocumentType::DEBIT_NOTE, $lineInputs, $comment, $actor);
    }

    /**
     * §17 du lot — un document correctif suit lui aussi GENERATED → SENT. Réutilise
     * FirmInvoiceService::issue()/InstrumentistStatementService::issue() (Lot 5) pour
     * la transition elle-même (numéro, date, audit *_ISSUED) plutôt que de la
     * dupliquer, et audite en plus FINANCIAL_CORRECTION_ISSUED (contexte propre à la
     * correction) + DOCUMENT_NET_BALANCE_CHANGED si le solde net du document racine
     * change réellement (une correction ne compte dans le calcul qu'une fois émise —
     * voir DocumentPaymentService::computeBalance()).
     */
    public function issueCorrection(PayableDocument $correction, User $actor): PayableDocument
    {
        $root = $correction->getCorrectsDocument();
        if ($root === null || !$correction->getDocumentType()->isCorrection()) {
            throw new \LogicException('issueCorrection() attend un document CREDIT_NOTE/DEBIT_NOTE rattaché à un document racine.');
        }

        $result = null;

        $this->em->wrapInTransaction(function () use (&$result, $correction, $root, $actor): void {
            $this->em->lock($root, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($root);

            $before = $this->paymentService->computeBalance($root);

            $issued = $correction instanceof FirmInvoice
                ? $this->firmInvoiceService->issue($correction, $actor)
                : $this->statementService->issue($correction, $actor);

            $after = $this->paymentService->computeBalance($root);

            $payload = [
                'rootDocumentType' => $root->getPaymentDocumentType()->value,
                'rootDocumentId' => $root->getId(),
                'correctionId' => $issued->getId(),
                'correctionType' => $issued->getDocumentType()->value,
                'number' => $issued->getNumber(),
            ];
            $this->audit->recordGlobal($actor, AuditEventType::FINANCIAL_CORRECTION_ISSUED, $payload);

            if ($before->netDocumentAmount !== $after->netDocumentAmount || $before->remainingAmount !== $after->remainingAmount) {
                $this->audit->recordGlobal($actor, AuditEventType::DOCUMENT_NET_BALANCE_CHANGED, $payload + [
                    'previousNetDocumentAmount' => $before->netDocumentAmount,
                    'newNetDocumentAmount' => $after->netDocumentAmount,
                    'previousRemainingAmount' => $before->remainingAmount,
                    'newRemainingAmount' => $after->remainingAmount,
                ]);
            }
            $this->em->flush();

            $result = $issued;
        });

        return $result;
    }

    /** §14/§15 du lot — délègue à DocumentPaymentService::recordRefund() (seul point d'écriture Payment, Lot 5) ; garde le contexte "correction" comme seul point d'entrée exposé aux contrôleurs (§20). */
    public function recordRefund(
        PayableDocument $root,
        string $amount,
        string $currency,
        \DateTimeImmutable $paidAt,
        PaymentMethod $method,
        ?string $reference,
        ?string $comment,
        User $actor,
    ): Payment {
        return $this->paymentService->recordRefund($root, $amount, $currency, $paidAt, $method, $reference, $comment, $actor);
    }

    // ── Construction interne ────────────────────────────────────────────────

    /** @param CorrectionLineInput[] $lineInputs */
    private function createCorrection(PayableDocument $root, FinancialDocumentType $type, array $lineInputs, ?string $comment, User $actor): PayableDocument
    {
        $result = null;

        $this->em->wrapInTransaction(function () use (&$result, $root, $type, $lineInputs, $comment, $actor): void {
            $this->em->lock($root, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($root);

            $this->assertRootEligible($root);

            if (count($lineInputs) === 0) {
                throw new CorrectionValidationException([
                    new CorrectionLineAnomaly('EMPTY_CORRECTION', 'Au moins une ligne corrective est requise.'),
                ]);
            }

            $anomalies = [];
            $lineSpecs = [];
            foreach ($lineInputs as $input) {
                $spec = $this->validateLineInput($root, $input, $anomalies);
                if ($spec !== null) {
                    $lineSpecs[] = $spec;
                }
            }

            if ($type === FinancialDocumentType::CREDIT_NOTE && count($anomalies) === 0) {
                $this->assertCreditLimits($root, $lineSpecs, $anomalies);
            }

            if (count($anomalies) > 0) {
                throw new CorrectionValidationException($anomalies);
            }

            $correction = $this->instantiateCorrection($root, $type);

            $total = '0.00';
            foreach ($lineSpecs as $spec) {
                $line = $this->hydrateLine($correction, $spec);
                $correction->addLine($line);
                $this->em->persist($line);
                $total = number_format((float) $total + (float) $spec['totalAmount'], 2, '.', '');
            }
            $correction->setTotalAmount($total);

            $this->em->persist($correction);
            $this->em->flush();

            $eventType = $type === FinancialDocumentType::CREDIT_NOTE
                ? AuditEventType::CREDIT_NOTE_CREATED
                : AuditEventType::DEBIT_NOTE_CREATED;

            $this->audit->recordGlobal($actor, $eventType, [
                'rootDocumentType' => $root->getPaymentDocumentType()->value,
                'rootDocumentId' => $root->getId(),
                'correctionId' => $correction->getId(),
                'total' => $total,
                'currency' => $root->getCurrency(),
                'lineCount' => count($lineSpecs),
                'reasons' => array_map(static fn (array $s) => $s['reasonCode']->value, $lineSpecs),
                'originalDocumentLineIds' => array_values(array_filter(array_map(
                    static fn (array $s) => $s['originalLine']?->getId(),
                    $lineSpecs,
                ))),
                'comment' => $comment,
                'legacy' => $root->isLegacySource(),
            ]);
            $this->em->flush();

            $result = $correction;
        });

        return $result;
    }

    /** §21 du lot. */
    private function assertRootEligible(PayableDocument $root): void
    {
        if ($root->getDocumentType() !== FinancialDocumentType::STANDARD) {
            throw new CorrectionNotEligibleException('Seul un document STANDARD racine peut recevoir une correction — jamais une correction de correction (§6 du lot).');
        }
        if ($root->getStatus() === InvoiceStatus::GENERATED) {
            throw new CorrectionNotEligibleException('Un document GENERATED doit être annulé (cancel()), jamais corrigé — voir §21 du lot.');
        }
        if (!in_array($root->getStatus(), [InvoiceStatus::SENT, InvoiceStatus::PAID], true)) {
            throw new CorrectionNotEligibleException(sprintf(
                'Ce document ne peut pas recevoir de correction dans son état actuel (%s).',
                $root->getStatus()->value,
            ));
        }
    }

    /**
     * @param CorrectionLineAnomaly[] &$anomalies
     * @return array{originalLine: FirmInvoiceLine|InstrumentistStatementLine|null, mission: Mission, reasonCode: CorrectionReasonCode, description: string, quantity: string, unitAmount: string, totalAmount: string, financialCalculationLine: FinancialCalculationLine|null}|null
     */
    private function validateLineInput(PayableDocument $root, CorrectionLineInput $input, array &$anomalies): ?array
    {
        if ($input->reasonCode === CorrectionReasonCode::OTHER && ($input->comment === null || trim($input->comment) === '')) {
            $anomalies[] = new CorrectionLineAnomaly('MISSING_OTHER_COMMENT', 'Un commentaire est requis lorsque le motif est OTHER.', []);
            return null;
        }
        if ((float) $input->quantity <= 0.0) {
            $anomalies[] = new CorrectionLineAnomaly('INVALID_QUANTITY', 'La quantité doit être strictement positive.', []);
            return null;
        }
        if ((float) $input->unitAmount <= 0.0) {
            $anomalies[] = new CorrectionLineAnomaly('INVALID_UNIT_AMOUNT', 'Le montant unitaire doit être strictement positif.', []);
            return null;
        }

        $lineClass = $root instanceof FirmInvoice ? FirmInvoiceLine::class : InstrumentistStatementLine::class;

        $originalLine = null;
        if ($input->originalDocumentLineId !== null) {
            $originalLine = $this->em->find($lineClass, $input->originalDocumentLineId);
            if ($originalLine === null) {
                $anomalies[] = new CorrectionLineAnomaly('ORIGINAL_LINE_NOT_FOUND', sprintf('Ligne d\'origine #%d introuvable.', $input->originalDocumentLineId), ['originalDocumentLineId' => $input->originalDocumentLineId]);
                return null;
            }
            $owner = $originalLine instanceof FirmInvoiceLine ? $originalLine->getInvoice() : $originalLine->getStatement();
            if ($owner?->getId() !== $root->getId()) {
                $anomalies[] = new CorrectionLineAnomaly('ORIGINAL_LINE_NOT_IN_ROOT', sprintf('La ligne #%d n\'appartient pas au document racine.', $input->originalDocumentLineId), ['originalDocumentLineId' => $input->originalDocumentLineId]);
                return null;
            }
        }

        $mission = null;
        if ($originalLine !== null) {
            $mission = $originalLine->getMission();
        } elseif ($input->missionId !== null) {
            $mission = $this->em->find(Mission::class, $input->missionId);
        }
        if ($mission === null) {
            $anomalies[] = new CorrectionLineAnomaly('MISSING_MISSION', 'Une ligne sans ligne d\'origine doit préciser missionId (§10 du lot).', []);
            return null;
        }

        $financialCalculationLine = null;
        if ($input->financialCalculationLineId !== null) {
            $financialCalculationLine = $this->em->find(FinancialCalculationLine::class, $input->financialCalculationLineId);
            if ($financialCalculationLine === null) {
                $anomalies[] = new CorrectionLineAnomaly('FINANCIAL_CALCULATION_LINE_NOT_FOUND', sprintf('FinancialCalculationLine #%d introuvable.', $input->financialCalculationLineId), []);
                return null;
            }
            if ($financialCalculationLine->isAssigned()) {
                $anomalies[] = new CorrectionLineAnomaly('FINANCIAL_CALCULATION_LINE_ALREADY_ASSIGNED', 'Cette ligne financière est déjà rattachée à un document — jamais réutilisée (§10/§34 du lot).', ['financialCalculationLineId' => $input->financialCalculationLineId]);
                return null;
            }
        }

        $quantity = number_format((float) $input->quantity, 4, '.', '');
        $unitAmount = number_format((float) $input->unitAmount, 2, '.', '');
        $totalAmount = number_format((float) $quantity * (float) $unitAmount, 2, '.', '');

        return [
            'originalLine' => $originalLine,
            'mission' => $mission,
            'reasonCode' => $input->reasonCode,
            'description' => $input->description,
            'quantity' => $quantity,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'financialCalculationLine' => $financialCalculationLine,
        ];
    }

    /**
     * §22 du lot — deux limites cumulées : (1) par ligne d'origine, la somme des
     * crédits déjà liés (GENERATED/SENT/PAID, jamais CANCELLED — un crédit encore en
     * brouillon compte déjà contre la limite pour empêcher un double-crédit créé sous
     * le même verrou avant qu'aucun des deux ne soit émis) ne peut dépasser le montant
     * original de cette ligne ; (2) pour le document entier, le montant net ne peut
     * jamais devenir négatif (recommandation §22 : netDocumentAmount >= 0).
     *
     * @param array<int, array{originalLine: FirmInvoiceLine|InstrumentistStatementLine|null, totalAmount: string}> $lineSpecs
     * @param CorrectionLineAnomaly[] &$anomalies
     */
    private function assertCreditLimits(PayableDocument $root, array $lineSpecs, array &$anomalies): void
    {
        $newCreditByLine = [];
        foreach ($lineSpecs as $spec) {
            if ($spec['originalLine'] !== null) {
                $id = $spec['originalLine']->getId();
                $newCreditByLine[$id] = ($newCreditByLine[$id] ?? 0.0) + (float) $spec['totalAmount'];
            }
        }

        if (count($newCreditByLine) > 0) {
            $existingCreditByLine = $this->sumExistingCreditsPerOriginalLine($root);
            foreach ($newCreditByLine as $lineId => $newCredit) {
                $originalLine = $this->em->find($root instanceof FirmInvoice ? FirmInvoiceLine::class : InstrumentistStatementLine::class, $lineId);
                $originalAmount = (float) $originalLine->getTotalAmount();
                $existingCredit = $existingCreditByLine[$lineId] ?? 0.0;
                if ($existingCredit + $newCredit > $originalAmount + 0.0001) {
                    $anomalies[] = new CorrectionLineAnomaly('CREDIT_EXCEEDS_ORIGINAL_LINE', sprintf(
                        'Les crédits cumulés sur la ligne #%d (%.2f) dépasseraient son montant d\'origine (%.2f).',
                        $lineId, $existingCredit + $newCredit, $originalAmount,
                    ), ['originalDocumentLineId' => $lineId]);
                }
            }
        }

        $newCreditTotal = array_sum(array_map(static fn (array $s) => (float) $s['totalAmount'], $lineSpecs));
        $balance = $this->paymentService->computeBalance($root);
        $projectedNet = (float) $balance->netDocumentAmount - $newCreditTotal;
        if ($projectedNet < -0.0001) {
            $anomalies[] = new CorrectionLineAnomaly('NET_AMOUNT_NEGATIVE', sprintf(
                'Cette note de crédit rendrait le document net négatif (%.2f) — recommandation §22 : netDocumentAmount >= 0.',
                $projectedNet,
            ), []);
        }
    }

    /** @return array<int, float> montant cumulé de crédit par id de ligne d'origine */
    private function sumExistingCreditsPerOriginalLine(PayableDocument $root): array
    {
        $lineClass = $root instanceof FirmInvoice ? FirmInvoiceLine::class : InstrumentistStatementLine::class;
        $documentField = $root instanceof FirmInvoice ? 'invoice' : 'statement';

        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.originalDocumentLine) as originalId', 'l.totalAmount as totalAmount')
            ->from($lineClass, 'l')
            ->join("l.{$documentField}", 'd')
            ->where('d.correctsDocument = :root')
            ->andWhere('d.documentType = :creditNote')
            ->andWhere('d.status != :cancelled')
            ->andWhere('l.originalDocumentLine IS NOT NULL')
            ->setParameter('root', $root)
            ->setParameter('creditNote', FinancialDocumentType::CREDIT_NOTE)
            ->setParameter('cancelled', InvoiceStatus::CANCELLED)
            ->getQuery()
            ->getArrayResult();

        $sums = [];
        foreach ($rows as $row) {
            $id = (int) $row['originalId'];
            $sums[$id] = ($sums[$id] ?? 0.0) + (float) $row['totalAmount'];
        }
        return $sums;
    }

    private function instantiateCorrection(PayableDocument $root, FinancialDocumentType $type): PayableDocument
    {
        if ($root instanceof FirmInvoice) {
            $correction = new FirmInvoice();
            $correction->setFirm($root->getFirm());
            $correction->setPeriodStart($root->getPeriodStart());
            $correction->setPeriodEnd($root->getPeriodEnd());
            $correction->setBillingEmailTo($root->getBillingEmailTo());
            $correction->setBillingEmailCc($root->getBillingEmailCc());
            $correction->setGeneratedAt(new \DateTimeImmutable());
        } elseif ($root instanceof InstrumentistStatement) {
            $correction = new InstrumentistStatement();
            $correction->setInstrumentist($root->getInstrumentist());
            $correction->setPeriodYear($root->getPeriodYear());
            $correction->setPeriodMonth($root->getPeriodMonth());
            $correction->setInstrumentistNameSnapshot($root->getInstrumentistNameSnapshot());
            $correction->setInstrumentistEmailSnapshot($root->getInstrumentistEmailSnapshot());
        } else {
            throw new \LogicException('Type de document racine non supporté : ' . get_class($root));
        }

        $correction->setDocumentType($type);
        $correction->setCorrectsDocument($root);
        $correction->setStatus(InvoiceStatus::GENERATED);
        $correction->setCurrency($root->getCurrency());
        $correction->setLegacySource(false);

        return $correction;
    }

    /** @param array{originalLine: FirmInvoiceLine|InstrumentistStatementLine|null, mission: Mission, reasonCode: CorrectionReasonCode, description: string, quantity: string, unitAmount: string, totalAmount: string, financialCalculationLine: FinancialCalculationLine|null} $spec */
    private function hydrateLine(PayableDocument $correction, array $spec): FirmInvoiceLine|InstrumentistStatementLine
    {
        if ($correction instanceof FirmInvoice) {
            /** @var FirmInvoiceLine|null $originalLine */
            $originalLine = $spec['originalLine'];

            $line = new FirmInvoiceLine();
            $line->setMission($spec['mission']);
            $line->setLineType($originalLine?->getLineType() ?? PricingRuleType::INTERVENTION_FEE);
            $line->setDescriptionSnapshot($spec['description']);
            $line->setFirmNameSnapshot($originalLine?->getFirmNameSnapshot() ?? $correction->getFirm()?->getName() ?? '');
            $line->setUnitPrice($spec['unitAmount']);
            $line->setQuantity($spec['quantity']);
            $line->setTotalAmount($spec['totalAmount']);
            $line->setCurrency($correction->getCurrency());
            $line->setUnitSnapshot($originalLine?->getUnitSnapshot());
            $line->setReasonCode($spec['reasonCode']);
            $line->setOriginalDocumentLine($originalLine);
            if ($spec['financialCalculationLine'] !== null) {
                $line->setFinancialCalculationLine($spec['financialCalculationLine']);
            }
            return $line;
        }

        /** @var InstrumentistStatementLine|null $originalLine */
        $originalLine = $spec['originalLine'];
        $mission = $spec['mission'];

        $line = new InstrumentistStatementLine();
        $line->setMission($mission);
        $line->setLineType($originalLine?->getLineType() ?? StatementLineType::BLOC);
        $line->setDescriptionSnapshot($spec['description']);
        $line->setRateSnapshot($spec['unitAmount']);
        $line->setQuantity($spec['quantity']);
        $line->setTotalAmount($spec['totalAmount']);
        $line->setCurrency($correction->getCurrency());
        $line->setUnitSnapshot($originalLine?->getUnitSnapshot());
        $line->setReasonCode($spec['reasonCode']);
        $line->setOriginalDocumentLine($originalLine);
        $line->setSurgeonNameSnapshot($mission->getSurgeon() ? $this->displayName($mission->getSurgeon()) : null);
        $line->setSiteNameSnapshot($mission->getSite()?->getName());
        $line->setMissionDateSnapshot(new \DateTimeImmutable($mission->getStartAt()->format('Y-m-d')));
        if ($spec['financialCalculationLine'] !== null) {
            $line->setFinancialCalculationLine($spec['financialCalculationLine']);
        }
        return $line;
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : $user->getEmail();
    }
}
