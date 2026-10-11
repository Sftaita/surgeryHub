<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * H5a — every remaining business email through NotificationDispatcher.
 *
 * Purely additive:
 *   - generated_document: PDFs rendered once (planning personal / global), referenced by id +
 *     sha256 from the queued email message and the delivery record — never bytes there;
 *   - outbound_notification.recipient_address: the address an email was really handed to
 *     (an email change notifies the PREVIOUS address).
 * No existing row is modified; nothing is backfilled.
 */
final class Version20261006100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates generated_document and adds outbound_notification.recipient_address (H5a).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE generated_document (
                id INT AUTO_INCREMENT NOT NULL,
                document_type VARCHAR(40) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                sha256 CHAR(64) NOT NULL,
                byte_size INT NOT NULL,
                content_type VARCHAR(100) NOT NULL,
                content LONGBLOB NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_generated_document_sha256 (sha256),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE outbound_notification ADD recipient_address VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbound_notification DROP recipient_address');
        $this->addSql('DROP TABLE generated_document');
    }
}
