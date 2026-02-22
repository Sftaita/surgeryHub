<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260222153419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_event (id INT AUTO_INCREMENT NOT NULL, actor_id INT NOT NULL, mission_id INT NOT NULL, event_type VARCHAR(255) NOT NULL, payload JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3CE56E5010DAF24A (actor_id), INDEX IDX_3CE56E50BE6CAE90 (mission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE audit_event ADD CONSTRAINT FK_3CE56E5010DAF24A FOREIGN KEY (actor_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE audit_event ADD CONSTRAINT FK_3CE56E50BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_event DROP FOREIGN KEY FK_3CE56E5010DAF24A');
        $this->addSql('ALTER TABLE audit_event DROP FOREIGN KEY FK_3CE56E50BE6CAE90');
        $this->addSql('DROP TABLE audit_event');
    }
}
