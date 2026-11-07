<?php

namespace App\MessageHandler;

use App\Message\SendTemplatedEmailMessage;
use App\Notification\DocumentReference;
use App\Notification\DocumentStore;
use App\Notification\HtmlToTextRenderer;
use App\Service\AbsenceCommunicationJournalService;
use App\Service\OutboundNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
final class SendTemplatedEmailMessageHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly AbsenceCommunicationJournalService $absenceCommunicationJournalService,
        private readonly DocumentStore $documentStore,
        private readonly HtmlToTextRenderer $textRenderer,
    ) {
    }

    public function __invoke(SendTemplatedEmailMessage $message): void
    {
        $htmlBody = $this->twig->render($message->htmlTemplate, $message->context);

        $textBody = $message->textTemplate !== null
            ? $this->twig->render($message->textTemplate, $message->context)
            // H5a (§20) — DOM-based, never strip_tags() (which leaked <style> CSS into text/plain).
            : $this->textRenderer->render($htmlBody);

        $email = (new Email())
            ->from(new Address($message->fromAddress, $message->fromName))
            ->to($message->to)
            ->subject($message->subject)
            ->text($textBody)
            ->html($htmlBody);

        // Communication des absences chirurgiens, Lot B (D-114) — gestion du bloc : To
        // unique + plusieurs CC (contrairement au flux collègues du Lot A, un email
        // individuel par destinataire). Même pattern que SendBillingEmailMessageHandler.
        if ($message->cc !== []) {
            $email->cc(...$message->cc);
        }
        // Jamais d'usurpation du From SMTP — l'expéditeur reste l'adresse SurgicalHub
        // configurée ; Reply-To permet seulement qu'une réponse du bloc atteigne
        // naturellement le chirurgien concerné.
        if ($message->replyTo !== null) {
            $email->replyTo($message->replyTo);
        }

        foreach ($message->attachments as $attachment) {
            if (isset($attachment['base64'], $attachment['filename'])) {
                $email->attach(base64_decode($attachment['base64']), $attachment['filename'], 'application/pdf');
            }
        }
        // H5a (§16) — a missing or altered document fails the send (retried, then FAILED with
        // a controlled reason): never an email silently without the PDF it announces.
        foreach ($message->documents as $reference) {
            $document = $this->documentStore->load(DocumentReference::fromArray($reference));
            $email->attach($document->getContent(), $document->getFilename(), $document->getContentType());
        }

        $this->mailer->send($email);

        // "Sent" here means "handed off" — not a delivery confirmation. See
        // SendBillingEmailMessageHandler for why: Mailer's Messenger integration clones
        // $email before any MessageEvent listener (e.g. App\EventListener\
        // MailSafeModeListener) ever sees it, and the real transport-level send happens
        // later, on yet another clone, well after this call returns — $email is never
        // mutated here regardless of what happened. That listener's own "MAIL_SAFE_MODE:
        // ..." log lines are the only authoritative source for whether this was actually
        // blocked/stripped.
        $this->logger->info('Email dispatched', [
            'to' => $message->to,
            'subject' => $message->subject,
            'htmlTemplate' => $message->htmlTemplate,
        ]);

        // D-084 — "handed off" is the honest signal available here (see comment above),
        // so this is what SENT means for the history: transport accepted it, not that it
        // was delivered/read. A later genuine failure (this send throwing on a retry, or
        // a bounce) is not tracked here — see OutboundNotificationEmailFailureListener.
        if ($message->outboundNotificationId !== null) {
            $this->outboundNotificationService->recordEmailAttempt(
                $message->outboundNotificationId,
                success: true,
                reason: null,
                bodyText: $message->storeRenderedBody ? $textBody : null,
                bodyHtml: $message->storeRenderedBody ? $htmlBody : null,
            );
        }

        // Communication des absences chirurgiens, Lot A (D-114) — même discipline honnête
        // que ci-dessus : la livraison ne passe à SENT qu'ici, jamais de manière optimiste
        // au moment du dispatch. Le chemin d'échec est couvert par
        // OutboundNotificationEmailFailureListener (une fois les retries épuisés).
        if ($message->absenceCommunicationDeliveryId !== null) {
            $this->absenceCommunicationJournalService->recordDeliverySuccess($message->absenceCommunicationDeliveryId);
        }
    }
}