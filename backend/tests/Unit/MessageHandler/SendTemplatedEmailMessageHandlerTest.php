<?php

namespace App\Tests\Unit\MessageHandler;

use App\Message\SendTemplatedEmailMessage;
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

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
        $this->absenceCommunicationJournalService = $this->createMock(AbsenceCommunicationJournalService::class);
    }

    private function handler(): SendTemplatedEmailMessageHandler
    {
        return new SendTemplatedEmailMessageHandler($this->mailer, $this->twig, $this->logger, $this->outboundNotificationService, $this->absenceCommunicationJournalService);
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
}
