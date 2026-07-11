# Planning V2 — Architecture Freeze (Batch 8, updated Batch 9, Batch 13, design Batch 15)

> **Batch 13 — LAUNCHED.** Planning V2 is now the official manager planning UI (see
> `docs/decisions.md` D-048). Backend (Batches 1–9) and frontend (Batches 10–12) are both
> implemented, tested, and live behind the sidebar's "Planning" entry and the bare
> `/app/m/planning` route. V1 is hidden from navigation but **not deleted** — see §C for
> the still-unimplemented per-site cutover flag and exit criterion, and §K below for the
> consolidated non-blocking remaining-work list.

Design-only batch. No code changes. Purpose: freeze the V2 architecture before any
frontend work starts. Backend status going in: entities → generation → alerts →
actions → notifications → preferences are all implemented and tested (Batches 1–7).
V1 (`PlanningTemplate`/`PlanningSlot`/PAIR/IMPAIR/TOUTES) is untouched throughout.

> **Batch 9 update**: the single RED finding below (§I) — "no V2 preview/generate/deploy
> HTTP endpoint exists" — is now resolved. `PlanningV2GenerationController` exposes
> `/api/planning/v2/preview|generate|deploy`. See `docs/api.md` §26.10 and
> `docs/architecture.md` "Flux planning V2" for the implemented contract, which matches
> this document's §B design with the deviations noted inline below. Batch 9 also found
> and fixed a real pre-existing bug in `PlanningDeploymentService` (shared by V1/V2) —
> see the note at the end of §D.

---

## A. UX Audit

### A1. Surgeon posts: by surgeon, by site, or both?

**Both, but not as two equal nav items.** Site-first list as the primary view (a
manager thinks "what am I planning for this site/group"), with surgeon as a *filter*
on that list (`GET /api/planning/surgeon-posts?surgeonId=...` already supports this).
A surgeon-centric view (all of one surgeon's posts across every site) is genuinely
useful for multi-site surgeons, but belongs as a contextual link from that surgeon's
profile page, not a duplicate top-level nav entry — the data is the same, only the
entry point differs.

### A2. Generation: monthly, weekly, or arbitrary range?

**Monthly, as already implemented** (`PlanningGeneratorServiceV2::preview(string $month, ...)`).
Reasons: matches the existing PDF/email cadence (planning_global/instrumentist/surgeon
PDFs are already monthly-shaped), matches how RecurrenceRule's `interval` already
expresses "every week / every 2 weeks / monthly" without needing the *generation*
window itself to be sub-monthly, and avoids UI complexity (date-range picker, ambiguous
recurrence-phase edge cases at arbitrary boundaries) for no demonstrated need.
Recommendation: keep month as the default and only generation unit exposed in the UI.
An arbitrary-range override is a plausible *future* escape hatch (e.g. catching up a
late-onboarded site) but should not be built until a real case demands it — flagged as
a yellow item, not built now.

### A3. Cleanest manager workflow — simplification of the proposed pipeline

The proposed pipeline (Create → Preview → Generate draft → Deploy → Published planning
→ Alerts → Reassign/open → Notifications) is directionally right but has three sources
of unnecessary complexity as a *navigation* model:

1. **Preview and Generate draft should be one screen, not two.** They're already one
   engine pass conceptually (`generate()` calls `preview()` internally) — the UI should
   be "pick month + site/group → table updates live (Preview) → Generate Draft button
   persists it", not a page transition between two routes.
2. **"Site Groups" and "Shift Periods" are admin-frequency tasks, not weekly workflow
   steps.** They don't belong as top-level `Planning` nav items alongside Generate/Alerts.
   Nest them under a single `Settings` entry.
3. **"Reassign/open" must be an inline action on the Alerts list/detail, never a
   separate page.** The pipeline diagram is right conceptually but must not become two
   real navigation hops — a manager triaging alerts should never leave the Alerts view
   to act on one. **"Notifications" is a side effect, not a workflow step** — it
   shouldn't appear as a pipeline stage in the UI at all; it's ambient (existing
   notification-bell pattern), not an action the manager takes.

Revised top-level menu:

```
Planning
 ├── Surgeon Posts        (site-first list; surgeon + active + type filters; link to per-surgeon view)
 ├── Generate              (month + site/group picker → live preview → Generate Draft → Deploy, one flow)
 ├── Published Planning    (existing PlanningVersion list/detail — unchanged)
 ├── Alerts                (list + inline acknowledge/resolve/ignore/reassign/open actions)
 └── Settings              (Site Groups, Shift Periods, [future] Notification Preferences)
```

Five items instead of six, with admin-frequency tasks grouped out of the weekly flow.

---

## B. API Contract Freeze

Conventions frozen across every V2 DTO below: dates as `"YYYY-MM-DD"`, datetimes as
ISO-8601 (`DateTimeInterface::ATOM`), times as `"HH:MM"`. Any user/site/instrumentist
reference is a nested object, never a flat `xxxName` field — **except** where noted.

### SurgeonPostResponse

```jsonc
{
  "id": 1,
  "surgeon": { "id": 2, "email": "...", "name": "..." },
  "site": { "id": 3, "name": "..." },
  "type": "BLOCK" | "CONSULTATION",
  "period": "MATIN" | "APRES_MIDI" | "JOURNEE",
  "instrumentist": { "id": 4, "email": "...", "name": "..." } | null,
  "startDate": "2026-01-01",
  "endDate": "2026-06-30" | null,
  "active": true,
  "recurrence": {
    "frequency": "WEEKLY" | "MONTHLY",
    "interval": 1,
    "weekdays": [1, 3],            // empty for MONTHLY
    "anchorDate": "2026-01-05",
    "monthlyNthWeekday": null
  }
}
```

Gaps vs. today's actual `serialize()`: `surgeon`/`instrumentist` currently return only
`{id, email}` — **freeze decision: add `name` to every user reference across all V2
DTOs** before frontend builds list columns around the current shape. `createdAt`/
`createdBy` are missing — recommend adding for audit-display parity with other
entities. Both are additive, non-breaking.

### SurgeonPostListResponse

```jsonc
{ "items": [ /* SurgeonPostResponse[] */ ] }
```

**Red flag, not just yellow**: no pagination today (`search()` returns every matching
row). Fine at current data volumes; will not stay fine. Add `page`/`limit`/`total`
**before** frontend builds an unpaginated list component — adding pagination after the
fact is a breaking response-shape change (`items` moves under a wrapper it didn't need
before, or paging params silently start truncating a list the UI assumed was complete).

### ShiftPeriodResponse

```jsonc
{ "id": 1, "site": { "id": 3, "name": "..." }, "period": "MATIN", "startTime": "08:00", "endTime": "13:00", "active": true }
```

Stable as-is.

### SiteGroupResponse

```jsonc
{
  "id": 1, "name": "...", "createdAt": "2026-01-01T00:00:00+00:00",
  "createdBy": { "id": 2, "email": "...", "name": "..." },   // currently missing — add
  "sites": [ { "id": 3, "name": "..." } ]
}
```

### PlanningAlertResponse

```jsonc
{
  "id": 1, "type": "SURGEON_ABSENCE" /* | INSTRUMENTIST_ABSENCE | SURGEON_CONFLICT | INSTRUMENTIST_CONFLICT | REASSIGNMENT_REQUIRED | OCCURRENCE_CANCELLED */,
  "status": "OPEN" | "ACKNOWLEDGED" | "RESOLVED" | "IGNORED",
  "detectedAt": "...", "resolvedAt": "..." | null,
  "resolvedBy": { "id": 2, "email": "...", "name": "..." } | null,
  "resolutionNote": "..." | null,
  "mission": { "id": 10, "status": "ASSIGNED", "startAt": "...", "endAt": "...",
                "site": { "id": 3, "name": "..." } | null,
                "surgeon": { "id": 2, "email": "...", "name": "..." } | null,
                "instrumentist": { "id": 4, "email": "...", "name": "..." } | null },
  "absence": { "id": 5, "dateStart": "...", "dateEnd": "...", "reason": "..." } | null,
  "actions": { "canAcknowledge": true, "canResolve": true, "canIgnore": true,
               "canReassign": false, "canOpenAsAvailable": false,
               "recommendedAction": "REASSIGN" | "REVIEW" | "NONE" }
}
```

This is the most important DTO — it's the manager's primary triage surface and the
`actions` block is already shaped for direct button-enablement binding. Freeze as-is.
Recommend (additive) hoisting `siteId`/`siteName` to the top level too, so a list
column renderer doesn't need to reach into `mission.site` for the single most common
column. Non-breaking to add later, but cheaper to add before the list UI is built.

### EligibleInstrumentistResponse

```jsonc
{ "items": [ { "id": 4, "email": "...", "name": "...", "sites": ["..."] } ] }
```

**Updated (frontend redesign, Batch 12)**: `sites` (the candidate's affiliated site
names) added — small additive backend change, used by the reassign modal's candidate
cards. Still no compatibility score/availability/conflicts annotation — the frontend
redesign deliberately shipped the simplified card (name/email/sites only) rather than
fabricate a score the backend doesn't compute. Future enhancement (not a launch
blocker): reuse `PlanningScoreService`'s scoring concept to rank/annotate candidates.

