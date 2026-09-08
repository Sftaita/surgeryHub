<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\EncodingAnomalyReport;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoiceLine;
use App\Entity\InstrumentistStatementLine;
use App\Entity\Mission;
use App\Entity\MissingMaterialReport;
use App\Entity\MissionExecutionDispute;
use App\Entity\MissionInterventionDraft;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningVersionStatus;
use App\Exception\PlanningVersionNotDraftException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * CAS D (D-115) — lifecycle of an already-persisted Planning V2 draft: reopen it for
 * editing, save further line-level changes, or delete it outright. Never touches a
 * SurgeonSchedulePost, never regenerates via PlanningGeneratorServiceV2::generate() (that
 * would mint a second PlanningVersion and orphan this one — exactly the accumulation
 * problem the 409 guard in PlanningV2GenerationController::generate() exists to prevent).
 *
 * Source of truth for a draft is, and stays, `PlanningVersion` + its DRAFT `Mission[]`
 * (see docs/decisions.md D-115) — reopen() never reconstructs the draft FROM a fresh
 * preview(); it calls preview() only to re-derive display-only, never-persisted
 * information (SKIPPED lines for surgeons absent right now) and overlay it on top of the
 * real, persisted Missions — which preview()'s own claimMission() matching already does
 * for free (it pools every non-REJECTED Mission in the period/site, DRAFT included).
 */
