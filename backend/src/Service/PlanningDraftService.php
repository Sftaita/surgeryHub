<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\AuditEvent;
use App\Entity\EncodingAnomalyReport;
use App\Entity\FinancialCalculation;
use App\Entity\FirmInvoiceLine;
use App\Entity\Hospital;
use App\Entity\ImplantSubMission;
use App\Entity\InstrumentistRating;
use App\Entity\InstrumentistStatementLine;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissingMaterialReport;
use App\Entity\MissionClaim;
use App\Entity\MissionEncodingComment;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\MissionPublication;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\SurgeonRatingByInstrumentist;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\SchedulePrecision;
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

        $siteId = $version->getSite()?->getId();
        // D-115bis — a group-scoped draft ($siteId === null) re-previews against its own
        // frozen scope snapshot, never the SiteGroup's current (mutable) membership.
        $explicitSiteIds = $siteId === null ? $this->resolveGroupScopeSiteIds($version) : null;

        $month = $version->getPeriodStart()->format('Y-m');
        $lines = $this->generator->preview($month, $siteId, null, null, $explicitSiteIds);
        $currentHash = $this->generator->computePreviewVersion($month, $siteId, null, $explicitSiteIds);

        $this->normalizeForDraftEditing($lines);
        $this->appendAdHocMissions($version, $lines);

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

            // Two distinct "no existingMissionId yet" origins, told apart by postId's sign —
            // the same convention the editor already uses for a Modification-mode manual
            // add (GeneratePlanningTab.tsx's nextDraftIdRef, negative, decrementing):
            //   > 0  — a real SurgeonSchedulePost added to the scope after this draft's
            //          generate() ran. Same construction as a brand-new generate() line.
            //   <= 0 — CAS C (D-116): "Ajouter" used on a reopened draft. A genuinely
            //          one-off addition — never create a SurgeonSchedulePost for it (per
            //          CAS C rule), so createMissionFromLine() (Post-required) cannot be
            //          reused here.
            $postId   = $line['postId'] ?? null;
            $mission  = ($postId !== null && $postId > 0)
                ? $this->generator->createMissionFromLine($line, $version, $actor, $rejected)
                : $this->createAdHocDraftMission($version, $line, $actor, $rejected);
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
     * Extended 2026-09-08 (delete() performance fix) with every remaining
     * `orphanRemoval: true` collection Mission itself declares — MissionClaim (an OPEN-
     * mission claim, structurally impossible pre-deploy), MissionPublication,
     * MissionExecution, SurgeonRatingByInstrumentist, InstrumentistRating,
     * ImplantSubMission, MissionIntervention, MaterialLine, MaterialItemRequest,
     * InterventionTypeRequest, MissionEncodingComment — every one of them a post-deploy or
     * post-encoding artifact, same "never left DRAFT" argument as the original seven. Until
     * now these relied entirely on Doctrine's own per-entity orphanRemoval cascade (fired
     * only inside the removal loop this list now replaces — see delete() below); asserting
     * them empty here first is what makes it safe to bulk-DELETE Mission directly without
     * ever bypassing a real cascade. `outbound_notification` needs no entry (`ON DELETE SET
     * NULL` at the DB level); `service_hours_dispute`/`instrumentist_service` (legacy,
     * unmapped by any current entity) and `surgeon_mission_request.created_mission_id` (a
     * different mission-creation origin, can never reference a generate()'d/CAS-D draft
     * mission) stay out of scope exactly as the original CLEANUP_ON_MISSION_DELETE audit
     * already concluded.
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
        MissionClaim::class,
        MissionPublication::class,
        MissionExecution::class,
        SurgeonRatingByInstrumentist::class,
        InstrumentistRating::class,
        ImplantSubMission::class,
        MissionIntervention::class,
        MaterialLine::class,
        MaterialItemRequest::class,
        InterventionTypeRequest::class,
        MissionEncodingComment::class,
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
     *
     * Performance (2026-09-08): a draft with many missions (~100) used to issue one
     * individual DELETE per Mission (`$em->remove()` inside a loop, one flush()) — Doctrine
     * never batches entity removal into a single statement, so this was ~100 sequential
     * round trips in one transaction, occasionally slow enough to exceed the frontend's
     * request timeout even though the deletion itself always completed and committed
     * correctly (found via a real browser walkthrough deleting a 97-mission draft: server
     * genuinely finished, but the manager saw a timeout error and a stale "brouillon
     * existant" screen until reloading). `assertNoProtectedArtifacts()` above now also
     * covers every `orphanRemoval: true` collection Mission declares (not just the
     * originally non-cascading ones), which makes it safe to delete every Mission of this
     * version with a single bulk DQL DELETE instead — one statement instead of ~100,
     * same transaction, same 409 guards, zero change to what gets deleted or refused.
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

        $this->em->wrapInTransaction(function () use ($version, $missionIds): void {
            foreach (self::CLEANUP_ON_MISSION_DELETE as $entityClass) {
                if ($missionIds === []) {
                    break;
                }
                $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.mission IN (:ids)', $entityClass))
                    ->setParameter('ids', $missionIds)
                    ->execute();
            }

            if ($missionIds !== []) {
                $this->em->createQuery(sprintf('DELETE FROM %s m WHERE m.id IN (:ids)', Mission::class))
                    ->setParameter('ids', $missionIds)
                    ->execute();
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
     * D-115bis — a group-scoped draft's own frozen scope, for reopen() only. Never falls
     * back to resolving the SiteGroup's current membership (that would let a later
     * membership change retroactively alter this draft's scope — see PlanningVersion::
     * $scopeSiteIds for why).
     *
     * The migration backfills $scopeSiteIds once for every pre-existing group-scoped
     * DRAFT at deploy time, so this lazy path is normally a no-op for old drafts too. It
     * still exists — and is what tests exercise directly, without needing to simulate
     * migration timing — as a self-healing fallback: reconstructed once here from the
     * version's own persisted DRAFT Missions (never the SiteGroup's current membership)
     * and persisted back, so every later reopen() of the same draft is O(1) again and
     * never re-derives it. Same reconstruction the migration itself performs — safe here
     * specifically because it is scoped to a single already-generated draft's own fixed
     * set of persisted Missions, never used as an ongoing resolution mechanism.
     *
     * The one residual refusal case, explicitly narrower than the old blanket one: a
     * group-scoped draft with zero persisted DRAFT Missions (everything SKIPPED) — there
     * is genuinely nothing to reconstruct its original scope from.
     */
    private function resolveGroupScopeSiteIds(PlanningVersion $version): array
    {
        $snapshot = $version->getScopeSiteIds();
        if ($snapshot !== null && $snapshot !== []) {
            return $snapshot;
        }

        $ids = [];
        foreach ($version->getMissions() as $mission) {
            if ($mission->getStatus() !== MissionStatus::DRAFT) {
                continue;
            }
            $siteId = $mission->getSite()?->getId();
            if ($siteId !== null) {
                $ids[$siteId] = $siteId;
            }
        }

        if (empty($ids)) {
            throw new BadRequestHttpException(
                'La réouverture de ce brouillon multi-sites est impossible : aucune mission n\'y est '
                . 'associée, et son périmètre d\'origine n\'a pas pu être reconstruit (brouillon créé '
                . 'avant D-115bis, sans mission générée à cette époque).',
            );
        }

        $ids = array_values($ids);
        sort($ids);
        $version->setScopeSiteIds($ids);
        $this->em->flush();

        return $ids;
    }

    /**
     * CAS C (D-116) — "Ajouter" used on a reopened DRAFT: a genuinely one-off addition,
     * never tied to a SurgeonSchedulePost (that rule is explicit — never invent a Post for
     * a punctual add). Mirrors MissionPostDeployService::createPostDeploy()'s field
     * construction (same Mission, same shape of inputs) but deliberately never calls that
     * service: the mission must stay DRAFT here, never ASSIGNED/OPEN, and this is pre-
     * publication — no AuditEvent, no MissionLifecycleChangedMessage (same "draft
     * mutations aren't audited business events" convention generate()/update() already
     * follow for every other draft line).
     */
    private function createAdHocDraftMission(PlanningVersion $version, array $line, User $actor, array &$rejected): ?Mission
    {
        $site = $this->em->find(Hospital::class, $line['siteId'] ?? null);
        if ($site === null) {
            return null;
        }
        $surgeon = $this->em->find(User::class, $line['surgeonId'] ?? null);
        if ($surgeon === null) {
            return null;
        }
        $type = MissionType::tryFrom((string) ($line['missionType'] ?? ''));
        if ($type === null) {
            return null;
        }

        $instrumentist = ($line['instrumentistId'] ?? null) !== null
            ? $this->em->find(User::class, $line['instrumentistId'])
            : null;

        // Same Brussels wall-clock construction as PlanningGeneratorServiceV2::generate()/
        // createMissionFromLine() — see D-066.
        $day = new \DateTimeImmutable($line['date'], new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE));
        [$h1, $m1] = explode(':', $line['startTime']);
        [$h2, $m2] = explode(':', $line['endTime']);

        $mission = new Mission();
        $mission->setStatus(MissionStatus::DRAFT);
        $mission->setType($type);
        $mission->setSurgeon($surgeon);
        $mission->setSite($site);
        $mission->setStartAt($day->setTime((int) $h1, (int) $m1));
        $mission->setEndAt($day->setTime((int) $h2, (int) $m2));
        $mission->setSchedulePrecision(SchedulePrecision::EXACT);
        $mission->setCreatedBy($actor);
        $mission->setPlanningVersion($version);
        $mission->setInstrumentist($this->guardInstrumentist($mission, $instrumentist, $rejected));

        return $mission;
    }

    /**
     * CAS C (D-116) — preview()'s line set is built by iterating SurgeonSchedulePost
     * occurrences (see PlanningGeneratorServiceV2::preview()); a one-off addition made
     * via createAdHocDraftMission() has no Post to be re-derived from, so it would
     * silently vanish from every subsequent reopen() — appearing to have been lost —
     * without this step. Appends one line per DRAFT mission of this version that no
     * preview() line already claims (existingMissionId); never touches or duplicates a
     * Post-backed line.
     *
     * @param array<int, array<string, mixed>> &$lines
     */
    private function appendAdHocMissions(PlanningVersion $version, array &$lines): void
    {
        $claimedIds = array_flip(array_filter(array_column($lines, 'existingMissionId')));

        foreach ($version->getMissions() as $mission) {
            if ($mission->getStatus() !== MissionStatus::DRAFT || isset($claimedIds[$mission->getId()])) {
                continue;
            }

            $instrumentist = $mission->getInstrumentist();
            $lines[] = [
                'date'                      => $mission->getStartAt()->format('Y-m-d'),
                // 0 = "no real Post", the same sentinel the editor's own negative-decrementing
                // convention treats as "not a real post" (postId <= 0) — PreviewLineResponse's
                // frozen shape (docs/planning-v2-architecture-freeze.md §B) requires a real int.
                'postId'                    => 0,
                'surgeonId'                 => $mission->getSurgeon()?->getId(),
                'surgeonName'               => $this->displayName($mission->getSurgeon()),
                'missionType'               => $mission->getType()->value,
                'startTime'                 => $mission->getStartAt()->format('H:i'),
                'endTime'                   => $mission->getEndAt()->format('H:i'),
                'siteId'                    => $mission->getSite()?->getId(),
                'siteName'                  => $mission->getSite()?->getName(),
                'instrumentistId'           => $instrumentist?->getId(),
                'instrumentistName'         => $this->displayName($instrumentist),
                'status'                    => $instrumentist !== null ? 'COVERED' : 'UNCOVERED',
                'existingMissionId'         => $mission->getId(),
                'existingInstrumentistId'   => $instrumentist?->getId(),
                'existingInstrumentistName' => $instrumentist !== null ? $this->displayName($instrumentist) : null,
                'freedFrom'                 => false,
                'surgeonPhotoPath'          => $mission->getSurgeon()?->getProfilePicturePath(),
                'instrumentistPhotoPath'    => $instrumentist?->getProfilePicturePath(),
            ];
        }
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
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
