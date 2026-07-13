# Planning V2 — Engineering Roadmap

> **Status:** Frozen — approved 2026-06-29  
> **Authority:** This document is the single source of truth for all remaining Planning V2 implementation.  
> Every batch must reference this document before implementation begins.  
> Changes require an explicit ADR amendment.

---

## 1. Vision

### 1.1 Planning Model

A planning is born from **SurgeonSchedulePost** entries. Each Post is a recurring template: "Dr Dupont operates at Delta Hospital, Tuesday mornings, every week." Posts are permanent and describe **future intent** — they are never modified to resolve an operational problem on a published planning.

The planning lifecycle has two distinct phases:

```
PLANNING MODEL (Posts)          EXECUTION MODEL (Missions)
══════════════════════          ══════════════════════════
SurgeonSchedulePost             Mission
  · surgeon                       · surgeon
  · site                          · instrumentist
  · period                        · site / period / times
  · recurrence                    · status
  · instrumentist (optional)      · planningVersion FK
                                  · AuditEvent[]
```

### 1.2 The Generation Pipeline

```
SurgeonSchedulePost(s)
        ↓
   preview()              Pure computation. Nothing written to DB.
        ↓                 Returns PreviewLineV2[]. Manager edits locally.
 [Preview Editor]         Manager assigns instrumentists, skips lines.
        ↓
  generate()              Creates Mission entities in DRAFT status.
        ↓                 Creates PlanningVersion record.
   deploy()               DRAFT → ASSIGNED (has instrumentist)
        ↓                         DRAFT → OPEN (no instrumentist)
                          Creates PlanningDeployment record.
                          Dispatches PlanningDeployPdfsMessage (async).
```

### 1.3 Living Planning

After deployment, the planning is **not frozen**. It is a living object that evolves until the period closes. All subsequent changes operate exclusively on **existing Mission entities** through dedicated endpoints. There is never a second generate/deploy cycle for operational corrections.

```
PUBLISHED PLANNING
══════════════════════════════════════════════════
  Prise de mission      OPEN → ASSIGNED  (instrumentiste)
  Ouverture pool        ASSIGNED → OPEN  (manager relâche)
  Annulation            OPEN → CANCELLED (manager)
  Réassignation         ASSIGNED → OPEN → ASSIGNED (manager)
  Ajout post-deploy     nouvelle Mission créée directement
  Chaque action:        AuditEvent + MissionLifecycleChangedMessage
══════════════════════════════════════════════════
```

### 1.4 Entity Relationships

```
SurgeonSchedulePost (1) ──────────── (N) Mission
                                          │
PlanningVersion (1) ──────────────── (N) Mission
        │                                 │
PlanningDeployment (1) ─────────────────(1) PlanningVersion
                                          │
                               (N) AuditEvent
                                          │
                               (N) NotificationEvent
```

**SurgeonSchedulePost** → describes recurring surgical slot intent. Permanent. Never mutated post-deploy.

**Mission** → operational reality. One per (date × post × deploy). Lifecycle: DRAFT → OPEN/ASSIGNED → SUBMITTED → VALIDATED → CLOSED. Can be CANCELLED post-deploy.

**PlanningVersion** → groups all Missions generated in a single `generate()` call. One per (site/group × year × month × generation run). Identified by `id`.

**PlanningDeployment** → records a single deploy event (timestamp, author, missionCount, openPoolCount, status PENDING/PROCESSING/DONE/FAILED). Immutable after DONE.

**AuditEvent** → append-only log of every post-deploy change. `actor` FK NOT NULL, `mission` FK NOT NULL, `eventType` VARCHAR enum, `payload` JSON snapshot.

**NotificationEvent** → in-app notification record. `eventType` VARCHAR(100) — no migration needed for new types. Associated to recipient user.

**Coverage** → computed in real time from Mission statuses. Never persisted. Never approximated.

---

## 2. Immutable Architectural Rules

These rules are non-negotiable. Any PR that violates them must be rejected at review.

### R-01 — Never Regenerate a Published Mission

> `generate()` only creates or updates DRAFT missions. Statuses OPEN, ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS, CANCELLED are terminal for the generator — silently skipped.

**Why:** Regenerating would destroy the operational history (AuditEvent), notification context, and instrumentist assignments that exist post-deploy. Source: D-029, D-034, D-052.

### R-02 — SurgeonSchedulePost Is Never Modified for Operational Reasons

> A manager who needs to remove a slot from a published planning cancels the Mission. The Post is untouched.

**Why:** Posts describe future intent. Missions describe current reality. Mixing the two makes it impossible to distinguish "we stopped offering this slot permanently" from "this particular occurrence was cancelled operationally." Source: D-052.

### R-03 — Coverage Is Computed, Never Persisted

> `coveragePercent`, `covered`, `total`, `open`, `cancelled` are computed on-demand via a single DQL aggregate query. No column, no cache, no event listener updates them.

**Formula:**
```
total    = COUNT(missions WHERE status IN (OPEN, ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS))
covered  = COUNT(missions WHERE status IN (ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS))
open     = COUNT(missions WHERE status = OPEN)
cancelled = COUNT(missions WHERE status = CANCELLED)
coveragePercent = round(covered / total × 100, 1)   // null if total = 0
```

**Why:** Persisting derived state introduces cache invalidation bugs. A single aggregate query is fast enough. Source: architecture.md §Coverage.

### R-04 — All Mission Mutations Pass Through the Application Service

> No controller, no handler, no listener may call `$em->persist($mission)` or mutate a Mission's fields directly. All mutation goes through `MissionPostDeployService` (or a typed sub-service in future batches).

**Why:** The service is the single enforcement point for business validation + AuditEvent creation + MissionLifecycleChangedMessage dispatch. Bypassing it silently breaks the audit trail and notifications. Source: D-056.

**Test gate:** Every batch that introduces a Mission-mutating endpoint must include a test that asserts no `em->persist(mission)` call exists in the controller.

### R-05 — AuditEvent Flushed Before Message Dispatch

> Inside the application service: status mutation → AuditEvent creation → `em->flush()` → `bus->dispatch(MissionLifecycleChangedMessage(...))`.

**Why:** If the Messenger worker fails and retries, the AuditEvent is already persisted. The transition is permanently recorded even if notifications are delayed. Reversing the order (dispatch then flush) risks a committed notification with no audit trail on DB rollback.

### R-06 — NotificationPreferenceResolver Is Always Consulted

> No notification (in-app or email) is sent without first calling `NotificationPreferenceResolver::resolve($user, $notificationType)`.

**Why:** Hardcoded "always send" bypasses user preferences and causes spam. Source: D-054.

**Exception:** The existing legacy PDF emails in PlanningDeployPdfsMessageHandler steps 5–6 predate this rule. Batch 15C migrates them to use the resolver.

### R-07 — MissionLifecycleChangedMessage Is Always Dispatched After Mission Mutation

> Every application service method that mutates a Mission status must dispatch `MissionLifecycleChangedMessage` after the flush. No silent mutations.

**Why:** Notifications and future subscribers (analytics, webhooks) depend on this message. A mutation without dispatch is invisible to the async layer.

### R-08 — MissionLifecycleChangedMessage Routed to Async Transport

> `messenger.yaml` must contain `MissionLifecycleChangedMessage: async`. Every batch introducing a new Messenger message must add its routing and include a YAML-parse test that verifies this (D-043 pattern).

**Why:** An unrouted message is handled synchronously in the HTTP request — violating D-043 and degrading response times.

### R-09 — Controllers Are Orchestrators Only

> Controllers read the HTTP request, call the application service, serialize the response. They contain no business logic, no `em->persist`, no status transitions.

### R-10 — Preview Is Stateless; previewVersion Is a Hash

> The backend stores no preview state. `previewVersion` is a SHA-256 hex digest of the sorted inputs to `preview()` (SurgeonSchedulePosts, Absences, ShiftPeriodConfigs for the generation scope), computed at preview-time and recomputed at generate-time for comparison.

**Why:** Server-side preview state requires a lifecycle (expiry, cleanup, multi-tab isolation). A hash is free — the inputs are already loaded for preview computation. Source: architecture review 2026-06-29.

### R-11 — No Patient or Financial Data in Notification Payloads

> AuditEvent payloads and NotificationEvent payloads must never contain patient names, diagnosis codes, billing amounts, or any PHI.

### R-12 — Payload Snapshots Contain Names at Time of Action

> AuditEvent payloads must include `fromInstrumentistName`, `toInstrumentistName`, `actorName` etc. as strings — never rely on FK resolution at read-time.

**Why:** Users can be renamed or deactivated. An audit log that requires resolving a deleted user FK is unreadable.

---

## 3. Global Dependency Graph

### 3.1 Execution Order

```
Batch 14.5   ────────────────────────────────────────────  (generation workflow, independent)
     │
     │   (no file conflicts — can be parallel with 15A in 2-dev context)
     │
Batch 15A   ─── foundations (enums, message skeleton, deploy simplification)
     │
Batch 15B   ─── post-deploy actions (release, cancel, audit, D-056 application service)
     ├──────────────────────────────────────────────────────────────────────┐
     │                                                                      │
Batch 15C   ─── deploy notifications revamp                         Batch 15F ─── coverage KPI + history (backend)
     │                                                                      │
Batch 15D   ─── OPEN pool eligibility                               Batch 15G ─── living planning UI (schedule page)
     │                                                                      │
Batch 15E   ─── coverage notifications (MissionLifecycleChangedMessageHandler)   │
     │                                                               Batch 15H ─── planning history UI
     │                                                                      │
     └──────────────────── Batch 14 ───────────────────────────────────────┘
                           (notification preferences — needs all types from 15E)

Batch 15I   ─── analytics (future, non-blocking, after 15F+15H)
```

### 3.2 Why This Order Is Optimal

**14.5 first:** The Preview Editor belongs to the generation workflow (preview → edit → generate → deploy). Once deployed, the Preview Editor is no longer involved. Implementing it before the living planning batches preserves the natural user journey order and avoids context-switching between pre-deploy and post-deploy concerns. No file conflicts with any 15x batch.

**15A before everything else in 15x:** Foundations provides the enums (`MissionStatus::CANCELLED`, `NotificationType` extensions, `MissionChangeType`), the `MissionLifecycleChangedMessage` class, and the `MissionPostDeployService` skeleton that every subsequent batch depends on. Without 15A, 15B cannot compile.

**15B before 15C:** 15C revamps the deploy notifications to integrate with `NotificationPreferenceResolver`. 15B establishes `MissionPostDeployService` as the application service layer and creates the first real `MissionLifecycleChangedMessage` dispatches. 15C's handler integration depends on the message being correctly structured and routed.

**15C before 15D:** 15D fixes pool eligibility, which is a notification concern. Fixing eligibility before notifications are correctly structured (15C) would mean rewriting the notification path twice.

**15D before 15E:** 15E implements the MissionLifecycleChangedMessageHandler for RELEASED and CLAIMED. The CLAIMED case notifies eligible instrumentists about pool missions — the eligibility resolver from 15D is a prerequisite.

**15B parallel to 15F:** Coverage KPI and History only depend on 15B (AuditEvent infrastructure and the new Mission transitions are the data source). 15F can be developed independently from 15C–15E.

**15F before 15G and 15H:** Both frontend batches consume the backend APIs from 15F. They can be developed in parallel with each other since they touch different pages.

**14 last among blocking batches:** The Notification Preferences UI must enumerate every `NotificationType` case. The complete catalogue is only available after 15E (which adds the final SURGEON_POST_COVERED/UNCOVERED types). Delivering 14 earlier would mean updating the UI twice as types are added.

### 3.3 What Breaks If Batches Are Swapped

| Swap | Consequence |
|---|---|
| 15B before 15A | `MissionStatus::CANCELLED`, `MissionLifecycleChangedMessage` do not exist — compilation failure |
| 15C before 15B | `MissionPostDeployService` does not exist — `PlanningDeployPdfsMessageHandler` refactor has no service to inject |
| 15E before 15D | Handler dispatches `OPEN_MISSION_AVAILABLE` to ALL site instrumentists — eligibility bug ships |
| 14 before 15E | Preferences UI lists only `PLANNING_ALERT` — unusable |
| 15G before 15F | `GET /api/planning/versions/{id}/coverage-summary` and history endpoints do not exist — frontend has no data |
| 14.5 after 15G | No technical blocker, but it ships a broken UX sequence: managers have the living planning UI before the Preview Editor is ready, which means the generation workflow has a gap |

---

## 4. Batch Specifications

---

### Batch 15J.1 — Frontend Test Stabilization ✅ DONE (2026-07-01)

#### Root causes fixed

| File | Failing tests | Root cause | Fix |
|------|--------------|------------|-----|
| `GeneratePlanningTab.test.tsx` | 4 | `useNavigate()` called outside Router context | Wrapped render in `<MemoryRouter>` |
| `AdminCreateUserModal.test.tsx` | 3 | Missing `10000`ms timeout; first 2 tests already had it, last 3 forgot | Added `, 10000` to match the established pattern |
| `AbsencesPage.test.tsx` | 2 | 3-date loops with `user.clear + user.type` per iteration accumulated enough async overhead to exceed 5000ms | Replaced with `fireEvent.change` (same onChange signal) + `, 10000` timeout; all assertions unchanged |

**Final count**: 261/261 green, 27 test files, 0 skipped, 0 todo.

### Batch 14.5 / 15J — Preview Editor ✅ DONE (2026-07-01)

#### Files changed (Batch 15J)

- `frontend/src/app/pages/manager/planning/PlanningGeneratePage.tsx` — added CONFLICT/OVERRIDE filter chips; split CONFLICT from UNCOVERED in stats and filter logic; upgraded dirty dot → "Édité" Chip with `data-testid="edited-badge"`; added `conflicts` to Stats and stats bar; added `editedLines` dependency to `visibleLines` memo for OVERRIDE filter
- `frontend/src/app/pages/manager/planning/PlanningGeneratePage.test.tsx` — added 16 tests: instrumentist search, combined filters, inspector opens/updates/navigates, edit assignment, clear assignment, reset row, bulk assign, edited badge, stats update, conflict filter, manager overrides filter

#### Objective

**Business:** Give the manager a primary working screen where they can review, adjust, and validate the generated planning before committing it to the database. The Preview Editor replaces the current read-only preview table with an interactive editing surface.

**Technical:** Extend `PlanningGeneratorServiceV2::generate()` to accept an optional `overrideLines` array. Add `previewVersion` to the preview response and validate it at generate-time. Rebuild `PlanningGeneratePage` from a display component to an interactive editor.

**User value:** A manager can assign instrumentists, skip unwanted missions, and see live coverage statistics — all before a single Mission is written to the database.

#### Scope

**Included:**
- `previewVersion` hash on the preview response
- 409 PREVIEW_EXPIRED on stale generate request
- `overrideLines` support in `generate()`
- Dirty state management (local copy, original reference, dirty badge)
- Inspector panel (right-side, permanent, per-line detail and edit)
- Statistics bar (live, local computation)
- Multi-selection, bulk skip, bulk assign (same instrumentist for all selected lines of same site)
- Search (surgeon name, instrumentist name, site name)
- Filters (covered / uncovered / conflict / BLOCK / CONSULTATION)
- Reset one line
- Reset all (reload preview from API)
- V1→V2 type migration in PlanningGeneratePage (remove `slotId`, use `${date}-${postId}` as lineKey)

**Excluded:**
- Bulk period change (V2 — too many edge cases with mixed sites/surgeons)
- Complex bulk reassign with per-line different values
- Undo/redo stack (V2 — see Future roadmap)
- Persistent editor state across browser sessions

#### Backend

**`PlanningGeneratorServiceV2::preview()`**
- After computing lines, compute `previewVersion`:
  - Query 1 (already loaded for D-036): all active `SurgeonSchedulePost` for the scope — serialize `id`, `surgeonId`, `siteId`, `period`, `type`, `instrumentistId`, `startDate`, `endDate`, `active`, recurrence fields — sort by id ASC
  - Query 2 (already loaded): all `Absence` overlapping the period — serialize `id`, `userId`, `dateStart`, `dateEnd` — sort by id ASC
  - Query 3 (already loaded): all `ShiftPeriodConfig` for the relevant sites — serialize `id`, `siteId`, `period`, `startTime`, `endTime`, `active` — sort by id ASC
  - SHA-256 hex of JSON-encoded concatenation of the three sorted arrays
- Return `previewVersion` alongside `lines` and `summary`

**`PlanningGeneratorServiceV2::generate()`**
- New optional parameter: `?array $overrideLines = null`
- If `$overrideLines` is null: call `preview()` internally as before
- If `$overrideLines` is provided: use them directly as the line set — skip internal `preview()` call
- R-01 invariant applies identically: any line referencing an `existingMissionId` with status ≠ DRAFT is silently skipped regardless of `overrideLines`
- `previewVersion` validation (see Controller below)

**`PlanningV2GenerationController`**
- `POST /api/planning/v2/preview`: response now includes `previewVersion` (string) and `generatedAt` (ISO 8601)
- `POST /api/planning/v2/generate`: accept optional `previewVersion` (string) and `lines` (PreviewLineV2[]) in the JSON body
  - If `previewVersion` is provided: recompute the hash for the current scope; if mismatch → 409 `PREVIEW_EXPIRED`
  - If `lines` is provided: pass to `generate($overrideLines = $lines)`
  - If neither provided: existing behavior (no validation, no overrides)

**Validation rules for `lines` when provided:**
- Each line must contain: `date` (YYYY-MM-DD), `postId` (integer), `status` (PreviewLineStatus)
- `instrumentistId` optional (null = no assignment)
- `period` optional override — if provided, must be valid `ShiftPeriod`
- Lines with `status = SKIPPED` are excluded from generation
- Unknown `postId` values → 422

#### Frontend

**`PlanningGeneratePage.tsx` — major rewrite**

State model:
```
originalLines: PreviewLineV2[]   // immutable reference from API response
editedLines: Map<lineKey, Partial<PreviewLineV2>>  // local overrides only
previewVersion: string           // from API response
```

