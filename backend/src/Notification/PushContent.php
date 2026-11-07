<?php

namespace App\Notification;

/** H2 (D-128) — content of the push channel. $data never carries patient data (url, ids, type). */
final class PushContent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {
    }
}
