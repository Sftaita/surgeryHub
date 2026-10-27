<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-171 — reprise manager : `mission.encoding_validated_without_hours_at`, le fait « interventions
 * et matériel validés alors que les heures prestées de l'instrumentiste manquaient ». Tant que ces
 * heures manquent, elles restent déclarables (instrumentiste affectée, manager) malgré le verrou de
 * l'encodage ; aucune heure n'est créée ni validée par ce fait.
 *
 * Purement additif : colonne nullable, aucune mission existante n'est marquée.
 *
 * Rollback : DROP de la colonne (l'AuditEvent MISSION_ENCODING_TAKEN_OVER reste).
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-171 — mission.encoding_validated_without_hours_at (manager takeover validated without hours).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD encoding_validated_without_hours_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP encoding_validated_without_hours_at');
    }
}
