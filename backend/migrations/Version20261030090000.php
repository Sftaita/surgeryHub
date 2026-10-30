<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-175 — fiabilisation Web Push.
 *
 *  - push_subscription : état de fiabilité de l'appareil (version du service worker, dernière
 *    synchronisation, dernier envoi accepté, dernier accusé, envois sans accusé, suspicion,
 *    dernière demande de renouvellement) et empreinte du jeton d'appareil (pushsubscriptionchange).
 *  - push_endpoint_invalidation : empreintes des endpoints définitivement morts (404/410 ou
 *    remplacés) — jamais l'endpoint.
 *  - push_delivery : un envoi accepté vers un appareil et ses accusés (empreinte de la clé
 *    d'accusé seulement).
 *
 * Purement additif : colonnes nullables ou à défaut neutre, aucune ligne existante modifiée, aucune
 * clé étrangère vers une table existante (l'historique survit aux désabonnements).
 *
 * Rollback : DROP des deux tables et des colonnes ; aucune donnée métier n'en dépend.
 */
final class Version20261030090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-175 — push_subscription (état de fiabilité, jeton d\'appareil), push_endpoint_invalidation, push_delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE push_subscription
            ADD sw_version VARCHAR(32) DEFAULT NULL,
            ADD last_synced_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD receipts_capable_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD last_accepted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD last_receipt_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD accepted_since_receipt INT DEFAULT 0 NOT NULL,
            ADD first_unacknowledged_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD suspected_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD renewal_requested_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD device_token_hash VARCHAR(64) DEFAULT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_push_subscription_device_token ON push_subscription (device_token_hash)');

        $this->addSql("CREATE TABLE push_endpoint_invalidation (
            id INT AUTO_INCREMENT NOT NULL,
            endpoint_hash VARCHAR(64) NOT NULL,
            user_id INT DEFAULT NULL,
            push_subscription_id INT DEFAULT NULL,
            reason VARCHAR(32) NOT NULL,
            status_code INT DEFAULT NULL,
            provider VARCHAR(20) DEFAULT NULL,
            invalidated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            renewal_requested_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            renewal_request_count INT DEFAULT 0 NOT NULL,
            UNIQUE INDEX uniq_push_endpoint_invalidation_hash (endpoint_hash),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE push_delivery (
            id INT AUTO_INCREMENT NOT NULL,
            outbound_notification_id INT DEFAULT NULL,
            push_subscription_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            provider VARCHAR(20) NOT NULL,
            receipt_key_hash VARCHAR(64) NOT NULL,
            notification_type VARCHAR(64) DEFAULT NULL,
            status_code INT DEFAULT NULL,
            receipts_expected TINYINT(1) DEFAULT 0 NOT NULL,
            accepted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            received_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            display_requested_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            display_failed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            opened_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_push_delivery_receipt (receipt_key_hash),
            INDEX idx_push_delivery_subscription (push_subscription_id),
            INDEX idx_push_delivery_notification (outbound_notification_id),
            INDEX idx_push_delivery_accepted (accepted_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_delivery');
        $this->addSql('DROP TABLE push_endpoint_invalidation');
        $this->addSql('DROP INDEX uniq_push_subscription_device_token ON push_subscription');
        $this->addSql('ALTER TABLE push_subscription DROP sw_version, DROP last_synced_at, DROP receipts_capable_at, DROP last_accepted_at, DROP last_receipt_at, DROP accepted_since_receipt, DROP first_unacknowledged_at, DROP suspected_at, DROP renewal_requested_at, DROP device_token_hash');
    }
}
