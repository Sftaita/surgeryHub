<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add country/representative/phone to firm; add implant_type/material/description to material_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE firm ADD COLUMN country VARCHAR(100) NULL AFTER billing_email_cc");
        $this->addSql("ALTER TABLE firm ADD COLUMN representative VARCHAR(255) NULL AFTER country");
        $this->addSql("ALTER TABLE firm ADD COLUMN phone VARCHAR(30) NULL AFTER representative");

        $this->addSql("ALTER TABLE material_item ADD COLUMN implant_type VARCHAR(255) NULL AFTER is_implant");
        $this->addSql("ALTER TABLE material_item ADD COLUMN material VARCHAR(255) NULL AFTER implant_type");
        $this->addSql("ALTER TABLE material_item ADD COLUMN description TEXT NULL AFTER material");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE firm DROP COLUMN country, DROP COLUMN representative, DROP COLUMN phone");
        $this->addSql("ALTER TABLE material_item DROP COLUMN implant_type, DROP COLUMN material, DROP COLUMN description");
    }
}
