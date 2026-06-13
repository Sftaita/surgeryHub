<?php

namespace App\Message;

final class SendBillingEmailMessage
{
    /**
     * @param string[] $cc
     * @param array<string, mixed> $context
     * @param array<array{base64: string, filename: string}> $extraAttachments Additional PDF attachments
     */
    public function __construct(
        public readonly string $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $htmlTemplate,
        public readonly array $context = [],
        /** Base64-encoded primary PDF binary */
        public readonly ?string $attachmentBase64 = null,
        public readonly ?string $attachmentFilename = null,
        /** Additional attachments: [{base64: string, filename: string}] */
        public readonly array $extraAttachments = [],
    ) {}
}
