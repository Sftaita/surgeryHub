<?php

namespace App\Service;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\FirmInvoice;
use App\Entity\InstrumentistStatement;
use App\Entity\User;
use App\Enum\ActivityEventType;
use App\Enum\DocumentRetentionClass;
use App\Notification\DocumentReference;
use App\Notification\DocumentStore;
use App\Notification\EmailContent;
use App\Notification\TransactionalEmailSender;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Category B (D-131) — the financial documents sent by email: firm invoice and instrumentist
 * statement. Deliberately NOT a user notification: external / explicit recipients (+ CC),
 * never subject to notification preferences, never an artificial in-app item.
 *
 * H5b (D-132) — closes the H5a gap. Sending a document is now:
 *   1. the business decision, in the journal: FIRM_INVOICE_SENT / _RESENT,
 *      INSTRUMENTIST_STATEMENT_SENT / _RESENT (who, which document, which recipient kind) —
 *      distinct from the issue itself (AuditEvent *_ISSUED, mirrored) and from the delivery;
 *   2. the frozen PDF (GeneratedDocument IMMUTABLE_EVIDENCE, source "firm_invoice:{id}" /
 *      "instrumentist_statement:{id}"): rendered once, at the first send; a resend sends
 *      these exact bytes again (same sha256), never a new rendering;
 *   3. the traced delivery on the common low-level layer (TransactionalEmailSender):
 *      OutboundNotification QUEUED → SENT / FAILED, attempts, correlationId, timeline events.
 * Both business event and delivery share the operation's correlation; the queued delivery
 * points to the document event as its cause.
 */
