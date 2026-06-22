<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 1): SiteGroup, ShiftPeriodConfig, SurgeonSchedulePost, PlanningOccurrenceException, PlanningAlert + Mission(start_at,end_at) index — additive only, no drops of legacy planning tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE `site_group` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `name` VARCHAR(100) NOT NULL,
              `created_by_id` INT NOT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              INDEX IDX_SITE_GROUP_CREATED_BY (`created_by_id`),
              CONSTRAINT FK_SITE_GROUP_CREATED_BY FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `site_group_membership` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `site_group_id` INT NOT NULL,
              `site_id` INT NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE INDEX uniq_site_group_site (`site_group_id`, `site_id`),
              INDEX idx_site_group_membership_site (`site_id`),
              CONSTRAINT FK_SGM_GROUP FOREIGN KEY (`site_group_id`) REFERENCES `site_group` (`id`) ON DELETE CASCADE,
              CONSTRAINT FK_SGM_SITE FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `shift_period_config` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `site_id` INT NOT NULL,
              `period` VARCHAR(12) NOT NULL,
              `start_time` TIME NOT NULL,
              `end_time` TIME NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE INDEX uniq_site_period (`site_id`, `period`),
              CONSTRAINT FK_SPC_SITE FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `surgeon_schedule_post` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `surgeon_id` INT NOT NULL,
              `site_id` INT NOT NULL,
              `instrumentist_id` INT DEFAULT NULL,
              `created_by_id` INT NOT NULL,
              `type` VARCHAR(20) NOT NULL,
              `period` VARCHAR(12) NOT NULL,
              `recurrence_frequency` VARCHAR(10) NOT NULL,
              `recurrence_interval` SMALLINT NOT NULL DEFAULT 1,
              `recurrence_weekdays` LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\',
              `recurrence_anchor_date` DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
              `recurrence_monthly_nth_weekday` SMALLINT DEFAULT NULL,
              `start_date` DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
              `end_date` DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
              `created_at` DATETIME NOT NULL,
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              PRIMARY KEY (`id`),
              INDEX idx_post_surgeon_start (`surgeon_id`, `start_date`),
              INDEX idx_post_site (`site_id`),
              CONSTRAINT FK_POST_SURGEON FOREIGN KEY (`surgeon_id`) REFERENCES `user` (`id`),
              CONSTRAINT FK_POST_SITE FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`),
              CONSTRAINT FK_POST_INSTRUMENTIST FOREIGN KEY (`instrumentist_id`) REFERENCES `user` (`id`),
              CONSTRAINT FK_POST_CREATED_BY FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `planning_occurrence_exception` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `post_id` INT NOT NULL,
              `occurrence_date` DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
              `type` VARCHAR(24) NOT NULL,
              `override_instrumentist_id` INT DEFAULT NULL,
              `override_start_time` TIME DEFAULT NULL,
              `override_end_time` TIME DEFAULT NULL,
              `created_by_id` INT NOT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE INDEX uniq_post_occurrence_date (`post_id`, `occurrence_date`),
              INDEX idx_occurrence_exception_date (`occurrence_date`),
              CONSTRAINT FK_POE_POST FOREIGN KEY (`post_id`) REFERENCES `surgeon_schedule_post` (`id`) ON DELETE CASCADE,
              CONSTRAINT FK_POE_OVERRIDE_INSTRUMENTIST FOREIGN KEY (`override_instrumentist_id`) REFERENCES `user` (`id`),
              CONSTRAINT FK_POE_CREATED_BY FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `planning_alert` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `absence_id` INT NOT NULL,
              `mission_id` INT NOT NULL,
              `resolved_by_id` INT DEFAULT NULL,
              `status` VARCHAR(16) NOT NULL DEFAULT \'PENDING\',
              `detected_at` DATETIME NOT NULL,
              `resolved_at` DATETIME DEFAULT NULL,
              `snapshot_json` LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\',
              PRIMARY KEY (`id`),
              INDEX idx_planning_alert_status (`status`),
              INDEX idx_planning_alert_absence (`absence_id`),
              CONSTRAINT FK_PA_ABSENCE FOREIGN KEY (`absence_id`) REFERENCES `absence` (`id`),
              CONSTRAINT FK_PA_MISSION FOREIGN KEY (`mission_id`) REFERENCES `mission` (`id`),
              CONSTRAINT FK_PA_RESOLVED_BY FOREIGN KEY (`resolved_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('CREATE INDEX idx_mission_start_end ON `mission` (`start_at`, `end_at`)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_mission_start_end ON `mission`');
        $this->addSql('DROP TABLE `planning_alert`');
        $this->addSql('DROP TABLE `planning_occurrence_exception`');
        $this->addSql('DROP TABLE `surgeon_schedule_post`');
        $this->addSql('DROP TABLE `shift_period_config`');
        $this->addSql('DROP TABLE `site_group_membership`');
        $this->addSql('DROP TABLE `site_group`');
    }
}
