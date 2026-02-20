<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add declaredAt and declaredComment fields to mission (mission_declared Lot B1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD declared_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE mission ADD declared_comment LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP declared_at');
        $this->addSql('ALTER TABLE mission DROP declared_comment');
    }
}