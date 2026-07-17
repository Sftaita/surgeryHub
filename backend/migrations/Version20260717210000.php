<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 5 — Rattachement de MissionIntervention au référentiel InterventionType.
 *
 * Rapport de mapping (produit avant toute écriture, voir aussi docs/decisions.md) :
 * une seule ligne existante dans mission_intervention (id=1, mission #529,
 * code="LCA", label="csd" — donnée de test manifeste, déjà signalée par
 * Version20260716120000). intervention_type est entièrement vide (0 ligne) au moment
 * de cette migration : aucun candidat de rapprochement n'existe, mappage ambigu à
 * confiance nulle. Décision : **aucun backfill automatique**. La ligne reste avec
 * intervention_type_id NULL — non supprimée, non modifiée, à traiter manuellement si
 * besoin (voir audit Lot 5, docs/decisions.md D-068).
 *
 * intervention_type_id / primary_firm_id sont nullables en base pour ne pas casser
 * cette ligne historique non mappée — la contrainte "obligatoire pour les nouveaux
 * encodages" est appliquée au niveau applicatif (InterventionService::create()), pas
 * en base, précisément pour permettre à cette ligne pré-Lot 5 de continuer d'exister
 * sans type.
 */
final class Version20260717210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 5 — MissionIntervention.interventionType/primaryFirm ; InterventionTypeRequest (demande de nouveau type, miroir de MaterialItemRequest).';
    }

    public function up(Schema $schema): void
    {
        // ── MissionIntervention : rattachement au référentiel ────────────
        $this->addSql('ALTER TABLE mission_intervention ADD intervention_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_intervention ADD primary_firm_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_intervention ADD CONSTRAINT fk_mission_intervention_type FOREIGN KEY (intervention_type_id) REFERENCES intervention_type (id)');
        $this->addSql('ALTER TABLE mission_intervention ADD CONSTRAINT fk_mission_intervention_primary_firm FOREIGN KEY (primary_firm_id) REFERENCES firm (id)');
        $this->addSql('CREATE INDEX idx_mission_intervention_type ON mission_intervention (intervention_type_id)');
        $this->addSql('CREATE INDEX idx_mission_intervention_primary_firm ON mission_intervention (primary_firm_id)');

        // ── InterventionTypeRequest ("demande de nouveau type") ──────────
        // Miroir structurel de material_item_request : PENDING/RESOLVED/IGNORED,
        // resolved_intervention_type_id renseigné uniquement à la résolution manager.
        // Pas de mission_intervention_id : par construction, aucune MissionIntervention
        // ne peut exister sans type valide après ce lot — la demande précède la
        // création de l'intervention, elle ne peut donc jamais la référencer.
        $this->addSql('
            CREATE TABLE intervention_type_request (
                id INT AUTO_INCREMENT NOT NULL,
                mission_id INT NOT NULL,
                created_by_id INT NOT NULL,
                resolved_intervention_type_id INT DEFAULT NULL,
                label VARCHAR(255) NOT NULL,
                suggested_code VARCHAR(50) DEFAULT NULL,
                comment LONGTEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'PENDING\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_intervention_type_request_mission (mission_id),
                INDEX idx_intervention_type_request_created_by (created_by_id),
                INDEX idx_intervention_type_request_status (status),
                CONSTRAINT fk_itr_mission FOREIGN KEY (mission_id) REFERENCES mission (id),
                CONSTRAINT fk_itr_created_by FOREIGN KEY (created_by_id) REFERENCES user (id),
                CONSTRAINT fk_itr_resolved_type FOREIGN KEY (resolved_intervention_type_id) REFERENCES intervention_type (id) ON DELETE SET NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE intervention_type_request');

        $this->addSql('ALTER TABLE mission_intervention DROP FOREIGN KEY fk_mission_intervention_primary_firm');
        $this->addSql('ALTER TABLE mission_intervention DROP FOREIGN KEY fk_mission_intervention_type');
        $this->addSql('DROP INDEX idx_mission_intervention_primary_firm ON mission_intervention');
        $this->addSql('DROP INDEX idx_mission_intervention_type ON mission_intervention');
        $this->addSql('ALTER TABLE mission_intervention DROP primary_firm_id');
        $this->addSql('ALTER TABLE mission_intervention DROP intervention_type_id');
    }
}