### PlanningOccurrenceExceptionResponse

```jsonc
{
  "id": 1, "postId": 2, "occurrenceDate": "2026-01-12",
  "type": "CANCELLED" | "MOVED" | "TIME_OVERRIDE" | "INSTRUMENTIST_OVERRIDE",
  "overrideDate": "2026-01-14" | null,
  "overrideInstrumentist": { "id": 4, "email": "...", "name": "..." } | null,
  "overrideStartTime": "09:00" | null, "overrideEndTime": "12:00" | null,
  "createdAt": "..."
}
```

**Intentional exception to the "nested object" rule**: `postId` stays a flat int. The
frontend always reaches this resource nested under
`/surgeon-posts/{postId}/exceptions`, so the parent is already known from the URL —
nesting it again would be redundant. Documenting this now so it isn't "fixed"
inconsistently by someone who didn't see this note. See Section F for a likely future
shape change to this DTO (`type` enum may collapse).

### PreviewResponse *(implemented Batch 9 exactly as designed — `POST /api/planning/v2/preview`)*

```jsonc
{
  "lines": [{
    "date": "2026-01-12", "postId": 2, "surgeonId": 3, "surgeonName": "...",
    "missionType": "BLOCK", "startTime": "08:00", "endTime": "13:00",
    "siteId": 4, "siteName": "...", "instrumentistId": 5, "instrumentistName": "..." ,
    "status": "SKIPPED" | "UNCOVERED" | "COVERED" | "MODIFIED" | "CONFLICT",
    "existingMissionId": 9 | null,
    "existingInstrumentistId": 6 | null, "existingInstrumentistName": "..." | null,
    "freedFrom": false
  }],
  "summary": { "total": 40, "covered": 32, "uncovered": 4, "skipped": 2, "conflict": 1, "modified": 1 }
}
```

`summary` is now computed server-side exactly as recommended (`PreviewSummaryResponse::fromLines()`,
one `array_count_values` pass) — implemented as designed.

### GeneratedPlanningResponse *(implemented Batch 9 — `POST /api/planning/v2/generate`)*

```jsonc
{ "versionId": 7, "created": 30, "updated": 8, "skipped": 2 }
```

**Deviation from the original design**: the recommended nested `version` summary object
was **not** added — kept deliberately minimal (mirrors V1's `generate()` response
exactly) per Batch 9's explicit scope ("no V2-specific deploy logic unless required").
Still a reasonable future addition, not done here.

**DeployResponse** *(implemented Batch 9 — `POST /api/planning/v2/deploy`)*: `{deploymentId, missionCount, openPoolCount}`,
mirrors `PlanningDeploymentService::deploy()`'s return shape exactly, no V2-specific
fields added. Confirms the original prediction below — deploy reuses the existing
service with zero new logic, only the controller-level translation of
`{planningVersionId, sendPdf}` into `deploy()`'s existing parameters (`from`/`to`/`siteId`
derived from the targeted `PlanningVersion`; `sendPdf` mapped onto the existing
`sendChangeSummary` parameter — there is no "skip PDFs entirely" lever in the shared
service, and adding one was out of scope).

