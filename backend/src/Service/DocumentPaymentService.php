<?php

namespace App\Service;

use App\Dto\DocumentBalance;
use App\Entity\PayableDocument;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentDocumentType;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Exception\DocumentNotIssuedException;
use App\Exception\PaymentCurrencyMismatchException;
use App\Exception\PaymentExceedsRemainingException;
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
        return $this->em->getRepository(Payment::class)->findBy(
            ['documentType' => $document->getPaymentDocumentType(), 'documentId' => $document->getId()],
            ['paidAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * §7 du lot — grossAmount/paidAmount/remainingAmount toujours calculés, jamais
     * stockés en doublon.
     *
     * Compatibilité legacy : un document marqué PAID avant ce lot via l'ancien
     * FirmInvoiceService::markPaid()/InstrumentistStatementService::markPaid() (Lot 1)
     * n'a aucune ligne Payment (ce modèle n'existait pas encore) — traité comme
     * intégralement soldé pour l'affichage (paidAmount = grossAmount) sans qu'aucun
     * Payment ne soit reconstruit rétroactivement (§21 — pas de reconstruction
     * approximative).
     */
    public function computeBalance(PayableDocument $document): DocumentBalance
    {
        $gross = $document->getTotalAmount();
        $payments = $this->getPaymentsFor($document);
        $paid = $this->sumPayments($payments);

        if (count($payments) === 0 && $document->getStatus() === InvoiceStatus::PAID) {
            $paid = $gross;
        }

        $remaining = number_format(max(0.0, (float) $gross - (float) $paid), 2, '.', '');

        return new DocumentBalance($gross, $paid, $remaining, $this->resolveStatus($gross, $paid));
    }

    /**
     * §19 du lot — verrou pessimiste sur le document (aggregate root, même mécanisme que
     * les Lots 2-4) : deux enregistrements concurrents sur le MÊME document se
     * sérialisent, le second relit un solde à jour (refresh() sous verrou) avant de
     * revalider — un dépassement concurrent est structurellement impossible.
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

        $this->em->wrapInTransaction(function () use (&$result, $document, $amount, $currency, $paidAt, $method, $reference, $comment, $actor): void {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($document);

            if ($document->getStatus() !== InvoiceStatus::SENT) {
                throw new DocumentNotIssuedException();
            }
            if (strtoupper($currency) !== $document->getCurrency()) {
                throw new PaymentCurrencyMismatchException();
            }
            if ((float) $amount <= 0.0) {
                throw new PaymentExceedsRemainingException('Le montant du paiement doit être strictement positif.');
            }

            $before = $this->computeBalance($document);
            if ((float) $amount > (float) $before->remainingAmount + 0.0001) {
                throw new PaymentExceedsRemainingException(sprintf(
                    'Le paiement de %s %s dépasse le solde restant de %s %s.',
                    $amount, $currency, $before->remainingAmount, $document->getCurrency(),
                ));
            }

            $payment = new Payment();
            $payment->setDocumentType($document->getPaymentDocumentType());
            $payment->setDocumentId($document->getId());
            $payment->setAmount(number_format((float) $amount, 2, '.', ''));
            $payment->setCurrency(strtoupper($currency));
            $payment->setPaidAt($paidAt);
            $payment->setRecordedAt(new \DateTimeImmutable());
            $payment->setRecordedBy($actor);
            $payment->setReference($reference);
            $payment->setMethod($method);
            $payment->setComment($comment);
            $this->em->persist($payment);
            $this->em->flush();

            $after = $this->computeBalance($document);

            $payload = [
                'documentType' => $document->getPaymentDocumentType()->value,
                'documentId' => $document->getId(),
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

    private function resolveStatus(string $gross, string $paid): PaymentStatus
    {
        if ((float) $paid <= 0.0) {
            return PaymentStatus::UNPAID;
        }
        if ((float) $paid >= (float) $gross - 0.0001) {
            return PaymentStatus::PAID;
        }
        return PaymentStatus::PARTIALLY_PAID;
    }

    /** @param Payment[] $payments */
    private function sumPayments(array $payments): string
    {
        $total = '0.00';
        foreach ($payments as $payment) {
            $total = number_format((float) $total + (float) $payment->getAmount(), 2, '.', '');
        }
        return $total;
    }
}
