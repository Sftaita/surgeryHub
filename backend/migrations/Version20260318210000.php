<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanningTemplate — drop date_start/date_end, make site_id NOT NULL, extend type to VARCHAR(7) for TOUTES';
    }

    public function up(Schema $schema): void
    {
        // date_start, date_end and type resize were already applied in a partial run
        $this->addSql('ALTER TABLE `planning_template` DROP FOREIGN KEY `FK_PT_SITE`');
        $this->addSql('ALTER TABLE `planning_template` MODIFY COLUMN `site_id` INT NOT NULL');
        $this->addSql('ALTER TABLE `planning_template` ADD CONSTRAINT `FK_PT_SITE` FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `planning_template` DROP FOREIGN KEY `FK_PT_SITE`');
        $this->addSql('ALTER TABLE `planning_template` MODIFY COLUMN `site_id` INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `planning_template` ADD CONSTRAINT `FK_PT_SITE` FOREIGN KEY (`site_id`) REFERENCES `hospital` (`id`)');
        $this->addSql('ALTER TABLE `planning_template` MODIFY COLUMN `type` VARCHAR(6) NOT NULL');
        $this->addSql('ALTER TABLE `planning_template` ADD COLUMN `date_start` DATE NOT NULL DEFAULT \'2000-01-01\', ADD COLUMN `date_end` DATE DEFAULT NULL');
    }
}
