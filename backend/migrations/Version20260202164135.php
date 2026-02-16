<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix schema alignment after Firm refactor and Mission.createdBy introduction
 */
final class Version20260202164135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix Firm unique index, align MaterialItem index name, add Mission.created_by safely';
    }

    public function up(Schema $schema): void
    {
        /**
         * 1) material_item index rename (cosmetic, safe)
         */
        $this->addSql(
            'ALTER TABLE material_item 
             RENAME INDEX idx_material_item_firm_id TO IDX_4B73482B89AF7860'
        );

        /**
         * 2) mission.created_by_id
         *   - nullable first
         *   - explicit backfill
         *   - then NOT NULL + FK
         */

        // add nullable column
        $this->addSql(
            'ALTER TABLE mission 
             ADD created_by_id INT DEFAULT NULL'
        );

        /**
         * Backfill strategy (EXPLICITE, DOCUMENTÉE) :
         * - on met created_by = surgeon
         * - hypothèse métier acceptable et traçable
         * - PAS de fallback silencieux
         */
        $this->addSql(
            'UPDATE mission 
             SET created_by_id = surgeon_id 
             WHERE created_by_id IS NULL'
        );

        /**
         * Sécurité : si malgré tout il reste des NULL (missions orphelines),
         * on bloque ici volontairement.
         */
        $this->addSql(
            'ALTER TABLE mission 
             MODIFY created_by_id INT NOT NULL'
        );

        $this->addSql(
            'ALTER TABLE mission 
             ADD CONSTRAINT FK_9067F23CB03A8386 
             FOREIGN KEY (created_by_id) REFERENCES user (id)'
        );

        $this->addSql(
            'CREATE INDEX IDX_9067F23CB03A8386 
             ON mission (created_by_id)'
        );

        /**
         * 3) align NOT NULL / default on existing columns
         */
        $this->addSql(
            "ALTER TABLE mission 
             CHANGE status status VARCHAR(255) DEFAULT 'DRAFT' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CB03A8386');
        $this->addSql('DROP INDEX IDX_9067F23CB03A8386 ON mission');
        $this->addSql('ALTER TABLE mission DROP created_by_id');

        // index rename back (optional)
        $this->addSql(
            'ALTER TABLE material_item 
             RENAME INDEX IDX_4B73482B89AF7860 TO idx_material_item_firm_id'
        );
    }
}