`lineKey` = `${line.date}-${line.postId}` (never `slotId`)

The effective line for display = `merge(originalLines[key], editedLines[key])`.

**Statistics bar** (live, computed from effective lines, no API call):
```
{total} missions générées  ·  {covered} couvertes  ·  {uncovered} non couvertes  ·  {modified} modifiées  ·  {coveragePercent}% couverture
```
Updates on every local edit.

**Inspector panel** (permanent right side, ~320px):
- Opens when manager clicks a row
- Displays: date, site, surgeon, period, type, current status, current instrumentist
- Edit controls: instrumentist Select (existing autocomplete), period Select, SKIPPED toggle
- Dirty badge on modified fields
- Reset Line button (restores to `originalLines[key]`)
- Keyboard navigation: ArrowDown/ArrowUp to move between rows while panel stays open

**Table columns:**
- Checkbox (multi-select)
- Date
- Chirurgien
- Site
- Période
- Type (BLOCK / CONSULTATION)
- Instrumentiste (current, with dirty indicator if modified)
- Statut (badge: COVERED / UNCOVERED / SKIPPED / CONFLICT / MODIFIED)

**Toolbar:**
- Search input (debounced 300ms): filters by surgeon name, instrumentist name, site name across effective lines
- Filter chips: COUVERT, NON COUVERT, CONFLITS, BLOCK, CONSULTATION — multi-select
- Bulk actions bar (visible when ≥ 1 row selected): "Assigner [instrumentiste]" Select + button, "Passer" button
- "Réinitialiser tout" button (confirm dialog before reload)

**Bulk assign constraint:** When assigning in bulk, the selected instrumentist must be compatible with all selected lines' sites. If not, show per-line validation error, proceed with compatible lines only.

**React Query:**
- `usePreviewV2(params)` — fetches preview, stores `previewVersion` alongside lines
- `useGenerateV2()` — mutation, sends `{ previewVersion, lines: [...effectiveLines] }`, handles 409 PREVIEW_EXPIRED by showing "Le modèle de planning a changé. Veuillez régénérer l'aperçu." toast with "Régénérer" button

**Accessibility:**
- Inspector panel traps focus when open; Escape closes it
- Row selection keyboard: Space to toggle, Shift+Click to range-select
- Statistics bar announces updates via `aria-live="polite"`

**Responsive:** Inspector panel collapses to a bottom sheet on viewports < 1024px.

#### Database

No new tables. No migrations. No backfill.

#### API

```
POST /api/planning/v2/preview
Request: { siteId?: number, siteGroupId?: number, year: number, month: number }
Response 200:
{
  "previewVersion": "a3f9c2...",   // SHA-256 hex, 64 chars
  "generatedAt": "2026-07-01T09:12:00+02:00",
  "lines": [ ...PreviewLineV2[] ],
  "summary": { "total": 127, "covered": 121, "uncovered": 6, "skipped": 2, "conflict": 0, "modified": 0 }
}

POST /api/planning/v2/generate
Request:
{
  "siteId": 4,
  "year": 2026,
  "month": 7,
  "previewVersion": "a3f9c2...",   // optional
  "lines": [ ...PreviewLineV2[] ]  // optional — if provided, bypasses internal preview()
}
Response 200: { "versionId": 42, "created": 121, "updated": 0, "skipped": 6 }
Response 409:
{
  "code": "PREVIEW_EXPIRED",
  "message": "The planning model has changed since this preview was generated. Please regenerate the preview."
}
```

Invalidating events for `previewVersion`:
- SurgeonSchedulePost: created, updated (any field), deleted
- Absence: created, updated (userId, dateStart, dateEnd), deleted
- ShiftPeriodConfig: startTime or endTime changed, deactivated
- SiteGroup membership: site added or removed (if generating by group)

Deployment does NOT invalidate `previewVersion` — R-01 handles that case safely.

#### Documentation

- `docs/api.md`: update `POST /api/planning/v2/preview` (add `previewVersion`, `generatedAt`) and `POST /api/planning/v2/generate` (add `previewVersion`, `lines`)
- `docs/planning-v2-architecture-freeze.md`: add §N — Preview Editor (previewVersion, dirty state, bulk actions, inspector panel)
- `docs/planning-v2-roadmap.md`: mark Batch 14.5 as DONE

#### Tests

**Unit (`PlanningGeneratorServiceV2Test`)**
- `test_preview_returns_preview_version_hash`
- `test_preview_version_is_deterministic_for_same_inputs`
- `test_preview_version_changes_when_post_updated`
- `test_preview_version_changes_when_absence_added`
- `test_preview_version_changes_when_post_deleted`
- `test_generate_with_override_lines_skips_internal_preview_call`
- `test_generate_without_override_lines_calls_preview_internally`
- `test_generate_with_override_lines_still_respects_never_regenerate_invariant` — line referencing ASSIGNED mission is silently skipped
- `test_skipped_status_line_not_created_as_mission`

**Functional HTTP (`PlanningV2GenerationControllerTest`)**
- `test_preview_response_contains_preview_version_string`
- `test_generate_with_matching_preview_version_succeeds`
- `test_generate_with_stale_preview_version_returns_409_preview_expired`
- `test_generate_without_preview_version_succeeds_legacy`
- `test_generate_with_lines_creates_correct_missions`
- `test_generate_with_override_lines_skips_published_missions`

**Frontend**
- Statistics bar: `coveragePercent` updates immediately after inline assign
- Inspector panel: opens on row click, closes on Escape
- Reset line: restores original instrumentist
- Reset all: reloads from API
- Bulk skip: selected rows become SKIPPED in effective lines
- Search "Martin": filters to lines with "Martin" in surgeon/instrumentist/site
- Filter UNCOVERED: shows only status=UNCOVERED lines
- 409 response: shows "Régénérer l'aperçu" prompt

**Regression**
- Existing generate flow (no `lines`, no `previewVersion`) unchanged
- V1 planning generate page unaffected (separate route/component)
- `planningV2.types.ts`: zero import of V1 types after migration

#### Risks

| Risk | Mitigation |
|---|---|
| previewVersion hash collision (different inputs, same hash) | SHA-256 collision probability negligible; not a financial system |
| Manager generates immediately after preview with no edits — previewVersion always valid | Expected happy path; no risk |
| overrideLines bypasses the "never regenerate" check | R-01 guard is in generate() itself, before any line is processed |
| lineKey `${date}-${postId}` collision if same post generates two missions on same date | Impossible by construction — one post generates at most one occurrence per date |
| Inspector panel UX on small screens | Bottom sheet fallback for < 1024px (see Frontend above) |

#### Definition of Done ✅

- [x] `previewVersion` present in every `POST /api/planning/v2/preview` response
- [x] Stale `previewVersion` returns exactly `409 { code: "PREVIEW_EXPIRED" }`
- [x] `generate()` with `overrideLines` creates correct missions; ASSIGNED missions untouched
- [x] `PlanningGeneratePage` imports zero V1 types (`PreviewLine`, `slotId`)
- [x] `lineKey` = `${date}-${postId}` throughout
- [x] Statistics bar shows live coverage percent without API call; includes Conflits badge
- [x] Inspector panel opens on row click, navigates with arrow keys
- [x] Bulk skip and bulk assign functional
- [x] Reset one line and Reset all functional
- [x] Filter chips: Couverts, Non couverts, Ignorés, Conflits, Modifiés manager, Bloc, Consultation
- [x] Edited badge ("Édité" chip) visible on all modified rows
- [x] All frontend tests green (38 tests in PlanningGeneratePage.test.tsx)
- [x] `docs/api.md` updated (previewVersion, overrideLines in backend batch)

---

### Batch 15A — Foundations

#### Objective

**Business:** No user-visible change. This batch establishes the PHP infrastructure that every living planning batch depends on.

**Technical:** Add `MissionStatus::CANCELLED`, extend `NotificationType` and `AuditEventType` with all post-deploy cases, introduce `MissionChangeType` enum, create the `MissionLifecycleChangedMessage` and its skeleton handler, create the empty `MissionPostDeployService`, add Messenger routing, and simplify the V2 deploy path to publish all uncovered DRAFT missions as OPEN automatically.

**User value:** The deploy step no longer requires the manager to manually select which uncovered missions go to the pool. Every DRAFT mission without an instrumentist becomes OPEN automatically.

#### Scope

**Included:**
- `MissionStatus::CANCELLED` (new PHP enum case)
- `NotificationType`: 8 new cases (see Notification System section)
- `AuditEventType`: 6 new post-deploy cases (see Audit System section)
- `MissionChangeType` PHP enum (new file, never persisted)
- `MissionLifecycleChangedMessage` (readonly PHP class)
- `MissionLifecycleChangedMessageHandler` (skeleton — logs and returns, no logic)
- `MissionPostDeployService` (empty class, injected but unused)
- `messenger.yaml`: routing for `MissionLifecycleChangedMessage`
- `PlanningDeploymentService`: V2 path — all DRAFT without instrumentist → OPEN (remove `selectedUncoveredMissionIds` dependency for the `versionId !== null` branch)
- `DefaultNotificationPreferenceResolver`: per-type default channels instead of blanket defaults

**Excluded:**
- Any actual logic in `MissionPostDeployService` (Batch 15B)
- Any actual logic in `MissionLifecycleChangedMessageHandler` (Batch 15E)
- Frontend changes
- API changes (deploy endpoint behavior changes silently — no new parameter, just different behavior for V2 path)

#### Backend

**`MissionStatus::CANCELLED`** — add case. VARCHAR backing. No migration.

**`MissionChangeType` enum** (new file `backend/src/Enum/MissionChangeType.php`):
```
RELEASED      // ASSIGNED → OPEN
CANCELLED     // OPEN → CANCELLED
CLAIMED       // OPEN → ASSIGNED (instrumentiste)
REASSIGNED    // ASSIGNED → ASSIGNED (different instrumentiste)
TIME_CHANGED  // start/end time modification
ADDED         // new Mission created post-deploy
REMOVED       // Mission deleted post-deploy (if supported in future)
UPDATED       // generic update (fallback)
```

**`NotificationType`** — add 8 cases:
```
PLANNING_DEPLOYED_INSTRUMENTIST
PLANNING_DEPLOYED_SURGEON
PLANNING_DEPLOYED_MANAGER
OPEN_MISSION_AVAILABLE
SURGEON_POST_COVERED
SURGEON_POST_UNCOVERED
PLANNING_MISSION_REASSIGNED
PLANNING_MISSION_CANCELLED
PLANNING_MISSION_ADDED
PLANNING_MISSION_UPDATED
```
All new backing values are ≤ 32 chars (constraint from `notification_preference.notification_type` VARCHAR(32)).

**`AuditEventType`** — add 6 cases:
```
MISSION_RELEASED_TO_POOL
MISSION_CANCELLED_POST_DEPLOY
MISSION_REASSIGNED_POST_DEPLOY
MISSION_TIME_CHANGED_POST_DEPLOY
MISSION_ADDED_POST_DEPLOY
MISSION_CLAIMED_FROM_POOL
```
VARCHAR backing — no migration.

**`DefaultNotificationPreferenceResolver`** — replace blanket defaults with per-type:
```
OPEN_MISSION_AVAILABLE, SURGEON_POST_COVERED, SURGEON_POST_UNCOVERED,
PLANNING_MISSION_REASSIGNED, PLANNING_MISSION_ADDED, PLANNING_MISSION_UPDATED
  → NotificationChannels(inApp=true, email=false, push=false)

PLANNING_MISSION_CANCELLED
  → NotificationChannels(inApp=true, email=true, push=false)

default (PLANNING_ALERT, PLANNING_DEPLOYED_*)
  → NotificationChannels(inApp=true, email=true, push=false)
```

**`MissionLifecycleChangedMessage`** (new):
```php
final class MissionLifecycleChangedMessage {
    public function __construct(
        public readonly int             $missionId,
        public readonly MissionChangeType $changeType,
        public readonly int             $actorId,
        public readonly array           $payload,   // snapshot data
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
```

**`MissionLifecycleChangedMessageHandler`** (skeleton):
```php
public function __invoke(MissionLifecycleChangedMessage $message): void {
    $this->logger->info('MissionLifecycleChangedMessage received', [
        'missionId'  => $message->missionId,
        'changeType' => $message->changeType->value,
    ]);
    // Logic implemented in Batch 15E
}
```

**`MissionPostDeployService`** (empty shell, all methods to be filled in Batch 15B):
```php
final class MissionPostDeployService {
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly MessageBusInterface         $bus,
        private readonly AuditEventService           $audit, // existing
    ) {}
    // release(), cancel(), claim() — to be implemented in 15B
}
```

**`messenger.yaml`** routing:
```yaml
App\Message\MissionLifecycleChangedMessage: async
```

**`PlanningDeploymentService::deploy()`** — V2 path (`$version !== null`):
- Remove: selection of `$selectedUncoveredMissionIds` from the message
- Change: all DRAFT missions without `instrumentistId` → OPEN (not just the selected ones)
- `openPoolCount` in response = count of all missions that became OPEN
- V1 path (`$version === null`) unchanged

#### Frontend

None.

#### Database

No new tables. No new columns. No migrations.

`notification_preference.notification_type` is VARCHAR(32). All new `NotificationType` backing values are ≤ 32 chars — verified at definition time.

#### API

`POST /api/planning/v2/deploy` — silent behavior change for V2 path:
- Request body: `selectedUncoveredMissionIds` is now ignored when `versionId` is present
- Response: `openPoolCount` now reflects all auto-published OPEN missions

No new endpoints.

#### Documentation

- `docs/api.md`: note on deploy endpoint V2 path behavior (selectedUncoveredMissionIds ignored)
- `docs/planning-v2-roadmap.md`: mark Batch 15A as DONE

#### Tests

**Unit**
- `test_v2_deploy_all_uncovered_draft_become_open_without_selection`
- `test_v2_deploy_draft_with_instrumentist_become_assigned` — regression
- `test_v1_deploy_legacy_uses_selected_ids` — V1 path unchanged
- `test_resolver_open_mission_available_default_email_false`
- `test_resolver_planning_deployed_instrumentist_default_email_true`
- `test_resolver_planning_mission_cancelled_default_email_true`
- `test_mission_change_type_enum_backing_values_correct`
- `test_notification_type_all_new_values_lte_32_chars` — assertion on string length

**YAML parse test (D-043)**
- `test_messenger_yaml_routes_mission_lifecycle_changed_message_to_async`
  (loads `messenger.yaml`, asserts `App\Message\MissionLifecycleChangedMessage` maps to `async`)

**Functional HTTP**
- `POST /api/planning/v2/deploy` with `versionId` → `openPoolCount` includes all uncovered missions

**Regression**
- V1 deploy (no `versionId`) → `selectedUncoveredMissionIds` still honored
- `POST /api/planning/v2/preview` unaffected

#### Risks

| Risk | Mitigation |
|---|---|
| V1 deploy path accidentally modified | `if ($version !== null)` branch isolation; regression test on V1 path |
| NotificationType VARCHAR(32) overflow | `test_notification_type_all_new_values_lte_32_chars` gate |
| Messenger message unrouted (sync fallback) | YAML parse test is mandatory gate before merge |

#### Definition of Done

- [ ] `MissionStatus::CANCELLED` exists
- [ ] All new `NotificationType` cases ≤ 32 chars
- [ ] `DefaultNotificationPreferenceResolver` returns per-type defaults
- [ ] `MissionLifecycleChangedMessage` class exists and is readonly
- [ ] `MissionLifecycleChangedMessageHandler` exists (skeleton)
- [ ] `MissionPostDeployService` exists (empty)
- [ ] `messenger.yaml` routing for `MissionLifecycleChangedMessage: async`
- [ ] V2 deploy: all uncovered DRAFT → OPEN without selection
- [ ] YAML parse test green
- [ ] Full PHPUnit suite green
- [ ] Grep for `MissionChangedMessage` (old name) returns zero results

---

### Batch 15B — Post-Deploy Actions ✅ DONE (2026-06-29)

#### Objective

**Business:** Give managers the ability to release an assigned mission back to the pool, cancel an open mission, and reassign a mission to a different instrumentist.

**Technical:** Implement `release`, `cancel`, and `reassign` as dedicated status-transition endpoints, fully compliant with D-056 (all logic in `MissionPostDeployService`). Migrate the existing `claimMission` endpoint to also pass through the service. Add `AuditEvent` creation on all four transitions.

**User value:** Managers can react to last-minute changes (surgeon cancelled, instrumentist unavailable) without leaving the planning page.

#### Scope

**Included:**
- `POST /api/missions/{id}/release` — ASSIGNED → OPEN
- `POST /api/missions/{id}/cancel` — OPEN → CANCELLED
- `POST /api/missions/{id}/reassign` — ASSIGNED → ASSIGNED (new instrumentist)
- `MissionVoter` attributes: RELEASE, CANCEL, REASSIGN
- `MissionPostDeployService`: `release()`, `cancel()`, `claim()`, `reassign()`
- AuditEvent on release (`MISSION_RELEASED_TO_POOL`), cancel (`MISSION_CANCELLED_POST_DEPLOY`), claim (`MISSION_CLAIMED_FROM_POOL`), reassign (`MISSION_REASSIGNED_POST_DEPLOY`)
- `AuditService::record()` — generic method for post-deploy lifecycle events with extra payload support (R-12 name snapshots)
- Migrate existing claim endpoint to use `MissionPostDeployService::claim()` (D-056 compliance)

**Excluded:**
- Notifications (Batch 15C and 15E handle this via the handler)
- Reassign, time change, add post-deploy (future batches)
- Frontend UI (Batch 15G)

#### Backend

**`MissionVoter`** — add attributes:
```
RELEASE: manager or admin; subject must be a Mission
CANCEL:  manager or admin; subject must be a Mission
```

