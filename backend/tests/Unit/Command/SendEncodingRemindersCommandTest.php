<?php

namespace App\Tests\Unit\Command;

use App\Command\SendEncodingRemindersCommand;
use App\Entity\Mission;
use App\Service\EncodingReminderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D-083: cron-style command orchestrating the D+1 encoding reminder. Covers the
 * command's own responsibilities (08h gate, per-mission isolation, summary) — channel
 * selection and idempotence themselves are EncodingReminderServiceTest's job.
 */
class SendEncodingRemindersCommandTest extends TestCase
{
    private EncodingReminderService&MockObject $service;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->service = $this->createMock(EncodingReminderService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private static int $nextId = 1;

    private function makeMission(): Mission
    {
        $m = new Mission();
        $ref = new \ReflectionProperty($m, 'id');
        $ref->setAccessible(true);
        $ref->setValue($m, self::$nextId++);
        return $m;
    }

    /**
     * Defaults to a safe 10:00 Brussels wall-clock so tests unrelated to the 08h gate
     * itself never flake depending on when the suite actually runs.
     */
    private function tester(?\DateTimeImmutable $frozenNow = null): CommandTester
    {
        $frozenNow ??= $this->brussels('today 10:00');

        return new CommandTester(new class($this->service, $this->logger, $frozenNow) extends SendEncodingRemindersCommand {
            public function __construct(
                EncodingReminderService $service,
                LoggerInterface $logger,
                private readonly \DateTimeImmutable $frozenNow,
            ) {
                parent::__construct($service, $logger);
            }

            protected function now(): \DateTimeImmutable
            {
                return $this->frozenNow;
            }
        });
    }

    private function brussels(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Brussels'));
    }

    public function test_reports_correct_summary_counts(): void
    {
        $a = $this->makeMission();
        $b = $this->makeMission();
        $c = $this->makeMission();
        $d = $this->makeMission();

        $this->service->method('findEligibleMissions')->willReturn([$a, $b, $c, $d]);
        $outcomes = new \SplObjectStorage();
        $outcomes[$a] = 'push';
        $outcomes[$b] = 'email';
        $outcomes[$c] = 'skipped';
        $outcomes[$d] = 'push';
        $this->service->method('processMission')->willReturnCallback(
            static fn (Mission $m): string => $outcomes[$m],
        );

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('eligible=4', $display);
        $this->assertStringContainsString('push_sent=2', $display);
        $this->assertStringContainsString('email_sent=1', $display);
        $this->assertStringContainsString('skipped=1', $display);
        $this->assertStringContainsString('failed=0', $display);
    }

    public function test_an_exception_on_one_mission_does_not_stop_the_others(): void
    {
        $a = $this->makeMission();
        $b = $this->makeMission();
        $c = $this->makeMission();

        $this->service->method('findEligibleMissions')->willReturn([$a, $b, $c]);

        $processed = [];
        $this->service->method('processMission')->willReturnCallback(function (Mission $m) use (&$processed, $b): string {
            $processed[] = $m;
            if ($m === $b) {
                throw new \RuntimeException('boom');
            }
            return 'push';
        });

        $this->logger->expects($this->once())->method('error')->with(
            'encoding_reminder.failed',
            $this->callback(function (array $context) use ($b): bool {
                return $context['missionId'] === $b->getId() && $context['reason'] === 'boom';
            }),
        );

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(3, $processed, 'all three missions must be attempted despite the middle one failing');
        $display = $tester->getDisplay();
        $this->assertStringContainsString('eligible=3', $display);
        $this->assertStringContainsString('push_sent=2', $display);
        $this->assertStringContainsString('failed=1', $display);
    }

    public function test_failure_log_never_contains_push_or_email_secrets(): void
    {
        $a = $this->makeMission();
        $this->service->method('findEligibleMissions')->willReturn([$a]);
        $this->service->method('processMission')->willThrowException(new \RuntimeException('transport error'));

        $loggedContext = null;
        $this->logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$loggedContext): void {
            $loggedContext = $context;
        });

        $this->tester()->execute([]);

        $this->assertIsArray($loggedContext);
        $this->assertArrayNotHasKey('endpoint', $loggedContext);
        $this->assertArrayNotHasKey('p256dh', $loggedContext);
        $this->assertArrayNotHasKey('auth', $loggedContext);
        $this->assertSame(['missionId', 'reason'], array_keys($loggedContext));
    }

    public function test_does_nothing_before_eight_am_europe_brussels(): void
    {
        $this->service->expects($this->never())->method('findEligibleMissions');
        $this->service->expects($this->never())->method('processMission');

        $exitCode = $this->tester($this->brussels('today 07:59'))->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_runs_exactly_at_eight_am_europe_brussels(): void
    {
        $this->service->method('findEligibleMissions')->willReturn([]);

        $exitCode = $this->tester($this->brussels('today 08:00'))->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_runs_after_eight_am_in_summer_dst(): void
    {
        // CEST (UTC+2) — 15-08 is always within Belgian summer time.
        $this->service->method('findEligibleMissions')->willReturn([]);

        $tester = $this->tester($this->brussels('2026-08-15 08:30'));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('eligible=0', $tester->getDisplay());
    }

    public function test_runs_after_eight_am_in_winter_standard_time(): void
    {
        // CET (UTC+1) — 15-01 is always within Belgian winter time.
        $this->service->method('findEligibleMissions')->willReturn([]);

        $exitCode = $this->tester($this->brussels('2026-01-15 08:30'))->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_reports_success_with_zero_counts_when_no_mission_is_eligible(): void
    {
        $this->service->method('findEligibleMissions')->willReturn([]);
        $this->service->expects($this->never())->method('processMission');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('eligible=0', $tester->getDisplay());
    }
}
