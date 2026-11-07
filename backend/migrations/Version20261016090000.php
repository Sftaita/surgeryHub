<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-153 — `financial_calculation.encoding_reopened_at` : posé par la réouverture de l'encodage
 * sur le calcul encore actif (CALCULATED / APPROVED). Un calcul ainsi marqué n'est plus
 * approuvé, verrouillé ni facturé ; seul un recalcul en produit une version à jour.
 *
 * Purement additif (colonne nullable, aucune donnée existante modifiée). Les calculs déjà
 * actifs sur une mission rouverte AVANT cette migration ne sont pas marqués rétroactivement.
 *
 * Rollback : DROP COLUMN (le marquage est perdu ; l'AuditEvent MISSION_ENCODING_REOPENED
 * garde la liste des calculs concernés).
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-153 — financial_calculation.encoding_reopened_at (calculation made obsolete by an encoding reopen).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_calculation ADD encoding_reopened_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_calculation DROP encoding_reopened_at');
    }
}
