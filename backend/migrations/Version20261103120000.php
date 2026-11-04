<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-182 — mission annulée comme doublon : `mission.replaced_by_mission_id`, la mission
 * existante conservée à sa place pour le même programme.
 *
 * Purement additif : une colonne nullable, NULL pour toutes les missions existantes (aucune
 * donnée réécrite). FK ON DELETE SET NULL : la suppression d'une mission ne bloque jamais
 * l'autre.
 *
 * Rollback : DROP de la contrainte, de l'index et de la colonne (perd seulement le lien).
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-182 — mission.replaced_by_mission_id (mission annulée remplacée par une mission existante, additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD replaced_by_mission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23C741DFB7F FOREIGN KEY (replaced_by_mission_id) REFERENCES mission (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9067F23C741DFB7F ON mission (replaced_by_mission_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23C741DFB7F');
        $this->addSql('DROP INDEX IDX_9067F23C741DFB7F ON mission');
        $this->addSql('ALTER TABLE mission DROP replaced_by_mission_id');
    }
}
