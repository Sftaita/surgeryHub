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

        $this->logger->info('Email sent', [
            'to' => $message->to,
            'subject' => $message->subject,
            'htmlTemplate' => $message->htmlTemplate,
        ]);
    }
}