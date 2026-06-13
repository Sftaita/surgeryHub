<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260315204318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_MIR_MATERIAL_ITEM');
        $this->addSql('DROP INDEX idx_mir_status ON material_item_request');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_3EB23DCBA3F589F1 FOREIGN KEY (material_item_id) REFERENCES material_item (id)');
        $this->addSql('ALTER TABLE material_item_request RENAME INDEX idx_mir_material_item TO IDX_3EB23DCBA3F589F1');
        $this->addSql('DROP INDEX uniq_site_user ON site_membership');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_3EB23DCBA3F589F1');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_MIR_MATERIAL_ITEM FOREIGN KEY (material_item_id) REFERENCES material_item (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_mir_status ON material_item_request (status)');
        $this->addSql('ALTER TABLE material_item_request RENAME INDEX idx_3eb23dcba3f589f1 TO idx_mir_material_item');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_user ON site_membership (site_id, user_id)');
    }
}
