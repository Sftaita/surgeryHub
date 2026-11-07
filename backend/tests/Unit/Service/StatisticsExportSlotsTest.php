<?php

namespace App\Tests\Unit\Service;

use App\Exception\StatisticsExportException;
use App\Service\StatisticsExportSlots;
use PHPUnit\Framework\TestCase;

/**
 * D-187 — emplacements d'export PDF simultanés (flock, sans attente).
 *
 * En production chaque export est servi par son propre processus php-fpm : la concurrence est
 * testée avec de vrais processus PHP séparés (le verrou flock d'un même processus n'entre pas en
 * conflit avec lui-même sous Windows), qui exécutent le vrai StatisticsExportSlots::run().
 */
final class StatisticsExportSlotsTest extends TestCase
{
    private string $dir;
    /** @var list<array{0: resource, 1: resource}> */
    private array $children = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sh-export-slots-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$proc, $out]) {
            @proc_terminate($proc, 9);
            @fclose($out);
            @proc_close($proc);
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_two_simultaneous_exports_run_and_a_third_is_refused_explicitly(): void
    {
        self::assertSame('held', $this->startExportInAnotherProcess(2), 'premier export simultané');
        self::assertSame('held', $this->startExportInAnotherProcess(2), 'second export simultané');

        try {
            (new StatisticsExportSlots($this->dir, 2))->run(fn () => self::fail('capacité atteinte : aucun export ne doit démarrer'));
            self::fail('refus attendu');
        } catch (StatisticsExportException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertSame(StatisticsExportException::BUSY, $e->getErrorCode());
            self::assertSame('30', $e->getHeaders()['Retry-After']);
            self::assertSame(['capacity' => 2, 'retryAfter' => 30], $e->getExtra());
            self::assertStringContainsString('2 au maximum en même temps', $e->getMessage());
            self::assertStringContainsString('Réessayez dans quelques instants', $e->getMessage());
        }
    }

    public function test_slot_is_released_after_success(): void
    {
        $slots = new StatisticsExportSlots($this->dir, 1);
        $during = $slots->run(fn () => $this->tryExportInAnotherProcess(1));

        self::assertSame('busy', $during, 'pendant l\'export, l\'emplacement est occupé');
        self::assertSame('ok', $this->tryExportInAnotherProcess(1), 'l\'emplacement est libre après un export réussi');
    }

    public function test_slot_is_released_after_exception(): void
    {
        $slots = new StatisticsExportSlots($this->dir, 1);
        $during = null;
        try {
            $slots->run(function () use (&$during): never {
                $during = $this->tryExportInAnotherProcess(1);
                throw new \RuntimeException('dompdf en échec');
            });
            self::fail('exception attendue');
        } catch (\RuntimeException $e) {
            self::assertSame('dompdf en échec', $e->getMessage(), 'l\'erreur d\'origine est propagée telle quelle');
        }

        self::assertSame('busy', $during);
        self::assertSame('ok', $this->tryExportInAnotherProcess(1), 'l\'emplacement est libre après une exception');
    }

    public function test_slot_held_by_a_killed_process_is_freed_by_the_system(): void
    {
        self::assertSame('held', $this->startExportInAnotherProcess(1));
        self::assertSame('busy', $this->tryExportInAnotherProcess(1));

        // Interruption brutale (worker php-fpm tué, dépassement mémoire) : le processus ne libère rien lui-même.
        [$proc, $out] = array_pop($this->children);
        proc_terminate($proc, 9);
        fclose($out);
        proc_close($proc);

        $deadline = microtime(true) + 5;
        do {
            $after = $this->tryExportInAnotherProcess(1);
        } while ($after !== 'ok' && microtime(true) < $deadline && usleep(100_000) === null);
        self::assertSame('ok', $after, 'un verrou ne survit jamais au processus qui le tenait');
    }

    public function test_default_capacity_is_two(): void
    {
        self::assertSame(2, (new StatisticsExportSlots($this->dir))->capacity());
        self::assertSame(2, StatisticsExportSlots::CAPACITY);
    }

    /** Lance un export qui reste en cours (30 s) dans un autre processus ; renvoie « held » ou « busy ». */
    private function startExportInAnotherProcess(int $capacity): string
    {
        $proc = proc_open([PHP_BINARY, '-r', $this->childScript($capacity, 'echo "held\n"; fflush(STDOUT); sleep(30);')], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $this->children[] = [$proc, $pipes[1]];
        return trim((string) fgets($pipes[1]));
    }

    /** Tente un export immédiat dans un autre processus : « ok » ou « busy ». */
    private function tryExportInAnotherProcess(int $capacity): string
    {
        $proc = proc_open([PHP_BINARY, '-r', $this->childScript($capacity, 'echo "ok\n";')], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $line = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($proc);
        return $line;
    }

    private function childScript(int $capacity, string $work): string
    {
        return sprintf(
            'require %s; $s = new App\Service\StatisticsExportSlots(%s, %d);'
            . ' try { $s->run(function () { %s }); } catch (App\Exception\StatisticsExportException $e) { echo "busy\n"; }',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            var_export($this->dir, true),
            $capacity,
            $work,
        );
    }
}
