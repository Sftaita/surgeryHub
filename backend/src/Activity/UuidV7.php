<?php

namespace App\Activity;

/**
 * H1 (D-126) — RFC 9562 UUID version 7 (48-bit Unix ms timestamp + random), canonical
 * 36-char form. Time-ordered, so correlation ids sort roughly by creation time.
 *
 * Hand-rolled (a dozen lines) rather than adding symfony/uid for a single call site.
 */
final class UuidV7
{
    public static function generate(): string
    {
        $ms    = (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms); // 8 bytes big-endian — keep the low 6
        $bytes = substr($bytes, 2, 6) . random_bytes(10);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value);
    }
}
