<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Security lot (VAPID rotation, docs/decisions.md) guardrail — the dev VAPID private key
 * previously leaked into a committed `backend/.env`. These files must only ever contain
 * the `CHANGE_ME` placeholder for VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY; the real pair for
 * each environment lives outside git (`.env.local`, `.env.*.local`, or the prod server's
 * own `/opt/stack/apps/surgicalhub/.env`).
 *
 * `backend/.env.test` is deliberately NOT checked here — it holds a real (but strictly
 * test-only, never reused elsewhere) keypair, safe to commit by design; see its own
 * comment and WebPushServiceTest.php's anti-regression fingerprint check.
 */
final class NoRealVapidKeyCommittedTest extends TestCase
{
    private const PLACEHOLDER_ONLY_FILES = [
        'backend/.env',
        'backend/.env.dev',
        'backend/.env.prod.local.example',
    ];

    public function test_placeholder_only_env_files_never_contain_a_real_vapid_key(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::PLACEHOLDER_ONLY_FILES as $relativePath) {
            $path = $root . '/' . $relativePath;
            if (!is_file($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);

            foreach (['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'] as $key) {
                if (preg_match('/^' . $key . '=(.*)$/m', $content, $m) === 1) {
                    self::assertSame(
                        'CHANGE_ME',
                        trim($m[1]),
                        "{$relativePath} must only ever contain a CHANGE_ME placeholder for {$key} — never a real key.",
                    );
                }
            }
        }
    }
}
