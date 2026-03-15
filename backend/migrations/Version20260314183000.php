<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260314183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove duplicate site memberships and enforce unique site/user constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            DELETE sm1
            FROM site_membership sm1
            INNER JOIN site_membership sm2
                ON sm1.user_id = sm2.user_id
               AND sm1.site_id = sm2.site_id
               AND sm1.id > sm2.id
        ');

        $this->addSql('CREATE UNIQUE INDEX uniq_site_user ON site_membership (site_id, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_site_user ON site_membership');
    }
}