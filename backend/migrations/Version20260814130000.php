<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tarification firme conditionnée à un choix obligatoire — RequiredChoiceGroup +
 * ChoiceOption (générique, jamais de sémantique clinique en dur), discriminant optionnel
 * sur PricingRule (choice_option_id nullable — NULL = comportement standard inchangé) et
 * sélection persistée sur MissionIntervention (selected_choice_option_id nullable).
 * Purement additive : aucune donnée existante affectée, toutes les nouvelles colonnes/
 * tables sont nullable ou vides par défaut.
 */
final class Version20260814130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarification firme conditionnée à un choix obligatoire — RequiredChoiceGroup, ChoiceOption, PricingRule.choice_option_id, MissionIntervention.selected_choice_option_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE required_choice_group (
                id INT AUTO_INCREMENT NOT NULL,
                firm_service_offering_id INT NOT NULL,
                question VARCHAR(500) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_choice_group_offering (firm_service_offering_id),
                CONSTRAINT fk_choice_group_offering FOREIGN KEY (firm_service_offering_id) REFERENCES firm_service_offering (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE choice_option (
                id INT AUTO_INCREMENT NOT NULL,
                required_choice_group_id INT NOT NULL,
                label VARCHAR(255) NOT NULL,
                material_item_id INT DEFAULT NULL,
                display_order SMALLINT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_choice_option_group (required_choice_group_id),
                INDEX idx_choice_option_material_item (material_item_id),
                CONSTRAINT fk_choice_option_group FOREIGN KEY (required_choice_group_id) REFERENCES required_choice_group (id),
                CONSTRAINT fk_choice_option_material_item FOREIGN KEY (material_item_id) REFERENCES material_item (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE pricing_rule ADD choice_option_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pricing_rule ADD CONSTRAINT fk_pricing_rule_choice_option FOREIGN KEY (choice_option_id) REFERENCES choice_option (id)');
        $this->addSql('CREATE INDEX idx_pricing_rule_choice_option ON pricing_rule (choice_option_id)');

        $this->addSql('ALTER TABLE mission_intervention ADD selected_choice_option_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_intervention ADD CONSTRAINT fk_mission_intervention_selected_choice_option FOREIGN KEY (selected_choice_option_id) REFERENCES choice_option (id)');
        $this->addSql('CREATE INDEX idx_mission_intervention_selected_choice_option ON mission_intervention (selected_choice_option_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention DROP FOREIGN KEY fk_mission_intervention_selected_choice_option');
        $this->addSql('DROP INDEX idx_mission_intervention_selected_choice_option ON mission_intervention');
        $this->addSql('ALTER TABLE mission_intervention DROP COLUMN selected_choice_option_id');

        $this->addSql('ALTER TABLE pricing_rule DROP FOREIGN KEY fk_pricing_rule_choice_option');
        $this->addSql('DROP INDEX idx_pricing_rule_choice_option ON pricing_rule');
        $this->addSql('ALTER TABLE pricing_rule DROP COLUMN choice_option_id');

        $this->addSql('DROP TABLE choice_option');
        $this->addSql('DROP TABLE required_choice_group');
    }
}
