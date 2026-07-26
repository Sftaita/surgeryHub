<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-083 (rappel unique d'encodage D+1 à 08 h) — ajoute la marque d'envoi persistante
 * garantissant au plus un rappel par mission (idempotence stricte, indépendante des logs
 * et robuste aux doubles exécutions du job planifié). Colonne nullable, additive.
 */
final class Version20260726090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "D-083 : ajoute mission.encoding_reminder_sent_at (nullable, additive) pour l'idempotence du rappel D+1.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD encoding_reminder_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP COLUMN encoding_reminder_sent_at');
    }
}
