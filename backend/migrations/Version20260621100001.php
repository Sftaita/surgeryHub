<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 1): seed default ShiftPeriodConfig (MATIN/APRES_MIDI/JOURNEE) for every existing site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO `shift_period_config` (`site_id`, `period`, `start_time`, `end_time`)
            SELECT `id`, 'MATIN', '08:00:00', '13:00:00' FROM `hospital`
        ");

        $this->addSql("
            INSERT INTO `shift_period_config` (`site_id`, `period`, `start_time`, `end_time`)
            SELECT `id`, 'APRES_MIDI', '13:00:00', '18:00:00' FROM `hospital`
        ");

        $this->addSql("
            INSERT INTO `shift_period_config` (`site_id`, `period`, `start_time`, `end_time`)
            SELECT `id`, 'JOURNEE', '08:00:00', '18:00:00' FROM `hospital`
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE FROM `shift_period_config`
            WHERE (`period`, `start_time`, `end_time`) IN (
                ('MATIN', '08:00:00', '13:00:00'),
                ('APRES_MIDI', '13:00:00', '18:00:00'),
                ('JOURNEE', '08:00:00', '18:00:00')
            )
        ");
    }
}
