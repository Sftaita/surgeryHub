<?php

namespace App\Tests\Unit\Activity;

use App\Activity\AppendOnly\ActivityEventImmutableException;
use App\Activity\AppendOnly\AppendOnlyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** H1 (D-126) — SQL-level rule behind activity_event's append-only guarantee. */
final class AppendOnlyGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forbidden(): iterable
    {
        yield 'orm update' => ['UPDATE activity_event SET summary = ? WHERE id = ?'];
        yield 'backticks' => ['UPDATE `activity_event` SET outcome = "X"'];
        yield 'schema prefix' => ['update surgicalhub.activity_event set outcome = 1'];
        yield 'orm delete' => ['DELETE FROM activity_event WHERE id = ?'];
        yield 'dql bulk delete' => ["DELETE FROM activity_event WHERE event_type = 'ABSENCE_CREATED'"];
        yield 'multi-table delete' => ['DELETE a FROM activity_event a WHERE a.id = 1'];
        yield 'truncate' => ['TRUNCATE TABLE activity_event'];
        yield 'truncate short' => ['truncate activity_event'];
        yield 'replace' => ['REPLACE INTO activity_event (id) VALUES (1)'];
        yield 'leading whitespace' => ["\n  DELETE FROM activity_event"];
    }

    #[DataProvider('forbidden')]
    public function test_mutating_statements_on_activity_event_are_refused(string $sql): void
    {
        $this->expectException(ActivityEventImmutableException::class);
        AppendOnlyGuard::assertAllowed($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'insert' => ['INSERT INTO activity_event (event_type) VALUES (?)'];
        yield 'select' => ['SELECT * FROM activity_event WHERE correlation_id = ?'];
        yield 'other table update' => ['UPDATE audit_event SET payload = ?'];
        yield 'other table delete' => ['DELETE FROM audit_event WHERE mission_id IN (?)'];
        yield 'similar name' => ['DELETE FROM activity_event_archive WHERE id = 1'];
        yield 'create table' => ['CREATE TABLE activity_event (id INT)'];
    }

    #[DataProvider('allowed')]
    public function test_other_statements_pass(string $sql): void
    {
        AppendOnlyGuard::assertAllowed($sql);
        $this->addToAssertionCount(1);
    }

    public function test_explicit_maintenance_lifts_the_guard_for_its_callback_only(): void
    {
        $result = AppendOnlyGuard::runMaintenance(function () {
            AppendOnlyGuard::assertAllowed('DELETE FROM activity_event WHERE id = 1');

            return 'done';
        });
        self::assertSame('done', $result);

        $this->expectException(ActivityEventImmutableException::class);
        AppendOnlyGuard::assertAllowed('DELETE FROM activity_event WHERE id = 1');
    }

    public function test_maintenance_guard_is_restored_after_an_exception(): void
    {
        try {
            AppendOnlyGuard::runMaintenance(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->expectException(ActivityEventImmutableException::class);
        AppendOnlyGuard::assertAllowed('UPDATE activity_event SET outcome = 1');
    }
}
