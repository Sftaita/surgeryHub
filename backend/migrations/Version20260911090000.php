<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-115bis — lifts the "reopening a site-group/all-sites draft is not supported" limitation.
 *
 * `planning_version.site` alone cannot distinguish a real multi-site scope from the V1
 * "no site filter" bucket, and reconstructing the scope from the DRAFT's own persisted
 * Missions (DISTINCT site_id) is unsafe as an ongoing mechanism: (1) a group site with zero
 * occurrences that month would silently drop out of the scope, and (2) SiteGroupMembership
 * is mutable — resolving live at reopen time would let a later group-composition change
 * retroactively alter an old draft's scope, violating the historical-stability requirement.
 *
 * Two purely additive, nullable columns:
 * - `site_group_id` — informational only (which SiteGroup this draft names, for display —
 *   e.g. "Groupe : Bloc Ouest" instead of the generic "Tous sites" fallback). ON DELETE
 *   SET NULL: deleting a SiteGroup later must never break a historical draft.
 * - `scope_site_ids` — the actual source of truth for reopen(): a JSON snapshot of the
 *   exact Hospital ids in scope at generate() time, frozen forever regardless of later
 *   SiteGroupMembership changes.
 *
 * Backfill, scoped to exactly the versions this fix can affect (DRAFT + site IS NULL —
 * ACTIVE/ARCHIVED group-scoped versions are never reopened through this path and are left
 * untouched): reconstructs `scope_site_ids` from each draft's own persisted Mission.site
 * (DISTINCT). This is the same reconstruction ruled out above as an *ongoing* mechanism —
 * but for a one-time backfill of already-existing drafts it is the best information
 * actually available (their real SiteGroupMembership at generation time was never
 * captured), and it is materially better than the current state (reopening blocked
 * outright). `site_group_id` is NOT backfilled — which original group produced these rows
 * is genuinely unrecoverable; the display falls back to the existing generic label, but
 * reopen() itself works correctly via `scope_site_ids`.
 *
 * The one residual, explicitly accepted edge case: a pre-existing group DRAFT with zero
 * persisted Missions (everything SKIPPED) has no Mission row to reconstruct from — the
 * backfill UPDATE naturally leaves `scope_site_ids` NULL for it, and
 * PlanningDraftService::reopen() keeps the same 400 refusal for that one case, now correctly
 * scoped to "nothing to reconstruct" instead of "every group draft".
 */
final class Version20260911090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-115bis — planning_version.site_group_id + scope_site_ids, with backfill for existing DRAFT group-scoped versions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_version ADD site_group_id INT DEFAULT NULL, ADD scope_site_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_version ADD CONSTRAINT FK_PLANNING_VERSION_SITE_GROUP FOREIGN KEY (site_group_id) REFERENCES site_group (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PLANNING_VERSION_SITE_GROUP ON planning_version (site_group_id)');

        $this->addSql(<<<'SQL'
            UPDATE planning_version pv
            SET scope_site_ids = (
                SELECT JSON_ARRAYAGG(t.site_id)
                FROM (
                    SELECT DISTINCT m.site_id AS site_id
                    FROM mission m
                    WHERE m.planning_version_id = pv.id
                ) t
            )
            WHERE pv.site_id IS NULL AND pv.status = 'DRAFT'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_version DROP FOREIGN KEY FK_PLANNING_VERSION_SITE_GROUP');
        $this->addSql('DROP INDEX IDX_PLANNING_VERSION_SITE_GROUP ON planning_version');
        $this->addSql('ALTER TABLE planning_version DROP site_group_id, DROP scope_site_ids');
    }
}
