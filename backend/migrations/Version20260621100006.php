<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621100006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning V2 (Batch 7): notification_preference table — per-user, per-notification-type in-app/email/push channel toggles. Absence of a row means "use hardcoded defaults", so this is purely additive.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE `notification_preference` (
              `id` INT AUTO_INCREMENT NOT NULL,
              `user_id` INT NOT NULL,
              `notification_type` VARCHAR(32) NOT NULL,
              `in_app_enabled` TINYINT(1) NOT NULL DEFAULT 1,
              `email_enabled` TINYINT(1) NOT NULL DEFAULT 1,
              `push_enabled` TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE INDEX uniq_notification_preference_user_type (`user_id`, `notification_type`),
              CONSTRAINT FK_NOTIF_PREF_USER FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `notification_preference`');
    }
}
