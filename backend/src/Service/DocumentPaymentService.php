<?php

namespace App\Service;

use App\Dto\DocumentBalance;
use App\Entity\PayableDocument;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialDocumentType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentDirection;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Exception\DocumentNotIssuedException;
use App\Exception\PaymentCurrencyMismatchException;
use App\Exception\PaymentExceedsRemainingException;
use App\Exception\RefundExceedsOverpaidException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — seul point d'écriture pour les
 * paiements, commun à FirmInvoice et InstrumentistStatement (§18 du lot). Les
 * paiements sont des événements append-only (§6/D-075) : jamais de modification, jamais
 * de suppression, jamais de recalcul de FinancialCalculation/FinancialCalculationLine
 * ni des montants documentaires (FirmInvoice.totalAmount/InstrumentistStatement.
 * totalAmount restent gelés). Le solde est toujours dérivé en sommant les Payment
 * existants (computeBalance()), jamais stocké.
 *
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — §17/§18 du lot : politique retenue —
 * les paiements ET les remboursements sont TOUJOURS rattachés au document STANDARD
 * racine, jamais à un document correctif (resolveRoot() le garantit même si l'appelant
 * passe une note de crédit/débit par erreur). computeBalance() devient corrections-
 * aware : seules les notes de crédit/débit ISSUED (SENT/PAID, jamais GENERATED ni
 * CANCELLED) entrent dans le calcul du montant net — une correction encore modifiable
 * ne doit jamais influencer silencieusement ce qui est dû.
 */
