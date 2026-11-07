<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * H5b (D-132) — the common low-level email delivery layer and the generated documents' nature.
 *
 * Additive / relaxing only:
 *   - outbound_notification.recipient_user_id becomes NULLABLE (a delivery to a non-user address:
 *     firm billing, D-114 block management) + recipient_label (readable, never the address);
 *     existing rows keep their user;
 *   - generated_document: retention_class (default IMMUTABLE_EVIDENCE — every existing document
 *     is a planning PDF really sent), source_key + input_fingerprint (reuse of a frozen document);
 *   - surgeon_absence_communication_delivery.outbound_notification_id (FK SET NULL): the D-114
 *     delivery's record on the common layer.
 * No row is rewritten.
 */
final class Version20261007100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Common low-level email delivery (nullable recipient, label), generated_document retention/source, D-114 delivery link (H5b).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbound_notification MODIFY recipient_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE outbound_notification ADD recipient_label VARCHAR(120) DEFAULT NULL');

        $this->addSql("ALTER TABLE generated_document ADD retention_class VARCHAR(32) DEFAULT 'IMMUTABLE_EVIDENCE' NOT NULL, ADD source_key VARCHAR(120) DEFAULT NULL, ADD input_fingerprint CHAR(64) DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_generated_document_source ON generated_document (source_key, input_fingerprint)');

        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery ADD outbound_notification_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery ADD CONSTRAINT fk_absence_comm_delivery_outbound FOREIGN KEY (outbound_notification_id) REFERENCES outbound_notification (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_absence_comm_delivery_outbound ON surgeon_absence_communication_delivery (outbound_notification_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery DROP FOREIGN KEY fk_absence_comm_delivery_outbound');
        $this->addSql('DROP INDEX idx_absence_comm_delivery_outbound ON surgeon_absence_communication_delivery');
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery DROP outbound_notification_id');
        $this->addSql('DROP INDEX idx_generated_document_source ON generated_document');
        $this->addSql('ALTER TABLE generated_document DROP retention_class, DROP source_key, DROP input_fingerprint');
        $this->addSql('ALTER TABLE outbound_notification DROP recipient_label');
        // Restoring NOT NULL requires that no delivery to a non-user address exists any more.
        $this->addSql('ALTER TABLE outbound_notification MODIFY recipient_user_id INT NOT NULL');
    }
}
