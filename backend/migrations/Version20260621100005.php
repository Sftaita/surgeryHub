<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 6): ShiftPeriodConfig.active (deactivate instead of hard delete) — relax the absolute (site,period) unique constraint so a deactivated period can coexist with a new active one for the same site+period; uniqueness among ACTIVE rows is now enforced at the application layer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `shift_period_config` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1");
        // uniq_site_period(site_id, period) also backs FK_SPC_SITE (site_id) — add a plain
        // index first so MySQL has something else to support the FK before dropping it.
        $this->addSql("CREATE INDEX idx_shift_period_config_site ON `shift_period_config` (`site_id`)");
        $this->addSql("DROP INDEX uniq_site_period ON `shift_period_config`");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("CREATE UNIQUE INDEX uniq_site_period ON `shift_period_config` (`site_id`, `period`)");
        $this->addSql("DROP INDEX idx_shift_period_config_site ON `shift_period_config`");
        $this->addSql("ALTER TABLE `shift_period_config` DROP COLUMN `active`");
    }
}
