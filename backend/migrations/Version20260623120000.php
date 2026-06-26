<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Planning V2 (Batch 14A): evolve RecurrenceRule's MONTHLY support from a single
 * `monthlyNthWeekday` int to an array `monthWeeks` (1-5), and let `weekdays` be used
 * for MONTHLY too instead of being derived implicitly from the post's startDate.
 *
 * MONTHLY is hidden from the frontend create/edit picker (lacks test coverage until
 * this batch), so no real production data depends on `monthlyNthWeekday` today —
 * backfill is a courtesy for any rows created via direct API use, not a hard
 * compatibility requirement.
 */
final class Version20260623120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 14A): RecurrenceRule.monthlyNthWeekday (?int) -> monthWeeks (int[] JSON), MONTHLY now uses weekdays explicitly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `surgeon_schedule_post` ADD `recurrence_month_weeks` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)'");
        $this->addSql("UPDATE `surgeon_schedule_post` SET `recurrence_month_weeks` = JSON_ARRAY(`recurrence_monthly_nth_weekday`) WHERE `recurrence_monthly_nth_weekday` IS NOT NULL");
        $this->addSql("UPDATE `surgeon_schedule_post` SET `recurrence_month_weeks` = '[]' WHERE `recurrence_month_weeks` IS NULL");
        $this->addSql("ALTER TABLE `surgeon_schedule_post` MODIFY `recurrence_month_weeks` LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
        // Backfill weekdays for existing MONTHLY rows from the post's own start_date,
        // matching the exact behaviour the old isOccurrenceActive() implicitly relied on.
        $this->addSql("UPDATE `surgeon_schedule_post` SET `recurrence_weekdays` = JSON_ARRAY(DAYOFWEEK(`start_date`) - 1) WHERE `recurrence_frequency` = 'MONTHLY' AND DAYOFWEEK(`start_date`) > 1");
        $this->addSql("UPDATE `surgeon_schedule_post` SET `recurrence_weekdays` = JSON_ARRAY(7) WHERE `recurrence_frequency` = 'MONTHLY' AND DAYOFWEEK(`start_date`) = 1");
        $this->addSql('ALTER TABLE `surgeon_schedule_post` DROP `recurrence_monthly_nth_weekday`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `surgeon_schedule_post` ADD `recurrence_monthly_nth_weekday` SMALLINT DEFAULT NULL");
        $this->addSql("UPDATE `surgeon_schedule_post` SET `recurrence_monthly_nth_weekday` = JSON_EXTRACT(`recurrence_month_weeks`, '$[0]') WHERE JSON_LENGTH(`recurrence_month_weeks`) > 0");
        $this->addSql('ALTER TABLE `surgeon_schedule_post` DROP `recurrence_month_weeks`');
    }
}