final class PlanningDraftService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
        private readonly MissionEligibilityService $eligibilityService,
    ) {
    }

    /**
     * @return array{lines: array, previewVersion: string, divergent: bool}
     */
    public function reopen(PlanningVersion $version): array
    {
        $this->assertDraft($version);
        $siteId = $this->requireSingleSiteScope($version);

        $month = $version->getPeriodStart()->format('Y-m');
        $lines = $this->generator->preview($month, $siteId, null, null);
        $currentHash = $this->generator->computePreviewVersion($month, $siteId, null);

        $this->normalizeForDraftEditing($lines);

        return [
            'lines'          => $lines,
            'previewVersion' => $currentHash,
            // Never blocks, never replaces anything — purely informational (§8): a Post,
            // ShiftPeriodConfig, or absence changed since this draft's own generate() ran.
            // Versions created before this lot have no stored hash (null) — never flagged
            // divergent, since there is nothing to compare against.
            'divergent'      => $version->getPreviewHash() !== null && $version->getPreviewHash() !== $currentHash,
        ];
    }

    /**
     * Corrects two artifacts of reusing preview()'s occurrence-matching for an *already-
     * persisted* draft, found via real end-to-end testing (§17) — not covered by the
     * existing unit-level tests because they always resent every line's `instrumentistId`
     * unchanged from the same reopen() response, which masked both:
     *
     * 1. **Silent data loss.** On a MODIFIED line (the persisted Mission's instrumentist
     *    differs from the Post's current template default — e.g. reassigned after
     *    generate()), preview() puts the *template's* default in `instrumentistId` and the
     *    *real* persisted value only in `existingInstrumentistId` — correct for Mode
     *    Modification, where `instrumentistId` is purely a diff display. But
     *    `update()` (and the editor's own edit/resubmit cycle) treats `instrumentistId` as
     *    "the value to (re)write". Left uncorrected, saving *any other* line's edit while
     *    this one is resent as-is (which is exactly what happens whenever a manager edits
     *    one line and saves) silently reverts this Mission's real instrumentist back to the
     *    template default. Confirmed with a real HTTP+DB round trip: generate() an
     *    instrumentist onto line A, PATCH only line B, reopen — line A's real Mission had
     *    lost its instrumentist.
     * 2. **Misleading status.** When both the persisted Mission and the template have no
     *    instrumentist, preview() reports `COVERED` (matches the template's own — empty —
     *    expectation) rather than `UNCOVERED` (still needs staffing) — correct for Mode
     *    Modification (an intentionally-open template slot isn't something to fix in the
     *    editor), misleading for a freshly-generated draft, where every occurrence still
     *    needing an instrumentist must read as such.
     *
     * @param array<int, array<string, mixed>> &$lines
     */
    private function normalizeForDraftEditing(array &$lines): void
    {
        foreach ($lines as &$line) {
            if ($line['existingMissionId'] === null) {
                continue;
            }
            if ($line['status'] === 'MODIFIED') {
                $line['instrumentistId']   = $line['existingInstrumentistId'];
                $line['instrumentistName'] = $line['existingInstrumentistName'];
            }
            if ($line['instrumentistId'] === null) {
                $line['status'] = 'UNCOVERED';
            }
        }
        unset($line);
    }

    /**
     * Applies the editor's current line set to this draft's real, persisted Missions —
     * the draft-editing equivalent of generate()'s override mode, minus creating a new
     * PlanningVersion. A line already backed by a DRAFT Mission of this exact version is
     * reassigned (or removed, if the manager marked it SKIPPED/ignored); a line with no
     * matching Mission yet (a Post added to the scope since this draft was generated) is
     * created fresh via the same helper generate() itself uses — same rules, one call site.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array{created: int, updated: int, removed: int, skipped: int, rejectedAssignments: list<array<string, mixed>>}
     */
    public function update(PlanningVersion $version, array $lines, User $actor): array
    {
        $this->assertDraft($version);

        $created = 0;
        $updated = 0;
        $removed = 0;
        $skipped = 0;
        $rejected = [];

        foreach ($lines as $line) {
            $existingMissionId = $line['existingMissionId'] ?? null;
            $status            = $line['status'] ?? null;

            if ($existingMissionId !== null) {
                $mission = $this->em->find(Mission::class, $existingMissionId);

                // Defensive: a stale line (mission deleted meanwhile, or — should never
                // happen given the version-scoped reopen query — belonging to a different
                // version) is skipped rather than mutated. R-01 (never touch a non-DRAFT
                // mission) enforced the same way generate()'s own override mode does.
                if ($mission === null
                    || $mission->getPlanningVersion()?->getId() !== $version->getId()
                    || $mission->getStatus() !== MissionStatus::DRAFT
                ) {
                    $skipped++;
                    continue;
                }

                if ($status === 'SKIPPED') {
                    // "Ignorer cette ligne" on an already-persisted draft occurrence — the
                    // Mission never should have existed for this occurrence, so remove it
                    // outright (a fresh preview()/reopen will show it UNCOVERED again,
                    // never silently resurrected — see docs/decisions.md D-115).
                    $this->em->remove($mission);
                    $removed++;
                    continue;
                }

                $newInstrumentistId = $line['instrumentistId'] ?? null;
                $newInstrumentist   = $newInstrumentistId !== null
                    ? $this->em->find(User::class, $newInstrumentistId)
                    : null;
                $mission->setInstrumentist($this->guardInstrumentist($mission, $newInstrumentist, $rejected));
                $updated++;
                continue;
            }

            if ($status === 'SKIPPED') {
                // Never persisted (R-01/roadmap) — nothing to create, nothing to remove.
                $skipped++;
                continue;
            }

            // No existing mission for this occurrence yet — e.g. a SurgeonSchedulePost
            // added to the scope after this draft's generate() ran. Same construction as
            // a brand-new generate() line, same eligibility guard, one shared helper.
            $mission = $this->generator->createMissionFromLine($line, $version, $actor, $rejected);
            if ($mission === null) {
                $skipped++;
                continue;
            }
            $this->em->persist($mission);
            $created++;
        }

        $this->em->flush();

        return [
            'created'             => $created,
            'updated'             => $updated,
            'removed'             => $removed,
            'skipped'             => $skipped,
            'rejectedAssignments' => $rejected,
        ];
    }

    /**
     * Entities that are purely derived from a Mission's existence (never independently
     * meaningful once that Mission is gone) and are NOT already covered by an
     * orphanRemoval=true collection on Mission itself — so Doctrine's own cascade-on-
     * remove() never reaches them, and their FK to mission is a plain RESTRICT (no
     * ON DELETE clause) at the DB level. Found by a full audit of every FK referencing
     * `mission` (2026-09-08, prompted by a real 500 on planning_alert in production —
     * see docs/decisions.md D-115 errata). Explicitly cleaned up here, scoped to exactly
     * this version's own missions — never a row belonging to a mission outside this
     * version, and never the Absence/whatever else *caused* the row (e.g. a
     * PlanningAlert's source Absence is untouched; only the alert row itself, which is
     * intrinsically mission-scoped, is removed).
     *
     * @var list<class-string>
     */
    private const CLEANUP_ON_MISSION_DELETE = [
        PlanningAlert::class,
        AuditEvent::class,
        NotificationEvent::class,
    ];

    /**
     * Entities that require a Mission to have gone through a post-deploy/post-encoding
     * lifecycle stage (claimed+encoded+validated+invoiced, or disputed after encoding) —
     * structurally impossible on a Mission that has never left DRAFT. Their presence
     * would mean this "draft" isn't actually untouched, so deletion is refused outright
     * (never silently force-deleted — some of these are financial/audit-adjacent records
     * explicitly marked non-cascading elsewhere, e.g. FinancialCalculation/
     * MissionInterventionDraft both have orphanRemoval=false on Mission by deliberate
     * design). Same audit as CLEANUP_ON_MISSION_DELETE above.
     *
     * @var list<class-string>
     */
    private const PROTECTED_IF_MISSION_TOUCHED = [
        MissingMaterialReport::class,
        MissionExecutionDispute::class,
        EncodingAnomalyReport::class,
        FirmInvoiceLine::class,
        InstrumentistStatementLine::class,
        FinancialCalculation::class,
        MissionInterventionDraft::class,
    ];

    /**
     * Restores the pre-D-079 DRAFT-delete semantics (removed as an orphaned V1-only route
     * in commit 570a551, "retire Planning V1", without a V2 replacement — see
     * docs/decisions.md D-115): refuses outright if the version itself isn't DRAFT, or if
     * any of its missions already left DRAFT some other way (a partially-published
     * version is never a "simple brouillon" — the manager must resolve that state
     * manually, this is not the place to silently reconcile it). Only ever removes DRAFT
     * missions and the version itself — never a SurgeonSchedulePost, never a published
     * Mission belonging to any other version, never the Absence behind a cleaned-up
     * PlanningAlert. Whole operation is one atomic transaction — any failure rolls back
     * everything, never a partial deletion.
     */
    public function delete(PlanningVersion $version): void
    {
        if ($version->getStatus() !== PlanningVersionStatus::DRAFT) {
            throw new PlanningVersionNotDraftException(sprintf(
                'This planning version is %s, not a draft, and cannot be deleted as one.',
                $version->getStatus()->value,
            ));
        }

        $missions = $version->getMissions();
        foreach ($missions as $mission) {
            if ($mission->getStatus() !== MissionStatus::DRAFT) {
                throw new PlanningVersionNotDraftException(
                    'This planning version contains published missions and cannot be deleted as a draft.',
                );
            }
        }

        $missionIds = array_map(static fn (Mission $m) => $m->getId(), $missions->toArray());

        if ($missionIds !== []) {
            $this->assertNoProtectedArtifacts($missionIds);
        }

        $this->em->wrapInTransaction(function () use ($version, $missions, $missionIds): void {
            foreach (self::CLEANUP_ON_MISSION_DELETE as $entityClass) {
                if ($missionIds === []) {
                    break;
                }
                $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.mission IN (:ids)', $entityClass))
                    ->setParameter('ids', $missionIds)
                    ->execute();
            }

            foreach ($missions as $mission) {
                $this->em->remove($mission);
            }
            $this->em->remove($version);
            $this->em->flush();
        });
    }

    /**
     * @param list<int> $missionIds
     */
    private function assertNoProtectedArtifacts(array $missionIds): void
    {
        foreach (self::PROTECTED_IF_MISSION_TOUCHED as $entityClass) {
            $count = (int) $this->em->createQuery(sprintf('SELECT COUNT(e) FROM %s e WHERE e.mission IN (:ids)', $entityClass))
                ->setParameter('ids', $missionIds)
                ->getSingleScalarResult();

            if ($count > 0) {
                throw new PlanningVersionNotDraftException(sprintf(
                    'This planning version has %d %s record(s) referencing its missions — a mission that reached this stage should never still be DRAFT, and cannot be deleted as a draft.',
                    $count,
                    (new \ReflectionClass($entityClass))->getShortName(),
                ));
            }
        }
    }

    private function assertDraft(PlanningVersion $version): void
    {
        if ($version->getStatus() !== PlanningVersionStatus::DRAFT) {
            throw new PlanningVersionNotDraftException(sprintf(
                'This planning version is %s, not a draft.',
                $version->getStatus()->value,
            ));
        }
    }

    /**
     * Known limitation (same class as the pre-existing site-group / site=null bucket
     * documented in PlanningV2GenerationController::assertNoUndeployedDraftExists() and
     * Batch 8/9): a site-GROUP draft persists with `site = null`, indistinguishable from
     * "no site filter" — there is no siteGroupId column on PlanningVersion to recover
     * which group it was generated for. Reopening such a draft is refused explicitly
     * rather than silently previewing the wrong (or every) site. Not fixed here.
     */
    private function requireSingleSiteScope(PlanningVersion $version): int
    {
        $site = $version->getSite();
        if ($site === null) {
            throw new BadRequestHttpException(
                'La réouverture d\'un brouillon généré pour un groupe de sites n\'est pas encore supportée '
                . '(PlanningVersion ne mémorise pas le groupe — limitation connue, voir docs/decisions.md D-115).',
            );
        }
        return $site->getId();
    }

    private function guardInstrumentist(Mission $mission, ?User $candidate, array &$rejected): ?User
    {
        if ($candidate === null) {
            return null;
        }

        $eligibility = $this->eligibilityService->evaluateForReassignment($mission, $candidate);
        if ($eligibility->eligible) {
            return $candidate;
        }

        $rejected[] = [
            'missionId'                  => $mission->getId(),
            'date'                       => $mission->getStartAt()?->format('Y-m-d'),
            'requestedInstrumentistId'   => $candidate->getId(),
            'requestedInstrumentistName' => trim(sprintf('%s %s', $candidate->getFirstname() ?? '', $candidate->getLastname() ?? '')) ?: $candidate->getEmail(),
            'reasons'                    => array_map(static fn ($r) => $r->value, $eligibility->reasons),
        ];

        return null;
    }
}
