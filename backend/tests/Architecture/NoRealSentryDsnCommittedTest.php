<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Secrets guardrail (docs/production.md « Clé Sentry », 2026-10-17) — the backend Sentry DSN
 * of production was the one committed in `backend/.env`. Tracked env files only ever hold an
 * empty SENTRY_DSN: dev sets its own in `.env.local`, production in the server's
 * `/opt/stack/apps/surgicalhub/.env`. The frontend VITE_SENTRY_DSN is public by design (it
 * ships in the JavaScript bundle) and is not checked here.
 */
final class NoRealSentryDsnCommittedTest extends TestCase
{
    private const TRACKED_ENV_FILES = [
        'backend/.env',
        'backend/.env.dev',
        'backend/.env.test',
        'backend/.env.prod.local.example',
    ];

    public function test_tracked_env_files_never_contain_a_sentry_dsn(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;

        foreach (self::TRACKED_ENV_FILES as $relativePath) {
            $path = $root . '/' . $relativePath;
            if (!is_file($path)) {
                continue;
            }
            if (preg_match_all('/^\s*SENTRY_DSN\s*=(.*)$/m', (string) file_get_contents($path), $matches) > 0) {
                foreach ($matches[1] as $value) {
                    $checked++;
                    // assertTrue, not assertContains: a failure must never print the value.
                    self::assertTrue(
                        in_array(trim($value), ['', '""', "''"], true),
                        "{$relativePath} must keep SENTRY_DSN empty — the real DSN lives outside git.",
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'backend/.env declares SENTRY_DSN (empty)');
    }
}
