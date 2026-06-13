<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanningTemplate — add label column (optional custom name)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `planning_template` ADD COLUMN `label` VARCHAR(100) NULL AFTER `type`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `planning_template` DROP COLUMN `label`');
    }
}
