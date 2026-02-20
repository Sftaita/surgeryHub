# Surgical Hub — Specification v2.2 (PWA Missions + Matériel + Notifications)

Timezone default: Europe/Brussels
Core principle: no patient data stored or inferred.

## 1. Non-negotiable principles

- No patient data: no names, IDs, dates of birth, MRN, etc.
- Mission-centric: everything relates to a Mission.
- Financial compartmentalization:
  - Surgeons and instrumentists must NOT see tariffs, computed amounts, payment status.
  - Managers/Admins can see tariffs, computed amounts, billing states.
- Mobile-first PWA with installability + notifications.
- Full audit trail (who/what/when) for all critical actions.
- Claiming a mission must be concurrency-safe (no double assignment).
- Only Manager/Admin can create official planning missions.
- Surgeons can NEVER create missions.
- Instrumentists may declare unforeseen missions, subject to Manager validation.

## 2. PWA Requirements

### 2.1 Installability

- Must be installable (Android/iOS/desktop).
- Manifest: name/short_name, icons, theme_color, background_color, display=standalone, scope/start_url.
- Service Worker caches:
  - app shell (static assets)
  - read-only data with TTL (missions list, templates, catalog)
- Offline-light behavior:
  - app opens offline and shows last synced data (read-only).
  - write actions (claim, submit, updates) require online.
  - optional: allow saving local drafts without server submit.

### 2.2 Notifications (mandatory)

Channels:

- In-app notifications (always)
- Push notifications (PWA) if user opts in
- Email notifications (for mission publishing / escalation, configurable)

Rules:

- 19:00 local: reminder to assigned instrumentist if mission ended and not submitted.
- D+1 08:00 local (configurable): escalation for missions still not submitted.
- Additional triggers:
  - mission published (OPEN)
  - mission claimed
  - mission declared (to manager)
  - declared mission approved/rejected
  - hours recorded/updated
  - dispute created/resolved
- Each notification is logged: sent/failed/seen timestamps.

Push implementation:

- Web Push (VAPID), per device subscription.
- Users can enable/disable per device.

## 3. Roles & Access Model

System roles:

- INSTRUMENTIST
- SURGEON
- MANAGER
- ADMIN

Surgeons:

- cannot create missions
- cannot publish missions
- cannot approve declared missions

Managers/Admins:

- control planning
- validate declared missions
- control financial workflow

## 4. Mission creation flows

There are now three controlled creation paths.

### 4.1 Weekly generation from templates (scheduled missions)

Unchanged.

Generated missions may be:

- ASSIGNED
- OPEN

### 4.2 Ad hoc missions (manager-created)

Unchanged.

Status lifecycle:
DRAFT → OPEN → ASSIGNED → SUBMITTED → VALIDATED → CLOSED

### 4.3 Instrumentist-declared missions (NEW)

Purpose: handle unforeseen real-world activity (urgent call, overrun block, etc.).

#### 4.3.1 Creation

Instrumentist may declare a mission using:

- site_id
- surgeon_user_id
- mission_type
- start_at / end_at (approximate allowed)
- mandatory comment

Upon creation:

- status = DECLARED
- instrumentist_user_id = current user
- created_by_user_id = instrumentist
- no publication created
- audit event: MISSION_DECLARED

#### 4.3.2 Restrictions

DECLARED missions:

- are NOT claimable
- are NOT publishable
- are NOT billable
- cannot be VALIDATED
- cannot generate invoice
- cannot be CLOSED

Encoding is allowed (draft mode).

#### 4.3.3 Manager decision

Manager/Admin may:

- APPROVE → mission becomes ASSIGNED
- REJECT → mission becomes REJECTED

Audit events:

- MISSION_DECLARED_APPROVED
- MISSION_DECLARED_REJECTED

Notification sent to instrumentist.

If rejected:

- mission remains in history
- no deletion allowed
- no financial consequence

## 5. Mission lifecycle (updated)

Mission statuses:

- DRAFT
- OPEN
- ASSIGNED
- DECLARED (new)
- REJECTED (new – only for declared)
- SUBMITTED
- VALIDATED
- CLOSED

Rules:

DECLARED can only transition to:

- ASSIGNED (approved)
- REJECTED

REJECTED is terminal (no further transitions).

ASSIGNED from DECLARED follows normal lifecycle.

## 6. Claim rules (anti-double)

Unchanged for OPEN missions.

DECLARED missions:

- cannot be claimed
- instrumentist already attached

## 7. Domain model (updates)

Mission:

- site_id
- surgeon_user_id
- instrumentist_user_id
- status
- created_by_user_id
- declared_comment (nullable, mandatory if status = DECLARED)
- declared_at (nullable)

Constraint:

If status = DECLARED → created_by_user_id must equal instrumentist_user_id.

## 8. Encoding structure

Unchanged.

DECLARED missions:

- encoding allowed
- submit allowed
- validation forbidden until approved

## 9. Consultation hours and workflow

Unchanged structurally.

However:

For DECLARED missions:

- hours are editable
- computed_amount not calculated until approval
- dispute system remains available after approval

Audit remains mandatory.

## 10. Notifications additions

New notification types:

- NOTIF_MISSION_DECLARED → to Manager/Admin
- NOTIF_DECLARED_APPROVED → to Instrumentist
- NOTIF_DECLARED_REJECTED → to Instrumentist

All logged.

## 11. Abuse prevention rules

System must:

- log count of declared missions per instrumentist
- track rejection ratio
- allow manager monitoring dashboard (future feature)
- forbid deletion of declared missions
- forbid financial processing until approval

## 12. API additions (conceptual)

New endpoints:

- POST /api/missions/declare
- POST /api/missions/{id}/approve-declared
- POST /api/missions/{id}/reject-declared

Authorization:

- declare → INSTRUMENTIST only
- approve/reject → MANAGER/ADMIN only

## 13. Audit & Compliance (extended)

Additional audit events:

- MISSION_DECLARED
- MISSION_DECLARED_APPROVED
- MISSION_DECLARED_REJECTED

No patient data.

Full traceability preserved.

End of Specification v2.2
