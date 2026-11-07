<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\GeneratedDocument;
use App\Message\SendTemplatedEmailMessage;
use App\Notification\DocumentReference;
use App\Notification\DocumentStore;
use App\MessageHandler\SendTemplatedEmailMessageHandler;
use App\Service\AbsenceCommunicationJournalService;
use App\Service\OutboundNotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

/**
 * D-084 — SendTemplatedEmailMessageHandler's own responsibility for the outbound history:
 * on successful send, record ONE attempt (success) and backfill the true rendered
 * content. The failure path is NOT this handler's job — see
 * OutboundNotificationEmailFailureListenerTest (Messenger routes failures to a
 * WorkerMessageFailedEvent listener, not back through this handler's own try/catch).
 */
class SendTemplatedEmailMessageHandlerTest extends TestCase
{
    private MailerInterface&MockObject $mailer;
    private Environment&MockObject $twig;
    private LoggerInterface&MockObject $logger;
    private OutboundNotificationService&MockObject $outboundNotificationService;
    private AbsenceCommunicationJournalService&MockObject $absenceCommunicationJournalService;
    private DocumentStore&MockObject $documentStore;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
        $this->absenceCommunicationJournalService = $this->createMock(AbsenceCommunicationJournalService::class);
        $this->documentStore = $this->createMock(DocumentStore::class);
    }

    private function handler(): SendTemplatedEmailMessageHandler
    {
        return new SendTemplatedEmailMessageHandler($this->mailer, $this->twig, $this->logger, $this->outboundNotificationService, $this->absenceCommunicationJournalService, $this->documentStore, new \App\Notification\HtmlToTextRenderer());
    }

    public function test_records_success_attempt_with_rendered_body_when_notification_id_present(): void
    {
        $this->twig->method('render')->willReturn('<p>Bonjour Jane</p>');
        $this->mailer->expects($this->once())->method('send');

        // Positional, matching recordEmailAttempt(id, success, reason, final, bodyText, bodyHtml)
        // exactly — PHPUnit's with() doesn't support named-argument-style constraints.
        $this->outboundNotificationService->expects($this->once())
            ->method('recordEmailAttempt')
            ->with(777, true, null, true, 'Bonjour Jane', '<p>Bonjour Jane</p>');
        $this->absenceCommunicationJournalService->expects($this->never())->method('recordDeliverySuccess');

        $message = new SendTemplatedEmailMessage(
            to: 'jane@example.com',
            subject: 'Sujet',
            fromAddress: 'no-reply@surgicalhub.be',
            fromName: 'SurgicalHub',
            htmlTemplate: 'emails/mission_encoding_reminder.html.twig',
            context: ['firstname' => 'Jane'],
            outboundNotificationId: 777,
        );

        $this->handler()->__invoke($message);
    }

    public function test_does_not_touch_outbound_notification_service_when_id_is_null(): void
    {
        $this->twig->method('render')->willReturn('<p>x</p>');
        $this->mailer->method('send');

        $this->outboundNotificationService->expects($this->never())->method('recordEmailAttempt');
        $this->absenceCommunicationJournalService->expects($this->never())->method('recordDeliverySuccess');

        $message = new SendTemplatedEmailMessage(
            to: 'jane@example.com',
            subject: 'Sujet',
            fromAddress: 'no-reply@surgicalhub.be',
            fromName: 'SurgicalHub',
            htmlTemplate: 'emails/instrumentist_invitation.html.twig',
            context: [],
        );

        $this->handler()->__invoke($message);
    }

    /**
     * Communication des absences chirurgiens, Lot A (D-114) — indépendant de
     * $outboundNotificationId : un message ne porte jamais les deux à la fois en pratique,
     * mais les deux chemins sont mutuellement exclusifs dans le handler.
     */
    public function test_records_delivery_success_when_absence_communication_delivery_id_present(): void
    {
        $this->twig->method('render')->willReturn('<p>Libération de salle</p>');
        $this->mailer->expects($this->once())->method('send');

        $this->outboundNotificationService->expects($this->never())->method('recordEmailAttempt');
        $this->absenceCommunicationJournalService->expects($this->once())
            ->method('recordDeliverySuccess')
            ->with(42);

        $message = new SendTemplatedEmailMessage(
            to: 'colleague@example.com',
            subject: 'Libération de salle — Delta',
            fromAddress: 'no-reply@surgicalhub.be',
            fromName: 'SurgicalHub',
            htmlTemplate: 'emails/absence_room_release.html.twig',
            context: [],
            absenceCommunicationDeliveryId: 42,
        );

        $this->handler()->__invoke($message);
    }

    public function test_does_not_touch_absence_communication_journal_when_delivery_id_is_null(): void
    {
        $this->twig->method('render')->willReturn('<p>x</p>');
        $this->mailer->method('send');

        $this->absenceCommunicationJournalService->expects($this->never())->method('recordDeliverySuccess');

        $message = new SendTemplatedEmailMessage(
            to: 'jane@example.com',
            subject: 'Sujet',
            fromAddress: 'no-reply@surgicalhub.be',
            fromName: 'SurgicalHub',
            htmlTemplate: 'emails/instrumentist_invitation.html.twig',
            context: [],
        );

        $this->handler()->__invoke($message);
    }

    // ── H5a ──────────────────────────────────────────────────────────────────

    public function test_a_confidential_email_never_copies_its_rendered_body_into_the_history(): void
    {
        $this->twig->method('render')->willReturn('<a href="https://app/complete-account?token=SECRET">x</a>');
        $this->mailer->expects($this->once())->method('send');
        $this->outboundNotificationService->expects($this->once())
            ->method('recordEmailAttempt')
            ->with(42, true, null, true, null, null);

        $this->handler()->__invoke(new SendTemplatedEmailMessage(
            to: 'new@example.com', subject: 'Invitation', fromAddress: 'no-reply@surgicalhub.be', fromName: 'SurgicalHub',
            htmlTemplate: 'emails/instrumentist_invitation.html.twig', outboundNotificationId: 42, storeRenderedBody: false,
        ));
    }

    public function test_attaches_stored_documents_by_reference(): void
    {
        $this->twig->method('render')->willReturn('<p>x</p>');
        $document = new GeneratedDocument('PLANNING_INSTRUMENTIST', 'planning.pdf', '%PDF-1.4 bytes');
        (new \ReflectionProperty($document, 'id'))->setValue($document, 9);
        $this->documentStore->expects($this->once())->method('load')
            ->with($this->callback(static fn (DocumentReference $r) => $r->documentId === 9 && $r->sha256 === $document->getSha256()))
            ->willReturn($document);

        $sent = null;
        $this->mailer->expects($this->once())->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->handler()->__invoke(new SendTemplatedEmailMessage(
            to: 'jane@example.com', subject: 'Planning', fromAddress: 'no-reply@surgicalhub.be', fromName: 'SurgicalHub',
            htmlTemplate: 'emails/planning_instrumentist.html.twig', documents: [DocumentReference::of($document)->toArray()],
        ));

        self::assertCount(1, $sent->getAttachments());
        self::assertSame('planning.pdf', $sent->getAttachments()[0]->getFilename());
        self::assertSame('%PDF-1.4 bytes', $sent->getAttachments()[0]->getBody());
    }

    public function test_a_missing_document_fails_the_send_instead_of_mailing_without_it(): void
    {
        $this->twig->method('render')->willReturn('<p>x</p>');
        $this->documentStore->method('load')->willThrowException(new \RuntimeException('Document #9 introuvable.'));
        $this->mailer->expects($this->never())->method('send');
        $this->outboundNotificationService->expects($this->never())->method('recordEmailAttempt');

        $this->expectException(\RuntimeException::class);
        $this->handler()->__invoke(new SendTemplatedEmailMessage(
            to: 'jane@example.com', subject: 'Planning', fromAddress: 'no-reply@surgicalhub.be', fromName: 'SurgicalHub',
            htmlTemplate: 'emails/planning_instrumentist.html.twig', outboundNotificationId: 5,
            documents: [['documentId' => 9, 'documentType' => 'PLANNING_INSTRUMENTIST', 'filename' => 'p.pdf', 'sha256' => str_repeat('a', 64), 'byteSize' => 3]],
        ));
    }
}
