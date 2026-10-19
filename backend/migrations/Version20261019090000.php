<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-156 — confirmation ciblée des affectations multi-salles d'un brouillon Planning V2.
 *
 * Purement additif : nouvelle table `planning_multi_room_confirmation`, vide à la création.
 * Une ligne = un manager a confirmé (ou décidé lui-même dans l'éditeur) qu'une instrumentiste
 * couvre un groupe de missions BLOC simultanées du même chirurgien, même site, même jour, dans
 * un brouillon donné ; l'empreinte couvre la composition et les horaires du groupe.
 * Supprimée avec son brouillon (ON DELETE CASCADE). Aucune mission ni version existante n'est
 * modifiée ; les brouillons existants au déploiement devront simplement confirmer leurs
 * regroupements avant d'être déployés.
 *
 * Rollback : DROP TABLE (perd seulement les confirmations des brouillons non déployés).
 */
final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-156 — planning_multi_room_confirmation (confirmations multi-salles des brouillons, additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE planning_multi_room_confirmation (id INT AUTO_INCREMENT NOT NULL, planning_version_id INT NOT NULL, confirmed_by_id INT NOT NULL, fingerprint VARCHAR(64) NOT NULL, instrumentist_id INT NOT NULL, service_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', mission_ids JSON NOT NULL, source VARCHAR(24) NOT NULL, confirmed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_E39EC4F4D21D2665 (planning_version_id), INDEX IDX_E39EC4F46F45385D (confirmed_by_id), UNIQUE INDEX uniq_pmrc_version_fingerprint (planning_version_id, fingerprint), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE planning_multi_room_confirmation ADD CONSTRAINT FK_E39EC4F4D21D2665 FOREIGN KEY (planning_version_id) REFERENCES planning_version (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE planning_multi_room_confirmation ADD CONSTRAINT FK_E39EC4F46F45385D FOREIGN KEY (confirmed_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_multi_room_confirmation DROP FOREIGN KEY FK_E39EC4F4D21D2665');
        $this->addSql('ALTER TABLE planning_multi_room_confirmation DROP FOREIGN KEY FK_E39EC4F46F45385D');
        $this->addSql('DROP TABLE planning_multi_room_confirmation');
    }
}
