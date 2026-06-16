<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_audit_event table (admin module — audit trail for user management actions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_audit_event (
                id           INT AUTO_INCREMENT NOT NULL,
                actor_id     INT NOT NULL,
                target_user_id INT DEFAULT NULL,
                event_type   VARCHAR(50) NOT NULL,
                description  VARCHAR(500) NOT NULL,
                payload      LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
                created_at   DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_user_audit_actor      (actor_id),
                INDEX idx_user_audit_target     (target_user_id),
                INDEX idx_user_audit_created_at (created_at),
                INDEX idx_user_audit_event_type (event_type),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE user_audit_event
                ADD CONSTRAINT FK_UAE_actor
                    FOREIGN KEY (actor_id) REFERENCES `user` (id),
                ADD CONSTRAINT FK_UAE_target
                    FOREIGN KEY (target_user_id) REFERENCES `user` (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_audit_event DROP FOREIGN KEY FK_UAE_actor');
        $this->addSql('ALTER TABLE user_audit_event DROP FOREIGN KEY FK_UAE_target');
        $this->addSql('DROP TABLE user_audit_event');
    }
}
