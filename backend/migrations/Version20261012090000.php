<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-146 bis — fiabilisation des demandes de matériel et d'intervention.
 *
 * Purement additif, toutes colonnes NULLABLES : aucune donnée historique n'est réécrite
 * et aucune valeur de remplacement fictive n'est posée sur les demandes existantes
 * (NULL = « information jamais recueillie », jamais « quantité 1 » ni « firme X ») :
 *  - `material_item_request.proposed_firm_id` : firme existante choisie par
 *    l'instrumentiste (FK `firm`, ON DELETE SET NULL — la demande reste lisible) ;
 *  - `material_item_request.proposed_firm_name` : nom libre d'une firme absente du
 *    catalogue (« Ma firme n'est pas dans la liste ») — jamais une firme créée ;
 *  - `material_item_request.quantity` : quantité déclarée (même type que
 *    `material_line.quantity`) ;
 *  - `mission_intervention_draft.proposed_firm_name` : même nom libre pour une demande
 *    d'intervention (la firme existante y était déjà conservée : `requested_firm_id`).
 *
 * Rollback : DROP des 4 colonnes et de la FK (perd les firmes/quantités saisies depuis
 * le déploiement ; aucune autre donnée touchée).
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-146 bis — material_item_request.proposed_firm_id/proposed_firm_name/quantity + mission_intervention_draft.proposed_firm_name (nullable, additive).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material_item_request ADD proposed_firm_id INT DEFAULT NULL, ADD proposed_firm_name VARCHAR(150) DEFAULT NULL, ADD quantity NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT fk_material_item_request_proposed_firm FOREIGN KEY (proposed_firm_id) REFERENCES firm (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_material_item_request_proposed_firm ON material_item_request (proposed_firm_id)');
        $this->addSql('ALTER TABLE mission_intervention_draft ADD proposed_firm_name VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention_draft DROP proposed_firm_name');
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY fk_material_item_request_proposed_firm');
        $this->addSql('DROP INDEX idx_material_item_request_proposed_firm ON material_item_request');
        $this->addSql('ALTER TABLE material_item_request DROP proposed_firm_id, DROP proposed_firm_name, DROP quantity');
    }
}
