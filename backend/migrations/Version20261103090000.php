<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-181 — relais d'une mission commencée : `mission.relay_of_mission_id`, la mission que
 * celle-ci poursuit avec une autre instrumentiste.
 *
 * Purement additif : une colonne nullable, NULL pour toutes les missions existantes (aucune
 * donnée réécrite). FK ON DELETE SET NULL : la suppression d'une mission ne bloque ni ne
 * supprime jamais son relais.
 *
 * Rollback : DROP de la contrainte, de l'index et de la colonne (perd seulement le lien).
 */
final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-181 — mission.relay_of_mission_id (relais d\'une mission commencée, additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD relay_of_mission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CFD6A7F79 FOREIGN KEY (relay_of_mission_id) REFERENCES mission (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9067F23CFD6A7F79 ON mission (relay_of_mission_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CFD6A7F79');
        $this->addSql('DROP INDEX IDX_9067F23CFD6A7F79 ON mission');
        $this->addSql('ALTER TABLE mission DROP relay_of_mission_id');
    }
}
