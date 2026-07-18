<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 7 (D-070) — cycle de vie de l'encodage.
 *
 * `ENCODING_IN_PROGRESS` (nouveau MissionStatus) ne nécessite aucune migration :
 * mission.status est un VARCHAR(255) libre (pas un ENUM MySQL natif), la validité des
 * valeurs est garantie côté PHP par le type d'enum, jamais par une contrainte SQL.
 *
 * `submittedAt`/`encodingLockedAt` existaient déjà en base (colonnes jamais renseignées
 * jusqu'ici — voir audit Lot 7) : aucune colonne à ajouter pour elles non plus, seul le
 * code applicatif les renseigne désormais réellement.
 */
final class Version20260717220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 7 — mission.encoding_started_at ; mission_encoding_comment (commentaires manager historisés reject/reopen).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD encoding_started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        $this->addSql('
            CREATE TABLE mission_encoding_comment (
                id INT AUTO_INCREMENT NOT NULL,
                mission_id INT NOT NULL,
                author_id INT NOT NULL,
                comment LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_mission_encoding_comment_mission (mission_id),
                CONSTRAINT fk_mission_encoding_comment_mission FOREIGN KEY (mission_id) REFERENCES mission (id),
                CONSTRAINT fk_mission_encoding_comment_author FOREIGN KEY (author_id) REFERENCES user (id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mission_encoding_comment');
        $this->addSql('ALTER TABLE mission DROP encoding_started_at');
    }
}
