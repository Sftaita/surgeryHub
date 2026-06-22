<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619145211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute refresh_tokens.remember_me pour la durée de session "Se souvenir de moi"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_tokens ADD remember_me TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_tokens DROP remember_me');
    }
}
