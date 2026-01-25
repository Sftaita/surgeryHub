<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125075106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE material_item_request (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, mission_intervention_id INT DEFAULT NULL, mission_intervention_firm_id INT DEFAULT NULL, created_by_id INT NOT NULL, label VARCHAR(255) NOT NULL, reference_code VARCHAR(255) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3EB23DCBBE6CAE90 (mission_id), INDEX IDX_3EB23DCBB86F7406 (mission_intervention_id), INDEX IDX_3EB23DCB5B207EBF (mission_intervention_firm_id), INDEX IDX_3EB23DCBB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE missing_material_report (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, mission_intervention_id INT NOT NULL, created_by_id INT NOT NULL, firm_name VARCHAR(120) NOT NULL, search_text VARCHAR(255) NOT NULL, quantity NUMERIC(10, 2) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, status VARCHAR(255) DEFAULT \'OPEN\' NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_ED3882E6BE6CAE90 (mission_id), INDEX IDX_ED3882E6B86F7406 (mission_intervention_id), INDEX IDX_ED3882E6B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_3EB23DCBBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_3EB23DCBB86F7406 FOREIGN KEY (mission_intervention_id) REFERENCES mission_intervention (id)');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_3EB23DCB5B207EBF FOREIGN KEY (mission_intervention_firm_id) REFERENCES mission_intervention_firm (id)');
        $this->addSql('ALTER TABLE material_item_request ADD CONSTRAINT FK_3EB23DCBB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE missing_material_report ADD CONSTRAINT FK_ED3882E6BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE missing_material_report ADD CONSTRAINT FK_ED3882E6B86F7406 FOREIGN KEY (mission_intervention_id) REFERENCES mission_intervention (id)');
        $this->addSql('ALTER TABLE missing_material_report ADD CONSTRAINT FK_ED3882E6B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_3EB23DCBBE6CAE90');
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_3EB23DCBB86F7406');
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_3EB23DCB5B207EBF');
        $this->addSql('ALTER TABLE material_item_request DROP FOREIGN KEY FK_3EB23DCBB03A8386');
        $this->addSql('ALTER TABLE missing_material_report DROP FOREIGN KEY FK_ED3882E6BE6CAE90');
        $this->addSql('ALTER TABLE missing_material_report DROP FOREIGN KEY FK_ED3882E6B86F7406');
        $this->addSql('ALTER TABLE missing_material_report DROP FOREIGN KEY FK_ED3882E6B03A8386');
        $this->addSql('DROP TABLE material_item_request');
        $this->addSql('DROP TABLE missing_material_report');
    }
}
