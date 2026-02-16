<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix mission_claim: replace UNIQUE index on mission_id by normal index (requires FK drop/recreate).';
    }

    public function up(Schema $schema): void
    {
        // MySQL: FK depends on an index on mission_id, so we must drop/recreate the FK around index change.
        $this->addSql('ALTER TABLE mission_claim DROP FOREIGN KEY FK_37FF811ABE6CAE90');
        $this->addSql('ALTER TABLE mission_claim DROP INDEX UNIQ_37FF811ABE6CAE90');
        $this->addSql('CREATE INDEX IDX_37FF811ABE6CAE90 ON mission_claim (mission_id)');
        $this->addSql('ALTER TABLE mission_claim ADD CONSTRAINT FK_37FF811ABE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_claim DROP FOREIGN KEY FK_37FF811ABE6CAE90');
        $this->addSql('DROP INDEX IDX_37FF811ABE6CAE90 ON mission_claim');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_37FF811ABE6CAE90 ON mission_claim (mission_id)');
        $this->addSql('ALTER TABLE mission_claim ADD CONSTRAINT FK_37FF811ABE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
    }
}