**`MissionPostDeployService::release(Mission $mission, User $actor): void`**
1. Guard: `$mission->getStatus() !== MissionStatus::ASSIGNED` → throw `UnprocessableEntityHttpException("Mission must be ASSIGNED to release")`
2. Snapshot: `$fromInstrumentistId = $mission->getInstrumentist()->getId()`, `$fromInstrumentistName = ...`
3. `$mission->setStatus(MissionStatus::OPEN)`; `$mission->setInstrumentist(null)`
4. Create `AuditEvent(MISSION_RELEASED_TO_POOL, actor=$actor, mission=$mission, payload=[...])`
5. `$this->em->flush()` — persists status change AND AuditEvent atomically
6. `$this->bus->dispatch(new MissionLifecycleChangedMessage(missionId, RELEASED, actorId, payload, now))`

**`MissionPostDeployService::cancel(Mission $mission, User $actor, ?string $reason): void`**
1. Guard: `$mission->getStatus() !== MissionStatus::OPEN` → 422
2. `$mission->setStatus(MissionStatus::CANCELLED)`
3. AuditEvent(`MISSION_CANCELLED_POST_DEPLOY`, payload=`{ reason }`)
4. Flush
5. Dispatch `MissionLifecycleChangedMessage(missionId, CANCELLED, actorId, payload, now)`

**`MissionPostDeployService::claim(Mission $mission, User $actor): void`**
1. Guard: `$mission->getStatus() !== MissionStatus::OPEN` → 422
2. Guard: actor must have ROLE_INSTRUMENTIST and be eligible for the site
3. `$mission->setStatus(MissionStatus::ASSIGNED)`; `$mission->setInstrumentist($actor)`
4. AuditEvent(`MISSION_CLAIMED_FROM_POOL`, payload=`{ instrumentistId, instrumentistName }`)
5. Flush
6. Dispatch `MissionLifecycleChangedMessage(missionId, CLAIMED, actorId, payload, now)`

**`MissionController`** — two new routes:
```
POST /api/missions/{id}/release   → denyUnless RELEASE → service->release()
POST /api/missions/{id}/cancel    → denyUnless CANCEL  → service->cancel(..., $request->get('reason'))
```
Existing claim route refactored to call `service->claim()`.

Controller methods contain no `em->persist`, no status logic. They are pure orchestrators (R-09).

#### Frontend

None (Batch 15G).

#### Database

No new tables. `mission.status` column already stores VARCHAR. `CANCELLED` is a new valid value — no migration required (VARCHAR is open-ended). `AuditEvent` table already exists with correct schema.

#### API

```
POST /api/missions/{id}/release
  Auth: MANAGER / ADMIN
  Body: {} (empty)
  Response 200: { "id": 42, "status": "OPEN" }
  Response 409: { "code": "INVALID_TRANSITION", "message": "Mission must be ASSIGNED to release" }
  Response 403: not authorized

POST /api/missions/{id}/cancel
  Auth: MANAGER / ADMIN
  Body: { "reason": "Chirurgien absent" }   // optional
  Response 200: { "id": 42, "status": "CANCELLED" }
  Response 409: { "code": "INVALID_TRANSITION", "message": "Mission must be OPEN to cancel" }
  Response 403: not authorized
```

#### Documentation

- `docs/api.md`: add release and cancel endpoints
- `docs/planning-v2-roadmap.md`: mark Batch 15B as DONE

#### Tests

**Unit (`MissionVoterTest`)**
- `test_manager_can_release_assigned_mission`
- `test_admin_can_release_assigned_mission`
- `test_instrumentist_cannot_release_mission`
- `test_manager_can_cancel_open_mission`
- `test_instrumentist_cannot_cancel_mission`

**Unit (`MissionPostDeployServiceTest`)**
- `test_release_transitions_status_to_open`
- `test_release_clears_instrumentist_reference`
- `test_release_creates_audit_event_with_instrumentist_snapshot`
- `test_release_flushes_before_dispatch` — assert flush called before bus->dispatch
- `test_release_dispatches_lifecycle_message_with_released_change_type`
- `test_release_on_non_assigned_mission_throws_422`
- `test_cancel_transitions_status_to_cancelled`
- `test_cancel_creates_audit_event_with_reason`
- `test_cancel_dispatches_lifecycle_message_with_cancelled_change_type`
- `test_cancel_on_non_open_mission_throws_422`
- `test_claim_creates_mission_claimed_from_pool_audit_event`

**Functional HTTP**
- `POST /api/missions/{id}/release` on ASSIGNED → 200, `status=OPEN`, AuditEvent in DB
- `POST /api/missions/{id}/release` on OPEN → 409
- `POST /api/missions/{id}/cancel` on OPEN → 200, `status=CANCELLED`, AuditEvent in DB
- `GET /api/missions/{id}` after cancel → `status=CANCELLED`
- `MissionLifecycleChangedMessage` in in-memory transport after release

**D-056 compliance test**
- `test_release_controller_contains_no_direct_em_persist` — static analysis assertion or reflection test

**Regression**
- Existing claim endpoint: AuditEvent now created (previously absent) — verify no duplicate
- Billing: CANCELLED missions should not appear in FirmInvoice or InstrumentistStatement

#### Risks

| Risk | Mitigation |
|---|---|
| Claim migration introduces double AuditEvent if existing claim path already created one | Verify existing claim code has no AuditEvent before migrating |
| Retry: `MissionLifecycleChangedMessage` handler retries → duplicate processing | Handler is idempotent by design in 15E (check NotificationEvent uniqueness) |
| CANCELLED missions appearing in billing queries | Billing queries already filter by SUBMITTED/VALIDATED — verify in regression test |

#### Definition of Done

- [x] `POST /api/missions/{id}/release` returns 200 with `status=OPEN`
- [x] `POST /api/missions/{id}/cancel` returns 200 with `status=CANCELLED`
- [x] `POST /api/missions/{id}/reassign` returns 200 with `status=ASSIGNED`
- [x] AuditEvent in DB after each transition (verified by functional test)
- [x] `MissionLifecycleChangedMessage` dispatched after each transition (unit test R-05/R-07 assertion)
- [x] Flush occurs before dispatch (test assertion)
- [x] Zero `em->persist($mission)` in controllers (D-056 compliance test)
- [x] Existing claim endpoint migrated to `MissionPostDeployService::claim()`
- [x] `AuditService::record()` with name snapshots per R-12
- [x] Full PHPUnit suite green — 577/577

#### Batch 15B.1 — Consistency Check ✅ DONE (2026-06-29)

**Objective:** Verify `MissionPostDeployService` is the only component mutating deployed Missions.

**Violations found and fixed:**

| Location | Violation | Fix |
|---|---|---|
| `PlanningAlertActionService::reassign()` | Direct `setInstrumentist` + `setStatus(ASSIGNED)` on OPEN/ASSIGNED missions — no audit, no dispatch | Delegate to `MissionPostDeployService::assign()` for OPEN/ASSIGNED; DRAFT keeps direct mutation (pre-deploy setup) |
| `PlanningAlertActionService::openAsAvailable()` | Direct `setInstrumentist(null)` + `setStatus(OPEN)` on ASSIGNED missions — no audit, no dispatch | Delegate to `MissionPostDeployService::release()` for ASSIGNED; OPEN is a no-op; DRAFT keeps direct mutation |
| `MissionController::assignInstrumentist()` | No status guard — any manager could call on a deployed mission, mutating it directly in the controller (D-056) | Added DRAFT-only guard: returns 409 `MISSION_NOT_DRAFT` for non-DRAFT missions |
| `MissionService::claim()` | Dead code since controller migration in 15B | Removed; also cleaned up unused `MissionClaim` + `UniqueConstraintViolationException` imports |

**New in `MissionPostDeployService`:** `assign(Mission, User, int): void` — OPEN|ASSIGNED → ASSIGNED (alert-triggered manager assignment, emits `MISSION_REASSIGNED_POST_DEPLOY` audit + `MissionLifecycleChangedMessage`).

**Test suite:** 584/584 tests pass, 1647 assertions.

---

### Batch 15C — Deploy Notifications Revamp

#### Objective

**Business:** Ensure that surgeons receive a meaningful per-post notification at deployment (not aggregate counts), instrumentists receive a single Famille 1 notification, and all notification channels respect user preferences.

**Technical:** Revamp `PlanningDeployPdfsMessageHandler` steps 5–9 to route emails through `NotificationPreferenceResolver`, create in-app `NotificationEvent` records, introduce `UncoveredReason` enum, and change the surgeon notification payload to `posts[]` format per D-053.

**User value:** Surgeons see exactly which of their posts are covered and by whom. Instrumentists receive one clean notification per deploy. No user is spammed with notifications they have opted out of.

#### Scope

**Included:**
- `UncoveredReason` PHP enum
- `UncoveredReasonResolver` service
- Inject `NotificationPreferenceResolver` into `PlanningDeployPdfsMessageHandler`
- Step 5 (per-instrumentist email): gate through resolver with `PLANNING_DEPLOYED_INSTRUMENTIST`
- Step 6 (per-surgeon email): gate through resolver with `PLANNING_DEPLOYED_SURGEON`; payload changed to `posts[]`
- Step 7 (in-app loop): **replaced** by single `PLANNING_DEPLOYED_INSTRUMENTIST` NotificationEvent per instrumentist
- Step 8 (pool notifications): eligibility still broken at this stage (fixed in 15D) — scope is notification gating only
- Step 9 (manager notification): add in-app `NotificationEvent` with type `PLANNING_DEPLOYED_MANAGER`
- Email templates: `planning_surgeon.html.twig` rebuilt as per-post cards; `planning_instrumentist.html.twig` minor update

**Excluded:**
- Pool eligibility filtering (Batch 15D)
- Post-deploy coverage notifications (Batch 15E)
- Push notifications (future)

#### Backend

**`UncoveredReason` enum** (new):
```
NO_INSTRUMENTIST_AVAILABLE  // no eligible instrumentist at site for that period
ALL_ABSENT                  // all eligible instrumentists are absent that day
ALL_IN_CONFLICT             // all eligible are already assigned to another mission
NO_SITE_MEMBERSHIP          // no instrumentist affiliated to this site
MANUALLY_LEFT_OPEN          // manager explicitly chose not to assign
```

**`UncoveredReasonResolver::resolveForMission(Mission $mission): UncoveredReason`**
- Heuristic resolution: checks absences → conflicts → affiliations → fallback MANUALLY_LEFT_OPEN
- Best-effort; not persisted; used only for notification label

**`PlanningDeployPdfsMessageHandler` revamp:**

```
Step 5 (PDF per instrumentist):
  → check resolver.resolve(instrumentist, PLANNING_DEPLOYED_INSTRUMENTIST).emailEnabled
  → send email only if true
  → create NotificationEvent(type=PLANNING_DEPLOYED_INSTRUMENTIST, recipient=instrumentist,
      payload={ periodLabel, missionCount, deployedAt })

Step 6 (PDF per surgeon):
  → check resolver.resolve(surgeon, PLANNING_DEPLOYED_SURGEON).emailEnabled
  → build posts[] payload per D-053:
      each mission → { missionId, date, dayLabel, siteName, periodLabel,
                        covered, instrumentistName, uncoveredReasonLabel }
  → send email only if emailEnabled
  → check resolver.resolve(surgeon, PLANNING_DEPLOYED_SURGEON).inAppEnabled
  → create NotificationEvent(type=PLANNING_DEPLOYED_SURGEON, payload={ periodLabel, posts[] })

Step 7 (DELETED):
  → Remove planningMissionAssignedNotifyInstrumentist loop
  → Replaced by the NotificationEvent created in Step 5

Step 8 (pool):
  → Gating via resolver.resolve(instrumentist, OPEN_MISSION_AVAILABLE)
  → Pool eligibility filtering still uses "all site instrumentists" at this stage
  → NotificationEvent(type=OPEN_MISSION_AVAILABLE, payload={ openMissionIds, periodLabel })

Step 9 (manager):
  → check resolver.resolve(manager, PLANNING_DEPLOYED_MANAGER).inAppEnabled
  → create NotificationEvent(type=PLANNING_DEPLOYED_MANAGER,
      payload={ missionCount, assignedCount, openPoolCount, periodLabel })
```

#### Frontend

None.

#### Database

No migrations. `NotificationEvent.eventType` is VARCHAR(100) — new values are safe.

#### API

`POST /api/planning/v2/deploy` — no contract change. Side effects change internally.

#### Documentation

- `docs/api.md`: document `PLANNING_DEPLOYED_SURGEON` NotificationEvent payload (posts[] format per D-053)
- `docs/decisions.md`: D-053 reference already covers this
- `docs/planning-v2-roadmap.md`: mark Batch 15C as DONE

#### Tests

**Unit (`PlanningDeployPdfsMessageHandlerTest`)**
- `test_surgeon_notification_payload_is_posts_array_not_counts`
- `test_surgeon_posts_sorted_chronologically`
- `test_covered_post_has_instrumentist_name_not_null`
- `test_uncovered_post_has_uncovered_reason_label`
- `test_surgeon_only_sees_own_posts`
- `test_email_not_dispatched_when_resolver_email_disabled_for_surgeon`
- `test_email_not_dispatched_when_resolver_email_disabled_for_instrumentist`
- `test_in_app_notification_created_per_instrumentist`
- `test_in_app_notification_created_per_surgeon`
- `test_manager_in_app_notification_created_on_deploy`
- `test_step7_loop_method_no_longer_called` — verify `planningMissionAssignedNotifyInstrumentist` removed
- `test_no_patient_financial_data_in_surgeon_payload`

**Functional**
- Deploy → `NotificationEvent` with type `PLANNING_DEPLOYED_SURGEON` in DB for each surgeon
- Deploy → `NotificationEvent` with type `PLANNING_DEPLOYED_INSTRUMENTIST` in DB for each instrumentist
- Deploy → `NotificationEvent` with type `PLANNING_DEPLOYED_MANAGER` in DB for each manager
- Surgeon with email preference disabled → no email dispatched

**Regression**
- Existing test that validates `planningMissionAssignedNotifyInstrumentist` is called → DELETE and replace
- Full deploy flow (generate → deploy → notifications) still produces PDFs

#### Risks

| Risk | Mitigation |
|---|---|
| Step 7 deletion breaks existing in-app notifications for instrumentists | New Step 5 creates `PLANNING_DEPLOYED_INSTRUMENTIST` NotificationEvent — functional test verifies equivalence |
| Surgeon payload too large for N posts (e.g. 50 posts/month) | JSON payload ~5KB for 50 posts — negligible |

#### Definition of Done

- [x] Surgeon notification payload is `posts[]` (not counts)
- [x] All emails gated through `NotificationPreferenceResolver`
- [x] `planningMissionAssignedNotifyInstrumentist` loop removed
- [x] `NotificationEvent` created for each audience type on deploy
- [x] Full PHPUnit suite green (596/596)

#### ✅ Done — 2026-06-29

| File | Change |
|---|---|
| `src/Enum/UncoveredReason.php` | New enum: NO_SITE_MEMBERSHIP, ALL_ABSENT, ALL_IN_CONFLICT, MANUALLY_LEFT_OPEN + `label(): string` |
| `src/Service/UncoveredReasonResolver.php` | New heuristic service: affiliation → absence → conflict → MANUALLY_LEFT_OPEN |
| `src/MessageHandler/PlanningDeployPdfsMessageHandler.php` | Step 5: preference-gated email + NotificationEvent(PLANNING_DEPLOYED_INSTRUMENTIST) per instrumentist; Step 6: posts[] payload, gated email + NotificationEvent(PLANNING_DEPLOYED_SURGEON) per surgeon; Step 7 deleted (replaced by Step 5 event); Step 8: NotificationEvent(OPEN_MISSION_AVAILABLE) per site instrumentist (preference-gated), push kept; Step 9: NotificationEvent(PLANNING_DEPLOYED_MANAGER) with summary; added helpers resolveChannelsSafely, createNotificationEvent, buildSurgeonPosts, periodLabel |
| `templates/emails/planning_surgeon.html.twig` | Added posts[] table section for per-post cards |
| `tests/Unit/Service/PlanningDeployPdfsHandlerTest.php` | Added NotificationPreferenceResolver + UncoveredReasonResolver mocks; updated 3 existing tests; added 12 new tests (596/596 green) |
| `docs/api.md` | Documented notification event types and posts[] payload format |

---

### Batch 15D — OPEN Pool Eligibility ✅ DONE (2026-06-29)

#### Objective

**Business:** Pool mission notifications only go to instrumentists who are actually eligible: affiliated to the site, not absent that day, not in conflict with another mission.

**Technical:** `MissionEligibilityService` — single source of truth for eligibility decisions. Wired into `claim()` pre-lock gate and pool notifications (step 8).

#### Files delivered

| File | Change |
|---|---|
| `src/Enum/EligibilityReason.php` | New — 6 typed reasons: `INACTIVE`, `NO_SITE_MEMBERSHIP`, `ABSENT`, `SCHEDULE_CONFLICT`, `ALREADY_ASSIGNED`, `INCOMPATIBLE_STATUS` |
| `src/Dto/EligibilityResult.php` | New — readonly DTO: `candidate`, `reasons[]`, `eligible` (derived) |
| `src/Service/MissionEligibilityService.php` | New — `evaluate()`, `evaluateAllCandidates()`, `findEligible()` (each ≤ 3 queries, D-036) |
| `src/Security/Voter/MissionVoter.php` | `VIEW_ELIGIBLE_INSTRUMENTISTS` constant + fix `canClaim()` for V2 OPEN (no publications guard) |
| `src/Service/MissionPostDeployService.php` | Pre-lock eligibility gate in `claim()` (fast fail before pessimistic lock) |
| `src/MessageHandler/PlanningDeployPdfsMessageHandler.php` | `sendPoolNotifications()` replaced with `findEligible()` batch call |
| `src/Controller/Api/MissionController.php` | `GET /{id}/eligible-instrumentists` endpoint |
| `tests/Unit/Service/MissionEligibilityServiceTest.php` | New — 17 unit tests (all 6 reasons, 3-query performance assertions) |
| `tests/Unit/Service/MissionPostDeployServiceTest.php` | 4 new claim tests (eligible, ineligible×2, ordering) |
| `tests/Unit/Service/PlanningDeployPdfsHandlerTest.php` | Pool notification tests rewritten to use `MissionEligibilityService` mock |

