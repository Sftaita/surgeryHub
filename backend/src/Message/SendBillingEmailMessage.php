<?php

namespace App\Message;

final class SendBillingEmailMessage
{
    /**
     * @param string[] $cc
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $htmlTemplate,
        public readonly array $context = [],
        /** Base64-encoded PDF binary */
        public readonly ?string $attachmentBase64 = null,
        public readonly ?string $attachmentFilename = null,
    ) {}
}
