<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — bascule des documents financiers vers
 * les lignes figées de FinancialCalculation. Migration conservatrice (§19/§35 du lot) :
 * uniquement des colonnes NULLABLE ajoutées, aucune donnée existante modifiée, aucun
 * document/ligne préexistant perdu ou altéré.
 *
 * financial_calculation_line_id (nullable, UNIQUE) sur firm_invoice_line et
 * instrumentist_statement_line : NULL pour toute ligne existante avant ce lot (aucun
 * rapprochement rétroactif tenté — §19 : "ne pas faire de rapprochement approximatif
 * par montant/date/description"). currency ('EUR' par défaut, backfill des lignes/
 * documents existants — seule devise utilisée dans ce projet jusqu'ici) et
 * legacy_source (true par défaut pour tout document existant, false pour tout nouveau
 * document créé via FirmInvoiceService::createFromEligibleLines()/
 * InstrumentistStatementService::createFromEligibleLines()) sur firm_invoice et
 * instrumentist_statement.
 */
final class Version20260718175935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécution & Valorisation Lot 4 (D-074) — FK nullable financial_calculation_line_id (UNIQUE) sur les lignes de facture/décompte, currency + legacy_source sur les documents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firm_invoice ADD currency VARCHAR(3) NOT NULL DEFAULT \'EUR\', ADD legacy_source TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE instrumentist_statement ADD currency VARCHAR(3) NOT NULL DEFAULT \'EUR\', ADD legacy_source TINYINT(1) NOT NULL DEFAULT 1');

        $this->addSql('
            ALTER TABLE firm_invoice_line
                ADD financial_calculation_line_id INT DEFAULT NULL,
                ADD currency VARCHAR(3) NOT NULL DEFAULT \'EUR\',
                ADD unit_snapshot VARCHAR(50) DEFAULT NULL,
                ADD source_snapshot JSON DEFAULT NULL,
                ADD created_at DATETIME DEFAULT NULL
        ');
        $this->addSql('ALTER TABLE firm_invoice_line ADD CONSTRAINT uniq_fil_financial_calculation_line UNIQUE (financial_calculation_line_id)');
        $this->addSql('ALTER TABLE firm_invoice_line ADD CONSTRAINT fk_fil_financial_calculation_line FOREIGN KEY (financial_calculation_line_id) REFERENCES financial_calculation_line (id)');

        $this->addSql('
            ALTER TABLE instrumentist_statement_line
                ADD financial_calculation_line_id INT DEFAULT NULL,
                ADD currency VARCHAR(3) NOT NULL DEFAULT \'EUR\',
                ADD unit_snapshot VARCHAR(50) DEFAULT NULL,
                ADD source_snapshot JSON DEFAULT NULL,
                ADD created_at DATETIME DEFAULT NULL
        ');
        $this->addSql('ALTER TABLE instrumentist_statement_line ADD CONSTRAINT uniq_isl_financial_calculation_line UNIQUE (financial_calculation_line_id)');
        $this->addSql('ALTER TABLE instrumentist_statement_line ADD CONSTRAINT fk_isl_financial_calculation_line FOREIGN KEY (financial_calculation_line_id) REFERENCES financial_calculation_line (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instrumentist_statement_line DROP FOREIGN KEY fk_isl_financial_calculation_line');
        $this->addSql('ALTER TABLE instrumentist_statement_line DROP CONSTRAINT uniq_isl_financial_calculation_line');
        $this->addSql('ALTER TABLE instrumentist_statement_line DROP financial_calculation_line_id, DROP currency, DROP unit_snapshot, DROP source_snapshot, DROP created_at');

        $this->addSql('ALTER TABLE firm_invoice_line DROP FOREIGN KEY fk_fil_financial_calculation_line');
        $this->addSql('ALTER TABLE firm_invoice_line DROP CONSTRAINT uniq_fil_financial_calculation_line');
        $this->addSql('ALTER TABLE firm_invoice_line DROP financial_calculation_line_id, DROP currency, DROP unit_snapshot, DROP source_snapshot, DROP created_at');

        $this->addSql('ALTER TABLE instrumentist_statement DROP currency, DROP legacy_source');
        $this->addSql('ALTER TABLE firm_invoice DROP currency, DROP legacy_source');
    }
}
