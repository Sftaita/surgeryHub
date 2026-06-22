<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 3): PlanningAlert.absence FK becomes ON DELETE SET NULL — a hard-deleted Absence must never block or cascade-delete PlanningAlert history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `planning_alert` DROP FOREIGN KEY FK_PA_ABSENCE');
        $this->addSql('ALTER TABLE `planning_alert` ADD CONSTRAINT FK_PA_ABSENCE FOREIGN KEY (`absence_id`) REFERENCES `absence` (`id`) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `planning_alert` DROP FOREIGN KEY FK_PA_ABSENCE');
        $this->addSql('ALTER TABLE `planning_alert` ADD CONSTRAINT FK_PA_ABSENCE FOREIGN KEY (`absence_id`) REFERENCES `absence` (`id`)');
    }
}
