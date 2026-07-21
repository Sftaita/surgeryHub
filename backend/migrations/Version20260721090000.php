<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraft : ligne d'intervention
 * provisoire de mission, distincte de InterventionTypeRequest (qui reste le traitement
 * de la demande catalogue) et de MissionIntervention (qui reste l'intervention réelle).
 * Revue de conception complète avant implémentation (voir docs/decisions.md) : entité
 * séparée plutôt que fusionnée dans InterventionTypeRequest, pour rester alignée en
 * forme sur MissionIntervention (orderIndex, firme, matériel) et éviter un nommage
 * ambigu sur les FK de material_line/material_item_request (même principe que
 * PayableDocument/FirmInvoice/InstrumentistStatement, D-075 : deux agrégats distincts
 * unifiés par une interface métier plutôt qu'une fusion de table).
 *
 * requested_firm_name_snapshot fige le nom de la firme demandée par l'instrumentiste au
 * moment de la création (comme label) — un simple FK ne suffit pas si la firme est
 * renommée ensuite. Pas de code snapshot : Firm n'a aujourd'hui aucun champ code métier
 * stable (seulement name/billingEmail/country/representative/phone).
 *
 * intervention_draft_id sur material_line/material_item_request est un NOUVEAU
 * rattachement nullable, en plus de mission_intervention_id (déjà nullable, sens
 * inchangé : "non rattaché à une intervention précise", cf. FinancialCalculationLine).
 * Les deux FK ne sont jamais renseignées simultanément — invariant appliqué en service
 * (MissionInterventionDraftService, pas encore branché par cette migration), jamais en
 * contrainte DB, cohérent avec le reste du modèle.
 *
 * Migration strictement additive : aucune colonne existante modifiée, aucune donnée
 * supprimée, aucun comportement branché — l'entité MissionInterventionDraft, les FK
 * intervention_draft_id, sont créées mais rien ne les lit ni ne les écrit encore.
 */
final class Version20260721090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Revue instrumentiste, Lot 3 — table mission_intervention_draft ; intervention_draft_id nullable sur material_line/material_item_request.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE mission_intervention_draft (
                id INT AUTO_INCREMENT NOT NULL,
                mission_id INT NOT NULL,
                intervention_type_request_id INT NOT NULL,
                requested_firm_id INT DEFAULT NULL,
                resolved_mission_intervention_id INT DEFAULT NULL,
                created_by_id INT NOT NULL,
                label VARCHAR(255) NOT NULL,
                requested_firm_name_snapshot VARCHAR(255) DEFAULT NULL,
                order_index SMALLINT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'OPEN\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_draft_intervention_type_request (intervention_type_request_id),
                INDEX idx_draft_mission (mission_id),
                INDEX idx_draft_status (status),
                INDEX idx_draft_requested_firm (requested_firm_id),
                INDEX idx_draft_resolved_intervention (resolved_mission_intervention_id),
                INDEX idx_draft_created_by (created_by_id),
                CONSTRAINT fk_draft_mission FOREIGN KEY (mission_id) REFERENCES mission (id),
                CONSTRAINT fk_draft_itr FOREIGN KEY (intervention_type_request_id) REFERENCES intervention_type_request (id),
                CONSTRAINT fk_draft_requested_firm FOREIGN KEY (requested_firm_id) REFERENCES firm (id) ON DELETE SET NULL,
                CONSTRAINT fk_draft_resolved_intervention FOREIGN KEY (resolved_mission_intervention_id) REFERENCES mission_intervention (id),
                CONSTRAINT fk_draft_created_by FOREIGN KEY (created_by_id) REFERENCES user (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE material_line ADD intervention_draft_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT fk_material_line_draft FOREIGN KEY (intervention_draft_id) REFERENCES mission_intervention_draft (id)');
        $this->addSql('CREATE INDEX idx_material_line_draft ON material_line (intervention_draft_id)');

        $this->addSql('ALTER TABLE material_item_request ADD intervention_draft_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT fk_material_item_request_draft FOREIGN KEY (intervention_draft_id) REFERENCES mission_intervention_draft (id)');
        $this->addSql('CREATE INDEX idx_material_item_request_draft ON material_item_request (intervention_draft_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY fk_material_item_request_draft');
        $this->addSql('DROP INDEX idx_material_item_request_draft ON material_item_request');
        $this->addSql('ALTER TABLE material_item_request DROP intervention_draft_id');

        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY fk_material_line_draft');
        $this->addSql('DROP INDEX idx_material_line_draft ON material_line');
        $this->addSql('ALTER TABLE material_line DROP intervention_draft_id');

        $this->addSql('DROP TABLE mission_intervention_draft');
    }
}
