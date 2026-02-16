<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260202103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Introduce Firm reference entity; link MaterialItem to Firm; remove MissionInterventionFirm and related columns.';
    }

    public function up(Schema $schema): void
    {
        // 1) firm reference table
        $this->addSql("
            CREATE TABLE firm (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_firm_name (name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        // 2) backfill firm rows from legacy material_item.manufacturer
        $this->addSql("
            INSERT INTO firm (name, active, created_at, updated_at)
            SELECT DISTINCT TRIM(mi.manufacturer) AS name, 1 AS active, NOW(), NOW()
            FROM material_item mi
            WHERE mi.manufacturer IS NOT NULL AND TRIM(mi.manufacturer) <> ''
        ");

        // 3) add firm_id to material_item
        $this->addSql("ALTER TABLE material_item ADD firm_id INT DEFAULT NULL");
        $this->addSql("CREATE INDEX IDX_MATERIAL_ITEM_FIRM_ID ON material_item (firm_id)");

        // 4) backfill firm_id using name match (strict)
        $this->addSql("
            UPDATE material_item mi
            LEFT JOIN firm f ON f.name = TRIM(mi.manufacturer)
            SET mi.firm_id = f.id
            WHERE mi.manufacturer IS NOT NULL AND TRIM(mi.manufacturer) <> ''
        ");

        $this->addSql("ALTER TABLE material_item ADD CONSTRAINT FK_MATERIAL_ITEM_FIRM_ID FOREIGN KEY (firm_id) REFERENCES firm (id)");

        // 5) drop legacy manufacturer column (once firm_id is filled)
        $this->addSql("ALTER TABLE material_item DROP manufacturer");

        // 6) remove MissionInterventionFirm usage in schema (drop FKs/columns if they exist)
        $this->dropForeignKeyIfExists('material_line', 'mission_intervention_firm_id');
        $this->dropForeignKeyIfExists('material_item_request', 'mission_intervention_firm_id');

        $this->dropColumnIfExists('material_line', 'mission_intervention_firm_id');
        $this->dropColumnIfExists('material_item_request', 'mission_intervention_firm_id');

        // 7) drop legacy table
        $this->addSql("DROP TABLE IF EXISTS mission_intervention_firm");
    }

    public function down(Schema $schema): void
    {
        // Down is best-effort (do not recreate legacy mission_intervention_firm model)

        $this->addSql("ALTER TABLE material_item ADD manufacturer VARCHAR(255) DEFAULT NULL");

        $this->addSql("
            UPDATE material_item mi
            LEFT JOIN firm f ON f.id = mi.firm_id
            SET mi.manufacturer = f.name
        ");

        $this->addSql("ALTER TABLE material_item DROP FOREIGN KEY FK_MATERIAL_ITEM_FIRM_ID");
        $this->addSql("DROP INDEX IDX_MATERIAL_ITEM_FIRM_ID ON material_item");
        $this->addSql("ALTER TABLE material_item DROP firm_id");

        $this->addSql("DROP TABLE firm");
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        if ($this->connection->getDatabasePlatform()->getName() !== 'mysql') {
            return;
        }

        $fk = $this->connection->fetchOne(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            ['t' => $table, 'c' => $column]
        );

        if (is_string($fk) && $fk !== '') {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $fk));
        }
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if ($this->connection->getDatabasePlatform()->getName() !== 'mysql') {
            return;
        }

        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c",
            ['t' => $table, 'c' => $column]
        );

        if ($exists > 0) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
        }
    }
}
