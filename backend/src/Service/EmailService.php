<?php

namespace App\Service;

use App\Message\SendTemplatedEmailMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

class EmailService
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function sendTemplatedEmail(
        string $to,
        string $subject,
        string $htmlTemplate,
        array $context = [],
        ?string $textTemplate = null,
    ): void {
        $this->bus->dispatch(new SendTemplatedEmailMessage(
            to: $to,
            subject: $subject,
            fromAddress: $this->fromAddress,
            fromName: $this->fromName,
            htmlTemplate: $htmlTemplate,
            context: $context,
            textTemplate: $textTemplate,
        ));
    }
}