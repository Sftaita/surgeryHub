<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-115bis follow-up — explicit provenance for `planning_version.scope_site_ids`.
 *
 * A production audit (read-only, before this migration was written) of the only two real
 * multi-site DRAFTs in prod found their backfilled scope ({1,3}) matches the only SiteGroup
 * in the system and its current membership — but could not certify it: a site with zero
 * persisted Missions that month (e.g. every occurrence SKIPPED — see the exact scenario
 * PlanningV2DraftControllerTest::test_group_scoped_draft_keeps_a_site_with_only_skipped_occurrences_in_its_scope_snapshot
 * exercises for a *new* draft) would be silently missing from a Mission-reconstructed
 * scope, and nothing in this system persists the group actually requested at generate()
 * time for a draft created before Version20260911090000. Reconstruction from Missions is
 * therefore never promoted to "certain" on its own.
 *
 * `scope_source` (nullable, VARCHAR — PlanningVersionScopeSource) makes this explicit:
 * - NULL: single-site draft, or not yet touched by this migration (no group-scoped DRAFT
 *   with a backfilled scope_site_ids to mark).
 * - SNAPSHOT: captured live at generate() time (every draft created after this migration's
 *   companion code change) — always certain.
 * - RECONSTRUCTED: inferred after the fact from persisted Missions (this migration's own
 *   backfill, or PlanningDraftService's lazy self-heal for a draft the backfill missed) —
 *   never certain. PlanningDraftService::assertScopeConfirmed() blocks update()/deploy() on
 *   any such draft until a manager reviews and confirms it via confirmScope().
 * - CONFIRMED: a RECONSTRUCTED scope a manager has explicitly reviewed/corrected.
 *
 * Backfill scope is deliberately narrower than Version20260911090000's own backfill: only
 * rows that migration actually populated `scope_site_ids` for (site IS NULL AND
 * status='DRAFT' AND scope_site_ids IS NOT NULL) are marked RECONSTRUCTED here — the one
 * residual case that migration left NULL (zero persisted Missions, nothing to reconstruct)
 * stays NULL for scope_source too, consistent with reopen() still refusing that one case
 * outright rather than offering a confirm-scope step with nothing to confirm.
 */
final class Version20260911100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-115bis follow-up — planning_version.scope_source (SNAPSHOT/RECONSTRUCTED/CONFIRMED), with backfill marking existing DRAFT group-scoped versions RECONSTRUCTED.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_version ADD scope_source VARCHAR(16) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE planning_version
            SET scope_source = 'RECONSTRUCTED'
            WHERE site_id IS NULL AND status = 'DRAFT' AND scope_site_ids IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_version DROP scope_source');
    }
}
