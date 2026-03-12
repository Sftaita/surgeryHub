<?php

namespace App\Message;

final class SendTemplatedEmailMessage
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $htmlTemplate,
        public readonly array $context = [],
        public readonly ?string $textTemplate = null,
    ) {
    }
}