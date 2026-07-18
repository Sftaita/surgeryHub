<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — cycle de vie financier après émission
 * (émission, paiement, solde). Une seule table nouvelle, append-only : aucune colonne
 * ajoutée à firm_invoice/instrumentist_statement/firm_invoice_line/
 * instrumentist_statement_line (le solde est calculé, jamais stocké — §7 du lot),
 * aucune donnée existante modifiée.
 *
 * payment.document_id n'a pas de contrainte FK : table polymorphe unique servant
 * FirmInvoice ET InstrumentistStatement (document_type les distingue) — une FK
 * Doctrine/SQL classique ne peut référencer qu'une seule table. Validé au niveau
 * applicatif par DocumentPaymentService, jamais deviné.
 */
final class Version20260718184911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécution & Valorisation Lot 5 (D-075) — nouvelle table payment (paiements append-only, factures et décomptes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE payment (
                id INT AUTO_INCREMENT NOT NULL,
                document_type VARCHAR(30) NOT NULL,
                document_id INT NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                paid_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                recorded_by_id INT NOT NULL,
                reference VARCHAR(255) DEFAULT NULL,
                method VARCHAR(20) NOT NULL,
                comment LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_payment_document (document_type, document_id),
                INDEX idx_payment_recorded_by (recorded_by_id),
                CONSTRAINT fk_payment_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES user (id),
                CONSTRAINT chk_payment_amount_positive CHECK (amount > 0),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment');
    }
}
