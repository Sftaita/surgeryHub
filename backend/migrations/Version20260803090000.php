<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Task 11 — Référentiel canonique InterventionType : mécanisme de fusion explicite
 * (manager only, jamais automatique). Ajout additif pur, aucune donnée existante
 * réinterprétée : merged_into_id/merged_at restent NULL pour tout type non fusionné.
 * Le type source d'une fusion n'est jamais supprimé (préserve l'intégrité référentielle
 * de tout PricingRule/FirmServiceOffering historique encore attaché) — voir
 * InterventionTypeMergeService.
 */
final class Version20260803090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "InterventionType : ajout merged_into_id (auto-référence nullable) + merged_at, support de la fusion explicite de doublons (Task 11).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention_type ADD merged_into_id INT DEFAULT NULL, ADD merged_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE intervention_type ADD CONSTRAINT fk_intervention_type_merged_into FOREIGN KEY (merged_into_id) REFERENCES intervention_type (id)');
        $this->addSql('CREATE INDEX idx_intervention_type_merged_into ON intervention_type (merged_into_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention_type DROP FOREIGN KEY fk_intervention_type_merged_into');
        $this->addSql('DROP INDEX idx_intervention_type_merged_into ON intervention_type');
        $this->addSql('ALTER TABLE intervention_type DROP merged_into_id, DROP merged_at');
    }
}
