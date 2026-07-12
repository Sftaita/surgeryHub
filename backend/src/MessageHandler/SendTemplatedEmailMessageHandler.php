<?php

namespace App\MessageHandler;

use App\Message\SendTemplatedEmailMessage;
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
    ) {
    }

    public function __invoke(SendTemplatedEmailMessage $message): void
    {
        $htmlBody = $this->twig->render($message->htmlTemplate, $message->context);

        $textBody = $message->textTemplate !== null
            ? $this->twig->render($message->textTemplate, $message->context)
            : trim(html_entity_decode(strip_tags($htmlBody)));

        $email = (new Email())
            ->from(new Address($message->fromAddress, $message->fromName))
            ->to($message->to)
            ->subject($message->subject)
            ->text($textBody)
            ->html($htmlBody);

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
    }
}