#### Definition of Done ✅

- [x] `MissionEligibilityService` uses ≤ 3 queries (D-036)
- [x] Absent instrumentists excluded from pool notifications
- [x] Conflict-blocked instrumentists excluded
- [x] `claim()` gated by eligibility pre-lock (fast fail 409)
- [x] `GET /api/missions/{id}/eligible-instrumentists` endpoint (MANAGER/ADMIN)
- [x] Fix `MissionVoter::canClaim()` for V2 OPEN missions (no publications)
- [x] 617/617 tests green
- [x] `docs/api.md` updated
- [x] `docs/decisions.md` — ADR D-057 added
- [x] Roadmap marked DONE

---

### Batch 15E — Coverage Notifications (MissionLifecycleChangedMessageHandler) ✅ DONE (2026-06-30)

#### Objective

**Business:** When a mission is claimed or released, the responsible surgeon is notified immediately.

**Technical:** `MissionLifecycleChangedMessageHandler` becomes the orchestration layer for all Mission lifecycle side-effects (D-056 separation of concerns). CLAIMED and RELEASED handled in Batch 15E. All other changeTypes: structured log + forward-compatible skip.

#### Files delivered

| File | Change |
|---|---|
| `src/MessageHandler/MissionLifecycleChangedMessageHandler.php` | Full implementation — CLAIMED/RELEASED/default match; failure isolation; structured logging; extensibility hooks |
| `tests/Unit/MessageHandler/MissionLifecycleChangedMessageHandlerTest.php` | 19 unit tests |
| `docs/architecture.md` | Mission lifecycle separation-of-concerns diagram + updated notification table |

#### Architecture (D-056 enforcement)

```
MissionPostDeployService   → validates, mutates state, AuditEvent, dispatches message
MissionLifecycleChangedMessageHandler → ALL side-effects
  ├── CLAIMED  → SURGEON_POST_COVERED  (inApp + push, gated by NotificationPreferenceResolver)
  ├── RELEASED → SURGEON_POST_UNCOVERED (inApp + push, gated by NotificationPreferenceResolver)
  ├── default  → info log, return (no exception — forward-compatible)
  └── Future hooks: coverage, history, webhooks — add private handle*() methods here
```

#### Idempotency strategy

Messenger retries may produce duplicate `NotificationEvent` rows (accepted V1 limit, consistent with `PlanningDeployPdfsMessageHandler`). Each notification attempt is isolated in its own `try/catch`. A push failure does not prevent the in-app notification and vice versa. Full deduplication would require a UNIQUE index on `(mission_id, user_id, event_type, DATE(sent_at))`.

#### Definition of Done ✅

- [x] RELEASED case: `SURGEON_POST_UNCOVERED` NotificationEvent created for surgeon
- [x] CLAIMED case: `SURGEON_POST_COVERED` NotificationEvent created for surgeon with instrumentist name + coveredAt
- [x] Push notification sent when `$channels->push = true`
- [x] Unknown changeType: no exception, info logged (CANCELLED, REASSIGNED, etc.)
- [x] Mission not found → warning logged, no exception
- [x] No surgeon on mission → info logged, no exception
- [x] Preference resolver failure → fallback inApp=true (no exception)
- [x] inApp failure does not prevent push (and vice versa)
- [x] 636/636 tests green
- [x] `docs/architecture.md` updated (lifecycle diagram + notification table)
- [x] Roadmap marked DONE

---

### Batch 15F — Coverage KPI + PlanningVersion History (Backend)

#### Objective

**Business:** Give managers real-time visibility into planning coverage and a complete historical timeline of everything that happened since deployment.

**Technical:** Implement `PlanningCoverageService`, `PlanningVersionHistoryService`, and three read-only endpoints. Nothing is written or cached.

#### Scope

**Included:**
- `GET /api/planning/versions/{id}/coverage-summary`
- `GET /api/planning/versions/{id}/history`
- `GET /api/missions/{id}/audit`
- `PlanningCoverageService`
- `PlanningVersionHistoryService`

**Excluded:**
- Frontend (Batch 15G, 15H)
- Analytics (Batch 15I)
- Coverage persistence or caching

#### Backend

**`PlanningCoverageService::computeForVersion(int $versionId): CoverageSummary`**

Single DQL aggregate query:
```sql
SELECT
  COUNT(CASE WHEN m.status IN ('OPEN','ASSIGNED','SUBMITTED','VALIDATED','CLOSED','IN_PROGRESS') THEN 1 END) as total,
  COUNT(CASE WHEN m.status IN ('ASSIGNED','SUBMITTED','VALIDATED','CLOSED','IN_PROGRESS') THEN 1 END) as covered,
  COUNT(CASE WHEN m.status = 'OPEN' THEN 1 END) as open,
  COUNT(CASE WHEN m.status = 'CANCELLED' THEN 1 END) as cancelled
FROM mission m
WHERE m.planning_version_id = :versionId
```

Returns `CoverageSummary` DTO: `{ total, covered, open, cancelled, coveragePercent }`. No `em->persist` anywhere in this service.

**`PlanningVersionHistoryService::buildTimeline(int $versionId): array`**

1. Load `PlanningDeployment` for the version → deployment entry
2. Load all `AuditEvent` where `mission.planningVersionId = $versionId`, JOIN mission, ORDER BY `occurredAt` ASC
3. Merge and return chronological timeline entries

Each timeline entry has `type` (DEPLOYED / audit eventType string), `occurredAt`, and a `payload` union of deployment data or AuditEvent payload.

No new entities. No persistence.

#### API

```
GET /api/planning/versions/{id}/coverage-summary
  Auth: MANAGER / ADMIN
  Response 200:
  {
    "versionId": 42,
    "total": 125,
    "covered": 118,
    "open": 7,
    "cancelled": 2,
    "coveragePercent": 94.4
  }
  Response 404: version not found

GET /api/planning/versions/{id}/history
  Auth: MANAGER / ADMIN
  Response 200:
  [
    {
      "type": "DEPLOYED",
      "occurredAt": "2026-07-01T09:12:00+02:00",
      "deployedById": 3,
      "deployedByName": "Marie Durand",
      "missionCount": 125,
      "openPoolCount": 7
    },
    {
      "type": "MISSION_CLAIMED_FROM_POOL",
      "occurredAt": "2026-07-01T10:18:00+02:00",
      "missionId": 42,
      "actorId": 8,
      "actorName": "Sophie Martin",
      "payload": { ... }
    }
  ]

GET /api/missions/{id}/audit
  Auth: MANAGER / ADMIN / mission surgeon
  Response 200:
  [
    {
      "eventType": "MISSION_CLAIMED_FROM_POOL",
      "occurredAt": "...",
      "actorId": 8,
      "actorName": "Sophie Martin",
      "payload": { "instrumentistId": 8, "instrumentistName": "Sophie Martin" }
    }
  ]
  Sorted DESC by occurredAt.
```

#### Tests

**Unit**
- `test_cancelled_missions_excluded_from_total`
- `test_open_missions_reduce_coverage_percent`
- `test_coverage_percent_is_null_when_no_missions`
- `test_coverage_service_uses_single_aggregate_query`
- `test_coverage_service_never_calls_flush`
- `test_history_starts_with_deployment_event`
- `test_history_audit_events_sorted_asc`

**Functional**
- `GET /api/planning/versions/{id}/coverage-summary` → correct values after deploy
- Claim → coverage-summary `covered` increases
- Cancel → coverage-summary `total` decreases
- `GET /api/planning/versions/{id}/history` → deployment entry present

#### Definition of Done

- [x] Coverage service contains zero `em->persist` or `em->flush` calls
- [x] Coverage `coveragePercent` is `null` when `total = 0`
- [x] History endpoint starts with DEPLOYED entry
- [x] All three endpoints return 403 for unauthenticated requests
- [x] `docs/api.md` updated with three new endpoints

**Status: DONE — 651/651 tests green (2026-06-30)**

**Files created/modified:**
- `src/Dto/CoverageSummary.php` (new)
- `src/Service/PlanningCoverageService.php` (new)
- `src/Service/PlanningVersionHistoryService.php` (new)
- `src/Security/Voter/MissionVoter.php` — `VIEW_AUDIT` constant + voter logic
- `src/Controller/Api/MissionController.php` — `GET /{id}/audit` endpoint
- `src/Controller/Api/PlanningVersionController.php` — `coverage-summary` + `history` endpoints
- `tests/Unit/Service/PlanningCoverageServiceTest.php` (new — 8 tests)
- `tests/Unit/Service/PlanningVersionHistoryServiceTest.php` (new — 7 tests)
- `docs/api.md` — 3 new endpoints documented

---

### Batch 15G — Living Planning UI (Schedule Page) ✅ DONE (2026-07-01)

#### Objective

**Business:** Managers can release, cancel, and reassign missions directly from the planning schedule view, with live coverage feedback and full mission audit history.

**Technical:** Add Release/Cancel/Reassign inline actions to `PlanningSchedulePage`, add `CoverageBanner`, add `MissionHistoryDrawer` per mission, add `ReassignMissionDialog` using eligibility endpoint, add status filter chips.

#### Files changed

- `frontend/src/app/features/planning-v2/api/planningV2.types.ts` — added `EligibilityReason`, `EligibleCandidate`, `IneligibleCandidate`, `MissionEligibilityResponse`
- `frontend/src/app/features/planning-v2/api/planningV2.api.ts` — added `fetchMissionEligibleInstrumentists`
- `frontend/src/app/features/planning-v2/components/ReassignMissionDialog.tsx` — rewritten to use eligibility endpoint, shows eligible (selectable) + ineligible (reason chips)
- `frontend/src/app/features/planning-v2/components/CancelMissionDialog.tsx` — fixed aria-label on confirm button
- `frontend/src/app/pages/manager/planning/PlanningSchedulePage.tsx` — added Reassign action, status filter (All/Ouverts/Assignés/Annulés), filteredRows
- `frontend/src/app/features/planning-v2/components/ReassignMissionDialog.test.tsx` — 8 tests (new)
- `frontend/src/app/features/planning-v2/components/MissionHistoryDrawer.test.tsx` — added beforeEach clearAllMocks
- `frontend/src/app/pages/manager/planning/PlanningSchedulePage.test.tsx` — added Reassign + status filter tests, fixed label assertions
- `frontend/vitest.config.ts` — no change (clearMocks per-file only)

#### Scope

**Included:**
- `CoverageBanner` component (coverage KPI bar)
- Inline Release button (ASSIGNED rows)
- Inline Cancel button (OPEN rows)
- Inline Reassign button (ASSIGNED rows) — via `ReassignMissionDialog` using eligibility endpoint
- Confirmation dialog for Cancel (with optional reason field)
- `MissionStatusChip` CANCELLED variant
- `MissionHistoryDrawer` (mission audit timeline)
- Status filter chips (All / Ouverts / Assignés / Annulés)
- React Query mutations with optimistic update + rollback on error
- New types: `CoverageSummary`, `MissionAuditEvent`, `EligibilityReason`, `MissionEligibilityResponse`
- New API functions: `fetchCoverageSummary`, `releaseMission`, `cancelMission`, `fetchMissionAudit`, `fetchMissionEligibleInstrumentists`

**Excluded:**
- Planning version history timeline (Batch 15H)
- Push notifications

#### Frontend

**`CoverageBanner`:**
```
[████████████████░░░] 118/125 couverts · 7 au pool · 94%
```
- Fetched via `useQuery(['coverage-summary', versionId], fetchCoverageSummary)`
- Invalidated after every Release or Cancel mutation
- Color: green ≥ 90%, orange 70–89%, red < 70%

**`PlanningSchedulePage` — new column "Actions":**
- ASSIGNED row: Release button (icon: unlock) — opens confirm dialog "Remettre au pool ?"
- OPEN row: Cancel button (icon: ban) — opens confirm dialog with optional reason textarea
- CANCELLED row: no action, chip shows "Annulé" in dark grey

**Mutations (React Query):**
```typescript
releaseMission(id) → POST /api/missions/{id}/release
  onMutate: optimistic update → status OPEN in table
  onError: rollback
  onSuccess: invalidate ['missions', versionId], invalidate ['coverage-summary', versionId]

cancelMission(id, reason?) → POST /api/missions/{id}/cancel
  onMutate: optimistic update → status CANCELLED
  onError: rollback
  onSuccess: invalidate ['missions', versionId], invalidate ['coverage-summary', versionId]
```

**`MissionHistoryDrawer`:**
- Trigger: click on a mission row (outside the action column)
- Panel slides in from right (400px width)
- Fetches `GET /api/missions/{id}/audit`
- Timeline (DESC order): each AuditEvent rendered as a card with icon, date/time, actor name, payload details
- Empty state: "Aucune modification enregistrée"
- Keyboard: Escape closes

**`MissionStatusChip`** — add:
```
CANCELLED → background #616161, label "Annulé"
```

**Accessibility:**
- Confirm dialogs: focus trapped, Escape closes
- History drawer: focus trapped, first focusable element receives focus on open
- Release/Cancel buttons: `aria-label="Remettre au pool"` / `aria-label="Annuler la mission"`

#### Tests

**Frontend**
- `CoverageBanner`: renders correct percentage, correct color for < 70%
- Release button: visible on ASSIGNED, absent on OPEN
- Cancel button: visible on OPEN, absent on ASSIGNED, absent on CANCELLED
- Optimistic update: status changes immediately on click
- Rollback: status restores if API returns error
- MissionHistoryDrawer: renders AuditEvents in DESC order
- MissionStatusChip CANCELLED renders correctly

**Regression**
- Assign instrumentist existing flow: still functional
- PlanningSchedulePage base render: loads and displays missions

#### Definition of Done

- [x] CoverageBanner visible with real-time coverage
- [x] Release functional: ASSIGNED → OPEN, coverage updates
- [x] Cancel functional: OPEN → CANCELLED, excluded from total
- [x] Reassign functional: ASSIGNED → ASSIGNED via eligibility dialog
- [x] MissionHistoryDrawer shows AuditEvents
- [x] CANCELLED chip styled correctly
- [x] Optimistic updates with rollback
- [x] Status filter chips (All / Ouverts / Assignés / Annulés)
- [x] All Batch 15G frontend tests green (39 tests)

---

### Batch 15H — Planning History UI

#### Objective

**Business:** Give managers a complete timeline view of everything that happened to a planning since deployment: who claimed what, who released what, who cancelled what, and how coverage evolved.

**Technical:** Build `PlanningVersionTimeline` and related components. Consume `GET /api/planning/versions/{id}/history`. Pure frontend batch.

#### Scope

**Included:**
- `PlanningVersionTimeline` component
- `TimelineEventCard` variants per event type
- Coverage evolution indicator
- Filters by event type
- Filters by actor
- Search by mission (date + site) or actor name
- Integration into `PlanningVersionPage` (new tab or dedicated section)

**Excluded:**
- Backend (all API from Batch 15F)
- Analytics (Batch 15I)

#### Frontend

**`PlanningVersionTimeline`:**
- Chronological list (ASC) of entries from `GET /api/planning/versions/{id}/history`
- Each entry is a `TimelineEventCard` with:
  - DEPLOYED: deployment icon, date/time, "Publié par {name}", missionCount, openPoolCount
  - MISSION_CLAIMED_FROM_POOL: claim icon, "Couverte par {instrumentistName}", mission label (dayLabel + site)
  - MISSION_RELEASED_TO_POOL: release icon, "Remise au pool par {actorName}", mission label
  - MISSION_REASSIGNED_POST_DEPLOY: reassign icon, "Réassignée de {from} à {to}"
  - MISSION_CANCELLED_POST_DEPLOY: cancel icon, "Annulée par {actorName}", reason if present
- Coverage evolution: each CLAIMED card shows mini coverage indicator "→ 85% couvert"

**Filters toolbar:**
- Filter chips: COUVERTURE, REMISE AU POOL, ANNULATION, RÉASSIGNATION — multi-select
- Actor filter: Select dropdown from unique actors in the timeline
- Search: text input filtering dayLabel + siteName + actorName

**Integration:**
- Accessible from `PlanningVersionPage` (existing page) as a new "Historique" tab
- `useQuery(['version-history', versionId], fetchVersionHistory)` with stale time 30s

#### Tests

- Timeline: DEPLOYED entry first
- Timeline: entries in ASC order
- Filter ANNULATION: masks non-cancel events
- Search "Sophie": shows only events involving "Sophie"
- Empty state: "Aucune modification depuis la publication"
- Coverage indicator shows progression after CLAIMED events

#### Definition of Done

- [ ] Timeline renders all event types with correct icons and labels
- [ ] Coverage evolution visible per CLAIMED event
- [ ] Filters functional
- [ ] Search functional
- [ ] `docs/planning-v2-roadmap.md`: mark Batch 15H as DONE

---

### Batch 14 — Notification Preferences UI

#### Objective

**Business:** Users can configure which notifications they receive on which channels (in-app, email, push). No more one-size-fits-all notification behavior.

**Technical:** Add `GET` and `PATCH` endpoints for `NotificationPreference`. Build the settings UI. The entity and resolver already exist — this batch only adds the API surface and frontend form.

**Prerequisite:** Batch 15E must be complete so that all `NotificationType` cases exist in the PHP enum. The UI must list every type.

#### Scope

**Included:**
- `GET /api/notification-preferences` — user's current preferences with defaults
- `PATCH /api/notification-preferences/{type}` — upsert one preference
- `NotificationPreferenceController`
- Frontend: Notification Preferences form in Planning V2 settings tab

**Excluded:**
- Push subscription management (separate system)
- Admin override of user preferences

#### Backend

**`NotificationPreferenceController`:**

`GET /api/notification-preferences` — returns all `NotificationType` cases for the current user:
- For each type: check if a `NotificationPreference` row exists → use its values
- If not: call `DefaultNotificationPreferenceResolver` to get defaults → mark `isDefault: true`

