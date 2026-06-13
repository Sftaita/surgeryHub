<?php

namespace App\Tests\Unit\Config;

use App\Message\PlanningDeployPdfsMessage;
use App\Message\SendBillingEmailMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Locks the Messenger transport routing configuration.
 *
 * REGRESSION D-043: PlanningDeployPdfsMessage and SendBillingEmailMessage were not routed
 * to the "async" transport. Symfony Messenger handled them synchronously in the HTTP request,
 * causing Dompdf PDF generation to exceed the 10-second axios timeout.
 *
 * Rule: every message whose handler does significant IO (PDF, SMTP, DB writes) MUST be
 * explicitly routed to the "async" transport in config/packages/messenger.yaml.
 *
 * If a test here fails, add the missing routing line:
 *   App\Message\YourMessage: async
 */
class MessengerRoutingTest extends TestCase
{
    private static array $routing = [];

    public static function setUpBeforeClass(): void
    {
        $configFile = dirname(__DIR__, 3) . '/config/packages/messenger.yaml';
        $yaml       = Yaml::parseFile($configFile);
        self::$routing = $yaml['framework']['messenger']['routing'] ?? [];
    }

    // ── PlanningDeployPdfsMessage ─────────────────────────────────────────────

    /**
     * REGRESSION D-043 — was missing, causing PDF generation to run synchronously
     * in the HTTP deploy request → 10-second timeout.
     */
    public function test_planning_deploy_pdfs_message_is_routed_to_async(): void
    {
        $this->assertArrayHasKey(
            PlanningDeployPdfsMessage::class,
            self::$routing,
            'PlanningDeployPdfsMessage has no transport routing. '
            . 'Without an explicit routing it runs synchronously in the HTTP request — '
            . 'PDF generation (Dompdf) will exceed the axios timeout.'
        );
        $this->assertSame(
            'async',
            self::$routing[PlanningDeployPdfsMessage::class],
            'PlanningDeployPdfsMessage must be routed to "async". '
            . 'It runs the PDF/email/notification work in the Messenger worker, not in the HTTP request.'
        );
    }

    // ── SendBillingEmailMessage ───────────────────────────────────────────────

    /**
     * REGRESSION D-043 — was missing, causing email sending (SMTP) to run synchronously
     * within the Messenger worker process; if the deploy handler itself had been sync,
     * this would have added SMTP latency to the HTTP request as well.
     */
    public function test_send_billing_email_message_is_routed_to_async(): void
    {
        $this->assertArrayHasKey(
            SendBillingEmailMessage::class,
            self::$routing,
            'SendBillingEmailMessage has no transport routing. '
            . 'SMTP sending is slow and must not block the HTTP request or the deploy handler.'
        );
        $this->assertSame(
            'async',
            self::$routing[SendBillingEmailMessage::class],
            'SendBillingEmailMessage must be routed to "async".'
        );
    }

    // ── Guard — future heavy messages ─────────────────────────────────────────

    /**
     * Sentinel: verifies that the "async" transport itself exists and points to a real DSN.
     * Prevents a misconfiguration where "async" is defined but falls back to "sync://".
     */
    public function test_async_transport_is_not_sync(): void
    {
        $configFile = dirname(__DIR__, 3) . '/config/packages/messenger.yaml';
        $yaml       = Yaml::parseFile($configFile);
        $transports = $yaml['framework']['messenger']['transports'] ?? [];

        $this->assertArrayHasKey('async', $transports,
            '"async" transport must be defined in messenger.yaml.'
        );

        $dsn = is_array($transports['async'])
            ? ($transports['async']['dsn'] ?? '')
            : (string) $transports['async'];

        $this->assertStringNotContainsString('sync://', $dsn,
            '"async" transport must not use sync:// DSN — that would defeat the purpose.'
        );
    }
}
