<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Facturation — PricingRule, FirmInvoice, FirmInvoiceLine, InstrumentistStatement, InstrumentistStatementLine + Firm billing contact';
    }

    public function up(Schema $schema): void
    {
        // Firm — champs email facturation
        $this->addSql('ALTER TABLE firm ADD billing_email VARCHAR(255) DEFAULT NULL, ADD billing_email_cc LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:json)\'');

        // PricingRule
        $this->addSql('
            CREATE TABLE pricing_rule (
                id INT AUTO_INCREMENT NOT NULL,
                firm_id INT NOT NULL,
                material_item_id INT DEFAULT NULL,
                rule_type VARCHAR(20) NOT NULL,
                intervention_code VARCHAR(100) DEFAULT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX idx_pricing_rule_firm (firm_id),
                INDEX idx_pricing_rule_item (material_item_id),
                CONSTRAINT fk_pricing_rule_firm FOREIGN KEY (firm_id) REFERENCES firm (id),
                CONSTRAINT fk_pricing_rule_item FOREIGN KEY (material_item_id) REFERENCES material_item (id) ON DELETE SET NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // FirmInvoice
        $this->addSql('
            CREATE TABLE firm_invoice (
                id INT AUTO_INCREMENT NOT NULL,
                firm_id INT NOT NULL,
                number VARCHAR(20) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'DRAFT\',
                period_start DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                period_end DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                total_amount DECIMAL(10,2) NOT NULL DEFAULT \'0.00\',
                billing_email_to VARCHAR(255) DEFAULT NULL,
                billing_email_cc LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:json)\',
                generated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_firm_invoice_number (number),
                INDEX idx_firm_invoice_firm (firm_id),
                INDEX idx_firm_invoice_status (status),
                CONSTRAINT fk_firm_invoice_firm FOREIGN KEY (firm_id) REFERENCES firm (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // FirmInvoiceLine
        $this->addSql('
            CREATE TABLE firm_invoice_line (
                id INT AUTO_INCREMENT NOT NULL,
                invoice_id INT NOT NULL,
                mission_id INT NOT NULL,
                mission_intervention_id INT DEFAULT NULL,
                material_line_id INT DEFAULT NULL,
                line_type VARCHAR(20) NOT NULL,
                description_snapshot VARCHAR(500) NOT NULL,
                firm_name_snapshot VARCHAR(255) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                quantity DECIMAL(10,2) NOT NULL DEFAULT \'1.00\',
                total_amount DECIMAL(10,2) NOT NULL,
                INDEX idx_firm_invoice_line_invoice (invoice_id),
                CONSTRAINT fk_fil_invoice FOREIGN KEY (invoice_id) REFERENCES firm_invoice (id) ON DELETE CASCADE,
                CONSTRAINT fk_fil_mission FOREIGN KEY (mission_id) REFERENCES mission (id),
                CONSTRAINT fk_fil_intervention FOREIGN KEY (mission_intervention_id) REFERENCES mission_intervention (id) ON DELETE SET NULL,
                CONSTRAINT fk_fil_material_line FOREIGN KEY (material_line_id) REFERENCES material_line (id) ON DELETE SET NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // InstrumentistStatement
        $this->addSql('
            CREATE TABLE instrumentist_statement (
                id INT AUTO_INCREMENT NOT NULL,
                instrumentist_id INT NOT NULL,
                period_year SMALLINT NOT NULL,
                period_month SMALLINT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'GENERATED\',
                total_amount DECIMAL(10,2) NOT NULL DEFAULT \'0.00\',
                instrumentist_name_snapshot VARCHAR(255) DEFAULT NULL,
                instrumentist_email_snapshot VARCHAR(255) DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX idx_stmt_instrumentist (instrumentist_id),
                INDEX idx_stmt_status (status),
                CONSTRAINT fk_stmt_instrumentist FOREIGN KEY (instrumentist_id) REFERENCES user (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // InstrumentistStatementLine
        $this->addSql('
            CREATE TABLE instrumentist_statement_line (
                id INT AUTO_INCREMENT NOT NULL,
                statement_id INT NOT NULL,
                mission_id INT NOT NULL,
                line_type VARCHAR(20) NOT NULL,
                duration_minutes_raw INT DEFAULT NULL,
                duration_minutes_rounded INT DEFAULT NULL,
                rate_snapshot DECIMAL(10,2) NOT NULL,
                quantity DECIMAL(10,4) NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                surgeon_name_snapshot VARCHAR(255) DEFAULT NULL,
                site_name_snapshot VARCHAR(255) DEFAULT NULL,
                mission_date_snapshot DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                INDEX idx_stmt_line_statement (statement_id),
                CONSTRAINT fk_stmt_line_statement FOREIGN KEY (statement_id) REFERENCES instrumentist_statement (id) ON DELETE CASCADE,
                CONSTRAINT fk_stmt_line_mission FOREIGN KEY (mission_id) REFERENCES mission (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE instrumentist_statement_line');
        $this->addSql('DROP TABLE instrumentist_statement');
        $this->addSql('DROP TABLE firm_invoice_line');
        $this->addSql('DROP TABLE firm_invoice');
        $this->addSql('DROP TABLE pricing_rule');
        $this->addSql('ALTER TABLE firm DROP billing_email, DROP billing_email_cc');
    }
}
