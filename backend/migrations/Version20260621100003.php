<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 3): PlanningOccurrenceException.override_date (MOVED support); PlanningAlert.type + nullable absence + resolution_note (OPEN/ACKNOWLEDGED/RESOLVED/IGNORED lifecycle). Both tables are still empty in production use — additive/relaxing change only, no data migration needed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            ALTER TABLE `planning_occurrence_exception`
                ADD COLUMN `override_date` DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'
        ");

        $this->addSql("
            ALTER TABLE `planning_alert`
                ADD COLUMN `type` VARCHAR(24) NOT NULL,
                ADD COLUMN `resolution_note` VARCHAR(255) DEFAULT NULL,
                MODIFY COLUMN `absence_id` INT DEFAULT NULL,
                MODIFY COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'OPEN'
        ");

        $this->addSql('CREATE INDEX idx_planning_alert_mission_type ON `planning_alert` (`mission_id`, `type`)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_planning_alert_mission_type ON `planning_alert`');

        $this->addSql("
            ALTER TABLE `planning_alert`
                DROP COLUMN `type`,
                DROP COLUMN `resolution_note`,
                MODIFY COLUMN `absence_id` INT NOT NULL,
                MODIFY COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'PENDING'
        ");

        $this->addSql('ALTER TABLE `planning_occurrence_exception` DROP COLUMN `override_date`');
    }
}
