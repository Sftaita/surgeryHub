<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-064: seeds a dedicated "system" User row used as the audit `actor` for Mission
 * mutations that have no human behind them (today: MissionStartDueCommand's
 * ASSIGNED -> IN_PROGRESS auto-transition on startAt). AuditEvent.actor_id is a
 * NOT NULL FK (D-056 requires an actor on every Mission mutation), so a real User
 * row is the least invasive way to satisfy that constraint without loosening the
 * schema for every other caller.
 *
 * password stays NULL (nullable column) so authentication can never succeed for this
 * account; roles stays '[]' so it is invisible to every role-filtered listing.
 */
final class Version20260715064809 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-064: seed the system@surgicalhub.internal technical User row (audit actor for automated Mission transitions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO `user` (email, roles, password, firstname, lastname, active, default_currency, created_at, updated_at) " .
            "SELECT 'system@surgicalhub.internal', '[]', NULL, 'Système', NULL, 0, 'EUR', NOW(), NOW() " .
            "WHERE NOT EXISTS (SELECT 1 FROM `user` WHERE email = 'system@surgicalhub.internal')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `user` WHERE email = 'system@surgicalhub.internal'");
    }
}
