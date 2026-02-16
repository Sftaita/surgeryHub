<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260202063137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CB03A8386');
        $this->addSql('DROP INDEX IDX_9067F23CB03A8386 ON mission');
        $this->addSql('ALTER TABLE mission ADD submitted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD encoding_locked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD invoice_generated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP created_by_id, CHANGE surgeon_id surgeon_id INT DEFAULT NULL, CHANGE status status VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mission ADD created_by_id INT NOT NULL, DROP submitted_at, DROP encoding_locked_at, DROP invoice_generated_at, CHANGE surgeon_id surgeon_id INT NOT NULL, CHANGE status status VARCHAR(255) DEFAULT \'DRAFT\' NOT NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_9067F23CB03A8386 ON mission (created_by_id)');
    }
}