`PATCH /api/notification-preferences/{type}` — upsert:
- Validate `type` is a valid `NotificationType` backing value → 404 if not
- Find or create `NotificationPreference(user, type)`
- Update `inAppEnabled`, `emailEnabled`, `pushEnabled` from request body
- `em->flush()`
- Return updated preference

Authorization: a user can only manage their own preferences. Controller uses `$this->getUser()` — no voter needed beyond `IS_AUTHENTICATED_FULLY`.

#### Frontend

**Notification Preferences form** in existing settings section:

Grouped by family:
```
Publication initiale
  [ ] PLANNING_DEPLOYED_INSTRUMENTIST  "Planning publié"         inApp: ✓  Email: ✓  Push: –
  [ ] OPEN_MISSION_AVAILABLE           "Mission au pool"         inApp: ✓  Email: –  Push: –

Suivi de couverture
  [ ] SURGEON_POST_COVERED             "Poste couvert"           inApp: ✓  Email: –  Push: –
  [ ] SURGEON_POST_UNCOVERED           "Poste libéré"            inApp: ✓  Email: –  Push: –

Mises à jour (bientôt disponible)
  [ ] PLANNING_MISSION_REASSIGNED      "Réassignation"           inApp: ✓  Email: –  Push: –  [grisé]
  [ ] PLANNING_MISSION_CANCELLED       "Mission annulée"         inApp: ✓  Email: ✓  Push: –  [grisé]
  [ ] PLANNING_MISSION_ADDED           "Mission ajoutée"         inApp: ✓  Email: –  Push: –  [grisé]
  [ ] PLANNING_MISSION_UPDATED         "Mise à jour"             inApp: ✓  Email: –  Push: –  [grisé]
```

"Bientôt disponible" types are shown with disabled toggles and a tooltip. The API accepts PATCH on these types — users can pre-configure their preferences before the feature ships.

Toggle for Push: disabled if no PushSubscription registered for the user.

Each toggle PATCH is dispatched immediately on change (no save button). Error toast on failure, optimistic update with rollback.

#### Tests

**Functional**
- `GET /api/notification-preferences` with no rows → all types with `isDefault: true`
- `PATCH /api/notification-preferences/PLANNING_DEPLOYED_SURGEON` → upsert, `isDefault: false`
- Second `GET` → updated values returned
- User A cannot access User B's preferences (403)

**Frontend**
- Form loads with correct default values
- Toggle change → PATCH dispatched immediately
- "Bientôt disponible" types show disabled toggles

#### Definition of Done

- [ ] All active `NotificationType` cases listed in GET response
- [ ] PATCH upserts correctly
- [ ] User isolation enforced
- [ ] Frontend toggles functional with immediate PATCH
- [ ] "Bientôt disponible" types visually distinct

---

### Batch 15I — Planning Analytics (Future)

**Status:** Non-blocking. Planned after launch. No dependency on any batch for technical feasibility, but requires production data to be useful.

#### Scope (design only)

```
GET /api/planning/analytics?from=YYYY-MM&to=YYYY-MM&siteId=N

Metrics:
  coveragePercent           — per version / per site / per surgeon
  avgTimeToFirstClaim       — OPEN→ASSIGNED delay in hours
  avgTimeToCoverage         — deploy→100% covered delay in hours
  openMissionsCount         — missions currently OPEN
  releaseCount              — MISSION_RELEASED_TO_POOL events in period
  cancellationCount         — MISSION_CANCELLED_POST_DEPLOY events
  reassignmentCount         — MISSION_REASSIGNED_POST_DEPLOY events
  instrumentistWorkload     — missions covered per instrumentist
  hospitalWorkload          — coverage rate per site
  surgeonUncoveredRate      — uncovered/total ratio per surgeon
```

All metrics computed from `Mission` statuses and `AuditEvent` data. Nothing new to persist.

---

### Batch 15K — Mode Modification (Unified Editor) + Targeted Redeploy Notifications ✅ DONE (2026-07-10)

#### Objective

**Business:** A manager editing an already-deployed planning must use the exact same editor they used to generate it — not a different page, not a read-only detail view. Editing must feel like "finding your planning again," not starting over. Redeploying an edited planning must never resend a full deployment email to every surgeon/instrumentist — only the people whose own missions actually changed should hear about it, with a plain-language summary of what changed for them.

**Technical:** Introduce `PlanningEditorMode = "generation" | "modification"` as explicit state inside the existing `GeneratePlanningTab`. No fork, no second component. Modification mode sources its lines from the real `Mission` rows of a `PlanningVersion` (adapted into the same `PreviewLineV2` shape the Génération editor already renders/filters/selects) instead of from `previewPlanningV2()`. Edits are staged locally exactly like Génération mode, then submitted in one batch to a new `POST /api/planning/versions/{id}/apply-modifications` endpoint, which mutates `Mission` entities directly through the existing `MissionPostDeployService` (per D-052 "planning vivant" — no new generate/deploy cycle), computes a before/after diff, and sends exactly one consolidated "what changed" email per actually-affected recipient via the previously-unwired `PlanningChangeSummaryService`.

**User value:** One "Modifier" click from the planning history (or a click on an already-generated month chip) reopens the manager's planning exactly as deployed. They reassign, reschedule, cancel, release, or add missions in place, using the same permanent inspector panel as Génération. On "Redéployer," only the surgeons and instrumentists whose own missions changed get a short recap email — nobody else hears a thing.

#### Scope

**Included:**
- `PlanningEditorMode` state in `GeneratePlanningTab.tsx`; entry via history-row click or already-generated month-chip click (both call the same `enterModification(version)`)
- `Inspector.tsx` — new permanent side-panel component (replaces the transient reassignment `Popover`), always mounted, shows an empty state when no line is selected; adds schedule editing (Modification only), Annuler/Ignorer, Remettre au pool, and a "Nouvelle mission" create form
- `missionToPreviewLine()` adapter (`generatePreviewGrouping.ts`) — maps a real `Mission` to `PreviewLineV2` (ASSIGNED→COVERED, OPEN→UNCOVERED, CANCELLED→SKIPPED)
- `lineKeyV2()` extended to key on `existingMissionId` when present (stable identity for Modification even if content changes), falling back to `${date}-${postId}` for Génération/new-draft lines (unchanged behavior there)
- `MissionFilter.planningVersionId` (optional) → `GET /api/missions` — lists a PlanningVersion's real missions
- `MissionPostDeployService`: `notify: bool = true` parameter added to `release()/cancel()/assign()/reassign()` (default preserves all existing call sites); new `updateSchedule()` (time/site/type on OPEN|ASSIGNED missions) and `createPostDeploy()` (new Mission linked to an existing `PlanningVersion`), both notify-gated the same way
- `PlanningDiffService::computeDiffFromSnapshots()` — the comparison core extracted from `computeDiff()` to operate on plain serialized arrays instead of live entities, so it can diff a before/after snapshot pair of the *same* version (not just draft-vs-previous-version); `computeDiff()`'s entity-based signature and behavior are unchanged
- `PlanningChangeSummaryService::sendChangeSummaryEmails()` — optional `precomputedDiff` parameter; when passed, skips its own `PlanningDiffService::diff()` call and uses the given diff directly
- `PlanningModificationService` (new) — orchestrates one `apply-modifications` request: snapshot before → apply each line via `MissionPostDeployService` with `notify: false` → flush → snapshot touched missions after → `computeDiffFromSnapshots()` → if non-empty, call `PlanningChangeSummaryService::sendChangeSummaryEmails(..., precomputedDiff: $diff)`
- `POST /api/planning/versions/{id}/apply-modifications` (manager-only, `PlanningVoter::PLANNING_MANAGE`)

**Excluded:**
- Push notifications for the modification recap (email + in-app only, same channels as the rest of the notification system)
- A dedicated "diff preview" screen before redeploy (the dirty-count banner and "Édité" badges, already present in Génération mode, serve this purpose in both modes)

#### Backend

See `MissionPostDeployService`, `PlanningDiffService`, `PlanningModificationService`, `PlanningVersionController::applyModifications()` above. Key invariant: **every mutation still goes through `MissionPostDeployService`** (D-056) — the `notify: false` flag only suppresses the per-action `MissionLifecycleChangedMessage` dispatch inside methods that already do status guard + mutation + AuditEvent + flush identically to their `notify: true` callers. No parallel mutation path was introduced.

**Diff mechanism.** `PlanningModificationService::apply()` serializes every non-REJECTED mission of the version *before* touching anything (via `PlanningDiffService::serializeMission()`, already used elsewhere). It applies all requested line changes, flushes once, then re-serializes only the missions it actually touched. `computeDiffFromSnapshots(before[], after[])` — the same comparison logic `PlanningDiffService::diff()` already used for draft-vs-previous-version comparisons — produces `{added, removed, modified}` against these two snapshots of the *same* version. This diff, not `diff()`'s own previous-version baseline, is what gets passed to `PlanningChangeSummaryService`.

**Targeting rules.**
- An **instrumentist** receives an email only if at least one mission where they are (or were) the assigned instrumentist appears in `added`, `removed`, or `modified` — covers: new mission assigned to them, one of their missions cancelled, released back to the pool, reassigned away from them, or its date/time/site/type changed.
- A **surgeon** receives an email only if at least one of their missions appears in the diff — covers: instrumentist changed (either direction), schedule/site/type changed, a mission of theirs was added, cancelled, or released.
- Anyone with zero diff entries touching them receives nothing — not even an in-app notification. Unaffected recipients are never enumerated; the loop only ever considers people already present in `added`/`removed`/`modified`.
- Exactly one consolidated email per recipient per `apply-modifications` call — `PlanningChangeSummaryService` groups by recipient before sending, matching its pre-existing (previously unwired) behavior.
- Idempotency: one synchronous HTTP call per "Redéployer" click; the button disables for the duration of the mutation (same pattern as the existing `deployMutation.isPending` guard), so there is no double-submit path to deduplicate.

#### Frontend

`GeneratePlanningTab.tsx` — one component, mode-derived (`mode = modificationVersionId !== null ? "modification" : "generation"`), reused everywhere:
- **Data source**: Génération reads `preview?.lines`; Modification reads `fetchMissions(1, 500, { planningVersionId })` mapped through `missionToPreviewLine()`, plus any locally-drafted new lines not yet applied
- **Selection/filters/bulk actions**: identical code path for both modes — `effectiveLines`, `filterLines()`, `groupLinesByDayAndSurgeon()`, `editedLines` Map, `selectedKeys` Set are all mode-agnostic
- **Inspector**: one permanent panel, `accent`-themed; selecting a row just reloads its content — no popover open/close
- **Palette**: `GENERATION_ACCENT` (existing Planning V2 blue, `planningV2Colors.brand`) vs `MODIFICATION_ACCENT` (`#B5761A` amber) — applied to the mode eyebrow badge, the selected-row highlight, the Redéployer button, and Inspector accents. Semantic status colors (COVERED/UNCOVERED/CONFLICT/SKIPPED tokens) are untouched by mode, per design principle "semantic color is separate from accent hue"
- **Labels**: "Générer le planning" → "Modifier le planning — {mois} · {site}"; "Prévisualiser/Générer/Déployer" → (Modification skips straight to) "Redéployer"; added "Quitter la modification"
- **Entry points**: clicking a history row, or clicking a month chip that matches an already-generated version for the selected site, both call `enterModification(version)`

#### Database

No new tables, no new columns, no migrations. `Mission.planningVersion` (existing FK) is reused as the query/link target throughout.

#### API

```
GET /api/missions?planningVersionId=42        — existing endpoint, new optional filter

POST /api/planning/versions/{id}/apply-modifications
  Auth: MANAGER / ADMIN (PlanningVoter::PLANNING_MANAGE)
  Body: { "lines": [ ...PreviewLineV2[] ] }    — same shape as generate()'s overrideLines
  Response 200: { "created": 1, "updated": 2, "cancelled": 0, "released": 1, "unchanged": 4 }
```

#### Documentation

- `docs/planning-v2-roadmap.md` — this section
- `docs/planning-v2-architecture-freeze.md` §L/§K — Mode Modification and the diff/notification flow marked implemented
- `docs/architecture.md` §7 — unified editor + targeted-notification flow described

#### Tests

**Backend (unit)** — `MissionPostDeployServiceTest` (+15: notify=false paths, `updateSchedule()`, `createPostDeploy()`), `PlanningDiffServiceTest` (+3: `computeDiffFromSnapshots()`), `PlanningChangeSummaryServiceTest` (+1: `precomputedDiff` bypass) — 538/538 green.

**Backend (functional)** — `PlanningModificationControllerTest` (new, 5 tests): reassign persists; cancel on OPEN transitions to CANCELLED; a line with no `existingMissionId` creates a Mission linked to the version; an unchanged line is reported as unchanged and writes no AuditEvent; non-manager gets 403. 5/5 green.

