<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correctif workflow Demandes Catalogue — motif structuré + explication + décideur/date
 * sur l'action "Ignorer", pour MaterialItemRequest et InterventionTypeRequest (miroir).
 * Purement additive : toutes les nouvelles colonnes sont nullable, aucune donnée
 * existante n'est affectée.
 */
final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MaterialItemRequest/InterventionTypeRequest.{ignore_reason,ignore_comment,decided_by_id,decided_at} — motif d\'ignore structuré.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material_item_request ADD ignore_reason VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE material_item_request ADD ignore_comment LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE material_item_request ADD decided_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE material_item_request ADD decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT fk_material_item_request_decided_by FOREIGN KEY (decided_by_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX idx_material_item_request_decided_by ON material_item_request (decided_by_id)');

        $this->addSql('ALTER TABLE intervention_type_request ADD ignore_reason VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE intervention_type_request ADD ignore_comment LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE intervention_type_request ADD decided_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE intervention_type_request ADD decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE intervention_type_request ADD CONSTRAINT fk_intervention_type_request_decided_by FOREIGN KEY (decided_by_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX idx_intervention_type_request_decided_by ON intervention_type_request (decided_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention_type_request DROP FOREIGN KEY fk_intervention_type_request_decided_by');
        $this->addSql('DROP INDEX idx_intervention_type_request_decided_by ON intervention_type_request');
        $this->addSql('ALTER TABLE intervention_type_request DROP COLUMN ignore_reason');
        $this->addSql('ALTER TABLE intervention_type_request DROP COLUMN ignore_comment');
        $this->addSql('ALTER TABLE intervention_type_request DROP COLUMN decided_by_id');
        $this->addSql('ALTER TABLE intervention_type_request DROP COLUMN decided_at');

        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY fk_material_item_request_decided_by');
        $this->addSql('DROP INDEX idx_material_item_request_decided_by ON material_item_request');
        $this->addSql('ALTER TABLE material_item_request DROP COLUMN ignore_reason');
        $this->addSql('ALTER TABLE material_item_request DROP COLUMN ignore_comment');
        $this->addSql('ALTER TABLE material_item_request DROP COLUMN decided_by_id');
        $this->addSql('ALTER TABLE material_item_request DROP COLUMN decided_at');
    }
}
