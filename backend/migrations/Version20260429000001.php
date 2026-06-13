<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status tracking fields to planning_deployment (Lot 1: deploy modal 2-step)';
    }

    public function up(Schema $schema): void
    {
        // status defaults to DONE for existing rows (already processed before this migration)
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_deployment
                ADD COLUMN status       VARCHAR(16)  NOT NULL DEFAULT 'DONE'
                                        COMMENT 'PENDING|PROCESSING|DONE|FAILED',
                ADD COLUMN started_at   DATETIME     DEFAULT NULL
                                        COMMENT '(DC2Type:datetime_immutable)',
                ADD COLUMN completed_at DATETIME     DEFAULT NULL
                                        COMMENT '(DC2Type:datetime_immutable)',
                ADD COLUMN error_log    LONGTEXT     DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_deployment
                DROP COLUMN status,
                DROP COLUMN started_at,
                DROP COLUMN completed_at,
                DROP COLUMN error_log
        SQL);
    }
}