`PlanningDeployController` (V1) is version-agnostic and already shared by V1 and V2
(confirmed in Batch 2) — no new design needed there, and Batch 9 reused the underlying
`PlanningDeploymentService` directly from the new V2 controller rather than duplicating
V1's controller-level body-parsing.

---

## C. Cutover Strategy

**Recommendation: Option C — per-site feature flag, attached to `Hospital`.**

A new enum column `planningEngine: LEGACY | V2` on `Hospital`, defaulting to `LEGACY`
for every existing site. Not implemented in this batch (no code), but specified now as
the target.

| Criterion | A — Hard switch | B — Permanent parallel | C — Per-site flag |
|---|---|---|---|
| Safety | All sites break at once if V2 has an undiscovered bug | Safe, but never converges | One site's blast radius only |
| Rollback | Requires reverting every site simultaneously | N/A (nothing to roll back to) | Flip one site's flag back — instant, zero data loss |
| Migration complexity | One big-bang event | None (by design — that's the problem) | Each site's Batch-1 backfill can happen independently, at its own pace |
| Operational risk | Highest | Indefinite double-maintenance burden | Lowest |

Why C specifically: every batch so far has been built as a structurally independent
parallel engine (confirmed exhaustively in Section D) — `Mission`/`PlanningVersion`/
deploy/diff/PDF are identical regardless of which generator produced the missions. A
per-site flag costs nothing architecturally; it's purely "which generator does this
site's Generate button call." Option B is explicitly rejected **as an end state** —
it's actually the transient condition Option C passes through during rollout, not a
destination. Running two generators forever is a maintenance liability with no
matching benefit once every site has migrated.

**Exit criterion**: once every site is flagged V2 for one full billing/encoding cycle
with zero incidents, schedule removal of `PlanningGeneratorService` (V1),
`PlanningTemplateController`, `PlanningTemplate`, `PlanningSlot`, `PlanningTemplateType`,
`SlotPeriod` — this is the actual "cutover batch," explicitly out of scope here and for
every batch so far.

---

## D. PDF / Export Dependency Audit

Read, not assumed. Result is unusually clean — **zero risky or must-adapt findings**.

| Component | Finding | Why |
|---|---|---|
| `PdfService` | **Safe** | Generic Twig-render + Dompdf wrapper. Zero entity references of any kind. |
| `planning_global/instrumentist/surgeon.html.twig` | **Safe** | Grepped directly — only iterate `missions` (Mission entities). Zero references to template/slot/PAIR/IMPAIR/TOUTES. |
| `PlanningVersion` | **Safe** | No FK to PlanningTemplate/PlanningSlot (confirmed Batch 2). Wraps `Mission` only. |
| `PlanningDiffService` | **Safe** | Read in full this batch. Composite matching key built entirely from `Mission` fields (site/surgeon/type/date/startAt-rounded). No template/slot coupling anywhere. |
| `PlanningDeploymentService` | **Safe** | Read in full this batch. Operates purely on `PlanningVersion`+`Mission` via bulk UPDATE queries; dispatches `PlanningDeployPdfsMessage`, itself confirmed Mission-only in Batch 3. |
| `PlanningDeployPdfsMessageHandler` | **Safe** | Confirmed Batch 3 — loads missions by date/site only. |
| RH exports (`ExportService`/`ExportController`/`ExportLog`) | **Safe** | Grepped — zero "planning" references at all. Entirely orthogonal (billing/mission exports), never touches the planning generation model. |

**The only files anywhere in `src/` that reference `PlanningTemplate`/`PlanningSlot`/
`PlanningTemplateType`/`SlotPeriod`** are exactly the four V1-owned files themselves
(`PlanningGeneratorService`, `PlanningTemplateController`, the two entities, the two
enums) plus `PlanningGeneratorServiceV2`'s own backfill-era comments. Nothing else in
the codebase has a hidden dependency. This means cutover (Section C) only ever has to
swap *which service generates Mission rows* — every downstream system already treats
that as an implementation detail.

> **Batch 9 finding — "safe" did not mean "bug-free"**: wiring `PlanningDeploymentService`
> to a real HTTP request for the first time via a *functional* test (not the existing
> mocked unit test) surfaced a real, independent bug: `deploy()` called
> `$em->clear(Mission::class)`, but Doctrine ORM 3.x's `EntityManager::clear()` takes no
> arguments and always clears the *entire* identity map — the activated
> `PlanningVersion` was silently detached before the final `flush()`, so its `ACTIVE`
> status was never persisted. Fixed with an extra `flush()` immediately after
> activation, before the bulk Mission updates. This affects V1 deploys identically
> (same shared service) and was invisible to the existing mocked
> `PlanningDeploymentServiceTest` because a mock doesn't enforce real method signatures.
> Worth remembering: "decoupled from V1" (this section's main finding) is a different
> claim from "free of bugs" — only a real EntityManager exercising the real code proves
> the latter.

---

## E. Recurrence Evolution

Mapping Outlook's three-way choice onto the current model:

1. **"Modify only this occurrence"** — already fully supported via
   `PlanningOccurrenceException` (cancel/move/time-override/instrumentist-override). No
   new design needed.
2. **"Modify all occurrences"** — already supported via `SurgeonSchedulePost::update()`,
   **with one necessary clarification**: this can only ever mean *future, not-yet-generated*
   occurrences. Already-generated `Mission` rows are immutable snapshots (a hard rule
   across every batch) — retroactively rewriting past missions to match a rule change
   would violate that. This isn't a gap, it's the correct, already-implemented behavior;
   it just needs to be **named correctly in the UI** ("changes apply from today forward")
   so managers don't expect history to rewrite itself.
3. **"Modify this and future occurrences"** — **not yet supported structurally.** This
   is the real design question, and the user's proposed pattern is correct.

### Recommended design: split recurrence

Old post: `endDate = effectiveDate - 1 day`. New post: `startDate = effectiveDate`,
new recurrence/period/instrumentist. Two ordinary `SurgeonSchedulePost` rows, no schema
change required for the split mechanic itself.

**Why this works cleanly with zero generator changes**: `PlanningGeneratorServiceV2`
already iterates every active post independently and respects each one's own
`startDate`/`endDate` window — two posts with adjacent, non-overlapping date ranges
just naturally produce the right combined occurrence set. No special-casing needed.
`PlanningAlert` is keyed to `Mission`, not `SurgeonSchedulePost` — completely
unaffected by a split. Old `PlanningOccurrenceException` rows (cancellations etc. on
past dates) remain valid, untouched, still pointing at the old post id — and that's
correct, since they describe history that already happened under the old rule.

**Risks / things a future split-implementation batch must get right:**

- **Future exceptions must be reassigned.** Any `PlanningOccurrenceException` with
  `occurrenceDate >= effectiveDate` logically belongs to the *new* post going forward
  (it's now an exception to the new recurrence) but is currently keyed to the old
  post's id. The split operation must explicitly `UPDATE` those rows' `post_id` —
  this is the one genuinely non-trivial correctness requirement, not a structural
  problem, just an easy step to forget.
- **No DB link between old and new post today.** Reconstructing "this surgeon's
  assignment history at this site/period" requires a heuristic query (same
  surgeon+site+period, contiguous date ranges) rather than a clean join.
  **Recommendation: add a nullable self-referencing FK** (e.g. `previousPostId`) on
  `SurgeonSchedulePost` as cheap, additive, forward-compatible groundwork — proposed
  now, not implemented in this batch, but cheap enough that it shouldn't wait for a
  dedicated batch either.
- **UX risk, not backend risk**: a surgeon now has two post rows for what a manager
  perceives as one ongoing assignment. The post list/detail UI must present split
  chains as a single logical timeline (a "history" view), or managers will see
  confusing duplicates. Flagged for Batch 9 design, not a backend concern.

---

## F. Occurrence Exceptions — One vs. Multiple per Occurrence

Current limitation: one exception row per `(post, date)`, with `type` used as a
*mutually exclusive* discriminator (CANCELLED | MOVED | TIME_OVERRIDE |
INSTRUMENTIST_OVERRIDE) — so "moved to afternoon AND instrumentist changed" on the same
occurrence isn't expressible today.

**Recommendation: Option A (one object, multiple simultaneous overrides) — not Option B
(multiple rows).**

The entity *already* stores `overrideDate`, `overrideInstrumentist`,
`overrideStartTime`/`overrideEndTime` as independent nullable columns on one row — the
only thing preventing combining them today is the generator treating `type` as an
exclusive switch. The fix is conceptual, not structural: collapse the type model from
4 mutually-exclusive cases to **2 structural cases (CANCELLED, MOVED) + independent,
combinable override fields** for everything else (drop TIME_OVERRIDE and
INSTRUMENTIST_OVERRIDE as distinct "types" in favor of checking "is
`overrideInstrumentist` set?" / "are override times set?" independently — both can be
true on the same row). CANCELLED and MOVED remain genuinely exclusive with everything
else (you can't cancel *and* time-override the same occurrence — cancellation wins).

**Option B (multiple rows per occurrence) is rejected**: it reintroduces exactly the
layering-order ambiguity ("which row wins if two rows touch the same field?") that the
single-row design was built to avoid, and adds a real merge step to the generator for
no structural benefit — the entity already has room for every field on one row.

This is a real (if contained) future change to `OccurrenceExceptionType` and to
`PlanningGeneratorServiceV2`'s exception-application branches — not implemented now.
Existing CANCELLED/MOVED rows need no migration; existing TIME_OVERRIDE/
INSTRUMENTIST_OVERRIDE rows remain valid data, just reinterpreted. Should land **before**
Batch 9 builds an exception-editing UI that assumes one-type-at-a-time — retrofitting a
shipped UI is more expensive than designing the editor against the final model once.

---

## G. Conflict Detection (SURGEON_CONFLICT / INSTRUMENTIST_CONFLICT)

These two `PlanningAlertType` cases are defined (Batch 3) but never triggered anywhere
(confirmed again this batch). Investigating *why* surfaces a real, specific gap worth
fixing, not just an abstract question.

**Generation-time conflicts already exist and are handled correctly — no alert needed
there.** `PlanningGeneratorServiceV2::preview()` already computes a `CONFLICT` line
status (D-025/D-035 logic, unchanged from V1) when two posts in the *same*
preview/generate call would double-book an instrumentist. That's preview-only, visible
to the manager before anything is persisted, and correctly never becomes a committed
Mission. **This should stay exactly as it is — preview-only, never a persistent alert,
never a blocking error** (other non-conflicting lines must still generate).

**The real gap `SURGEON_CONFLICT`/`INSTRUMENTIST_CONFLICT` should fill: cross-site
double-booking that the per-call conflict check structurally cannot see.**
`loadExistingMissionsPool()` (and the reassign action's `hasConflict()` check) are both
scoped to the *current call's* `siteIds` — a shared multi-site instrumentist
double-booked across two *separate* generation runs (e.g. site A generated in January,
site B generated in February, both touching the same week) is never checked against
each other today. This is the scenario these two alert types exist for.

**Recommended behavior**: persistent alerts (not preview-only, not blocking errors) —
consistent with every other `PlanningAlertType` in the system. A genuinely
already-published cross-site double-booking shouldn't block deploy retroactively (the
double-booking might be deliberate, e.g. two short adjacent-site procedures), but the
manager must see it and decide (resolve/ignore/reassign), same triage model as
absence-driven alerts.

**Recommended trigger point** (future batch, not now): a detection pass conceptually
parallel to `AbsenceImpactService`, run when a Mission's surgeon/instrumentist
assignment changes (deploy time for newly-ASSIGNED missions, and the reassign action),
scanning *across all sites* for that person — not scoped to the current call.

---

## H. Notification Future

Verifying Batch 7's design evolves cleanly to the requested granularity
(`PLANNING_ALERT`, `SURGEON_ABSENCE`, `INSTRUMENTIST_ABSENCE`, `REASSIGNMENT`,
`OPEN_MISSION`, `REMINDER`): **yes, cleanly, with one contained exception.**

- `NotificationPreference` is keyed `(user, notificationType)` with `notification_type`
  stored as a plain `VARCHAR(32)` — no DB-level enum constraint. Adding new
  `NotificationType` cases is a zero-migration, PHP-only change.
- `NotificationPreferenceResolver::resolve(User, NotificationType): NotificationChannels`
  never needs to change — new types are just new values passed to the same method.
- `DefaultNotificationPreferenceResolver`'s hardcoded defaults would eventually want to
  branch per type (e.g. `REMINDER` might default `push=true` while `PLANNING_ALERT`
  defaults `push=false`) — a contained, additive change inside the resolver, never
  touching the interface or any caller.
- **The one real gap**: `PlanningAlertRaisedMessageHandler` currently hardcodes
  `NotificationType::PLANNING_ALERT` for every `PlanningAlert`, regardless of its
  specific `PlanningAlertType`. Splitting granularity (e.g. separate toggles for
  `SURGEON_ABSENCE` vs `REASSIGNMENT_REQUIRED`) needs one small mapping function
  (`PlanningAlertType → NotificationType`) inside that handler — contained, not
  architectural.
- **A pre-existing inconsistency worth flagging now, not silently leaving**: the
  *older*, pre-Batch-7 notification methods on `NotificationService`
  (`missionDeclaredNotifyManagersAdmins`, `planningMissionAssignedNotifyInstrumentist`,
  `planningNewOpenMissionsNotifySite`, `planningDeployedNotifyManager`) still hardcode
  IN_APP and bypass `NotificationPreferenceResolver` entirely — they predate Batch 7.
  Only the new planning-alert path respects preferences today. Retrofitting these to
  the resolver is a real, contained future task (matches the `OPEN_MISSION`/`REMINDER`
  types requested above) — listed as a yellow item, not done in this batch.

---

## I. Frontend Readiness Audit

### Green — unlikely to change, build on top of confidently
- Entity model: `SiteGroup`, `ShiftPeriodConfig`, `SurgeonSchedulePost`,
  `RecurrenceRule`, `PlanningOccurrenceException`, `PlanningAlert`.
- CRUD endpoints for all of the above (modulo the DTO harmonization notes in Section B).
- `PlanningAlert` lifecycle + transition + reassign/open-as-available endpoints.
- `PlanningVersion`/`Mission`/deploy/diff/PDF — confirmed fully decoupled (Section D),
  will not change for cutover reasons regardless of which generator produced them.
- Notification infrastructure shape (`NotificationEvent`, channels, resolver interface).
- RBAC convention (`PlanningVoter::PLANNING_MANAGE` everywhere, no exceptions found).
- **(Batch 9)** `POST /api/planning/v2/preview|generate|deploy` — implemented exactly
  per Section B's contract, fully tested (functional, real DB), zero V1 changes.

### Yellow — uncertain, address early in Batch 9 rather than after components exist
- DTO field-shape harmonization (Section B: missing `name` fields, flat vs nested,
  no pagination on surgeon-posts).
- Recurrence split-pattern (Section E) not yet implemented — scope the first post
  editor to direct edits only; defer the 3-way Outlook-style choice dialog.
- Occurrence exception type model (Section F) likely to collapse — wait for it rather
  than building a one-type-at-a-time editor now.
- No settings endpoints exist yet for users to read/write their own
  `NotificationPreference` rows — Batch 7 built the resolver, not a preferences CRUD API.

### Red — should be resolved before frontend starts on the affected feature
- ~~No V2 preview/generate/deploy HTTP endpoint exists at all.~~ **Resolved Batch 9** —
  see the Green list above. This was the single most important finding in the original
  version of this document.
- Cross-site conflict detection (Section G) doesn't exist. If an Alerts screen renders
  all six `PlanningAlertType` values, two of them will be permanently empty. Cheap
  mitigation: filter `SURGEON_CONFLICT`/`INSTRUMENTIST_CONFLICT` out of the UI until
  the detection pass exists, rather than blocking the whole Alerts screen on it.

---

## J. Deliverables Summary

1. **UX**: site-first post list with surgeon filter; monthly generation; 5-item nav
   (Posts/Generate/Published/Alerts/Settings) collapsing Preview+Generate into one
   screen and Reassign/Open into inline alert actions.
2. **API contracts**: frozen shapes in Section B; harmonize user-reference shape (add
   `name`) and add pagination to the surgeon-posts list before frontend builds against
   them; `PreviewResponse`/`GeneratedPlanningResponse` designed for an endpoint that
   doesn't exist yet.
3. **Cutover**: Option C, per-site `planningEngine` flag on `Hospital`, with an
   explicit exit criterion to eventually remove V1 — not before every site has run
   clean on V2 for a full cycle.
4. **PDF/export audit**: everything downstream of Mission/PlanningVersion is safe —
   zero risky or must-adapt findings. Cutover only ever swaps the generator.
5. **Recurrence evolution**: split-recurrence pattern recommended, zero generator
   changes required; add a nullable `previousPostId` self-FK now; the split operation
   itself must reassign future exceptions to the new post.
6. **Exception model**: collapse `OccurrenceExceptionType` to CANCELLED/MOVED +
   independent combinable override fields (Option A), not multiple rows (Option B).
7. **Conflict detection**: persistent, non-blocking alerts triggered by a cross-site
   detection pass (the real gap), not by the existing in-call preview conflict check
   (which is already correct and should stay preview-only).
8. **Notifications**: Batch 7 design evolves cleanly to finer `NotificationType`
   granularity; one contained mapping function needed in the message handler; older
   pre-Batch-7 notification call sites should eventually be retrofitted onto the
   resolver for consistency.
9. **Frontend readiness (updated Batch 9)**: green except one remaining soft gap
   (conflict detection) — the former hard blocker (no V2 generation endpoint) is
   resolved; `/api/planning/v2/preview|generate|deploy` exist, tested, documented.
10. **Unresolved questions** (originally listed before Batch 9 — first one now answered):
    - ~~New V2-specific generation controller, or branch the existing V1 routes on a
      flag/param?~~ **Answered**: a dedicated `PlanningV2GenerationController` was
      built, entirely parallel to V1's `PlanningGenerationController`/`PlanningDeployController`.
    - Does "surgeon posts by surgeon" need to ship in the first frontend batch, or
      can it wait?
    - Is monthly-only generation confirmed sufficient for every current site?
    - Build order for the first frontend batch: Generate screen, Alerts screen, or
      Settings/Posts screens first?
    - Should the recurrence-split groundwork (`previousPostId` FK) and the exception
      type-collapse (Section F) be their own small backend batch before frontend starts,
      or deferred until frontend actually needs them?
    - Build the cross-site conflict-detection pass now, or treat it as backlog?
    - Who decides the first pilot site and target date for flipping the cutover flag?

---

## K. Batch 13 launch status & consolidated remaining work

**Shipped and launched:**
- Backend (Batches 1–9): entities, generation engine, alerts, reassign/open-as-available
  actions, notifications, preview/generate/deploy endpoints — all tested.
- Frontend (Batches 10–12): 4-tab module (Postes/Générer/Alertes/Paramètres), redesigned
  to the hi-fi handoff spec, dedicated design tokens, `SearchableSelect` combobox.
- UI cutover (Batch 13): sidebar "Planning" entry and bare `/app/m/planning` route both
  point to V2. V1 nav entries hidden (not deleted). See D-048 in `docs/decisions.md`.

**Remaining work, none of it blocking the launch above:**

1. **Batch 14 — Notification preferences** (see `docs/decisions.md` D-048 for the full
   scope). `GET`/`PATCH /api/notification-preferences`, settings UI, per-type
   (Planning alert, Surgeon absence, Instrumentist absence, Reassignment, Open mission,
   Reminder) × per-channel (in-app, email, push) granularity. Today's defaults
   (in-app/email on, push not built) keep working without this — it only blocks
   broader rollout/generalization, not this initial launch.
2. **Monthly recurrence test coverage** (Batch 13 finding) — `PlanningGeneratorServiceV2`'s
   `MONTHLY`+`monthlyNthWeekday` branch has zero recurrence-expansion test coverage.
   Hidden from the create/edit post picker until a real test matrix backs it (see §E
   for the unrelated, already-covered split-recurrence design). Code path is untouched
   and still reachable by direct API call or by editing a pre-existing monthly post.
3. **Cross-site conflict detection** (§G) — `SURGEON_CONFLICT`/`INSTRUMENTIST_CONFLICT`
   still never trigger. Filtered out of the Alertes tab's type options already.
4. **Per-site cutover flag + V1 deletion** (§C) — not implemented. Current Batch 13
   cutover is UI-only (hide nav, redirect bare route); the `Hospital.planningEngine`
   flag and the actual removal of `PlanningTemplate`/`PlanningSlot`/
   `PlanningGeneratorService`/`PlanningTemplateController` remain future work, gated on
   a full incident-free billing/encoding cycle on V2.
5. **Recurrence split-pattern** (§E) and **exception type-collapse** (§F) — designed,
   not implemented; still future work, independent of the launch.

---

## L. Planning vivant — vie du planning après déploiement

**Filosofie (ADR D-052)** : le déploiement crée la première version du planning. Tout ce
qui suit — réassignations, prises de missions, ajouts, annulations, changements d'horaire —
est la vie du planning et opère directement sur les entités `Mission`, jamais via un nouveau
cycle generate/deploy.

### L1. Transitions de statut post-déploiement

```
Mission ASSIGNED  →  OPEN      (release — manager ouvre au pool)
Mission OPEN      →  ASSIGNED  (claim — instrumentiste, ou réassignation directe manager)
Mission OPEN      →  CANCELLED (cancel — manager annule la mission)
Mission [any]     →  nouvelle Mission DRAFT créée post-deploy (ajout manager)
```

### L2. Endpoints à créer (Batch 15)

| Endpoint | Transition | Acteur |
|---|---|---|
| `POST /api/missions/{id}/release` | ASSIGNED → OPEN | Manager |
| `POST /api/missions/{id}/cancel` | OPEN → CANCELLED | Manager |
| `GET  /api/missions/{id}/audit` | — | Manager / Chirurgien |
| `GET  /api/planning/versions/{id}/coverage-summary` | — | Manager |

`POST /api/missions/{id}/claim` et `POST /api/missions/{id}/assign-instrumentist` existent
déjà — étendus pour créer un `AuditEvent` et déclencher les notifications appropriées.

### L3. Audit trail — nouveaux `AuditEventType` (à ajouter dans Batch 15)

Chaque transition post-deploy crée un `AuditEvent` (actor + mission NOT NULL + payload
snapshot). Les noms des personnes sont toujours snapshotés dans le payload pour garantir
la lisibilité après modification ou désactivation d'un compte.

`AuditEvent.eventType` est mappé avec `enumType: AuditEventType::class` sur une colonne
VARCHAR — ajouter de nouveaux cases à l'enum PHP ne nécessite aucune migration DB. Le
code PHP doit être déployé avant que de nouvelles valeurs soient écrites en base.

```
MISSION_RELEASED_TO_POOL         payload: { fromInstrumentistId, fromInstrumentistName }
MISSION_CANCELLED_POST_DEPLOY    payload: { reason? }
MISSION_REASSIGNED_POST_DEPLOY   payload: { fromInstrumentistId, fromInstrumentistName,
                                            toInstrumentistId, toInstrumentistName }
MISSION_TIME_CHANGED_POST_DEPLOY payload: { fromStartAt, fromEndAt, toStartAt, toEndAt }
MISSION_ADDED_POST_DEPLOY        payload: { surgeonId, surgeonName, instrumentistId?, ... }
MISSION_CLAIMED_FROM_POOL        payload: { instrumentistId, instrumentistName }
```

`MISSION_CLAIMED_FROM_POOL` est **la journalisation du claim existant** (`POST /api/missions/{id}/claim`),
pas une nouvelle action. Le claim était déjà implémenté — Batch 15 ajoute uniquement la création
de l'AuditEvent correspondant.

### L4. Notifications — deux familles instrumentiste, une notification chirurgien par poste

**Famille 1 (publication initiale)** : `PLANNING_DEPLOYED_INSTRUMENTIST` — déclenché une
seule fois au déploiement, contient le résumé de la période et le PDF en pièce jointe.

**Famille 2 (mises à jour)** : `PLANNING_MISSION_REASSIGNED`, `PLANNING_MISSION_CANCELLED`,
`PLANNING_MISSION_ADDED`, `PLANNING_MISSION_UPDATED` — déclenché à chaque changement
affectant la mission d'un instrumentiste, contenu ciblé (nature du changement + before/after),
jamais de PDF. Ces types sont conçus maintenant, implémentés dans les batches futurs.

**Notification chirurgien (`PLANNING_DEPLOYED_SURGEON`)** : payload `posts[]` — une entrée
par poste du chirurgien (date, site, période, couvert/non couvert, nom instrumentiste ou
motif). Jamais de compteurs agrégés. Voir D-053.

**Couverture d'un poste** : `SURGEON_POST_COVERED` notifie le chirurgien lors d'OPEN →
ASSIGNED avec la date, le site, la période, le nom de l'instrumentiste et l'heure de prise.
`SURGEON_POST_UNCOVERED` notifie lors d'ASSIGNED → OPEN (relâché au pool).

**`NotificationType` — catalogue complet post-Batch 15** :

```
PLANNING_DEPLOYED_INSTRUMENTIST  — publication initiale instrumentiste (avec PDF)
PLANNING_DEPLOYED_SURGEON        — publication initiale chirurgien (par poste)
PLANNING_DEPLOYED_MANAGER        — publication initiale manager
OPEN_MISSION_AVAILABLE           — mission mise en pool (instrumentistes éligibles seulement)
SURGEON_POST_COVERED             — OPEN → ASSIGNED sur un poste du chirurgien
SURGEON_POST_UNCOVERED           — ASSIGNED → OPEN sur un poste du chirurgien
PLANNING_MISSION_REASSIGNED      — instrumentiste changé (future)
PLANNING_MISSION_CANCELLED       — mission annulée (future)
PLANNING_MISSION_ADDED           — mission ajoutée post-deploy (future)
PLANNING_MISSION_UPDATED         — horaire/période modifié (future)
```

Tous les canaux passent par `NotificationPreferenceResolver` — jamais de notification
codée en dur. `NotificationEvent.eventType` est VARCHAR(100) : aucune migration DB pour
les nouveaux types.

### L5. Bilan de couverture

`GET /api/planning/versions/{id}/coverage-summary` retourne en temps réel, pour la version
ACTIVE d'une période/site :

```json
{
  "total": 25,
  "assigned": 22,
  "open": 2,
  "cancelled": 1,
  "coveragePercent": 88
}
```

Affiché comme bandeau dans `PlanningSchedulePage` — pas de nouvelle page, pas de nouveau
item de navigation.

### L7. MissionStatus::CANCELLED — nouveau statut (Batch 15B)

La transition OPEN → CANCELLED (annulation manager) requiert l'ajout de `case CANCELLED = 'CANCELLED'`
à `MissionStatus.php`. Pas de migration DB (colonne VARCHAR backing). Ce statut exclut la
mission du `total` du coverage KPI — une mission annulée ne représente plus un besoin de
couverture.

### L8. Références

- ADR D-052 — philosophie "planning vivant" + invariant "never regenerate" + pattern MissionChangedMessage
- ADR D-039 note Batch 15A — simplification selectedUncoveredMissionIds pour V2
- ADR D-053 — notification chirurgien par poste
- ADR D-054 — deux familles de notifications instrumentiste
- ADR D-055 — AuditEvent comme historique post-déploiement + PlanningVersion history
- `docs/architecture.md` §7 "Planning vivant — vie du planning après déploiement"

### L9. Mode Modification — éditeur unifié (Batch 15K) ✅ DONE (2026-07-10)

Le handoff design (`MODES-Generation-vs-Modification.md`) décrivait un second mode pour
`GeneratePlanningTab` permettant d'éditer un planning déjà déployé dans le **même** éditeur
que la génération, jamais un fork. Implémenté en Batch 15K : `PlanningEditorMode =
"generation" | "modification"`, dérivé d'un seul état (`modificationVersionId`), même
composant, même inspecteur permanent, même système de sélection/filtres — seuls la source
de données (Missions réelles au lieu du Preview), la palette (ambre au lieu de bleu) et les
libellés de CTA changent. Deux points d'entrée : ligne "Modifier" dans l'historique des
plannings, ou clic sur un chip de mois déjà généré.

**Redéploiement et notifications ciblées.** Le "Redéployer" de ce mode n'emprunte **pas**
le chemin `PLANNING_MISSION_REASSIGNED`/`CANCELLED`/`ADDED`/`UPDATED` de L4 (ces types
restent le mécanisme des actions unitaires post-deploy hors mode Modification — release,
cancel, reassign, assign appelés individuellement continuent de dispatcher
`MissionLifecycleChangedMessage` normalement). À l'intérieur d'un lot Modification, chaque
mutation est appliquée avec `notify: false` pour ne pas déclencher ces notifications
unitaires ; à la place, `PlanningModificationService` calcule un diff avant/après sur
l'ensemble du lot (`PlanningDiffService::computeDiffFromSnapshots()`) et déclenche **une
seule fois** `PlanningChangeSummaryService::sendChangeSummaryEmails()` — jusque-là écrit
mais jamais câblé — pour envoyer exactement un email récapitulatif par personne réellement
concernée (instrumentiste ou chirurgien dont au moins une mission apparaît dans le diff).
Personne d'autre ne reçoit rien. Voir `docs/planning-v2-roadmap.md` Batch 15K pour le détail
technique complet (endpoint, tests, fichiers).

---

## M. Batch 15 — Test strategy (design-only, pre-implementation)

Tests are organized per sub-batch. All functional HTTP tests use the real EntityManager
(never mocked — see D-042 for why mocks miss deploy-path bugs). Messenger transport is
the in-memory test transport during tests.

---

### Batch 15A — Deploy simplification (all uncovered DRAFT → OPEN for V2)

**Unit (`PlanningDeploymentServiceTest`)**
- `test_v2_deploy_all_uncovered_draft_become_open_without_selection`
- `test_v2_deploy_draft_with_instrumentist_become_assigned` — regression
- `test_v1_deploy_legacy_fallback_still_uses_selected_ids` — V1 path unchanged

**Functional HTTP**
- `POST /api/planning/v2/deploy` → `{ missionCount, openPoolCount }` correct values
- Deploy without versionId (V1 path) → `selectedUncoveredMissionIds` still honored

---

### Batch 15B — Release, Cancel endpoints + MissionStatus::CANCELLED

**Unit (`MissionVoterTest`)**
- `test_manager_can_release_assigned_mission`
- `test_instrumentist_cannot_release_own_mission`
- `test_manager_can_cancel_open_mission`

**Unit (`MissionService` or dedicated service)**
- `test_release_creates_audit_event_with_instrumentist_snapshot`
- `test_cancel_creates_audit_event`
- `test_release_dispatches_mission_changed_message`

**Functional HTTP**
- `POST /api/missions/{id}/release` on ASSIGNED → 200, status OPEN, AuditEvent in DB
- `POST /api/missions/{id}/release` on OPEN → 422
- `POST /api/missions/{id}/cancel` on OPEN → 200, status CANCELLED

**Regression**
- Billing (FirmInvoice, Statement) unaffected — CANCELLED missions not billable

---

### Batch 15C — Surgeon deployment notification (per-post payload)

**Unit (`PlanningDeployPdfsMessageHandlerTest`)**
- `test_surgeon_notification_payload_contains_posts_array_not_counts`
- `test_surgeon_posts_sorted_chronologically`
- `test_uncovered_post_has_reason_label_not_null`
- `test_covered_post_has_instrumentist_name`
- `test_no_patient_or_financial_data_in_payload`
- `test_surgeon_only_sees_own_posts`

**Functional**
- Deploy → `NotificationEvent` PLANNING_DEPLOYED_SURGEON created per surgeon with `posts[]`

---

### Batch 15D — Instrumentist deployment notification (Famille 1)

**Unit**
- `test_each_assigned_instrumentist_receives_exactly_one_notification`
- `test_notification_contains_only_own_missions`
- `test_unassigned_instrumentist_receives_no_notification`
- `test_preference_resolver_consulted_not_hardcoded`

**Functional**
- Deploy with 3 instrumentists → 3 PLANNING_DEPLOYED_INSTRUMENTIST NotificationEvents

---

### Batch 15E — OPEN pool eligibility filtering (OpenMissionEligibleInstrumentistResolver)

**Unit**
- `test_absent_instrumentist_excluded`
- `test_inactive_instrumentist_excluded`
- `test_instrumentist_at_different_site_excluded`
- `test_instrumentist_with_conflicting_mission_excluded`
- `test_eligible_instrumentist_included`
- `test_uses_batch_query_not_per_mission_loop` — assert DB query count via SQL logger

**Functional**
- Deploy with OPEN missions → only eligible site-affiliated instrumentists notified
- Non-affiliated → no OPEN_MISSION_AVAILABLE notification

---

### Batch 15F — SURGEON_POST_COVERED / SURGEON_POST_UNCOVERED

**Unit**
- `test_claim_triggers_surgeon_post_covered_notification`
- `test_covered_payload_contains_date_site_period_instrumentist_coveredAt`
- `test_release_triggers_surgeon_post_uncovered_notification`

**Functional**
- `POST /api/missions/{id}/claim` → SURGEON_POST_COVERED NotificationEvent for surgeon
- `POST /api/missions/{id}/release` → SURGEON_POST_UNCOVERED NotificationEvent for surgeon

---

### Batch 15G — Audit trail completeness

**Unit**
- `test_release_payload_snapshots_instrumentist_name_at_action_time`
- `test_cancel_audit_event_contains_actor_and_timestamp`
- `test_reassign_payload_has_from_and_to_snapshot`
- `test_audit_event_not_created_if_transition_fails`

**Functional**
- `GET /api/missions/{id}/audit` → 200, events sorted DESC
- Payload contains no patient or financial data

---

### Batch 15H — Coverage summary endpoint

**Unit (`PlanningCoverageServiceTest`)**
- `test_cancelled_missions_excluded_from_total`
- `test_open_missions_reduce_coverage_percent`
- `test_coverage_uses_aggregate_query_no_entity_hydration`
- `test_coverage_is_never_persisted`

**Functional**
- `GET /api/planning/versions/{id}/coverage-summary` → `{ total, covered, open, cancelled, coveragePercent }`
- After claim: coveragePercent increases
- After cancel: total decreases

---

### Batch 15I — PlanningVersion history endpoint

**Unit**
- `test_deployment_event_is_first_in_timeline`
- `test_mission_audit_events_aggregated_chronologically`

**Functional**
- `GET /api/planning/versions/{id}/history` → deployment entry + mission change events in order

---

### Batch 15J — MissionChangedMessage dispatch pattern

**Unit (`MissionChangedMessageHandlerTest`)**
- `test_released_change_type_notifies_surgeon_only`
- `test_reassigned_change_type_notifies_old_and_new_instrumentist_and_surgeon`
- `test_preference_resolver_consulted_per_audience`
- `test_email_not_sent_if_preference_email_disabled`

**Integration (Messenger)**
- `test_release_dispatches_message_to_async_transport` — not handled synchronously
- `test_MissionChangedMessage_routing_present_in_messenger_yaml` — parse YAML (same pattern as D-043 regression test)

---

### Batch 15K — Failure handling and idempotence

**Unit**
- `test_pdf_failure_for_one_instrumentist_does_not_block_others`
- `test_notification_failure_is_logged_not_rethrown`
- `test_handler_skips_if_deployment_already_done` — idempotence re-entry
- `test_handler_marks_failed_on_fatal_error`

**Functional**
- Handler retry does not create duplicate AuditEvents (count assertion in DB)

---

### Regression suite (every sub-batch, before merge)

- `PlanningPreviewPerformanceTest::test_two_month_preview_uses_only_3_db_queries` — stays green
- Existing `POST /api/missions/{id}/claim` flow unchanged
- Instrumentist polling (`useInstrumentistMissionSync`) — OPEN missions still appear
- Billing flows unaffected by CANCELLED status addition
- Full backend PHPUnit suite green
- Frontend build green if any frontend touched

---

### Manual smoke tests (staging, after deploy)

1. Generate + deploy → surgeon and instrumentist receive email notifications
2. Surgeon email: each post rendered as individual card, no aggregate counts
3. Release a mission → surgeon notified "non couvert", eligible instrumentists see OPEN
4. Claim the OPEN mission → surgeon notified "couvert" with instrumentist name and timestamp
5. Coverage summary percentages update after each claim/release/cancel
6. Version history: timeline shows deployment entry then subsequent events in order
7. Cancel mission: coverage total decreases (not only covered count)
8. Re-generate same month post-deploy: OPEN/ASSIGNED missions untouched (invariant check)
