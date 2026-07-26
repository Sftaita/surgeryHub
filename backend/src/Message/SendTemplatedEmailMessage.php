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
        /**
         * D-084 — id of the OutboundNotification row already persisted (QUEUED) before
         * this message was dispatched, so the handler/failure listener update the SAME
         * row across retries instead of creating a new one each time. Null for callers
         * predating D-084 (invitations, absences, billing emails) that don't track history yet.
         */
        public readonly ?int $outboundNotificationId = null,
    ) {
    }
}