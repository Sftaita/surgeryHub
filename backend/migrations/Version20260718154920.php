<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — cœur déterministe de la valorisation
 * financière : deux nouvelles tables, aucune donnée existante touchée.
 *
 * financial_calculation : l'agrégat, une version figée par (mission, version) —
 * unicité imposée en DB (§21/§27 du lot), jamais réécrit en place une fois créé
 * (voir FinancialCalculation::class). superseded_by_calculation_id est une FK
 * auto-référencée, renseignée uniquement sur l'ancien calcul lors d'un recalcul.
 *
 * financial_calculation_line : les lignes détaillées (jamais un total opaque, §5) —
 * FK nullable vers les entités source (MissionIntervention/MaterialLine/PricingRule/
 * InstrumentistRate) pour la navigation uniquement ; le JSON `snapshot` porte la
 * vérité historique complète (§8), lisible même si l'entité référencée disparaît
 * plus tard du catalogue.
 *
 * CHECK constraints (§27) : MySQL 8.3 les applique réellement (vérifié — contrairement
 * à MySQL < 8.0.16 qui les acceptait en syntaxe mais ne les appliquait jamais), même
 * politique que Version20260718121937 (instrumentist_rate).
 */
final class Version20260718154920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécution & Valorisation Lot 3 (D-073) — nouvelles tables financial_calculation et financial_calculation_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE financial_calculation (
                id INT AUTO_INCREMENT NOT NULL,
                mission_id INT NOT NULL,
                version INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                effective_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                currency_policy VARCHAR(40) NOT NULL,
                calculated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                calculated_by_id INT DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                approved_by_id INT DEFAULT NULL,
                locked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                cancelled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                cancelled_by_id INT DEFAULT NULL,
                superseded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                superseded_by_calculation_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_financial_calculation_mission_status (mission_id, status),
                UNIQUE INDEX uniq_financial_calculation_mission_version (mission_id, version),
                UNIQUE INDEX uniq_financial_calculation_superseded_by (superseded_by_calculation_id),
                CONSTRAINT fk_financial_calculation_mission FOREIGN KEY (mission_id) REFERENCES mission (id),
                CONSTRAINT fk_financial_calculation_calculated_by FOREIGN KEY (calculated_by_id) REFERENCES user (id),
                CONSTRAINT fk_financial_calculation_approved_by FOREIGN KEY (approved_by_id) REFERENCES user (id),
                CONSTRAINT fk_financial_calculation_cancelled_by FOREIGN KEY (cancelled_by_id) REFERENCES user (id),
                CONSTRAINT fk_financial_calculation_superseded_by FOREIGN KEY (superseded_by_calculation_id) REFERENCES financial_calculation (id),
                CONSTRAINT chk_financial_calculation_version_positive CHECK (version > 0),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE financial_calculation_line (
                id INT AUTO_INCREMENT NOT NULL,
                financial_calculation_id INT NOT NULL,
                beneficiary_type VARCHAR(20) NOT NULL,
                beneficiary_firm_id INT DEFAULT NULL,
                beneficiary_instrumentist_id INT DEFAULT NULL,
                line_type VARCHAR(40) NOT NULL,
                source_type VARCHAR(30) NOT NULL,
                mission_intervention_id INT DEFAULT NULL,
                material_line_id INT DEFAULT NULL,
                pricing_rule_id INT DEFAULT NULL,
                instrumentist_rate_id INT DEFAULT NULL,
                description_snapshot VARCHAR(500) NOT NULL,
                quantity NUMERIC(10, 4) NOT NULL,
                duration_minutes INT DEFAULT NULL,
                unit_amount NUMERIC(10, 2) NOT NULL,
                total_amount NUMERIC(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                effective_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                snapshot JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_fcl_calculation (financial_calculation_id),
                INDEX idx_fcl_beneficiary_firm (beneficiary_firm_id),
                INDEX idx_fcl_beneficiary_instrumentist (beneficiary_instrumentist_id),
                CONSTRAINT fk_fcl_calculation FOREIGN KEY (financial_calculation_id) REFERENCES financial_calculation (id),
                CONSTRAINT fk_fcl_beneficiary_firm FOREIGN KEY (beneficiary_firm_id) REFERENCES firm (id),
                CONSTRAINT fk_fcl_beneficiary_instrumentist FOREIGN KEY (beneficiary_instrumentist_id) REFERENCES user (id),
                CONSTRAINT fk_fcl_mission_intervention FOREIGN KEY (mission_intervention_id) REFERENCES mission_intervention (id),
                CONSTRAINT fk_fcl_material_line FOREIGN KEY (material_line_id) REFERENCES material_line (id),
                CONSTRAINT fk_fcl_pricing_rule FOREIGN KEY (pricing_rule_id) REFERENCES pricing_rule (id),
                CONSTRAINT fk_fcl_instrumentist_rate FOREIGN KEY (instrumentist_rate_id) REFERENCES instrumentist_rate (id),
                CONSTRAINT chk_fcl_quantity_positive CHECK (quantity > 0),
                CONSTRAINT chk_fcl_unit_amount_nonneg CHECK (unit_amount >= 0),
                CONSTRAINT chk_fcl_total_amount_nonneg CHECK (total_amount >= 0),
                CONSTRAINT chk_fcl_duration_minutes_positive CHECK (duration_minutes IS NULL OR duration_minutes > 0),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE financial_calculation_line');
        $this->addSql('DROP TABLE financial_calculation');
    }
}
