# Surgical Hub — Specification v2.1 (PWA Missions + Matériel + Notifications)

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
  - mission published (OPEN) to targeted instrumentist or pool
  - mission claimed (to manager + surgeon)
  - hours recorded/updated (summary to surgeon)
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

### 3.1 Sites (Hospitals)

- Sites are master data maintained by Manager/Admin (create/update).
- Every Mission belongs to exactly one Site.

### 3.2 Multi-site access (important nuance)

- EMPLOYEE instrumentists can be tied to specific sites (internal staff).
- FREELANCER instrumentists can work across sites:
  - they can be invited/targeted for a mission without permanent site membership.
- Managers/Admins operate within sites they manage.

Access decisions are based on:

- role (system)
- mission.site_id
- membership/management rights (for managers/admins)
- publication targeting (for freelance instrumentists)

## 4. Two ways to create missions

### 4.1 Weekly generation from templates (scheduled missions)

- WeeklyTemplate defines recurring time slots:
  - site_id
  - day_of_week
  - start_time, end_time
  - mission_type: BLOCK | CONSULTATION
  - surgeon_user_id
  - default_instrumentist_user_id (optional)
  - schedule_precision: EXACT | APPROXIMATE (default EXACT)
- Weekly generation creates Missions for future weeks.
- Generated missions can be:
  - ASSIGNED (if default instrumentist set)
  - OPEN (if no instrumentist set and manager chooses to publish)

### 4.2 Ad hoc missions (“plik plok”)

- Manager/Admin can create a mission with:
  - site, surgeon, type
  - approximate or exact schedule
  - instrumentist initially null
- Manager/Admin can then publish it to:
  - a pool of eligible instrumentists, or
  - a specific instrumentist
- Published missions become OPEN.

## 5. Mission publishing and claiming (anti-double)

### 5.1 Mission statuses (minimum)

- DRAFT: created but not published
- OPEN: published, claimable by an instrumentist
- ASSIGNED: instrumentist assigned/claimed
- SUBMITTED: instrumentist submitted encoding (or “no material implanted” flag)
- VALIDATED: manager validated (financial separation)
- CLOSED: billing complete

### 5.2 Publishing rules

- Only Manager/Admin can publish missions.
- Publication targets:
  - POOL: broadcast to eligible instrumentists (benchmark list)
  - TARGETED: sent to a specific instrumentist
- Publication creates NotificationEvents and optionally emails.

### 5.3 Claim rules (critical)

- Instrumentist can claim an OPEN mission if eligible by publication.
- Claim is atomic: once claimed, mission is no longer available to others.
- Server must enforce:
  - DB uniqueness constraint on mission claim
  - transaction + lock to prevent race conditions
- If mission already claimed, API returns 409.

## 6. Domain model (conceptual)

### 6.1 Site (Hospital)

- id, name, address(optional), timezone

### 6.2 User

- email (unique), firstname/lastname nullable, active flag
- authentication: email/password + Google
- instrumentist profile fields (if applicable):
  - employment_type: EMPLOYEE | FREELANCER
  - hourly_rate (manager/admin only; nullable)
  - consultation_fee (manager/admin only; nullable)
  - default_currency (EUR)

### 6.3 SiteMembership (for staff + managers)

- user_id, site_id
- site_role: MANAGER | ADMIN | (optional: STAFF)
- Note: freelancers may exist without membership; eligibility is handled via publication targeting.

### 6.4 Mission

- site_id (required)
- start_at, end_at (nullable allowed for approximate scheduling)
- schedule_precision: EXACT | APPROXIMATE
- type: BLOCK | CONSULTATION
- surgeon_user_id (required)
- instrumentist_user_id (nullable until claimed)
- status (see 5.1)
- created_by_user_id
- Constraints:
  - CONSULTATION missions cannot have any material consumption lines.

### 6.5 Catalog

- Firm (entité de référence) : id, name, active (manager/admin)
- MaterialItem:
  - manufacturer/firm
  - reference_code
  - label
  - unit
  - is_implant boolean
  - active boolean

### 6.6 Encoding structure: Intervention → Material (Firm via Item)

- MissionIntervention:
  - mission_id
  - code, label
  - order_index
- MaterialLine (consumption):
  - mission_id
  - mission_intervention_id (nullable)
  - item_id (MaterialItem)
  - quantity
  - comment
  - created_by_user_id
- Firm exposure:
  - firm est **dérivée** via `MaterialItem.manufacturer` (FK stricte)
  - le frontend ne peut pas surcharger une firm d’item

Constraint:

- forbidden if mission.type = CONSULTATION

Verrouillage encodage:

- `submittedAt` : indique “déclaré fini” (utile pour éviter des rappels), **sans verrouiller** l’encodage.
- lock réel via `encodingLockedAt` (manual manager) ou `invoiceGeneratedAt` (facturation).

### 6.7 Manager-only: Implant Sub-Missions (billing grouping)

(billing grouping)

