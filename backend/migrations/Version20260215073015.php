<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260215073015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mission ADD instrumentist_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CFF41632 FOREIGN KEY (instrumentist_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_9067F23CFF41632 ON mission (instrumentist_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CFF41632');
        $this->addSql('DROP INDEX IDX_9067F23CFF41632 ON mission');
        $this->addSql('ALTER TABLE mission DROP instrumentist_id');
    }
}
