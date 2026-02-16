<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make mission.surgeon_id NOT NULL by dropping/recreating FK (Doctrine schema sync).';
    }

    public function up(Schema $schema): void
    {
        // Drop FK that blocks the CHANGE
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CE506D696');

        // Enforce NOT NULL (will fail if there are rows with surgeon_id IS NULL — which is desired)
        $this->addSql('ALTER TABLE mission CHANGE surgeon_id surgeon_id INT NOT NULL');

        // Recreate FK
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CE506D696 FOREIGN KEY (surgeon_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CE506D696');
        $this->addSql('ALTER TABLE mission CHANGE surgeon_id surgeon_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CE506D696 FOREIGN KEY (surgeon_id) REFERENCES user (id)');
    }
}
