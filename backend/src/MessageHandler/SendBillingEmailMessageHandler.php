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

        $this->logger->info('Billing email sent', [
            'to' => $message->to,
            'cc' => $message->cc,
            'subject' => $message->subject,
        ]);
    }
}
