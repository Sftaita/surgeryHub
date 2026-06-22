<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Planning V2 (Batch 1) backfill: one SurgeonSchedulePost per existing PlanningSlot.
 *
 * Mapping rules:
 * - PlanningTemplateType TOUTES  -> RecurrenceRule(interval=1)
 * - PlanningTemplateType PAIR    -> RecurrenceRule(interval=2, anchor on a known even ISO week)
 * - PlanningTemplateType IMPAIR  -> RecurrenceRule(interval=2, anchor on a known odd ISO week)
 * - SlotPeriod AM -> ShiftPeriod MATIN, PM -> ShiftPeriod APRES_MIDI
 *
 * Post hours are NOT copied from the slot (postes derive hours from ShiftPeriodConfig,
 * not per-post overrides). Any slot whose own start/end time does not match the seeded
 * site default for that period is written to a migration report for manual manager
 * review rather than silently dropped or auto-resolved.
 */
final class Version20260621100002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 1): backfill SurgeonSchedulePost from existing PlanningTemplate/PlanningSlot rows';
    }

    public function up(Schema $schema): void
    {
        $slots = $this->connection->fetchAllAssociative('
            SELECT
                ps.id              AS slot_id,
                ps.day_of_week     AS day_of_week,
                ps.period          AS slot_period,
                ps.start_time      AS slot_start_time,
                ps.end_time        AS slot_end_time,
                ps.site_id         AS slot_site_id,
                ps.surgeon_id      AS surgeon_id,
                ps.instrumentist_id AS instrumentist_id,
                ps.mission_type    AS mission_type,
                pt.id              AS template_id,
                pt.type            AS template_type,
                pt.site_id         AS template_site_id,
                pt.created_by_id   AS created_by_id
            FROM planning_slot ps
            INNER JOIN planning_template pt ON pt.id = ps.template_id
        ');

        $shiftConfigs = $this->connection->fetchAllAssociative('
            SELECT site_id, period, start_time, end_time FROM shift_period_config
        ');
        $defaultsBySitePeriod = [];
        foreach ($shiftConfigs as $row) {
            $defaultsBySitePeriod[$row['site_id'] . '_' . $row['period']] = [$row['start_time'], $row['end_time']];
        }

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        // Known Monday with ISO week 1 (odd) — used to anchor IMPAIR; +7 days gives ISO week 2 (even) for PAIR.
        $oddAnchor = '2024-01-01';
        $evenAnchor = '2024-01-08';

        $mismatches = [];

        foreach ($slots as $row) {
            $siteId = $row['slot_site_id'] ?? $row['template_site_id'];
            $shiftPeriod = $row['slot_period'] === 'AM' ? 'MATIN' : 'APRES_MIDI';

            $defaultTimes = $defaultsBySitePeriod[$siteId . '_' . $shiftPeriod] ?? null;
            if ($defaultTimes !== null && ($defaultTimes[0] !== $row['slot_start_time'] || $defaultTimes[1] !== $row['slot_end_time'])) {
                $mismatches[] = sprintf(
                    'slot_id=%d site_id=%s period=%s slot_times=%s-%s site_default=%s-%s',
                    $row['slot_id'],
                    $siteId,
                    $shiftPeriod,
                    $row['slot_start_time'],
                    $row['slot_end_time'],
                    $defaultTimes[0],
                    $defaultTimes[1]
                );
            }

            [$interval, $anchorDate] = match ($row['template_type']) {
                'PAIR'   => [2, $evenAnchor],
                'IMPAIR' => [2, $oddAnchor],
                default  => [1, $oddAnchor],
            };

            $this->connection->executeStatement('
                INSERT INTO surgeon_schedule_post (
                    surgeon_id, site_id, instrumentist_id, created_by_id,
                    type, period,
                    recurrence_frequency, recurrence_interval, recurrence_weekdays, recurrence_anchor_date, recurrence_monthly_nth_weekday,
                    start_date, end_date, created_at, active
                ) VALUES (
                    :surgeon_id, :site_id, :instrumentist_id, :created_by_id,
                    :type, :period,
                    \'WEEKLY\', :interval, :weekdays, :anchor_date, NULL,
                    :start_date, NULL, NOW(), 1
                )
            ', [
                'surgeon_id'       => $row['surgeon_id'],
                'site_id'          => $siteId,
                'instrumentist_id' => $row['instrumentist_id'],
                'created_by_id'    => $row['created_by_id'],
                'type'             => $row['mission_type'],
                'period'           => $shiftPeriod,
                'interval'         => $interval,
                'weekdays'         => json_encode([(int) $row['day_of_week']]),
                'anchor_date'      => $anchorDate,
                'start_date'       => $today,
            ]);
        }

        if ($mismatches !== []) {
            $reportDir = __DIR__ . '/../var/migration_reports';
            if (!is_dir($reportDir)) {
                mkdir($reportDir, 0775, true);
            }
            file_put_contents(
                $reportDir . '/planning_v2_backfill_hour_mismatches.txt',
                "Slots whose hours differ from the seeded site default — review manually:\n" . implode("\n", $mismatches) . "\n"
            );
        }
    }

    public function down(Schema $schema): void
    {
        // At this point in the migration sequence no CRUD endpoint for postes exists yet,
        // so every row in the table was produced by this backfill — safe to clear entirely.
        $this->addSql('DELETE FROM surgeon_schedule_post');
    }
}
