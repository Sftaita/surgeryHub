<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-177 — priorité exclusive de 48 heures sur la mission d'une reprise de salle.
 *
 *  - mission_publication.expires_at : échéance d'une priorité nominative (NULL pour toute
 *    autre publication : demandes D-125, pool).
 *  - mission_publication.closed_at / close_reason : fin d'une demande nominative pour une
 *    autre raison que le refus de sa destinataire (declined_at, D-125) — acceptation,
 *    expiration, absence, levée manager…
 *  - index (closed_at, declined_at, expires_at) : recherche des priorités échues.
 *
 * Purement additif : colonnes nullables, aucune ligne existante modifiée (toutes les
 * publications existantes restent actives exactement comme avant : closed_at NULL).
 *
 * Rollback : DROP des trois colonnes et de l'index ; aucune donnée existante n'en dépend.
 */
final class Version20261030150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-177 — mission_publication.expires_at / closed_at / close_reason (priorité de reprise de salle).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mission_publication
            ADD expires_at DATETIME DEFAULT NULL,
            ADD closed_at DATETIME DEFAULT NULL,
            ADD close_reason VARCHAR(32) DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_publication_priority_due ON mission_publication (closed_at, declined_at, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_publication_priority_due ON mission_publication');
        $this->addSql('ALTER TABLE mission_publication DROP expires_at, DROP closed_at, DROP close_reason');
    }
}
