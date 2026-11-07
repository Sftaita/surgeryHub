<?php

namespace App\Tests\Unit\Command;

use App\Command\SendEncodingRemindersCommand;
use App\Entity\Mission;
use App\Enum\EncodingReminderStage;
use App\Service\EncodingReminderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * D-143 — commande cron de la relance progressive J+1/J+2/J+4. Couvre ses seules
 * responsabilités (garde 09 h Europe/Brussels, isolation par mission, résumé) ; le
 * calendrier, les canaux et l'idempotence sont prouvés sur base réelle par
 * EncodingReminderScheduleIntegrationTest.
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
        $ref->setValue($m, self::$nextId++);
        return $m;
    }

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

        $this->service->method('findDueReminders')->willReturn([
            ['mission' => $a, 'stage' => EncodingReminderStage::D1],
            ['mission' => $b, 'stage' => EncodingReminderStage::D2],
            ['mission' => $c, 'stage' => EncodingReminderStage::D4],
        ]);
        $this->service->method('processReminder')->willReturnCallback(
            fn (Mission $m) => $m === $b ? 'skipped' : 'sent',
        );

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('due=3 sent=2 skipped=1 failed=0', $tester->getDisplay());
    }

    public function test_an_exception_on_one_mission_does_not_stop_the_others(): void
    {
        $a = $this->makeMission();
        $b = $this->makeMission();

        $this->service->method('findDueReminders')->willReturn([
            ['mission' => $a, 'stage' => EncodingReminderStage::D1],
            ['mission' => $b, 'stage' => EncodingReminderStage::D1],
        ]);
        $this->service->method('processReminder')->willReturnCallback(
            function (Mission $m) use ($a): string {
                if ($m === $a) {
                    throw new \RuntimeException('smtp://user:secret@host failure');
                }
                return 'sent';
            },
        );

        $logged = [];
        $this->logger->method('error')->willReturnCallback(function (string $msg, array $ctx) use (&$logged): void {
            $logged[] = $ctx;
        });

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('due=2 sent=1 skipped=0 failed=1', $tester->getDisplay());
        self::assertCount(1, $logged);
        self::assertSame('RuntimeException', $logged[0]['error'], "seule la classe de l'erreur est journalisée, jamais son message (secrets, adresses)");
        self::assertSame('D1', $logged[0]['stage']);
    }

    public function test_does_nothing_before_nine_am_europe_brussels(): void
    {
        $this->service->expects(self::never())->method('findDueReminders');

        $tester = $this->tester($this->brussels('2026-07-26 08:59'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Trop tôt', $tester->getDisplay());
    }

    public function test_runs_exactly_at_nine_am_europe_brussels(): void
    {
        $this->service->expects(self::once())->method('findDueReminders')->willReturn([]);

        self::assertSame(Command::SUCCESS, $this->tester($this->brussels('2026-07-26 09:00'))->execute([]));
    }

    /** Un cron réglé en UTC : 07:30 UTC en été = 09:30 à Bruxelles → envoi ; en hiver = 08:30 → rien. */
    public function test_guard_uses_brussels_time_even_when_now_is_utc(): void
    {
        $this->service->expects(self::once())->method('findDueReminders')->willReturn([]);
        $this->tester(new \DateTimeImmutable('2026-07-26 07:30', new \DateTimeZone('UTC')))->execute([]);

        $winter = $this->createMock(EncodingReminderService::class);
        $winter->expects(self::never())->method('findDueReminders');
        $this->service = $winter;
        $tester = $this->tester(new \DateTimeImmutable('2026-12-14 07:30', new \DateTimeZone('UTC')));
        $tester->execute([]);
        self::assertStringContainsString('Trop tôt', $tester->getDisplay());
    }

    public function test_reports_success_with_zero_counts_when_nothing_is_due(): void
    {
        $this->service->method('findDueReminders')->willReturn([]);

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('due=0 sent=0 skipped=0 failed=0', $tester->getDisplay());
    }

    /** D-144 — contrôle de la sélection en prod : lecture seule, aucune réservation ni envoi. */
    public function test_dry_run_lists_due_reminders_without_processing_them(): void
    {
        $m = $this->makeMission();
        $this->service->method('findDueReminders')->willReturn([['mission' => $m, 'stage' => EncodingReminderStage::D4]]);
        $this->service->expects(self::never())->method('processReminder');
        $this->service->expects(self::once())->method('describeDue')->willReturn([
            'dueAt' => '2026-10-12 09:00', 'instrumentistId' => 7, 'situation' => 'HOURS_MISSING', 'alreadySent' => 'non', 'managers' => '4',
        ]);

        $tester = new CommandTester(new SendEncodingRemindersCommand($this->service, $this->logger, 'prod'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--at' => '2026-10-12 09:05']));
        self::assertStringContainsString('DRY-RUN 2026-10-12 09:05', $tester->getDisplay());
        self::assertStringContainsString('HOURS_MISSING', $tester->getDisplay());
    }

    /** L'horloge de production n'est jamais modifiable pour un envoi réel. */
    public function test_at_is_refused_in_prod_without_dry_run(): void
    {
        $this->service->expects(self::never())->method('findDueReminders');

        $tester = new CommandTester(new SendEncodingRemindersCommand($this->service, $this->logger, 'prod'));
        self::assertSame(Command::FAILURE, $tester->execute(['--at' => '2026-10-12 09:05']));
    }
}
