<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-140 — intégration MedVue : liaison technique compte SurgicalHub ↔ compte MedVue.
 *
 * Purement additif : une nouvelle table, aucune donnée existante touchée (ni `absence`, ni
 * `user`). Les nouvelles valeurs de `user_audit_event.event_type` (MEDVUE_*) tiennent dans la
 * colonne VARCHAR(50) existante : aucun ALTER nécessaire.
 *
 * « Au plus une liaison active » par compte et par linkId, sans index partiel (MySQL) :
 * `active_user_id` / `active_link_id` valent la clé tant que la ligne est active, NULL ensuite ;
 * un index UNIQUE accepte plusieurs NULL. Rollback : DROP TABLE (perd l'historique des liaisons,
 * les traces d'audit restent dans user_audit_event).
 */
final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates medvue_account_link — SurgicalHub ↔ MedVue account link (D-140).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE medvue_account_link (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            medvue_link_id VARCHAR(64) NOT NULL,
            active_user_id INT DEFAULT NULL,
            active_link_id VARCHAR(64) DEFAULT NULL,
            linked_by_id INT DEFAULT NULL,
            linked_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            revoked_by_id INT DEFAULT NULL,
            revoked_via VARCHAR(20) DEFAULT NULL,
            INDEX IDX_MEDVUE_LINK_USER (user_id),
            INDEX IDX_MEDVUE_LINK_LINKED_BY (linked_by_id),
            INDEX IDX_MEDVUE_LINK_REVOKED_BY (revoked_by_id),
            INDEX idx_medvue_link_link_id (medvue_link_id),
            UNIQUE INDEX uniq_medvue_link_active_user (active_user_id),
            UNIQUE INDEX uniq_medvue_link_active_link (active_link_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE medvue_account_link ADD CONSTRAINT FK_MEDVUE_LINK_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE medvue_account_link ADD CONSTRAINT FK_MEDVUE_LINK_LINKED_BY FOREIGN KEY (linked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE medvue_account_link ADD CONSTRAINT FK_MEDVUE_LINK_REVOKED_BY FOREIGN KEY (revoked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE medvue_account_link');
    }
}
