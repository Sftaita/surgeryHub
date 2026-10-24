<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-157 — sessions persistantes glissantes : `refresh_tokens` devient l'historique des
 * générations d'une session d'appareil (famille). `family_id` regroupe les générations d'une
 * même connexion, `rotated_at` / `replaced_by` tracent la rotation à chaque refresh,
 * `created_at` date l'émission.
 *
 * Purement additif (colonnes nullables, aucune donnée modifiée). Les refresh tokens émis avant
 * cette migration restent valides : `family_id` NULL = leur propre famille, leur premier refresh
 * les fait entrer dans le nouveau régime sans déconnexion.
 *
 * Rollback : `down()` supprime les colonnes ; l'ancien code ignore les lignes déjà tournées
 * (`valid` inchangé) — à lancer seulement après le retour du code précédent.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-157 — refresh_tokens family/rotation columns (sliding device sessions).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_tokens ADD family_id VARCHAR(64) DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL, ADD rotated_at DATETIME DEFAULT NULL, ADD replaced_by VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_REFRESH_TOKENS_FAMILY ON refresh_tokens (family_id)');
        $this->addSql('CREATE INDEX IDX_REFRESH_TOKENS_USERNAME ON refresh_tokens (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_REFRESH_TOKENS_USERNAME ON refresh_tokens');
        $this->addSql('DROP INDEX IDX_REFRESH_TOKENS_FAMILY ON refresh_tokens');
        $this->addSql('ALTER TABLE refresh_tokens DROP family_id, DROP created_at, DROP rotated_at, DROP replaced_by');
    }
}
