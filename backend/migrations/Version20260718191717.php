<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Exécution & Valorisation, Lot 6 (D-076) — corrections financières additives.
 * Migration conservatrice (§31/§34 du lot) : uniquement des colonnes NOUVELLES,
 * NULLABLE ou avec valeur par défaut sûre — aucun document/ligne/paiement existant
 * modifié. `document_type` est backfillé à 'STANDARD' pour toute facture/décompte
 * existant (comportement inchangé) ; `direction` est backfillé à 'INBOUND' pour tout
 * Payment existant (le seul cas possible avant ce lot, Lot 5).
 *
 * instrumentist_statement.number est un nouveau champ (n'existait jamais avant ce lot,
 * FirmInvoice seule avait une numérotation) — reste NULL pour tout décompte STANDARD
 * existant ET futur, seules les notes de crédit/débit de décompte l'utilisent (§19).
 */
final class Version20260718191717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécution & Valorisation Lot 6 (D-076) — document_type/corrects_document_id (FirmInvoice/InstrumentistStatement), reason_code/original_document_line_id (lignes), direction (Payment), number (InstrumentistStatement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE firm_invoice
                ADD document_type VARCHAR(20) NOT NULL DEFAULT \'STANDARD\',
                ADD corrects_document_id INT DEFAULT NULL
        ');
        $this->addSql('ALTER TABLE firm_invoice ADD CONSTRAINT fk_firm_invoice_corrects FOREIGN KEY (corrects_document_id) REFERENCES firm_invoice (id)');
        $this->addSql('CREATE INDEX idx_firm_invoice_corrects ON firm_invoice (corrects_document_id)');
        $this->addSql('CREATE INDEX idx_firm_invoice_doc_type_status ON firm_invoice (document_type, status)');

        $this->addSql('
            ALTER TABLE firm_invoice_line
                ADD reason_code VARCHAR(30) DEFAULT NULL,
                ADD original_document_line_id INT DEFAULT NULL
        ');
        $this->addSql('ALTER TABLE firm_invoice_line ADD CONSTRAINT fk_fil_original_line FOREIGN KEY (original_document_line_id) REFERENCES firm_invoice_line (id)');
        $this->addSql('CREATE INDEX idx_fil_original_line ON firm_invoice_line (original_document_line_id)');

        $this->addSql('
            ALTER TABLE instrumentist_statement
                ADD number VARCHAR(20) DEFAULT NULL,
                ADD document_type VARCHAR(20) NOT NULL DEFAULT \'STANDARD\',
                ADD corrects_document_id INT DEFAULT NULL
        ');
        $this->addSql('ALTER TABLE instrumentist_statement ADD CONSTRAINT uniq_instrumentist_statement_number UNIQUE (number)');
        $this->addSql('ALTER TABLE instrumentist_statement ADD CONSTRAINT fk_instrumentist_statement_corrects FOREIGN KEY (corrects_document_id) REFERENCES instrumentist_statement (id)');
        $this->addSql('CREATE INDEX idx_instrumentist_statement_corrects ON instrumentist_statement (corrects_document_id)');
        $this->addSql('CREATE INDEX idx_instrumentist_statement_doc_type_status ON instrumentist_statement (document_type, status)');

        $this->addSql('
            ALTER TABLE instrumentist_statement_line
                ADD description_snapshot VARCHAR(500) DEFAULT NULL,
                ADD reason_code VARCHAR(30) DEFAULT NULL,
                ADD original_document_line_id INT DEFAULT NULL
        ');
        $this->addSql('ALTER TABLE instrumentist_statement_line ADD CONSTRAINT fk_isl_original_line FOREIGN KEY (original_document_line_id) REFERENCES instrumentist_statement_line (id)');
        $this->addSql('CREATE INDEX idx_isl_original_line ON instrumentist_statement_line (original_document_line_id)');

        $this->addSql('ALTER TABLE payment ADD direction VARCHAR(20) NOT NULL DEFAULT \'INBOUND\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP direction');

        $this->addSql('ALTER TABLE instrumentist_statement_line DROP FOREIGN KEY fk_isl_original_line');
        $this->addSql('DROP INDEX idx_isl_original_line ON instrumentist_statement_line');
        $this->addSql('ALTER TABLE instrumentist_statement_line DROP description_snapshot, DROP reason_code, DROP original_document_line_id');

        $this->addSql('ALTER TABLE instrumentist_statement DROP FOREIGN KEY fk_instrumentist_statement_corrects');
        $this->addSql('DROP INDEX idx_instrumentist_statement_corrects ON instrumentist_statement');
        $this->addSql('DROP INDEX idx_instrumentist_statement_doc_type_status ON instrumentist_statement');
        $this->addSql('ALTER TABLE instrumentist_statement DROP CONSTRAINT uniq_instrumentist_statement_number');
        $this->addSql('ALTER TABLE instrumentist_statement DROP number, DROP document_type, DROP corrects_document_id');

        $this->addSql('ALTER TABLE firm_invoice_line DROP FOREIGN KEY fk_fil_original_line');
        $this->addSql('DROP INDEX idx_fil_original_line ON firm_invoice_line');
        $this->addSql('ALTER TABLE firm_invoice_line DROP reason_code, DROP original_document_line_id');

        $this->addSql('ALTER TABLE firm_invoice DROP FOREIGN KEY fk_firm_invoice_corrects');
        $this->addSql('DROP INDEX idx_firm_invoice_corrects ON firm_invoice');
        $this->addSql('DROP INDEX idx_firm_invoice_doc_type_status ON firm_invoice');
        $this->addSql('ALTER TABLE firm_invoice DROP document_type, DROP corrects_document_id');
    }
}
