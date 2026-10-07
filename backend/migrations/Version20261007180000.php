<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-134 — journal append-only du parcours documentaire de chaque ligne de facturation
 * firme (intervention / matériel). Purement additif : une nouvelle table, aucune clé
 * étrangère (les événements survivent à toute suppression), aucune donnée existante touchée.
 */
final class Version20261007180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates firm_billing_line_event — append-only history of each firm billing line (D-134).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE firm_billing_line_event (
            id INT AUTO_INCREMENT NOT NULL,
            source_type VARCHAR(20) NOT NULL,
            source_id INT NOT NULL,
            financial_calculation_line_id INT DEFAULT NULL,
            mission_id INT NOT NULL,
            firm_id INT DEFAULT NULL,
            firm_name_snapshot VARCHAR(255) DEFAULT NULL,
            invoice_id INT DEFAULT NULL,
            invoice_number_snapshot VARCHAR(50) DEFAULT NULL,
            invoice_status_snapshot VARCHAR(20) DEFAULT NULL,
            amount_snapshot NUMERIC(12, 2) DEFAULT NULL,
            currency_snapshot VARCHAR(3) DEFAULT NULL,
            event_type VARCHAR(40) NOT NULL,
            actor_id INT DEFAULT NULL,
            actor_name_snapshot VARCHAR(255) DEFAULT NULL,
            occurred_at DATETIME NOT NULL,
            details JSON DEFAULT NULL,
            INDEX idx_fble_source (source_type, source_id, occurred_at),
            INDEX idx_fble_invoice (invoice_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE firm_billing_line_event');
    }
}
