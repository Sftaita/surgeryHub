<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PlanningVersion entity and Mission.planning_version_id FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE planning_version (
                id              INT AUTO_INCREMENT NOT NULL,
                site_id         INT DEFAULT NULL,
                generated_by_id INT NOT NULL,
                period_start    DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                period_end      DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                version_number  INT NOT NULL DEFAULT 1,
                status          VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
                generated_at    DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                deployed_at     DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                archived_at     DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                summary_json    LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
                PRIMARY KEY (id),
                CONSTRAINT FK_PLANNING_VERSION_SITE    FOREIGN KEY (site_id)         REFERENCES hospital (id),
                CONSTRAINT FK_PLANNING_VERSION_USER    FOREIGN KEY (generated_by_id) REFERENCES user (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE mission
                ADD COLUMN planning_version_id INT DEFAULT NULL,
                ADD CONSTRAINT FK_MISSION_PLANNING_VERSION
                    FOREIGN KEY (planning_version_id) REFERENCES planning_version (id)
        SQL);

        $this->addSql('CREATE INDEX IDX_MISSION_PLANNING_VERSION ON mission (planning_version_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_MISSION_PLANNING_VERSION');
        $this->addSql('ALTER TABLE mission DROP INDEX IDX_MISSION_PLANNING_VERSION');
        $this->addSql('ALTER TABLE mission DROP COLUMN planning_version_id');
        $this->addSql('DROP TABLE planning_version');
    }
}
