<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-152 — incompatibilités chirurgien–instrumentiste : table `surgeon_instrumentist_incompatibility`.
 *
 * Purement additif : aucune donnée existante n'est touchée. « Au plus une incompatibilité
 * ACTIVE par paire » est garanti par MySQL : `active_pair_key` est une colonne générée STORED
 * (= "surgeonId-instrumentistId" tant que lifted_at IS NULL, NULL ensuite) avec un index
 * UNIQUE. Les lignes ne sont jamais supprimées (historique) ; la levée pose lifted_at/by.
 * L'index (surgeon_id, instrumentist_id, lifted_at) sert aussi à la lecture verrouillante
 * d'un claim (sérialisation avec une création concurrente).
 *
 * Le nouveau type d'alerte planning SURGEON_INCOMPATIBILITY (23 caractères) tient dans la
 * colonne VARCHAR(24) existante de planning_alert : aucun ALTER nécessaire.
 *
 * Rollback : DROP TABLE (perd l'historique des incompatibilités ; les AuditEvent restent).
 */
final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-152 — surgeon_instrumentist_incompatibility (history kept, one active per pair).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE surgeon_instrumentist_incompatibility (
            id INT AUTO_INCREMENT NOT NULL,
            surgeon_id INT NOT NULL,
            instrumentist_id INT NOT NULL,
            source_assignment_id INT DEFAULT NULL,
            created_by_id INT DEFAULT NULL,
            lifted_by_id INT DEFAULT NULL,
            origin VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            lifted_at DATETIME DEFAULT NULL,
            active_pair_key VARCHAR(23) GENERATED ALWAYS AS (IF(lifted_at IS NULL, CONCAT(surgeon_id, '-', instrumentist_id), NULL)) STORED,
            INDEX idx_sii_pair (surgeon_id, instrumentist_id, lifted_at),
            INDEX idx_sii_instrumentist (instrumentist_id, lifted_at),
            INDEX IDX_SII_CREATED_BY (created_by_id),
            INDEX IDX_SII_LIFTED_BY (lifted_by_id),
            INDEX IDX_SII_SOURCE_ASSIGNMENT (source_assignment_id),
            UNIQUE INDEX uniq_sii_active_pair (active_pair_key),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE surgeon_instrumentist_incompatibility ADD CONSTRAINT FK_SII_SURGEON FOREIGN KEY (surgeon_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE surgeon_instrumentist_incompatibility ADD CONSTRAINT FK_SII_INSTRUMENTIST FOREIGN KEY (instrumentist_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE surgeon_instrumentist_incompatibility ADD CONSTRAINT FK_SII_SOURCE_ASSIGNMENT FOREIGN KEY (source_assignment_id) REFERENCES mission_assignment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE surgeon_instrumentist_incompatibility ADD CONSTRAINT FK_SII_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE surgeon_instrumentist_incompatibility ADD CONSTRAINT FK_SII_LIFTED_BY FOREIGN KEY (lifted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE surgeon_instrumentist_incompatibility');
    }
}
