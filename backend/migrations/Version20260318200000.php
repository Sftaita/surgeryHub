<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Planning — PlanningTemplate, PlanningSlot, Absence, PlanningDeployment + User.specialties';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD COLUMN `specialties` JSON NULL');

        $this->addSql('
            CREATE TABLE `planning_template` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `site_id` INT DEFAULT NULL,
              `created_by_id` INT NOT NULL,
              `type` VARCHAR(6) NOT NULL COMMENT \'(DC2Type:PlanningTemplateType)\',
              `date_start` DATE NOT NULL,
              `date_end` DATE DEFAULT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              INDEX IDX_PT_SITE (`site_id`),
              INDEX IDX_PT_CREATED_BY (`created_by_id`),
              CONSTRAINT FK_PT_SITE FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`),
              CONSTRAINT FK_PT_CREATED_BY FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `planning_slot` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `template_id` INT NOT NULL,
              `site_id` INT DEFAULT NULL,
              `surgeon_id` INT NOT NULL,
              `instrumentist_id` INT DEFAULT NULL,
              `day_of_week` SMALLINT NOT NULL,
              `period` VARCHAR(2) NOT NULL COMMENT \'(DC2Type:SlotPeriod)\',
              `start_time` TIME NOT NULL,
              `end_time` TIME NOT NULL,
              `mission_type` VARCHAR(20) NOT NULL COMMENT \'(DC2Type:MissionType)\',
              PRIMARY KEY (`id`),
              INDEX IDX_PS_TEMPLATE (`template_id`),
              INDEX IDX_PS_SURGEON (`surgeon_id`),
              INDEX IDX_PS_SITE (`site_id`),
              CONSTRAINT FK_PS_TEMPLATE FOREIGN KEY (`template_id`) REFERENCES `planning_template` (`id`) ON DELETE CASCADE,
              CONSTRAINT FK_PS_SITE FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`),
              CONSTRAINT FK_PS_SURGEON FOREIGN KEY (`surgeon_id`) REFERENCES `user` (`id`),
              CONSTRAINT FK_PS_INSTRUMENTIST FOREIGN KEY (`instrumentist_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `absence` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `user_id` INT NOT NULL,
              `created_by_id` INT NOT NULL,
              `date_start` DATE NOT NULL,
              `date_end` DATE NOT NULL,
              `reason` VARCHAR(255) DEFAULT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              INDEX IDX_ABS_USER (`user_id`),
              CONSTRAINT FK_ABS_USER FOREIGN KEY (`user_id`) REFERENCES `user` (`id`),
              CONSTRAINT FK_ABS_CREATED_BY FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE `planning_deployment` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `site_id` INT DEFAULT NULL,
              `deployed_by_id` INT NOT NULL,
              `period_from` DATE NOT NULL,
              `period_to` DATE NOT NULL,
              `deployed_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              CONSTRAINT FK_PD_SITE FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`),
              CONSTRAINT FK_PD_DEPLOYED_BY FOREIGN KEY (`deployed_by_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `planning_deployment`');
        $this->addSql('DROP TABLE `absence`');
        $this->addSql('DROP TABLE `planning_slot`');
        $this->addSql('DROP TABLE `planning_template`');
        $this->addSql('ALTER TABLE `user` DROP COLUMN `specialties`');
    }
}
