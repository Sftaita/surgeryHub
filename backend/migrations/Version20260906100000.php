<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Communication des absences chirurgiens — revue post-déploiement (D-114). Déplace les
 * coordonnées "gestion du bloc" (`blockManagementEmailTo`/`blockManagementEmailCc`) de
 * `absence_communication_site_config` vers `hospital` : ce sont des coordonnées
 * organisationnelles propres à l'établissement (qui prévenir pour le bloc opératoire),
 * jamais des paramètres spécifiques à la communication d'absence — `notifyBlockManagementEnabled`/
 * `blockManagementDelayDays` restent seuls dans la config, qui redevient un pur réglage de
 * comportement (activé/désactivé + délai), jamais un porteur de coordonnées.
 *
 * Additive puis migration de données puis suppression des deux colonnes devenues obsolètes
 * (jamais de perte : copie systématique avant suppression, dans le même `up()`). N'affecte
 * jamais `surgeon_absence_communication`/`surgeon_absence_communication_delivery` — les
 * snapshots déjà journalisés restent immuables et hors du périmètre de cette migration.
 */
final class Version20260906100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace blockManagementEmailTo/Cc de absence_communication_site_config vers hospital (coordonnées établissement, D-114).';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT (JSON_ARRAY()) — expression par défaut, supportée depuis MySQL 8.0.13 — évite
        // le détour "ajouter nullable, backfill, puis durcir en NOT NULL" pour une table déjà
        // peuplée : chaque ligne existante reçoit directement [] au moment de l'ALTER.
        $this->addSql('ALTER TABLE hospital ADD block_management_contact_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE hospital ADD block_management_contact_cc JSON NOT NULL DEFAULT (JSON_ARRAY())');

        // Les établissements ayant déjà une configuration "gestion du bloc" récupèrent leurs
        // coordonnées réelles telles quelles, sans transformation ni perte — les autres
        // gardent le [] posé par le DEFAULT ci-dessus.
        $this->addSql(
            'UPDATE hospital h
             INNER JOIN absence_communication_site_config c ON c.site_id = h.id
             SET h.block_management_contact_email = c.block_management_email_to,
                 h.block_management_contact_cc = c.block_management_email_cc'
        );

        $this->addSql('ALTER TABLE absence_communication_site_config DROP block_management_email_to');
        $this->addSql('ALTER TABLE absence_communication_site_config DROP block_management_email_cc');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence_communication_site_config ADD block_management_email_to VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE absence_communication_site_config ADD block_management_email_cc JSON NOT NULL DEFAULT (JSON_ARRAY())');
        $this->addSql(
            'UPDATE absence_communication_site_config c
             INNER JOIN hospital h ON h.id = c.site_id
             SET c.block_management_email_to = h.block_management_contact_email,
                 c.block_management_email_cc = h.block_management_contact_cc'
        );

        $this->addSql('ALTER TABLE hospital DROP block_management_contact_email');
        $this->addSql('ALTER TABLE hospital DROP block_management_contact_cc');
    }
}
