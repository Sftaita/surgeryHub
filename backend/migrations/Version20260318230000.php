<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hospital — add photo_path column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `hospital` ADD COLUMN `photo_path` VARCHAR(500) NULL AFTER `timezone`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `hospital` DROP COLUMN `photo_path`');
    }
}