class BillingDocumentMailer
{
    public const TYPE_FIRM_INVOICE = 'FIRM_INVOICE_DOCUMENT';
    public const TYPE_STATEMENT    = 'INSTRUMENTIST_STATEMENT_DOCUMENT';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionalEmailSender $sender,
        private readonly DocumentStore $documents,
        private readonly PdfService $pdfService,
        private readonly ActivityJournal $journal,
    ) {
    }

    /** @param list<string> $emailCc */
    public function sendFirmInvoiceEmail(FirmInvoice $invoice, string $emailTo, array $emailCc, User $actor, bool $resend = false): void
    {
        $number   = (string) ($invoice->getNumber() ?? $invoice->getId());
        $firm     = $invoice->getFirm();
        $document = $this->frozenDocument('FIRM_INVOICE', sprintf('firm_invoice:%d', $invoice->getId()),
            sprintf('facture-%s-%s.pdf', strtolower(str_replace(' ', '-', (string) $firm?->getName())), $number),
            fn () => $this->pdfService->generateFromTemplate('pdf/firm_invoice.html.twig', ['invoice' => $invoice]));

        $this->send(
            $resend ? ActivityEventType::FIRM_INVOICE_RESENT : ActivityEventType::FIRM_INVOICE_SENT,
            'FirmInvoice', (int) $invoice->getId(), sprintf('Facture firme %s', $number),
            sprintf('Facture firme %s %s à %s (facturation) par %s', $number, $resend ? 'renvoyée' : 'envoyée', $firm?->getName() ?? '—', ActivityJournal::displayName($actor)),
            ['invoiceId' => $invoice->getId(), 'documentNumber' => $number, 'firmId' => $firm?->getId(), 'firmName' => $firm?->getName(), 'recipientKind' => 'FIRM_BILLING'],
            self::TYPE_FIRM_INVOICE, $emailTo, sprintf('%s — facturation', $firm?->getName() ?? 'Firme'), null, $emailCc,
            new EmailContent(sprintf('Facture %s — %s', $number, $firm?->getName() ?? ''), 'emails/firm_invoice_sent.html.twig', ['invoice' => $invoice], attachments: [$document]),
            $document, $actor,
        );
    }

    public function sendStatementEmail(InstrumentistStatement $statement, string $emailTo, User $actor, bool $resend = false): void
    {
        $period   = sprintf('%02d/%d', $statement->getPeriodMonth(), $statement->getPeriodYear());
        $person   = $statement->getInstrumentist();
        $name     = $statement->getInstrumentistNameSnapshot() ?? ActivityJournal::displayName($person) ?? '—';
        $document = $this->frozenDocument('INSTRUMENTIST_STATEMENT', sprintf('instrumentist_statement:%d', $statement->getId()),
            sprintf('decompte-%02d-%d.pdf', $statement->getPeriodMonth(), $statement->getPeriodYear()),
            fn () => $this->pdfService->generateFromTemplate('pdf/instrumentist_statement.html.twig', ['statement' => $statement]));
        // The instrumentist's own address: the delivery belongs to her; another address is external.
        $isOwnAddress = $person !== null && strcasecmp((string) $person->getEmail(), $emailTo) === 0;

        $this->send(
            $resend ? ActivityEventType::INSTRUMENTIST_STATEMENT_RESENT : ActivityEventType::INSTRUMENTIST_STATEMENT_SENT,
            'InstrumentistStatement', (int) $statement->getId(), sprintf('Décompte %s — %s', $period, $name),
            sprintf('Décompte %s de %s %s par %s', $period, $name, $resend ? 'renvoyé' : 'envoyé', ActivityJournal::displayName($actor)),
            ['statementId' => $statement->getId(), 'documentNumber' => $statement->getNumber(), 'userId' => $person?->getId(), 'userName' => $name,
                'recipientKind' => $isOwnAddress ? 'INSTRUMENTIST' : 'EXPLICIT_ADDRESS'],
            self::TYPE_STATEMENT, $emailTo, $isOwnAddress ? $name : sprintf('Décompte de %s — adresse explicite', $name), $isOwnAddress ? $person : null, [],
            new EmailContent(sprintf('Décompte %s — %s', $period, $statement->getInstrumentistNameSnapshot() ?? ''), 'emails/instrumentist_statement_sent.html.twig', ['statement' => $statement], attachments: [$document]),
            $document, $actor,
        );
    }

    /**
     * The document as first sent: reused for every later send (same bytes, same sha256).
     *
     * @param callable(): string $render
     */
    private function frozenDocument(string $type, string $sourceKey, string $filename, callable $render): DocumentReference
    {
        return $this->documents->latestFor($sourceKey)
            ?? $this->documents->storeOnce($type, $sourceKey, hash('sha256', $sourceKey), $filename, $render, DocumentRetentionClass::IMMUTABLE_EVIDENCE);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string>          $cc
     */
    private function send(
        ActivityEventType $type,
        string $subjectType,
        int $subjectId,
        string $label,
        string $summary,
        array $context,
        string $notificationType,
        string $to,
        string $recipientLabel,
        ?User $recipientUser,
        array $cc,
        EmailContent $email,
        DocumentReference $document,
        User $actor,
    ): void {
        $this->em->getConnection()->transactional(function () use ($type, $subjectType, $subjectId, $label, $summary, $context, $notificationType, $to, $recipientLabel, $recipientUser, $cc, $email, $document, $actor): void {
            $anchor = sprintf('%s:%d', strtolower($subjectType), $subjectId);
            $this->journal->record(new ActivityRecord(
                eventType: $type,
                subjectType: $subjectType,
                subjectId: $subjectId,
                subjectLabel: $label,
                summary: $summary,
                payload: $context + [
                    'entityId' => $subjectId, 'ccCount' => count($cc),
                    'generatedDocumentId' => $document->documentId, 'sha256' => $document->sha256, 'documentType' => $document->documentType,
                ],
                actor: $actor,
                userId: $recipientUser?->getId(),
                anchorKey: $anchor,
            ));

            $this->sender->queue($notificationType, $to, $recipientLabel, $email, $recipientUser, $cc, causeAnchors: [$anchor]);
        });
    }
}
