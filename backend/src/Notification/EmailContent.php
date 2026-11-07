<?php

namespace App\Notification;

/** H2 (D-128) — content of the email channel; rendered by the async SendTemplatedEmailMessage handler. */
final class EmailContent
{
    /**
     * @param array<string, mixed>     $context
     * @param list<DocumentReference>  $attachments H5a (§16) — stored documents, by reference only
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $htmlTemplate,
        public readonly array $context,
        public readonly ?string $textTemplate = null,
        public readonly array $attachments = [],
    ) {
    }
}