- ImplantSubMission groups implant lines by firm for invoicing.
- Fields:
  - mission_id
  - firm_name
  - status: DRAFT | INVOICED | PAID
- Links:
  - contains MaterialLine entries where item.is_implant = true
- Visibility: manager/admin only.

## 7. Consultation hours, disputes, and workflow

### 7.1 Consultation rules

- CONSULTATION: material strictly forbidden.
- Hours encoding allowed only if:
  - instrumentist is FREELANCER, OR
  - manager/admin enabled hours for EMPLOYEE (config flag, default OFF)

### 7.2 InstrumentistService

- mission_id
- service_type: BLOCK | CONSULTATION
- employment_type_snapshot: EMPLOYEE | FREELANCER
- hours (nullable)
- consultation_fee_applied (nullable)
- hours_source: INSTRUMENTIST | MANAGER | SYSTEM
- status: CALCULATED | APPROVED | PAID
- computed_amount (manager/admin only)
- Any hours change emits audit event SERVICE_HOURS_UPDATED.

### 7.3 ServiceHoursDispute

- mission_id, service_id
- raised_by_user_id (surgeon)
- reason_code: DURATION_INCOHERENT | WRONG_DATE | DUPLICATE | OTHER
- comment mandatory if OTHER
- status: OPEN | IN_REVIEW | RESOLVED | REJECTED
- resolution_comment (manager/admin)
- constraint: max one OPEN dispute per service.
- Notifications:
  - surgeon: dispute resolved
  - instrumentist: hours adjusted or dispute rejected

### 7.4 Hours notification to surgeon

- Trigger: hours set/modified on InstrumentistService and allowed by rules.
- Content (no financials): site, date/time, type, instrumentist, hours, actions view/dispute.
- Audit: HOURS_RECORDED_SUMMARY_SENT (sent/failed/seen)

## 8. Ratings (quality)

### 8.1 Surgeon → Instrumentist rating (quality)

- InstrumentistRating:
  - site_id (context), mission_id
  - surgeon_user_id, instrumentist_user_id
  - criteria 1–5: sterility_respect, equipment_knowledge, attitude, punctuality
  - comment optional
  - is_first_collaboration boolean
  - constraint: 1 rating per surgeon per mission
- Mandatory on first collaboration surgeon-instrumentist (rule as configured).
- Instrumentist visibility: aggregated anonymized averages if threshold reached.

### 8.2 Instrumentist → Surgeon rating (new requirement)

- SurgeonRatingByInstrumentist:
  - mission_id
  - surgeon_user_id
  - instrumentist_user_id
  - criteria 1–5: cordiality, punctuality, mission_respect
  - comment optional (recommended mandatory if any criterion ≤ 2)
  - is_first_collaboration boolean
  - constraint: 1 rating per instrumentist per mission
- Mandatory when instrumentist works with surgeon for the first time (global first collaboration, not per site).

## 9. Exports (surgeon activity) + audit

- Screen "My activity" for surgeon:
  - filters: period, site(s), status, type
  - columns: date, site, type, schedule, instrumentist, interventions, material summary, no-material flag, status, hours (no amount)
- Exports:
  - Excel: option A (1 row per mission + summary) or option B (1 row per material line)
  - PDF: summary page + mission details
- ExportLog:
  - user_id, output_type, filters JSON, event_type, success/error, timestamps
- Audit: EXPORT_GENERATED with filters.

## 10. Audit & Compliance

- Audit events for:
  - mission create/update/publish/claim
  - intervention/firm/material line add/update/delete
  - submit/validate/close
  - hours updates, disputes lifecycle
  - ratings created
  - notification events sent/seen/failed
  - exports generated
- No patient data. GDPR by design.

## 11. API & Architecture (baseline)

Backend:

- Symfony API
- JWT access token + refresh token
- Google login: frontend obtains Google ID token, backend validates and returns JWT + refresh token
  Frontend:
- React PWA + MUI
- token storage initial approach: localStorage (v2.1); later can migrate to httpOnly cookies

Core endpoints (indicative):

- Auth:
  - POST /api/auth/login
  - POST /api/auth/google
  - POST /api/auth/refresh
- Missions:
  - POST /api/missions (manager/admin)
  - POST /api/missions/{id}/publish (manager/admin)
  - POST /api/missions/{id}/claim (instrumentist)
  - GET /api/missions (filters)
  - GET /api/missions/{id}
  - POST /api/missions/{id}/submit (instrumentist)
- Encoding:
  - POST/DELETE/PATCH /api/missions/{id}/interventions
  - POST/DELETE/PATCH /api/interventions/{id}/firms
  - POST/DELETE/PATCH /api/missions/{id}/material-lines
- Hours:
  - PATCH /api/missions/{id}/service
- Disputes:
  - POST /api/services/{serviceId}/disputes
  - GET /api/disputes?status=OPEN
  - PATCH /api/disputes/{id}
- Ratings:
  - POST /api/missions/{id}/instrumentist-rating
  - POST /api/missions/{id}/surgeon-rating
- Exports:
  - POST /api/exports/surgeon-activity
