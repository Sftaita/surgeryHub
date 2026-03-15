<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status and material_item_id to material_item_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE material_item_request ADD status VARCHAR(20) NOT NULL DEFAULT 'PENDING'");
        $this->addSql('ALTER TABLE material_item_request ADD material_item_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_MIR_MATERIAL_ITEM FOREIGN KEY (material_item_id) REFERENCES material_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_mir_material_item ON material_item_request (material_item_id)');
        $this->addSql('CREATE INDEX idx_mir_status ON material_item_request (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_mir_material_item ON material_item_request');
        $this->addSql('DROP INDEX idx_mir_status ON material_item_request');
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_MIR_MATERIAL_ITEM');
        $this->addSql('ALTER TABLE material_item_request DROP material_item_id');
        $this->addSql('ALTER TABLE material_item_request DROP status');
    }
}
