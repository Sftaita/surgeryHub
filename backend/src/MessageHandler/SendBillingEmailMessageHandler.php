<?php

namespace App\MessageHandler;

use App\Message\SendBillingEmailMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
final class SendBillingEmailMessageHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SendBillingEmailMessage $message): void
    {
        $htmlBody = $this->twig->render($message->htmlTemplate, $message->context);
        $textBody = trim(html_entity_decode(strip_tags($htmlBody)));

        $email = (new Email())
            ->from(new Address($message->fromAddress, $message->fromName))
            ->to($message->to)
            ->subject($message->subject)
            ->text($textBody)
            ->html($htmlBody);

        foreach ($message->cc as $ccAddress) {
            if ($ccAddress !== '') {
                $email->addCc($ccAddress);
            }
        }

        if ($message->attachmentBase64 !== null && $message->attachmentFilename !== null) {
            $email->attach(
                base64_decode($message->attachmentBase64),
                $message->attachmentFilename,
                'application/pdf'
            );
        }

        foreach ($message->extraAttachments as $extra) {
            if (isset($extra['base64'], $extra['filename'])) {
                $email->attach(base64_decode($extra['base64']), $extra['filename'], 'application/pdf');
            }
        }

        $this->mailer->send($email);

        // "Sent" here means "handed off" — not a delivery confirmation. When Mailer is
        // wired to Messenger (the case in this app), send() clones $email, dispatches a
        // *queued* MessageEvent against the clone, then queues the *original*, still-
        // unmodified $email for a later, separate MessageEvent + transport send (itself
        // against yet another clone) — this call returns long before that actually
        // happens, so $email is never mutated here regardless of what any MessageEvent
        // listener decided. For whether App\EventListener\MailSafeModeListener actually
        // blocked/stripped this specific email, its own "MAIL_SAFE_MODE: ..." log lines
        // are the only authoritative source — never this one.
        $this->logger->info('Billing email dispatched', [
            'to' => $message->to,
            'cc' => $message->cc,
            'subject' => $message->subject,
        ]);
    }
}