**Frontend** — `generatePreviewGrouping.test.ts` (+5: dual-mode `lineKeyV2`, `missionToPreviewLine`), `GeneratePlanningTab.test.tsx` (+3: enter Modification from history and load real missions; edit a real mission's schedule via the permanent inspector and redeploy calls `applyModifications` with the version id and edited lines; exit Modification returns to the Génération home). Full frontend suite: 361/361 green. `npx tsc --noEmit`: clean.

#### Definition of Done ✅

- [x] Same `GeneratePlanningTab` component serves both modes — zero forked page, zero duplicated editor
- [x] Both entry points (history row, already-generated month chip) open Modification in place
- [x] Modification mode loads real `Mission` rows, not a Preview
- [x] Permanent Inspector — no popover — schedule edit, cancel, release-to-pool, create-mission
- [x] Redeploy applies through `MissionPostDeployService` only (D-056), never a new generate/deploy cycle (D-052)
- [x] Redeploy sends exactly one targeted email per actually-affected recipient, computed from a real before/after diff — unaffected users receive nothing
- [x] Backend 538/538 unit + 5/5 new functional green; frontend 361/361 green; `tsc --noEmit` clean

---

## 5. Preview Editor — Complete Functional Specification

### Architecture

```
POST /api/planning/v2/preview
  ↓ returns: { previewVersion, generatedAt, lines[], summary }
  ↓
Frontend stores:
  originalLines (immutable reference)
  editedLines Map<lineKey, override>
  previewVersion string
  ↓
Manager edits (local only, no API calls)
  ↓
POST /api/planning/v2/generate
  body: { previewVersion, lines: effectiveLines[] }
```

### previewVersion

Computed at preview-time as SHA-256 hex of the sorted serialization of:
- All active `SurgeonSchedulePost` for the scope (sorted by id ASC)
- All `Absence` overlapping the period (sorted by id ASC)
- All `ShiftPeriodConfig` for the relevant sites (sorted by id ASC)

At generate-time, the same hash is recomputed. Mismatch → `409 PREVIEW_EXPIRED`.

Invalidating events: Post created/updated/deleted, Absence created/updated/deleted, ShiftPeriodConfig changed, SiteGroup membership changed.

Deployment does NOT invalidate (R-01 handles this safely).

### Dirty State

```
originalLines[key]  — immutable, fetched from API, never modified
editedLines[key]    — sparse map of overrides { instrumentistId?, status?, period? }
effectiveLines[key] — merge(originalLines[key], editedLines[key])
isDirty(key)        — editedLines.has(key)
```

On reset one line: delete `editedLines[key]`.
On reset all: clear `editedLines`, keep `originalLines` and `previewVersion`.

### Statistics Bar

Computed locally from `effectiveLines`:
```
total     = effectiveLines.filter(l => l.status !== 'SKIPPED').length
covered   = effectiveLines.filter(l => l.status === 'COVERED').length
uncovered = effectiveLines.filter(l => l.status === 'UNCOVERED').length
modified  = Object.keys(editedLines).length
coveragePercent = covered / total × 100
```

Updates on every state mutation. No API call.

### Bulk Actions (V1)

- **Multi-select:** checkbox column, Shift+Click range-select
- **Bulk skip:** sets all selected `editedLines[key].status = SKIPPED`
- **Bulk assign:** opens instrumentist Select for all selected lines; on confirm, sets `editedLines[key].instrumentistId` for each line where the selected instrumentist is compatible with the line's site. Lines with incompatible sites show per-line warning.
- **Deferred:** Bulk period change, complex bulk reassign with per-line different values

### Inspector Panel

- Position: permanent right side, 320px width
- Trigger: single click on row
- Navigation: ArrowUp/ArrowDown moves selected row while panel stays open
- Contents: date, surgeon, site, period, type, current instrumentist, status
- Edit controls: instrumentist Select, period Select (if override allowed), SKIPPED toggle
- Dirty indicator on modified fields
- Reset Line button
- Close: Escape or click outside

### Search & Filters

**Search** (debounced 300ms): matches `surgeonName`, `instrumentistName`, `siteName` in effective lines.

**Filters** (multi-select chips):
- COUVERT, NON COUVERT, CONFLITS, BLOCK, CONSULTATION

Filters compose with AND logic (all active filters must match).

### UX Decisions

- Undo/redo: NOT in V1. Reset line + Reset all suffice.
- Popup dialogs: NOT used. Inspector panel replaces all per-line dialogs.
- Responsive: Inspector panel → bottom sheet on viewports < 1024px.
- Auto-save: NOT applicable. Nothing exists in DB until Generate is clicked.

### Future Extensions (not in V1)

- Undo/redo stack (LIFO, max 50 steps)
- Bulk period change with conflict validation
- Preview diff view (current vs previous version)
- Collaborative editing (requires PreviewDraft — rejected for V1)

---

## 6. Living Planning — Complete Lifecycle

### Mission Status Transitions (Post-Deploy)

```
ASSIGNED ──release()──→ OPEN ──cancel()──→ CANCELLED
OPEN ──claim()──→ ASSIGNED
OPEN ──cancel()──→ CANCELLED
ASSIGNED ──[future: reassign()]──→ ASSIGNED (new instrumentist)
```

All transitions:
1. Go through `MissionPostDeployService` (R-04)
2. Create `AuditEvent` before dispatch (R-05)
3. Dispatch `MissionLifecycleChangedMessage` (R-07)

### Post-Deploy Endpoints (Batch 15B+)

| Endpoint | Actor | From | To | AuditEventType | ChangeType |
|---|---|---|---|---|---|
| `POST /missions/{id}/release` | MANAGER | ASSIGNED | OPEN | MISSION_RELEASED_TO_POOL | RELEASED |
| `POST /missions/{id}/cancel` | MANAGER | OPEN | CANCELLED | MISSION_CANCELLED_POST_DEPLOY | CANCELLED |
| `POST /missions/{id}/claim` | INSTRUMENTIST | OPEN | ASSIGNED | MISSION_CLAIMED_FROM_POOL | CLAIMED |

### Coverage Lifecycle

Coverage is a derivative of Mission statuses. It changes with every status transition. Computed on-demand. Never stored.

### History Reconstruction

`GET /api/planning/versions/{id}/history` returns entries from two sources:
1. `PlanningDeployment` — the deployment event (always first)
2. `AuditEvent JOIN mission WHERE mission.planningVersionId = :id` — all subsequent events

The timeline is sorted ASC by `occurredAt`. No synthetic events are injected (e.g. "fully covered" is not a timeline event — it is a derived state).

### Audit Payload Convention

Every `AuditEvent` payload must contain:
- Actor: `actorId`, `actorName` (snapshot at action time)
- Subjects: named snapshots (never just IDs)
- Timestamp: `occurredAt` (ISO 8601)
- No patient data, no financial data

---

## 7. Notification System

### Complete Notification Matrix

| Event | NotificationType | Audience | inApp | Email (default) | Push | Trigger | Batch |
|---|---|---|---|---|---|---|---|
| Deploy | `PLANNING_DEPLOYED_INSTRUMENTIST` | Assigned instrumentist | true | true | false | PlanningDeployPdfsMessage | 15C |
| Deploy | `PLANNING_DEPLOYED_SURGEON` | Each surgeon | true | true | false | PlanningDeployPdfsMessage | 15C |
| Deploy | `PLANNING_DEPLOYED_MANAGER` | Managers/admins | true | true | false | PlanningDeployPdfsMessage | 15C |
| Deploy | `OPEN_MISSION_AVAILABLE` | Eligible pool instrumentists | true | false | false | PlanningDeployPdfsMessage | 15D |
| Claim | `SURGEON_POST_COVERED` | Mission surgeon | true | false | false | MissionLifecycleChangedMessage(CLAIMED) | 15E |
| Release | `SURGEON_POST_UNCOVERED` | Mission surgeon | true | false | false | MissionLifecycleChangedMessage(RELEASED) | 15E |
| Reassign | `PLANNING_MISSION_REASSIGNED` | Old + new instrumentist, surgeon | true | false | false | MissionLifecycleChangedMessage(REASSIGNED) | Future |
| Cancel | `PLANNING_MISSION_CANCELLED` | Assigned instrumentist + surgeon | true | true | false | MissionLifecycleChangedMessage(CANCELLED) | Future |
| Add post-deploy | `PLANNING_MISSION_ADDED` | New instrumentist (if assigned) | true | false | false | MissionLifecycleChangedMessage(ADDED) | Future |
| Update | `PLANNING_MISSION_UPDATED` | Affected instrumentist + surgeon | true | false | false | MissionLifecycleChangedMessage(UPDATED) | Future |

### Invariants

- Every notification creation is preceded by a `resolver->resolve()` call (R-06)
- Famille 1 (deploy) notifications are never re-sent post-deploy for the same deployment
- Famille 2 (update) notifications are never sent during initial deployment

### Handler Architecture

```
PlanningDeployPdfsMessage (async)
  → PlanningDeployPdfsMessageHandler
      → Famille 1 notifications (DEPLOYED_*, OPEN_MISSION_AVAILABLE)
      → Uses NotificationPreferenceResolver
      → Creates NotificationEvent records
      → Dispatches emails if emailEnabled

MissionLifecycleChangedMessage (async)
  → MissionLifecycleChangedMessageHandler
      → Famille 2 notifications (POST_COVERED, POST_UNCOVERED, etc.)
      → Uses NotificationPreferenceResolver
      → Creates NotificationEvent records
```

### Extensibility

Adding a new notification type requires:
1. New `NotificationType` case (≤ 32 chars, no migration)
2. Default channels in `DefaultNotificationPreferenceResolver`
3. New handler case in `MissionLifecycleChangedMessageHandler` or `PlanningDeployPdfsMessageHandler`
4. New `NotificationEvent` payload documentation

`NotificationEvent.eventType` is VARCHAR(100) — no migration for new types.

---

## 8. Audit System

### AuditEvent Schema

```
actor_id       FK User NOT NULL
mission_id     FK Mission NOT NULL
event_type     VARCHAR (AuditEventType enum backing)
payload        JSON
occurred_at    DATETIME
```

### AuditEventType Catalogue

**Pre-existing:**
`MISSION_DECLARED_*`, `PLANNING_GENERATED`, `PLANNING_DEPLOYED`, `PLANNING_ALERT_*` (15 existing cases)

**New (Batch 15A–15B):**

| EventType | Trigger | Payload fields |
|---|---|---|
| `MISSION_RELEASED_TO_POOL` | release() | `fromInstrumentistId`, `fromInstrumentistName`, `occurredAt` |
| `MISSION_CANCELLED_POST_DEPLOY` | cancel() | `reason` (optional), `occurredAt` |
| `MISSION_REASSIGNED_POST_DEPLOY` | future reassign() | `fromInstrumentistId/Name`, `toInstrumentistId/Name`, `occurredAt` |
| `MISSION_TIME_CHANGED_POST_DEPLOY` | future timeChange() | `fromStartAt`, `fromEndAt`, `toStartAt`, `toEndAt`, `occurredAt` |
| `MISSION_ADDED_POST_DEPLOY` | future add() | `surgeonId/Name`, `instrumentistId/Name` (nullable), `occurredAt` |
| `MISSION_CLAIMED_FROM_POOL` | claim() | `instrumentistId`, `instrumentistName`, `occurredAt` |

### History Reconstruction

Two endpoints serve audit data:
- `GET /api/missions/{id}/audit` → single mission, all its AuditEvents DESC
- `GET /api/planning/versions/{id}/history` → version timeline, merges PlanningDeployment + AuditEvents ASC

No new entity. No migration. Reuses existing `AuditEvent` table.

### Future Compatibility

`event_type` is VARCHAR — adding new cases requires no migration. Payload is JSON — adding new fields is non-breaking.

---

## 9. Coverage

### Formula

```
CANCELLED missions are excluded from the denominator.

total    = missions WHERE status ∈ {OPEN, ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS}
covered  = missions WHERE status ∈ {ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS}
open     = missions WHERE status = OPEN
cancelled = missions WHERE status = CANCELLED
coveragePercent = round(covered / total × 100, 1)   [null if total = 0]
```

### API

```
GET /api/planning/versions/{id}/coverage-summary
→ { versionId, total, covered, open, cancelled, coveragePercent }
```

Real-time: calling this after every claim/release/cancel reflects the current state.

### Coverage Notifications

`SURGEON_POST_COVERED` and `SURGEON_POST_UNCOVERED` are per-event notifications dispatched via `MissionLifecycleChangedMessageHandler`. They do not reflect aggregate coverage; they reflect the state of a single mission.

### Coverage UI

`CoverageBanner` in `PlanningSchedulePage` renders the current coverage with a progress bar. Updated on every mutation (React Query invalidation).

### Future Analytics

Batch 15I will derive coverage-over-time from `AuditEvent` data. No new persistence needed.

---

## 10. Future Roadmap

### Mandatory Before Production

All batches 14.5, 15A–15H, and 14 are required before the living planning feature is production-ready.

### Recommended After Production

| Feature | Description | Complexity |
|---|---|---|
| Batch 15I — Analytics | Coverage %, workload, delay metrics | M |
| Famille 2 notifications | REASSIGNED, CANCELLED, ADDED, UPDATED notification cases in the handler | M |
| Reassign endpoint | `POST /api/missions/{id}/reassign` with direct instrumentist selection | M |
| Time change endpoint | `POST /api/missions/{id}/update-times` | S |
| Add post-deploy endpoint | `POST /api/planning/versions/{id}/missions` | M |

### Nice-To-Have

| Feature | Description |
|---|---|
| Undo/redo in Preview Editor | LIFO stack, max 50 steps |
| Bulk period change | With per-line conflict validation |
| Preview diff view | Current preview vs previous version |
| Push notifications | Requires service worker infrastructure |
| Collaborative preview editing | Requires PreviewDraft server-side state — deliberately excluded from V1 |
| Mobile planning view | Responsive schedule page for tablet use |

### Future improvement — Daily uncovered mission follow-up

**Status: not scheduled. Not part of Batch 15. Documented here so it is not forgotten — no implementation exists yet.**

**This is NOT a daily reminder per mission.** The intended behaviour:

A daily background job analyses all future `OPEN` missions. For any mission scheduled **within the next 7 days** that still has no assigned instrumentist, the surgeon must be notified. Missions more than 7 days away are **not** included — no notification for those yet on that run.

**Grouping is mandatory**: one email per surgeon per job execution, listing **all** of that surgeon's own interventions occurring within the next 7 days that are still uncovered. Never one email per mission — a surgeon with 3 uncovered sessions in the window gets a single email with 3 lines, not 3 emails.

The email must explain, in plain language:
- SurgicalHub is still actively looking for an eligible instrumentist for these sessions.
- If no instrumentist is found before surgery, the surgeon should consider contacting the medical device company (firm) so they can provide an operating room representative, if appropriate.

Open questions for whoever picks this up: the trigger mechanism (scheduled command vs. cron-driven message), the rolling 7-day window recomputed fresh on every run (a mission entering the window today wasn't included yesterday — no "already notified" suppression was specified, so by default this would re-notify daily for as long as a mission stays uncovered and within the window; confirm whether that's the desired behavior or whether a per-mission/per-day de-duplication marker is needed), and how it interacts with `OPEN_MISSION_AVAILABLE` (this is surgeon-facing follow-up, not the instrumentist-facing pool notification — the two are complementary, not duplicates).

### BUG — `planning_global.html.twig` ISO week grouping ✅ FIXED (2026-07-05)

**Status: fixed and validated on a real deployment. Originally discovered 2026-07-04 during template testing; confirmed as a P0 production bug 2026-07-05 when it was found to silently block the manager deployment email entirely (see D-058 manager email — required `globalPdf !== null`).**

**Root cause (confirmed via isolated reproduction):** the week-grouping key was a purely numeric-looking string (e.g. `"36"`, from `mission.startAt|date("W")|number_format`). Twig's `merge` filter is built on PHP's `array_merge()`, which **silently renumbers any purely-integer-like array keys as sequential list indices (0, 1, 2, …)** instead of preserving them — this is standard, documented PHP behavior, not a Twig bug. So the very first `merge` involving a week key silently turned `byWeek["36"]` into `byWeek[0]`, and every subsequent `byWeek[week]` lookup for `"36"` failed with `Twig\Error\RuntimeError: Key "36" for sequence/mapping with keys "0" does not exist`.

**Why only the global PDF was affected:** the surgeon and instrumentist PDFs group missions by plain day (`mission.startAt|date("Y-m-d")`, e.g. `"2026-09-01"`) — a string with dashes, never a canonical numeric string, so PHP never auto-casts or renumbers those keys. Only the global PDF's week-*number* grouping used a genuinely numeric-looking key, making it the sole template vulnerable to this exact class of bug.

**Why tests missed it:** every handler unit test (`PlanningDeployPdfsHandlerTest.php`) mocks `PdfService::generateFromTemplate()` entirely — none of them ever invoke real Twig rendering. It surfaced only once a real (non-mocked) Twig render test was added, and its true production impact (silently killing the manager email) was only proven by an actual end-to-end deployment through the real API.

**Fix (minimal, no layout/business-logic change):** prefix the week key with a non-numeric character (`'w' ~ mission.startAt|date("W")`) so PHP/Twig never treats it as an integer key, then strip the prefix back off (`week|replace({'w': ''})`) for the visible "Semaine N" label and the even/odd parity calculation — visual output is byte-for-byte identical to before, only the internal grouping key changed.

**Validation:** real deployment (site "CHIREC - Hôpital Delta", October 2026, 64 missions, deploymentId 6) — manager email now arrives (`Déploiement confirmé — planning 01/10/2026 au 31/10/2026`) with correct stats (64/52/12) and the global PDF attached; the PDF itself renders successfully across all 5 ISO weeks of the month (40–44) with correct alternating pair/impair styling and French `MissionStatus::label()` values; no regression in the surgeon/instrumentist PDFs from the same deployment. Regression test added: `PlanningPdfTemplatesTest::test_global_pdf_renders_across_multiple_iso_weeks_without_error` (2 missions in different ISO weeks — the exact condition that used to crash). Full backend suite: 717/717 green.

### Technical debt — Planning V1 retirement

**Status: not scheduled. Documentation only — nothing removed yet.**

Planning V2 (`SurgeonSchedulePost` + `PlanningGeneratorServiceV2`) is the active, routed generation/deploy flow. The original V1 generation/deployment stack still exists in the codebase and remains partially reachable, but its primary entry point is no longer wired into navigation. Before any removal, confirm no production workflow still depends on it — this needs an explicit audit (real usage logs/analytics, not just route-reachability) since "not linked from the main nav" is not the same as "definitely unused."

**Known V1 surface, to be reviewed as a whole before any removal:**

- **Backend controllers**: `PlanningGenerationController.php` (`/api/planning/generate`, `/api/planning/preview` — V1 versions, parallel to the V2 equivalents), `PlanningDeployController.php` (`/api/planning/deploy` — V1 deploy; still shares `PlanningDeploymentService::deploy()` with V2, so the service itself is not V1-specific and must stay regardless).
- **Backend services**: `PlanningGeneratorService.php` (V1; still has a live consumer in `PlanningGenerationController.php` — not dead code today, but a candidate once/if the controller is retired).
- **Frontend pages**: `PlanningGeneratePage.tsx` (V1 generate UI) — confirmed **not** registered in `AppRouter.tsx`, i.e. unreachable from any in-app navigation today. `PlanningVersionDetailPage.tsx` — still routed (`m/planning/versions/:id`) and contains the V1 `DeployModal.tsx` flow; this is the one page that keeps the V1 deploy endpoint reachable (via direct URL/link, not from the sidebar).
- **Obsolete API fields**: `sendChangeSummary` was already removed from `PlanningDeployPdfsMessage`/`PlanningDeploymentService`/both deploy controllers (D-058) — but the frontend (`planning-manager/api/planning.api.ts`, `DeployModal.tsx`, `PlanningVersionDetailPage.tsx`) still sends this field in its request body. It is now silently ignored server-side (no error), but the field itself is dead payload that should be removed from the frontend request shape as part of this same cleanup.
- **Dead UI options**: the `sendChangeSummary` checkbox in `DeployModal.tsx` (D-039's "Étape 2 — Récapitulatif des modifications") has no server-side effect anymore — it's a no-op control that should be removed or clearly relabeled, not left implying a feature that no longer exists.

This entry intentionally does not propose a removal timeline — it exists so the decision ("retire V1 entirely" vs. "keep as an intentional fallback") gets made explicitly rather than by accretion.

### Future V3 Ideas

- AI-assisted instrumentist suggestion based on historical pairing and workload
- Surgeon-facing planning app with direct calendar integration
- Automated pool re-notification at T+24h for unclaimed missions
- Cross-site planning (one planning spanning multiple hospitals)

---

## 11. Final Implementation Order

### Execution Order

| # | Batch | Complexity | Blocking | Estimated Effort |
|---|---|---|---|---|
| 1 | **14.5** — Preview Editor | L | Yes | 6–8 dev-days |
| 2 | **15A** — Foundations | S | Yes | 2 dev-days |
| 3 | **15B** — Post-Deploy Actions | M | Yes | 3–4 dev-days |
| 4 | **15C** — Deploy Notifications Revamp | M-L | Yes | 4–5 dev-days |
| 5 | **15D** — OPEN Pool Eligibility ✅ DONE | M | Yes | 3 dev-days |
| 6 | **15E** — Coverage Notifications ✅ DONE | S | Yes | 2 dev-days |
| 7 | **15F** — Coverage KPI + History (backend) | S-M | Yes | 2–3 dev-days |
| 8 | **15G** — Living Planning UI | L | Yes | 5 dev-days |
| 9 | **15H** — Planning History UI | M | No* | 3 dev-days |
| 10 | **14** — Notification Preferences | M | No* | 3 dev-days |
| 11 | **15I** — Analytics | L | No | 6–8 dev-days |

*Recommended in same release. Can be delayed by one release without breaking core functionality.

**Total blocking work:** ~33–40 dev-days

### Critical Path

```
15A → 15B → 15C → 15D → 15E → 14
                                    (longest chain: 14 dev-days backend)
15A → 15B → 15F → 15G
                    (parallel chain, converges at 15G: 10 dev-days)
```

14.5 is off the critical path — it can be developed on a parallel branch.

### Parallelizable Work (Two Developers)

| Developer A | Developer B |
|---|---|
| Batch 14.5 | Batch 15A |
| Batch 15C | Batch 15F |
| Batch 15G | Batch 15H |
| Batch 14 | Batch 15I |

---

## 12. Final Validation

### Self-Review Findings and Resolutions

**Missing dependency found:** Batch 15E dispatches `MissionLifecycleChangedMessage` notification for `CLAIMED`. The claim endpoint migration to `MissionPostDeployService` happens in Batch 15B. If 15B is skipped, 15E has no message to consume. Confirmed: 15B is correctly listed as a prerequisite.

**Duplicate work check:** `MissionPostDeployService::claim()` is introduced in 15B and consumed by 15E. No overlap. `PlanningCoverageService` is used by 15F (backend) and 15G (frontend) in strict sequence. No overlap.

**Architectural inconsistency check:** D-056 requires no `em->persist(mission)` in controllers. Batch 15B introduces the application service. Batches 15C–15E all operate via Messenger handlers, not controllers. Consistent.

**Untested feature check:** `previewVersion` 409 response is tested in functional HTTP (14.5). Handler unknown changeType is tested in 15E unit tests. Coverage `null` when `total = 0` is tested in 15F unit tests. Bulk assign with incompatible sites is covered in 14.5 frontend tests.

**Documentation gaps:** Each batch lists exactly which docs must change. `docs/api.md` is updated in: 14.5 (preview, generate), 15B (release, cancel), 15F (coverage-summary, history, audit). `docs/decisions.md` already contains D-052–D-056. `docs/planning-v2-architecture-freeze.md` updated in 14.5 (Preview Editor spec).

**Ordering issue check:** Batch 14 (Notification Preferences) listed as depending on 15E. Verified: `SURGEON_POST_COVERED` and `SURGEON_POST_UNCOVERED` are the last new `NotificationType` cases added in 15E. The UI should not be built before all types exist. Order confirmed correct.

**Risk not previously documented:** `NotificationPreference.notificationType` is mapped with `enumType: NotificationType::class`. If a DB row exists with a type string not in the PHP enum (e.g. from a future migration rollback), Doctrine will throw on hydration. Mitigation: any rollback of `NotificationType` enum cases must first delete the corresponding `notification_preference` rows.

---

### RC1-A — Fix Cluster A (OPEN Mission Pipeline) ✅ DONE (2026-07-01)

**Context:** End-to-end business-workflow audit (RC1 Gate) found that 10 of 12 workflows FAIL. Cluster A is the first blocker: OPEN pool missions are invisible to instrumentists at every level (deploy, list, view). No new features — fixes only.

#### Bugs fixed

| ID | Severity | File | Root cause | Fix |
|----|----------|------|------------|-----|
| P0-1 | P0 | `PlanningDeploymentService.php` | `openUncoveredIds` always `[]` for V2 → `OPEN_MISSION_AVAILABLE` notifications never fire | After the bulk OPEN UPDATE (step 4b), run a `SELECT m.id` query to fetch newly-opened IDs; pass them to the async message |
| P0-2 list | P0 | `MissionService.php:list()` | `innerJoin('m.publications', 'p')` silently excludes all V2 OPEN missions (they have no MissionPublication rows) | Changed to `leftJoin`; added V2 OR-clause: `p.id IS NULL AND (isFreelancer OR siteMemembership)` |
| P0-2 voter | P0 | `MissionVoter.php:canView()` | `canView()` called `isEligibleInstrumentistForOpenMission()` which iterates `$mission->getPublications()` → returns `false` for V2 | Added V2 bypass: `if (getPublications()->isEmpty()) return true;` mirroring the existing `canClaim()` bypass |
| W10-1 | P1 | `MissionService.php:list()` | `GET /api/missions` had no role-based scoping — any authenticated user saw all missions | Non-managers without `eligibleToMe`/`assignedToMe` flag are auto-scoped: instrumentists → `m.instrumentist = self`, surgeons → `m.surgeon = self`, others → 403 |

#### New tests

| File | Tests added |
|------|-------------|
| `PlanningDeploymentServiceTest.php` | `test_v2_deploy_message_carries_open_mission_ids_from_pool` (replaces old empty-IDs test), `test_v2_deploy_message_carries_empty_ids_when_no_pool_missions` |
| `MissionVoterTest.php` | `test_v2_open_mission_any_instrumentist_can_view`, `test_instrumentist_cannot_view_assigned_mission_they_do_not_own`, `test_surgeon_can_view_their_own_mission`, `test_surgeon_cannot_view_another_surgeons_mission`, `test_manager_can_view_any_mission` |
| `PlanningV2GenerationControllerTest.php` | `test_open_mission_pipeline_v2_deploy_to_claim` — full pipeline: deploy → OPEN → eligible list → view → claim → ASSIGNED; also validates W10-1 pre/post-claim scoping |

#### What is NOT fixed (future batches)

- **Cluster B:** `MissionLifecycleChangedMessageHandler` only handles CLAIMED and RELEASED — CANCELLED, REASSIGNED, ADDED all hit `default` skip
- **Cluster C:** `assignInstrumentist` uses raw `ROLE_MANAGER` check, bypasses MissionVoter entirely

---

### RC1-B — Complete Mission Lifecycle Notification Engine ✅ DONE (2026-07-01)

**Context:** RC1-A left Cluster B open: `MissionLifecycleChangedMessageHandler` only reacted to `CLAIMED` and `RELEASED`; `CANCELLED` and `REASSIGNED` silently hit the `default` branch, so managers cancelling or reassigning a deployed mission produced an `AuditEvent` but no notification to anyone. `MissionPostDeployService` remains the only mutation path (D-056); this batch only extends the handler side of the pattern.

#### Transitions implemented

| ChangeType | Recipients | Notification type(s) |
|---|---|---|
| `CLAIMED` | Surgeon | `SURGEON_POST_COVERED` |
| `RELEASED` | Surgeon | `SURGEON_POST_UNCOVERED` |
| `RELEASED` (new) | Eligible instrumentists (`MissionEligibilityService::findEligible()`, same model as deploy) | `OPEN_MISSION_AVAILABLE` |
| `REASSIGNED` | Old instrumentist | `PLANNING_MISSION_REASSIGNED` (removal) |
| `REASSIGNED` | New instrumentist | `PLANNING_MISSION_REASSIGNED` (assignment) |
| `REASSIGNED` (`fromInstrumentistId === null` only) | Surgeon | `SURGEON_POST_COVERED` — manager `assign()` from OPEN is dispatched as `REASSIGNED`; a pure ASSIGNED→ASSIGNED reassign does not change the surgeon's coverage view, so no surgeon notification fires in that case |
| `CANCELLED` | Surgeon | `PLANNING_MISSION_CANCELLED` |
| `CANCELLED` | Instrumentist, if assigned (defensive — `cancel()` currently requires `OPEN`, so this path is not reachable today but is handled for forward-compat) | `PLANNING_MISSION_CANCELLED` |
| `ADDED`, `TIME_CHANGED`, `REMOVED`, `UPDATED` | — | unhandled — `default` branch logs and returns (forward-compatible skip, unchanged from RC1-A) |

**`ASSIGNED` (`MissionChangeType`) does not exist as a distinct case.** Both `MissionPostDeployService::assign()` and `::reassign()` dispatch `REASSIGNED`; the OPEN→ASSIGNED case is distinguished purely by `payload['fromInstrumentistId'] === null`. No dedicated `ASSIGNED` handling was needed — documented in the handler's class docblock so future readers don't go looking for a case that doesn't exist.

#### Files changed

| File | Change |
|---|---|
| `src/MessageHandler/MissionLifecycleChangedMessageHandler.php` | Added `handleReassigned()`, `handleCancelled()`, `sendOpenMissionAvailableNotifications()`; constructor now takes `MissionEligibilityService` |
| `tests/Unit/MessageHandler/MissionLifecycleChangedMessageHandlerTest.php` | `makeHandler()` fixed to pass the 5th constructor arg (was throwing `ArgumentCountError` on every test — the file had gone stale relative to the handler); 19 new tests covering RELEASED pool notifications, REASSIGNED (both recipients, conditional surgeon notification, preference gating, missing-user resilience), CANCELLED (both recipients, no-instrumentist case, preference gating), failure isolation |

No changes were needed in `MissionPostDeployService`, `MissionEligibilityService`, controllers, or routing — RELEASED/CANCELLED/REASSIGNED were already dispatching correctly since RC1-A; only the handler's `default` branch needed replacing with real cases.

#### Failure isolation & idempotency

Unchanged from Batch 15E: every notification channel (inApp, push) is independently wrapped in `try/catch`; the pool eligibility query for RELEASED is also isolated so a query failure never blocks the surgeon's own notification. Idempotency limitation is the same accepted V1 gap — Messenger retries can produce duplicate `NotificationEvent` rows; full dedup would need a UNIQUE index on `(mission_id, user_id, event_type, DATE(sent_at))`.

#### Regression

Full backend suite green after this change (unit + functional + security). Deploy, OPEN pipeline, MissionEligibility, Coverage, History, and V1 paths untouched — this batch only added `match()` arms and private methods to the handler; no existing case was modified.

#### What is NOT fixed (future batches)

- **Cluster C:** `assignInstrumentist` uses raw `ROLE_MANAGER` check, bypasses MissionVoter entirely (unchanged, out of scope for RC1-B)
- Notification idempotency (UNIQUE index) — accepted V1 limitation, not introduced by this batch

---

### RC1-C — Fix Cluster C + Full Suite Validation ✅ DONE (2026-07-02)

**Context:** Cluster C from the RC1 gate audit: `MissionController::assignInstrumentist()` (the DRAFT-only pre-deploy assignment endpoint) used a raw `denyAccessUnlessGranted('ROLE_MANAGER')` string check instead of `MissionVoter`, and mutated `Mission` + called `$em->flush()` directly in the controller — violating D-056 (all Mission mutations must go through an application service).

#### Root cause & fix

| Violation | Fix |
|---|---|
| Raw `'ROLE_MANAGER'` string check — excludes ADMIN unless the user literally has both roles; bypasses the voter layer entirely | New `MissionVoter::ASSIGN_INSTRUMENTIST` attribute — `$isManager` gate (MANAGER or ADMIN), same pattern as `RELEASE`/`CANCEL`/`REASSIGN` |
| Direct `$mission->setInstrumentist(...)` + `$this->em->flush()` in the controller | New `MissionService::assignInstrumentistDraft(Mission, ?int): Mission` — DRAFT-only guard, throws `MissionNotDraftException` otherwise; controller now only orchestrates (voter check → service call → serialize) |
| Bespoke inline 409 JSON body (`{"error":{"code":"MISSION_NOT_DRAFT",...}}`) built by hand in the controller, bypassing the app-wide exception→JSON pipeline | New `App\Exception\MissionNotDraftException` (extends `ConflictHttpException`); `ApiExceptionSubscriber` maps it to `error.code = 'MISSION_NOT_DRAFT'` — same wire contract, now produced by the normal exception path instead of a controller-level `return $this->json(...)` |

**Scope discipline:** `assignInstrumentistDraft()` intentionally does **not** create an `AuditEvent` or dispatch `MissionLifecycleChangedMessage` — a DRAFT mission has no publication and no notification recipient yet, consistent with `MissionService::create()` and `::patch()`, which are the two other DRAFT-only mutation methods and also do not audit/notify. Deployed missions still go exclusively through `MissionPostDeployService::release()`/`reassign()`/`assign()`, unchanged.

#### Files changed

| File | Change |
|---|---|
| `src/Controller/Api/MissionController.php` | `assignInstrumentist()` rewritten: voter check + service delegation, no direct mutation |
| `src/Security/Voter/MissionVoter.php` | New `ASSIGN_INSTRUMENTIST` constant, added to `supports()` and `voteOnAttribute()` |
| `src/Service/MissionService.php` | New `assignInstrumentistDraft(Mission, ?int): Mission` |
| `src/Exception/MissionNotDraftException.php` | New — dedicated 409 type for the DRAFT guard |
| `src/EventSubscriber/ApiExceptionSubscriber.php` | Maps `MissionNotDraftException` → `error.code = 'MISSION_NOT_DRAFT'` |
| `tests/Security/Voter/MissionVoterTest.php` | +4 tests: manager/admin granted, instrumentist/surgeon denied for `ASSIGN_INSTRUMENTIST` |
| `tests/Unit/Service/MissionServiceAssignInstrumentistDraftTest.php` | New — 6 tests: assign, clear, DRAFT guard (409, both non-DRAFT statuses), 404 on unknown instrumentist |
| `tests/Functional/MissionAssignInstrumentistControllerTest.php` | New — 9 HTTP tests: manager/admin 200, instrumentist/surgeon 403, OPEN/ASSIGNED → 409 `MISSION_NOT_DRAFT`, clear instrumentist, 404 on unknown instrumentist, D-056 static check (no `setInstrumentist`/`->flush(` in the controller method) |

#### Two pre-existing bugs found and fixed while validating the full suite against a live DB (out of Cluster C, but required for a green suite)

Functional tests in this codebase had apparently never run against a live MySQL instance in this environment before this session — the DB was unreachable in every prior batch. Running the full suite for the first time surfaced two real, previously-invisible bugs unrelated to Cluster C:

1. **Test bug** — `PlanningV2GenerationControllerTest::test_open_mission_pipeline_v2_deploy_to_claim` called `authenticate()` (which performs an HTTP login request, causing Symfony to detach all entities from the test's `EntityManager`), then mutated the returned (now-detached) `User` and called `flush()` — silently a no-op. Fixed by re-fetching the entity via `$this->em->find(User::class, $id)` before mutating.
2. **Production bug** — fixing (1) exposed that `MissionEligibilityService::evaluate()` (the single-candidate gate used by `MissionPostDeployService::claim()`) never bypassed the site-membership check for `FREELANCER` candidates, unlike its sibling `findEligible()` (pool notifications) and `MissionVoter::isEligibleInstrumentistForOpenMission()` — both of which already implement that bypass per D-057. Net effect in production: a freelance instrumentist could never claim an OPEN pool mission unless they also happened to have a formal `SiteMembership` row, defeating the purpose of being freelance. Fixed by adding the same bypass to `evaluate()`'s Q1 site-membership query, mirroring the established pattern. Confirmed with the user before fixing (touches eligibility/Cluster A-adjacent logic, not Cluster C).
3. Also fixed the same test's `tearDown()`, which deleted `Mission` rows without first deleting their `AuditEvent` rows — never triggered before because the `claim()` step always failed with 409 (bug #2), so no `AuditEvent` was ever created for teardown to trip on. Now that `claim()` succeeds, `MISSION_CLAIMED_FROM_POOL` audit rows exist and must be deleted first (FK).

New unit tests for the eligibility fix: `MissionEligibilityServiceTest::test_evaluate_freelancer_bypasses_site_membership_check`, `::test_evaluate_non_freelancer_without_membership_still_ineligible`.

#### Test results

- `MissionVoterTest` + `MissionServiceAssignInstrumentistDraftTest`: 26/26 green
- `MissionAssignInstrumentistControllerTest` (live DB): 9/9 green
- `MissionEligibilityServiceTest`: 15/15 green (13 pre-existing + 2 new)
- **Full backend suite, MySQL running: 698/698 green**, 1 pre-existing unrelated "risky" warning (`AbsenceControllerTest` — framework artifact, not a failure, present before this batch)

#### Remaining (future batches)

- `MissionEligibilityService::findEligible()` and `::evaluateAllCandidates()` build their candidate pool via an inner join on `SiteMembership` — meaning a FREELANCER with no `SiteMembership` row is excluded from the candidate list *before* any eligibility check runs, for pool notifications and the manager's "eligible instrumentists" endpoint. This is the mirror-image gap of the bug fixed in this batch (exclusion instead of false rejection) — same root cause (freelancer bypass not applied consistently across all three `MissionEligibilityService` methods), left open since fixing it means restructuring the candidate query, not just adding a condition. **P1.**
- Notification idempotency (Messenger retries can duplicate `NotificationEvent` rows) — accepted V1 limitation since Batch 15E, unchanged.

---

### RC1-D — Fix `MissionEligibilityService::findEligible()` (P0) ✅ DONE (2026-07-03)

**Context:** The RC1 local Docker validation found that `OPEN_MISSION_AVAILABLE` pool notifications never actually fired — `release()` correctly notified the surgeon (`SURGEON_POST_UNCOVERED`) but silently produced zero notifications for eligible instrumentists, every time, with no visible error to the caller.

#### Root cause

`findEligible()`'s Q1 query (candidate pool by site membership):

```php
'SELECT sm FROM App\Entity\SiteMembership sm
 JOIN FETCH sm.user u
 WHERE sm.site IN (:sites) AND u.active = true AND u.roles LIKE :role'
```

**`JOIN FETCH` is not valid Doctrine DQL** — that two-word construct is HQL (Hibernate) syntax. Doctrine's DQL grammar has no `FETCH` keyword; a "fetch join" in DQL is simply a plain `JOIN` with the joined entity added to the `SELECT` clause. Doctrine's parser read `FETCH` as an unresolvable identifier and threw on every real execution:

```
[Semantical Error] line 0, col 62 near 'FETCH sm.user': Error: Class 'FETCH' is not defined.
```

The exception was caught by the `try/catch` around the `findEligible()` call in `MissionLifecycleChangedMessageHandler::sendOpenMissionAvailableNotifications()` (a deliberate failure-isolation boundary from Batch 15E — one notification failure must never block another), logged as an `ERROR`, and swallowed — so the bug produced no visible symptom beyond "the notification never showed up" and a log line nobody was watching for. `PlanningDeployPdfsMessageHandler::sendPoolNotifications()` (Batch 15D, deploy-time pool notifications) calls the exact same broken method and was equally affected.

#### Why `evaluate()` worked while `findEligible()` didn't

`evaluate()` (the single-candidate gate used by `claim()`) issues 3 flat, single-entity `SELECT COUNT(...) FROM X WHERE ...` queries — none of them contain a `JOIN` at all, because `evaluate()` already has both the candidate and the mission's site as concrete objects and only needs `WHERE x.field = :param` conditions. There was never an opportunity to write `JOIN FETCH` in `evaluate()` because it never needed a join in the first place.

`findEligible()`'s Q1 is structurally different: it's a *batch* query across an unknown number of sites, and it needs to filter on `User` fields (`u.active`, `u.roles`) while returning `SiteMembership` rows — that requires an actual join to `User`, and to avoid an N+1 (one lazy-load query per membership when `$sm->getUser()` is later called in the PHP loop), the author reached for a fetch-join and wrote invalid syntax. This is a pure DQL-authoring mistake, isolated to the one query that structurally needed a join.

**Why no test caught it in ~2 months:** every existing test for `findEligible()` mocks `EntityManager::createQuery()` and returns a canned `Query` double — the DQL *string* itself was never parsed by real Doctrine in any test run. This is the same class of gap documented in `tests/Integration/AbsenceReminderServiceTest.php`'s own doc comment ("query logic... has previously broken silently behind a mocked EntityManager in this codebase") — this is now a second, independent instance of exactly that failure mode.

**Not the cause (checked and ruled out):** freelancer filtering, duplicate elimination, hydration semantics, and general JOIN *strategy* (regular vs. left) are all fine as originally written — the single defect is the literal token `FETCH` where DQL has no such keyword. The freelancer-candidate-pool exclusion noted above ("Remaining, P1") is a real, separate, already-documented gap in the *design* of the candidate query (INNER JOIN on `SiteMembership` structurally excludes freelancers) — it is **not** part of this P0 and was deliberately left untouched here (fixing it means restructuring the query, which is out of scope for a targeted P0 syntax fix and would be scope creep beyond "fix only findEligible()").

#### Fix

One query rewritten in `MissionEligibilityService::findEligible()`:

```php
'SELECT sm, u FROM App\Entity\SiteMembership sm
 JOIN sm.user u
 WHERE sm.site IN (:sites) AND u.active = true AND u.roles LIKE :role'
```

`JOIN sm.user u` + adding `u` to `SELECT` is the correct DQL fetch-join — `User` is still hydrated in the same query (no N+1), same 3-query budget (D-036), same public contract, no architecture change (D-057 unaffected — still the single eligibility engine, no second algorithm introduced).

#### Files changed

| File | Change |
|---|---|
| `src/Service/MissionEligibilityService.php` | `findEligible()` Q1: `JOIN FETCH sm.user u` → `JOIN sm.user u` + `u` added to `SELECT` |
| `tests/Integration/MissionEligibilityServiceFindEligibleTest.php` | New — 9 tests against the real `surgicalhub_test` DB (not mocked): employee w/ membership eligible, inactive excluded, absent excluded, schedule-conflict excluded, multiple eligible candidates, candidate not duplicated across multiple missions at one site, freelancer-without-membership still excluded (pins the known P1 — not fixed here), zero-candidate site, empty mission list |

No changes to `evaluate()`, `evaluateAllCandidates()`, controllers, voters, or the message handlers — the fix is contained entirely to the one broken query string.

#### Regression — verified against the real local Docker stack (not just automated tests)

Re-ran the exact scenario that previously failed, live, against the local copy of the production database: flipped an existing `DRAFT` mission to `ASSIGNED`, called `POST /missions/{id}/release` as a real manager account, and watched the messenger container's logs in real time.

**Before this fix** (original finding): `ERROR MissionLifecycleChanged::RELEASED: eligibility query failed ... Class 'FETCH' is not defined`, zero `OPEN_MISSION_AVAILABLE` notifications.

**After this fix**, same exact flow: `OPEN_MISSION_AVAILABLE inApp created` logged once per eligible instrumentist (5 recipients), zero errors. Full chain confirmed:

```
release() → status OPEN → findEligible() (no error) → OPEN_MISSION_AVAILABLE NotificationEvent rows created
    → instrumentist's GET /missions?eligibleToMe=true includes the mission
    → claim() still succeeds (200, status → ASSIGNED)
    → AuditEvent(MISSION_RELEASED_TO_POOL) then AuditEvent(MISSION_CLAIMED_FROM_POOL), both correct
```

(The mutated missions were reverted back to their original `DRAFT` state afterward — this test was run against the user's local copy of the production database, not synthetic data, so the copy was restored to exact production parity once verification was complete.)

#### Test results

- New `MissionEligibilityServiceFindEligibleTest` (real DB): 9/9 green
- **Full backend suite: 707/707 green** (698 + 9 new), 1 pre-existing unrelated "risky" warning (`AbsenceControllerTest`, framework artifact, unchanged)

#### Remaining (unchanged, not in scope for this ticket)

- P1 — freelancer exclusion from the `findEligible()`/`evaluateAllCandidates()` candidate pool (see above) — still open, now with an explicit pinning test (`test_freelancer_without_site_membership_is_not_returned_by_find_eligible`) so a future fix is a deliberate, visible change.
- Notification idempotency — unchanged, accepted V1 limitation.

---

### RC1-E — Final Functional RC Blockers ✅ DONE (2026-07-03)

**Context:** The two remaining P1 blockers before RC1 — `findEligible()`'s freelancer candidate-pool gap (predicted in RC1-C/D, confirmed live in RC1-D) and the fact that Living Planning (`PlanningSchedulePage.tsx`) had no route and was completely unreachable in the browser.

#### P1-1 — `findEligible()` freelancer candidate pool

**Root cause:** Q1 built its candidate pool via an INNER JOIN on `SiteMembership` — a FREELANCER with no membership row was structurally invisible, even though `evaluate()` (the real `claim()` gate) already bypasses that requirement. The two methods disagreed on the candidate universe, violating D-057.

**Fix:** Q1 rewritten to `SELECT u AS user, IDENTITY(sm.site) AS siteId FROM App\Entity\User u LEFT JOIN App\Entity\SiteMembership sm ON sm.user = u AND sm.site IN (:sites) WHERE u.active = true AND u.roles LIKE :role AND (u.employmentType = :freelancer OR sm.id IS NOT NULL)`. A freelancer with zero matching memberships now comes back as one row with `siteId = NULL` instead of being dropped by the INNER JOIN. In PHP: freelancers are added to every relevant site's candidate set; employees only to sites where `siteId` matched. Same 3-query budget (D-036), same single algorithm (D-057) — `evaluate()` itself untouched.

**Files:** `src/Service/MissionEligibilityService.php` (Q1 rewritten, unused `SiteMembership` import removed); `tests/Unit/Service/MissionEligibilityServiceTest.php` (4 mocks updated to the new row shape + 1 new freelancer test); `tests/Integration/MissionEligibilityServiceFindEligibleTest.php` (5 new real-DB tests: freelancer without membership now eligible, freelancer with membership not duplicated, freelancer still excluded on absence, freelancer still excluded on conflict, freelancer eligible at multiple sites simultaneously; 1 existing test corrected — a fresh site is no longer guaranteed zero candidates system-wide now that freelancers are global, so that test now asserts the specific non-freelancer candidate is excluded rather than asserting total emptiness).

**Not fixed (documented only, explicitly out of this ticket's scope):** `evaluateAllCandidates()` has the identical INNER JOIN limitation and still excludes freelancers without membership from the manager's "eligible instrumentists" endpoint. Also discovered: `surgicalhub_test` has leftover `FREELANCER` rows (`batch9-*` emails) from an unrelated test's incomplete cleanup, surfaced only because freelancers are now globally visible — not touched.

#### P1-2 — Living Planning route wiring

**Root cause:** `PlanningSchedulePage.tsx` (coverage banner, release/reassign/cancel, mission history drawer, dialogs) existed and was already unit-tested in isolation, but had zero `<Route>` entry and no sidebar link — completely unreachable by any manager.

**Fix:** Reused the exact existing patterns, no navigation redesign:
- `AppRouter.tsx` — one new lazy import + `<Route path="m/planning/living" element={<PlanningSchedulePage />} />`, alongside the existing `m/planning/*` routes.
- `DesktopLayout.tsx` — one new flat entry `{ label: "Planning publié", href: "/app/m/planning/living" }` in the existing `NAV_ITEMS` list, same shape as every other sidebar link (including the precedent of `Absences` being a flat sibling under `/planning/*` despite its own nesting).

**Files:** `frontend/src/app/router/AppRouter.tsx`, `frontend/src/app/layouts/DesktopLayout.tsx`; new `frontend/src/app/router/LivingPlanningRoute.test.tsx` (2 tests: sidebar link navigates to and renders Living Planning; existing "Planning" link unchanged).

#### Test results

- Backend: **713/713 green** (707 + 6 new)
- Frontend `tsc --noEmit`: clean
- Frontend `test:run`: **262/263** — the 1 failure (`PostFormDialog.test.tsx`) is an untouched, pre-existing file (zero diff) timing out under genuine system load (10 Docker containers + 2 local MySQL processes running continuously for 29h in this environment); same class of environmental flake observed in the RC1-C validation session, not a regression from this ticket.

#### Confirmations

- Living Planning is reachable end-to-end: sidebar → "Planning publié" → coverage banner, mission table, release/cancel/reassign dialogs, history drawer all render.
- Freelancer eligibility is now fully aligned with D-057: `findEligible()` and `evaluate()` agree on the exact same candidate universe.

---

### Deploy Email Policy Redesign ✅ DONE (2026-07-04)

**Root cause found**: `PlanningV2GenerationController::deploy()` mapped `sendPdf` (default `true`) straight onto `PlanningDeploymentService::deploy()`'s `sendChangeSummary` parameter — every V2 deploy sent the change-summary "recap" email in addition to the standard planning email, with overlapping content (the surgeon email already listed uncovered posts).

**Fix — exactly ONE deploy email per recipient (D-058, amends D-053):**
- Surgeon: one email, aggregate counts (total/covered/uncovered) + non-technical explanation when uncovered > 0; own PDF only, global PDF removed.
- Instrumentist: one email, mission count + own PDF (unchanged content, D-054 Family 1 was already correct).
- Manager: **new** email (deployment confirmation + stats + global PDF), alongside the existing in-app notification.
- Change-summary emails extracted into standalone `PlanningChangeSummaryService` — no longer invoked during initial deploy; kept callable for a future post-publication-change trigger (not built yet).
- `sendChangeSummary` removed from `PlanningDeployPdfsMessage`, `PlanningDeploymentService::deploy()`, both deploy controllers.
- `MissionStatus::label()` + `french_day` Twig filter added — PDFs are now fully French (previously leaked `OPEN`, `ASSIGNED`, `Tuesday`, etc.).
- `UncoveredReason::MANUALLY_LEFT_OPEN` label changed from "Laissé ouvert manuellement" to "Recherche en cours" (the old label misrepresented a fallback case as a deliberate action).

**Files modified:** `PlanningDeployPdfsMessageHandler.php`, new `PlanningChangeSummaryService.php`, `PlanningDeployPdfsMessage.php`, `PlanningDeploymentService.php`, `PlanningDeployController.php`, `PlanningV2GenerationController.php`, `MissionStatus.php`, `UncoveredReason.php`, new `Twig/DateExtension.php`, 3 PDF templates, `emails/planning_surgeon.html.twig` (rewritten), `emails/planning_instrumentist.html.twig`, new `emails/planning_manager.html.twig`.

**Tests:** rewrote `PlanningDeployPdfsHandlerTest.php` (removed 8 obsolete change-summary tests, added exactly-one-email + manager-email + no-change-summary-during-deploy regression tests), new `PlanningChangeSummaryServiceTest.php`, updated `PlanningEmailTemplatesTest.php`, `PlanningDeploymentServiceTest.php`, `PlanningV2GenerationControllerTest.php`. Backend: **713/713 green**.

**Known follow-up (out of this ticket's scope):** `frontend/.../DeployModal.tsx` (V1 modal) still sends `sendChangeSummary` in its request body — now a silent no-op server-side, not a breaking error. The checkbox should be removed/relabeled in a frontend follow-up.

**Documented, not implemented:** "Daily uncovered mission follow-up" future feature — see §10 Future Roadmap.

---

### Batch 15L — Absence-Driven Mission Reaction ✅ DONE (2026-07-12)

#### Objective

**Business:** A published planning must react automatically when a surgeon or instrumentist declares an absence after generation/deployment — never touching the recurring `SurgeonSchedulePost` definition, only the concrete `Mission` occurrences the absence actually overlaps. Instrumentist absent on an `ASSIGNED` mission → released back to the pool (`OPEN`). Surgeon absent on an `OPEN`/`ASSIGNED` mission → cancelled. Everyone genuinely affected gets a targeted email; nobody gets a duplicate from two different pipelines.

**Technical:** `AbsenceImpactService` keeps its pre-existing, tested "never mutates a Mission" contract completely unchanged — a new collaborator, `AbsenceMissionReactionService`, is called by `AbsenceController` *in addition*, and *before*, `AbsenceImpactService`. Mutation reuses the existing `MissionPostDeployService::release()`/`cancel()` (the latter extended to accept `ASSIGNED`, not just `OPEN`) — same status guard, `AuditEvent`, flush-before-dispatch discipline as every other post-deploy mutator (D-056). A second async message, `AbsenceMissionsReactedMessage` (one per absence-processing run, never one per mission), feeds a new handler that sends exactly one batched recap email per affected recipient — the piece the existing `MissionLifecycleChangedMessage` pipeline never had, since that pipeline is in-app/push only for every change type.

**User value:** A manager declaring an instrumentist's or surgeon's absence no longer has to manually hunt down and reassign/cancel every affected mission on an already-published planning — it happens immediately, with the right people notified by email, and the pre-existing alert system never shows a stale "reassignment required" for a mission that was just auto-resolved.

#### Scope

**Included:**
- `AbsenceMissionReactionService` (new) — `onAbsenceCreated()`/`onAbsenceUpdated()` find missions overlapping the absence for the actionable status subset only (`ASSIGNED` for instrumentist absence; `OPEN`/`ASSIGNED` for surgeon absence), mutate via `MissionPostDeployService`, dispatch one `AbsenceMissionsReactedMessage` covering every mission processed. `onAbsenceDeleted()` never restores anything — only a generic in-app notice to managers/admins to reassess manually.
- `MissionPostDeployService::cancel()` extended: `OPEN → CANCELLED` becomes `OPEN|ASSIGNED → CANCELLED`, clearing the instrumentist in the `ASSIGNED` case. `release()` gains an optional `?string $reason` (audit context only, no behavior change).
- `AbsenceMissionsReactedMessage` + `AbsenceMissionsReactedMessageHandler` (new) — groups by recipient, sends one recap email (`SendBillingEmailMessage`) per person per processing run; in-app notifications stay one per mission (explicitly allowed by spec).
- 3 new `NotificationType` cases (`ABSENCE_INSTRUMENTIST_RELEASED`, `ABSENCE_SURGEON_MISSION_OPENED`, `ABSENCE_MISSION_CANCELLED`), added to `DefaultNotificationPreferenceResolver::EMAIL_ON_BY_DEFAULT` (same urgency tier as `PLANNING_MISSION_CANCELLED`).
- 3 new email templates (`absence_instrumentist_released`, `absence_surgeon_mission_opened`, `absence_mission_cancelled`), reusing the visual system already established for the initial-deploy/modification email redesign.
- `AbsenceController::create()/update()/delete()` — call `AbsenceMissionReactionService` before `AbsenceImpactService`, and pass `#[CurrentUser]` (newly added to `update()`/`delete()`, which didn't need it before).

**Excluded:**
- No mutation ever for `DRAFT`/`SUBMITTED`/`VALIDATED`/`IN_PROGRESS`/`DECLARED` missions — `AbsenceImpactService`'s existing alert remains the only reaction for those, unchanged.
- No automatic restoration on absence deletion (a released mission may already be claimed by someone else; a cancelled mission has already notified people — reconstructing the prior state would silently overwrite what happened since).
- No push notifications for the new absence-specific emails (in-app + email only, consistent with the rest of the notification system).

#### Backend

See D-062 in `docs/decisions.md` for the full status-by-status table, the ordering argument for why `AbsenceImpactService` needed zero changes, idempotency (query-based — a mutated mission naturally falls out of the overlap query, no tracking table), and concurrency (pessimistic lock + transaction, dispatch after commit — same pattern as `MissionPostDeployService::claim()`, the one prior high-contention case).

**Key invariant preserved:** every mutation still goes through `MissionPostDeployService` (D-056) — `AbsenceMissionReactionService` never mutates a `Mission` directly, and `SurgeonSchedulePost` is never touched (proven by a dedicated functional test snapshotting a post's fields before/after).

#### Tests

`MissionPostDeployServiceTest` (+3, `cancel()` extension), `AbsenceMissionReactionServiceTest` (13 unit), `AbsenceMissionsReactedMessageHandlerTest` (8 unit), `AbsenceMissionReactionFunctionalTest` (7 functional, real DB — multiple missions, out-of-period exclusion, terminal-status exclusion, `AuditEvent`/`NotificationEvent` persistence, idempotency on repeated `PATCH`, one message per run, `SurgeonSchedulePost` untouched), `AbsenceControllerTest` (2 rewritten for the new behavior + no-stale-alert composition with `AbsenceImpactService`), `PlanningEmailTemplatesTest` (+7, the 3 new templates). 831/831 backend green. Verified against real Mailpit (throwaway `@surgicalhub.internal` accounts, local dev): both scenarios produce exactly the expected emails, no duplicates, `MAIL_SAFE_MODE` unaffected.

---

### Stabilization — QA validation, RCA, and architecture freeze ✅ ARCHITECTURE FROZEN (2026-07-06)

A full production-readiness validation pass (real API/DB/Mailpit end-to-end testing, all 4 roles, V1 regression) found one P0 and one P1 defect, both root-caused before any fix was written:

- **P0** — a mission that has ever been claimed and then released can never be claimed again (`MissionClaim` row from the first claim is never cleaned up by `release()`, and `claim()`'s duplicate-guard checks that row's mere existence rather than the mission's actual current state).
- **P1** — `OPEN_MISSION_AVAILABLE` notifications can list missions the recipient isn't individually eligible for (`findEligible()`'s per-site aggregation is handed uniformly to every eligible-for-*something* user in that site, rather than filtered per mission).

**The architecture review is complete. `MissionClaim` and `MissionEligibilityService`'s target shape are now frozen per D-059** (`docs/decisions.md`):
- `MissionClaim` is an append-only historical entity — it must never again be consulted for a business decision. `Mission.status` + `Mission.instrumentist` remain the exclusive source of truth for current state.
- `MissionEligibilityService::findEligible()` is to progressively become mission-centric (`array<missionId, User[]>` instead of `array<siteId, User[]>`) — confirmed to require zero additional DB queries (D-036 preserved), since the per-mission eligibility check is already computed internally and simply discarded today.

Implementation of the P0/P1 fixes follows these validated ADRs in a separate ticket — not yet started.

---

*This document is the definitive implementation reference for Planning V2. All future batches must check the relevant sections of this roadmap before starting implementation and must mark their Definition of Done criteria as complete before merging.*