final class DocumentPaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
    ) {}

    /** @return Payment[] */
    public function getPaymentsFor(PayableDocument $document): array
    {
        $root = $this->resolveRoot($document);

        return $this->em->getRepository(Payment::class)->findBy(
            ['documentType' => $root->getPaymentDocumentType(), 'documentId' => $root->getId()],
            ['paidAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * §7/§12 du lot — toutes les valeurs sont calculées, jamais stockées en doublon.
     * Formules détaillées dans le docblock de DocumentBalance.
     *
     * Compatibilité legacy (Lot 5) : un document marqué PAID avant le Lot 5 via
     * l'ancien markPaid() (Lot 1) n'a aucune ligne Payment — traité comme
     * intégralement soldé pour l'affichage (paidAmount = grossAmount) sans qu'aucun
     * Payment ne soit reconstruit rétroactivement.
     */
    public function computeBalance(PayableDocument $document): DocumentBalance
    {
        $root = $this->resolveRoot($document);

        $gross = $root->getTotalAmount();
        $payments = $this->getPaymentsFor($root);

        $paid = $this->sumAmounts(array_filter($payments, static fn (Payment $p) => $p->getDirection() === PaymentDirection::INBOUND));
        $refunded = $this->sumAmounts(array_filter($payments, static fn (Payment $p) => $p->getDirection() === PaymentDirection::OUTBOUND));

        if (count($payments) === 0 && $root->getStatus() === InvoiceStatus::PAID) {
            $paid = $gross;
        }

        [$creditTotal, $debitTotal] = $this->sumIssuedCorrections($root);

        $net = number_format((float) $gross - (float) $creditTotal + (float) $debitTotal, 2, '.', '');
        $remaining = number_format(max(0.0, (float) $net - (float) $paid + (float) $refunded), 2, '.', '');
        $overpaid = number_format(max(0.0, (float) $paid - (float) $refunded - (float) $net), 2, '.', '');

        return new DocumentBalance($gross, $creditTotal, $debitTotal, $net, $paid, $refunded, $remaining, $overpaid, $this->resolveStatus($net, $paid, $refunded));
    }

    /**
     * §19 du lot — verrou pessimiste sur le document RACINE (aggregate root, même
     * mécanisme que les Lots 2-4) : deux enregistrements concurrents sur le MÊME
     * document se sérialisent, le second relit un solde à jour (refresh() sous verrou)
     * avant de revalider — un dépassement concurrent est structurellement impossible.
     */
    public function recordPayment(
        PayableDocument $document,
        string $amount,
        string $currency,
        \DateTimeImmutable $paidAt,
        PaymentMethod $method,
        ?string $reference,
        ?string $comment,
        User $actor,
    ): Payment {
        $result = null;
        $root = $this->resolveRoot($document);

        $this->em->wrapInTransaction(function () use (&$result, $root, $amount, $currency, $paidAt, $method, $reference, $comment, $actor): void {
            $this->em->lock($root, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($root);

            if ($root->getStatus() !== InvoiceStatus::SENT) {
                throw new DocumentNotIssuedException();
            }
            if (strtoupper($currency) !== $root->getCurrency()) {
                throw new PaymentCurrencyMismatchException();
            }
            if ((float) $amount <= 0.0) {
                throw new PaymentExceedsRemainingException('Le montant du paiement doit être strictement positif.');
            }

            $before = $this->computeBalance($root);
            if ((float) $amount > (float) $before->remainingAmount + 0.0001) {
                throw new PaymentExceedsRemainingException(sprintf(
                    'Le paiement de %s %s dépasse le solde restant de %s %s.',
                    $amount, $currency, $before->remainingAmount, $root->getCurrency(),
                ));
            }

            $payment = $this->buildPayment($root, PaymentDirection::INBOUND, $amount, $currency, $paidAt, $method, $reference, $comment, $actor);
            $this->em->persist($payment);
            $this->em->flush();

            $after = $this->computeBalance($root);

            $payload = [
                'documentType' => $root->getPaymentDocumentType()->value,
                'documentId' => $root->getId(),
                'paymentId' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'reference' => $reference,
                'method' => $method->value,
                'previousPaymentStatus' => $before->status->value,
                'newPaymentStatus' => $after->status->value,
            ];
            $this->audit->recordGlobal($actor, AuditEventType::DOCUMENT_PAYMENT_RECORDED, $payload);

            if ($after->status === PaymentStatus::PAID) {
                $this->audit->recordGlobal($actor, AuditEventType::DOCUMENT_FULLY_PAID, $payload);
            } elseif ($after->status === PaymentStatus::PARTIALLY_PAID) {
                $this->audit->recordGlobal($actor, AuditEventType::DOCUMENT_PARTIALLY_PAID, $payload);
            }
            $this->em->flush();

            $result = $payment;
        });

        return $result;
    }

    /**
     * EPIC Exécution & Valorisation, Lot 6 (D-076) — §14/§15 du lot : un remboursement
     * est un nouveau mouvement append-only (jamais une modification/suppression d'un
     * Payment existant, §13/§34). Refusé au-delà du trop-perçu réel
     * (DocumentBalance::$overpaidAmount, recalculé sous verrou juste avant
     * d'accepter — même garde-fou anti-dépassement que recordPayment()).
     */
    public function recordRefund(
        PayableDocument $document,
        string $amount,
        string $currency,
        \DateTimeImmutable $paidAt,
        PaymentMethod $method,
        ?string $reference,
        ?string $comment,
        User $actor,
    ): Payment {
        $result = null;
        $root = $this->resolveRoot($document);

        $this->em->wrapInTransaction(function () use (&$result, $root, $amount, $currency, $paidAt, $method, $reference, $comment, $actor): void {
            $this->em->lock($root, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($root);

            if (strtoupper($currency) !== $root->getCurrency()) {
                throw new PaymentCurrencyMismatchException();
            }
            if ((float) $amount <= 0.0) {
                throw new RefundExceedsOverpaidException('Le montant du remboursement doit être strictement positif.');
            }

            $before = $this->computeBalance($root);
            if ((float) $amount > (float) $before->overpaidAmount + 0.0001) {
                throw new RefundExceedsOverpaidException(sprintf(
                    'Le remboursement de %s %s dépasse le trop-perçu de %s %s.',
                    $amount, $currency, $before->overpaidAmount, $root->getCurrency(),
                ));
            }

            $payment = $this->buildPayment($root, PaymentDirection::OUTBOUND, $amount, $currency, $paidAt, $method, $reference, $comment, $actor);
            $this->em->persist($payment);
            $this->em->flush();

            $after = $this->computeBalance($root);

            $this->audit->recordGlobal($actor, AuditEventType::REFUND_RECORDED, [
                'documentType' => $root->getPaymentDocumentType()->value,
                'documentId' => $root->getId(),
                'paymentId' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'currency' => $payment->getCurrency(),
                'reference' => $reference,
                'method' => $method->value,
                'previousRemainingAmount' => $before->remainingAmount,
                'newRemainingAmount' => $after->remainingAmount,
                'previousOverpaidAmount' => $before->overpaidAmount,
                'newOverpaidAmount' => $after->overpaidAmount,
            ]);
            $this->em->flush();

            $result = $payment;
        });

        return $result;
    }

    /** §17/§18 du lot — les paiements/remboursements sont toujours rattachés à la racine, jamais à une correction. */
    private function resolveRoot(PayableDocument $document): PayableDocument
    {
        return $document->getCorrectsDocument() ?? $document;
    }

    private function buildPayment(
        PayableDocument $root,
        PaymentDirection $direction,
        string $amount,
        string $currency,
        \DateTimeImmutable $paidAt,
        PaymentMethod $method,
        ?string $reference,
        ?string $comment,
        User $actor,
    ): Payment {
        $payment = new Payment();
        $payment->setDocumentType($root->getPaymentDocumentType());
        $payment->setDocumentId($root->getId());
        $payment->setDirection($direction);
        $payment->setAmount(number_format((float) $amount, 2, '.', ''));
        $payment->setCurrency(strtoupper($currency));
        $payment->setPaidAt($paidAt);
        $payment->setRecordedAt(new \DateTimeImmutable());
        $payment->setRecordedBy($actor);
        $payment->setReference($reference);
        $payment->setMethod($method);
        $payment->setComment($comment);
        return $payment;
    }

    /**
     * §17/§18 — seules les corrections ISSUED (SENT/PAID) comptent dans le solde ;
     * une correction encore GENERATED reste modifiable/annulable et ne doit jamais
     * influencer silencieusement le montant dû. CANCELLED exclue explicitement.
     *
     * @return array{0: string, 1: string} [creditTotal, debitTotal]
     */
    private function sumIssuedCorrections(PayableDocument $root): array
    {
        $corrections = $this->em->getRepository(get_class($root))->findBy([
            'correctsDocument' => $root,
            'documentType' => [FinancialDocumentType::CREDIT_NOTE, FinancialDocumentType::DEBIT_NOTE],
            'status' => [InvoiceStatus::SENT, InvoiceStatus::PAID],
        ]);

        $creditTotal = '0.00';
        $debitTotal = '0.00';
        foreach ($corrections as $correction) {
            $lineSum = $this->sumLines($correction->getLines());
            if ($correction->getDocumentType() === FinancialDocumentType::CREDIT_NOTE) {
                $creditTotal = number_format((float) $creditTotal + (float) $lineSum, 2, '.', '');
            } else {
                $debitTotal = number_format((float) $debitTotal + (float) $lineSum, 2, '.', '');
            }
        }

        return [$creditTotal, $debitTotal];
    }

    private function sumLines(iterable $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $total = number_format((float) $total + (float) $line->getTotalAmount(), 2, '.', '');
        }
        return $total;
    }

    private function resolveStatus(string $net, string $paid, string $refunded): PaymentStatus
    {
        if ((float) $net <= 0.0) {
            return PaymentStatus::PAID;
        }
        $effectivePaid = (float) $paid - (float) $refunded;
        if ($effectivePaid <= 0.0) {
            return PaymentStatus::UNPAID;
        }
        if ($effectivePaid >= (float) $net - 0.0001) {
            return PaymentStatus::PAID;
        }
        return PaymentStatus::PARTIALLY_PAID;
    }

    /** @param Payment[] $payments */
    private function sumAmounts(iterable $payments): string
    {
        $total = '0.00';
        foreach ($payments as $payment) {
            $total = number_format((float) $total + (float) $payment->getAmount(), 2, '.', '');
        }
        return $total;
    }
}
