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

        // Read back $email's own To/Cc *after* send() — Mailer's MessageEvent listeners
        // (in particular App\EventListener\MailSafeModeListener) run synchronously inside
        // send() and may have stripped recipients or rejected the send outright before
        // this line. Logging $message->to/cc (the pre-filter intent) here would silently
        // claim delivery to someone who, in fact, never received anything.
        $actualTo = array_map(static fn ($a) => $a->getAddress(), $email->getTo());
        $actualCc = array_map(static fn ($a) => $a->getAddress(), $email->getCc());

        $this->logger->info(empty($actualTo) && empty($actualCc) ? 'Billing email blocked (no recipient left)' : 'Billing email sent', [
            'to' => $actualTo,
            'cc' => $actualCc,
            'subject' => $message->subject,
        ]);
    }
}
