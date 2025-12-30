<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251230060706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE export_log (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, output_type VARCHAR(50) NOT NULL, filters JSON DEFAULT NULL, event_type VARCHAR(100) NOT NULL, success TINYINT(1) DEFAULT 1 NOT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_E7392FF5A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE hospital (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) DEFAULT NULL, timezone VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE implant_sub_mission (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, firm_name VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8D69D1E0BE6CAE90 (mission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE instrumentist_rating (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, mission_id INT NOT NULL, surgeon_user_id INT NOT NULL, instrumentist_user_id INT NOT NULL, sterility_respect SMALLINT NOT NULL, equipment_knowledge SMALLINT NOT NULL, attitude SMALLINT NOT NULL, punctuality SMALLINT NOT NULL, comment LONGTEXT DEFAULT NULL, is_first_collaboration TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A9A00142F6BD1646 (site_id), INDEX IDX_A9A00142BE6CAE90 (mission_id), INDEX IDX_A9A00142B96F062 (surgeon_user_id), INDEX IDX_A9A00142EAAD9BEA (instrumentist_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE instrumentist_service (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, service_type VARCHAR(255) NOT NULL, employment_type_snapshot VARCHAR(255) NOT NULL, hours NUMERIC(5, 2) DEFAULT NULL, consultation_fee_applied NUMERIC(10, 2) DEFAULT NULL, hours_source VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, computed_amount NUMERIC(10, 2) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AC5ED2ADBE6CAE90 (mission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE material_item (id INT AUTO_INCREMENT NOT NULL, manufacturer VARCHAR(255) DEFAULT NULL, reference_code VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, unit VARCHAR(50) NOT NULL, is_implant TINYINT(1) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE material_line (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, mission_intervention_id INT DEFAULT NULL, mission_intervention_firm_id INT DEFAULT NULL, item_id INT NOT NULL, created_by_id INT NOT NULL, implant_sub_mission_id INT DEFAULT NULL, quantity NUMERIC(10, 2) NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_857CD9C3BE6CAE90 (mission_id), INDEX IDX_857CD9C3B86F7406 (mission_intervention_id), INDEX IDX_857CD9C35B207EBF (mission_intervention_firm_id), INDEX IDX_857CD9C3126F525E (item_id), INDEX IDX_857CD9C3B03A8386 (created_by_id), INDEX IDX_857CD9C363EE38BF (implant_sub_mission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mission (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, surgeon_id INT NOT NULL, instrumentist_id INT DEFAULT NULL, created_by_id INT NOT NULL, start_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', schedule_precision VARCHAR(255) DEFAULT \'EXACT\' NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) DEFAULT \'DRAFT\' NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_9067F23CF6BD1646 (site_id), INDEX IDX_9067F23CE506D696 (surgeon_id), INDEX IDX_9067F23CFF41632 (instrumentist_id), INDEX IDX_9067F23CB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mission_claim (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, instrumentist_id INT NOT NULL, claimed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_37FF811ABE6CAE90 (mission_id), INDEX IDX_37FF811AFF41632 (instrumentist_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mission_intervention (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, order_index SMALLINT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_672590FBE6CAE90 (mission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mission_intervention_firm (id INT AUTO_INCREMENT NOT NULL, mission_intervention_id INT NOT NULL, firm_name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3E5A3DA7B86F7406 (mission_intervention_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mission_publication (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, target_instrumentist_id INT DEFAULT NULL, scope VARCHAR(255) NOT NULL, channel VARCHAR(255) NOT NULL, published_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_C8A32D98BE6CAE90 (mission_id), INDEX IDX_C8A32D98DB0D2832 (target_instrumentist_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE notification_event (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, mission_id INT DEFAULT NULL, event_type VARCHAR(100) NOT NULL, channel VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, payload JSON DEFAULT NULL, sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', failed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_FD1AEF5EA76ED395 (user_id), INDEX IDX_FD1AEF5EBE6CAE90 (mission_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, endpoint VARCHAR(500) NOT NULL, public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL, content_encoding VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_562830F3A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE service_hours_dispute (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, service_id INT NOT NULL, raised_by_id INT NOT NULL, reason_code VARCHAR(255) NOT NULL, comment LONGTEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, resolution_comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_48E23C5DBE6CAE90 (mission_id), INDEX IDX_48E23C5DED5CA9E6 (service_id), INDEX IDX_48E23C5DB0CDEB44 (raised_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE site_membership (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, user_id INT NOT NULL, site_role VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A6F65D40F6BD1646 (site_id), INDEX IDX_A6F65D40A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE surgeon_rating_by_instrumentist (id INT AUTO_INCREMENT NOT NULL, mission_id INT NOT NULL, surgeon_user_id INT NOT NULL, instrumentist_user_id INT NOT NULL, cordiality SMALLINT NOT NULL, punctuality SMALLINT NOT NULL, mission_respect SMALLINT NOT NULL, comment LONGTEXT DEFAULT NULL, is_first_collaboration TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_510706E0BE6CAE90 (mission_id), INDEX IDX_510706E0B96F062 (surgeon_user_id), INDEX IDX_510706E0EAAD9BEA (instrumentist_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE weekly_template (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, surgeon_id INT NOT NULL, default_instrumentist_id INT DEFAULT NULL, day_of_week SMALLINT NOT NULL, start_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', end_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', mission_type VARCHAR(255) NOT NULL, schedule_precision VARCHAR(255) DEFAULT \'EXACT\' NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7D772BEF6BD1646 (site_id), INDEX IDX_7D772BEE506D696 (surgeon_id), INDEX IDX_7D772BE8009DE65 (default_instrumentist_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE export_log ADD CONSTRAINT FK_E7392FF5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE implant_sub_mission ADD CONSTRAINT FK_8D69D1E0BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE instrumentist_rating ADD CONSTRAINT FK_A9A00142F6BD1646 FOREIGN KEY (site_id) REFERENCES hospital (id)');
        $this->addSql('ALTER TABLE instrumentist_rating ADD CONSTRAINT FK_A9A00142BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE instrumentist_rating ADD CONSTRAINT FK_A9A00142B96F062 FOREIGN KEY (surgeon_user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE instrumentist_rating ADD CONSTRAINT FK_A9A00142EAAD9BEA FOREIGN KEY (instrumentist_user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE instrumentist_service ADD CONSTRAINT FK_AC5ED2ADBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT FK_857CD9C3BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT FK_857CD9C3B86F7406 FOREIGN KEY (mission_intervention_id) REFERENCES mission_intervention (id)');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT FK_857CD9C35B207EBF FOREIGN KEY (mission_intervention_firm_id) REFERENCES mission_intervention_firm (id)');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT FK_857CD9C3126F525E FOREIGN KEY (item_id) REFERENCES material_item (id)');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT FK_857CD9C3B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE material_line ADD CONSTRAINT FK_857CD9C363EE38BF FOREIGN KEY (implant_sub_mission_id) REFERENCES implant_sub_mission (id)');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CF6BD1646 FOREIGN KEY (site_id) REFERENCES hospital (id)');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CE506D696 FOREIGN KEY (surgeon_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CFF41632 FOREIGN KEY (instrumentist_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE mission_claim ADD CONSTRAINT FK_37FF811ABE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_claim ADD CONSTRAINT FK_37FF811AFF41632 FOREIGN KEY (instrumentist_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE mission_intervention ADD CONSTRAINT FK_672590FBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_intervention_firm ADD CONSTRAINT FK_3E5A3DA7B86F7406 FOREIGN KEY (mission_intervention_id) REFERENCES mission_intervention (id)');
        $this->addSql('ALTER TABLE mission_publication ADD CONSTRAINT FK_C8A32D98BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_publication ADD CONSTRAINT FK_C8A32D98DB0D2832 FOREIGN KEY (target_instrumentist_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE notification_event ADD CONSTRAINT FK_FD1AEF5EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE notification_event ADD CONSTRAINT FK_FD1AEF5EBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE service_hours_dispute ADD CONSTRAINT FK_48E23C5DBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE service_hours_dispute ADD CONSTRAINT FK_48E23C5DED5CA9E6 FOREIGN KEY (service_id) REFERENCES instrumentist_service (id)');
        $this->addSql('ALTER TABLE service_hours_dispute ADD CONSTRAINT FK_48E23C5DB0CDEB44 FOREIGN KEY (raised_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE site_membership ADD CONSTRAINT FK_A6F65D40F6BD1646 FOREIGN KEY (site_id) REFERENCES hospital (id)');
        $this->addSql('ALTER TABLE site_membership ADD CONSTRAINT FK_A6F65D40A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE surgeon_rating_by_instrumentist ADD CONSTRAINT FK_510706E0BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE surgeon_rating_by_instrumentist ADD CONSTRAINT FK_510706E0B96F062 FOREIGN KEY (surgeon_user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE surgeon_rating_by_instrumentist ADD CONSTRAINT FK_510706E0EAAD9BEA FOREIGN KEY (instrumentist_user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE weekly_template ADD CONSTRAINT FK_7D772BEF6BD1646 FOREIGN KEY (site_id) REFERENCES hospital (id)');
        $this->addSql('ALTER TABLE weekly_template ADD CONSTRAINT FK_7D772BEE506D696 FOREIGN KEY (surgeon_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE weekly_template ADD CONSTRAINT FK_7D772BE8009DE65 FOREIGN KEY (default_instrumentist_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE user ADD active TINYINT(1) DEFAULT 1 NOT NULL, ADD employment_type VARCHAR(255) DEFAULT NULL, ADD hourly_rate NUMERIC(10, 2) DEFAULT NULL, ADD consultation_fee NUMERIC(10, 2) DEFAULT NULL, ADD default_currency VARCHAR(3) DEFAULT \'EUR\' NOT NULL, ADD created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE password password VARCHAR(255) DEFAULT NULL, CHANGE firstname firstname VARCHAR(255) DEFAULT NULL, CHANGE lastname lastname VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE export_log DROP FOREIGN KEY FK_E7392FF5A76ED395');
        $this->addSql('ALTER TABLE implant_sub_mission DROP FOREIGN KEY FK_8D69D1E0BE6CAE90');
        $this->addSql('ALTER TABLE instrumentist_rating DROP FOREIGN KEY FK_A9A00142F6BD1646');
        $this->addSql('ALTER TABLE instrumentist_rating DROP FOREIGN KEY FK_A9A00142BE6CAE90');
        $this->addSql('ALTER TABLE instrumentist_rating DROP FOREIGN KEY FK_A9A00142B96F062');
        $this->addSql('ALTER TABLE instrumentist_rating DROP FOREIGN KEY FK_A9A00142EAAD9BEA');
        $this->addSql('ALTER TABLE instrumentist_service DROP FOREIGN KEY FK_AC5ED2ADBE6CAE90');
        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY FK_857CD9C3BE6CAE90');
        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY FK_857CD9C3B86F7406');
        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY FK_857CD9C35B207EBF');
        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY FK_857CD9C3126F525E');
        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY FK_857CD9C3B03A8386');
        $this->addSql('ALTER TABLE material_line DROP FOREIGN KEY FK_857CD9C363EE38BF');
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CF6BD1646');
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CE506D696');
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CFF41632');
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CB03A8386');
        $this->addSql('ALTER TABLE mission_claim DROP FOREIGN KEY FK_37FF811ABE6CAE90');
        $this->addSql('ALTER TABLE mission_claim DROP FOREIGN KEY FK_37FF811AFF41632');
        $this->addSql('ALTER TABLE mission_intervention DROP FOREIGN KEY FK_672590FBE6CAE90');
        $this->addSql('ALTER TABLE mission_intervention_firm DROP FOREIGN KEY FK_3E5A3DA7B86F7406');
        $this->addSql('ALTER TABLE mission_publication DROP FOREIGN KEY FK_C8A32D98BE6CAE90');
        $this->addSql('ALTER TABLE mission_publication DROP FOREIGN KEY FK_C8A32D98DB0D2832');
        $this->addSql('ALTER TABLE notification_event DROP FOREIGN KEY FK_FD1AEF5EA76ED395');
        $this->addSql('ALTER TABLE notification_event DROP FOREIGN KEY FK_FD1AEF5EBE6CAE90');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('ALTER TABLE service_hours_dispute DROP FOREIGN KEY FK_48E23C5DBE6CAE90');
        $this->addSql('ALTER TABLE service_hours_dispute DROP FOREIGN KEY FK_48E23C5DED5CA9E6');
        $this->addSql('ALTER TABLE service_hours_dispute DROP FOREIGN KEY FK_48E23C5DB0CDEB44');
        $this->addSql('ALTER TABLE site_membership DROP FOREIGN KEY FK_A6F65D40F6BD1646');
        $this->addSql('ALTER TABLE site_membership DROP FOREIGN KEY FK_A6F65D40A76ED395');
        $this->addSql('ALTER TABLE surgeon_rating_by_instrumentist DROP FOREIGN KEY FK_510706E0BE6CAE90');
        $this->addSql('ALTER TABLE surgeon_rating_by_instrumentist DROP FOREIGN KEY FK_510706E0B96F062');
        $this->addSql('ALTER TABLE surgeon_rating_by_instrumentist DROP FOREIGN KEY FK_510706E0EAAD9BEA');
        $this->addSql('ALTER TABLE weekly_template DROP FOREIGN KEY FK_7D772BEF6BD1646');
        $this->addSql('ALTER TABLE weekly_template DROP FOREIGN KEY FK_7D772BEE506D696');
        $this->addSql('ALTER TABLE weekly_template DROP FOREIGN KEY FK_7D772BE8009DE65');
        $this->addSql('DROP TABLE export_log');
        $this->addSql('DROP TABLE hospital');
        $this->addSql('DROP TABLE implant_sub_mission');
        $this->addSql('DROP TABLE instrumentist_rating');
        $this->addSql('DROP TABLE instrumentist_service');
        $this->addSql('DROP TABLE material_item');
        $this->addSql('DROP TABLE material_line');
        $this->addSql('DROP TABLE mission');
        $this->addSql('DROP TABLE mission_claim');
        $this->addSql('DROP TABLE mission_intervention');
        $this->addSql('DROP TABLE mission_intervention_firm');
        $this->addSql('DROP TABLE mission_publication');
        $this->addSql('DROP TABLE notification_event');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('DROP TABLE service_hours_dispute');
        $this->addSql('DROP TABLE site_membership');
        $this->addSql('DROP TABLE surgeon_rating_by_instrumentist');
        $this->addSql('DROP TABLE weekly_template');
        $this->addSql('ALTER TABLE user DROP active, DROP employment_type, DROP hourly_rate, DROP consultation_fee, DROP default_currency, DROP created_at, DROP updated_at, CHANGE password password VARCHAR(255) NOT NULL, CHANGE firstname firstname VARCHAR(255) NOT NULL, CHANGE lastname lastname VARCHAR(255) NOT NULL');
    }
}
