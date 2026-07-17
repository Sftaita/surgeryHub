<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 1 — Socle du catalogue financier des firmes.
 *
 * Données réelles au moment de cette migration (vérifiées le 2026-07-16, base locale
 * copie de production) : firm=3, material_item=16, material_line=0, pricing_rule=0,
 * firm_invoice=0, firm_invoice_line=0. Seuls `firm` et `material_item` contiennent des
 * données réelles à préserver — tout le reste de ce périmètre est refondu librement.
 *
 * mission_intervention (1 ligne, mission #529 label="csd") est une donnée de test
 * manifeste, non traitée par cette migration (voir docs/decisions.md) — elle n'est ni
 * lue ni modifiée ici, et ses nouvelles colonnes (Lot 5) n'existent pas encore.
 */
final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 1 — InterventionType, FirmServiceOffering, SuggestedMaterial ; MaterialItem (firme obligatoire, unicité) ; PricingRule (interventionType, dates, devise, MATERIAL_FEE).';
    }

    public function up(Schema $schema): void
    {
        // ── InterventionType ────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE intervention_type (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(50) NOT NULL,
                label VARCHAR(255) NOT NULL,
                specialty VARCHAR(50) DEFAULT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_intervention_type_code (code),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // ── MaterialItem : firme obligatoire + unicité ──────────────────
        // 0 ligne n'a de firm_id NULL et 0 doublon (firm_id, reference_code) au
        // 2026-07-16 — resserrement sans risque de perte de données (voir audit).
        $this->addSql('ALTER TABLE material_item MODIFY firm_id INT NOT NULL');
        $this->addSql('ALTER TABLE material_item ADD CONSTRAINT uniq_material_item_firm_reference UNIQUE (firm_id, reference_code)');
        $this->addSql('ALTER TABLE material_item ADD CONSTRAINT uniq_material_item_firm_id UNIQUE (firm_id, id)');

        // ── FirmServiceOffering ("Prestation") ───────────────────────────
        $this->addSql('
            CREATE TABLE firm_service_offering (
                id INT AUTO_INCREMENT NOT NULL,
                firm_id INT NOT NULL,
                intervention_type_id INT NOT NULL,
                label VARCHAR(255) DEFAULT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_offering_firm_intervention_type (firm_id, intervention_type_id),
                INDEX idx_offering_firm (firm_id),
                INDEX idx_offering_intervention_type (intervention_type_id),
                CONSTRAINT fk_offering_firm FOREIGN KEY (firm_id) REFERENCES firm (id),
                CONSTRAINT fk_offering_intervention_type FOREIGN KEY (intervention_type_id) REFERENCES intervention_type (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // ── SuggestedMaterial ────────────────────────────────────────────
        // firm_id est dénormalisé depuis firm_service_offering.firm_id pour permettre la
        // FK composée ci-dessous : garantit EN BASE (pas seulement côté service) qu'un
        // matériel suggéré appartient toujours à la même firme que sa prestation.
        $this->addSql('
            CREATE TABLE suggested_material (
                id INT AUTO_INCREMENT NOT NULL,
                firm_service_offering_id INT NOT NULL,
                firm_id INT NOT NULL,
                material_item_id INT NOT NULL,
                display_order SMALLINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_suggested_material_offering_item (firm_service_offering_id, material_item_id),
                INDEX idx_suggested_material_offering (firm_service_offering_id),
                CONSTRAINT fk_suggested_material_offering FOREIGN KEY (firm_service_offering_id) REFERENCES firm_service_offering (id),
                CONSTRAINT fk_suggested_material_firm_item FOREIGN KEY (firm_id, material_item_id) REFERENCES material_item (firm_id, id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // ── PricingRule : interventionType (FK) + dates + devise ─────────
        // 0 ligne existante (vérifié) : renommage/refonte sans donnée à migrer.
        $this->addSql("UPDATE pricing_rule SET rule_type = 'MATERIAL_FEE' WHERE rule_type = 'IMPLANT_FEE'");
        $this->addSql('ALTER TABLE pricing_rule DROP COLUMN intervention_code');
        $this->addSql('ALTER TABLE pricing_rule ADD intervention_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pricing_rule ADD CONSTRAINT fk_pricing_rule_intervention_type FOREIGN KEY (intervention_type_id) REFERENCES intervention_type (id)');
        $this->addSql('CREATE INDEX idx_pricing_rule_intervention_type ON pricing_rule (intervention_type_id)');
        $this->addSql("ALTER TABLE pricing_rule ADD currency VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        $this->addSql('ALTER TABLE pricing_rule ADD valid_from DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE pricing_rule ADD valid_to DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pricing_rule DROP valid_to');
        $this->addSql('ALTER TABLE pricing_rule DROP valid_from');
        $this->addSql('ALTER TABLE pricing_rule DROP currency');
        $this->addSql('ALTER TABLE pricing_rule DROP FOREIGN KEY fk_pricing_rule_intervention_type');
        $this->addSql('DROP INDEX idx_pricing_rule_intervention_type ON pricing_rule');
        $this->addSql('ALTER TABLE pricing_rule DROP COLUMN intervention_type_id');
        $this->addSql('ALTER TABLE pricing_rule ADD intervention_code VARCHAR(100) DEFAULT NULL');
        $this->addSql("UPDATE pricing_rule SET rule_type = 'IMPLANT_FEE' WHERE rule_type = 'MATERIAL_FEE'");

        $this->addSql('DROP TABLE suggested_material');
        $this->addSql('DROP TABLE firm_service_offering');

        $this->addSql('ALTER TABLE material_item DROP CONSTRAINT uniq_material_item_firm_id');
        $this->addSql('ALTER TABLE material_item DROP CONSTRAINT uniq_material_item_firm_reference');
        $this->addSql('ALTER TABLE material_item MODIFY firm_id INT DEFAULT NULL');

        $this->addSql('DROP TABLE intervention_type');
    }
}
