# API Missions v2.1 (Symfony 7 / JSON)

Toutes les routes sont préfixées par `/api`, réponses JSON, erreurs standard HTTP (400 validation, 401, 403, 404, 409).
Pagination : `page` (default 1), `limit` (default 20).

## AuthZ

- Voters : `MissionVoter` (view/create/publish/claim/submit/edit/edit_encoding), `ServiceVoter` (update/dispute), `RatingVoter`, `ExportVoter`, `InstrumentistVoter`.
- Managers/Admin uniquement pour création/publication et vues manager (`*:read_manager`).
- Aucun endpoint ne doit exposer des données financières aux rôles SURGEON ou INSTRUMENTIST.

## Missions

- `POST /api/missions` (ROLE_MANAGER/ROLE_ADMIN via `MissionVoter::CREATE`) — body `MissionCreateRequest`.
  - Response: `MissionDetailDto`.
- `PATCH /api/missions/{id}` (ROLE_MANAGER/ROLE_ADMIN via `MissionVoter::EDIT`, mission en DRAFT) — body `MissionPatchRequest`.
  - Response: `MissionDetailDto`.
- `POST /api/missions/{id}/publish` (ROLE_MANAGER/ROLE_ADMIN via `MissionVoter::PUBLISH`) — body `MissionPublishRequest`.
  - Response: 204 No Content.
- `POST /api/missions/{id}/claim` (ROLE_INSTRUMENTIST via `MissionVoter::CLAIM`, mission OPEN + publication POOL/TARGETED) — body vide.
  - Response: `MissionDetailDto`.
- `GET /api/missions` (pas de voter explicite en controller) — query `MissionFilter`:
  - `periodStart`, `periodEnd`, `siteId`, `status`, `type`, `assignedToMe`, `page`, `limit`.
  - Response: `{ items: MissionListDto[], total, page, limit }`.
- `GET /api/missions/{id}` (via `MissionVoter::VIEW`) — body vide.
  - Response: `MissionDetailDto`.
- `POST /api/missions/{id}/submit` (via `MissionVoter::SUBMIT`) — body `MissionSubmitRequest`.
  - Response: `MissionDetailDto`.

## Encoding (Interventions / Firms / Material lines)

- `POST /api/missions/{missionId}/interventions` (via `MissionVoter::EDIT_ENCODING`) — body `MissionInterventionCreateRequest`.
  - Response: `MissionIntervention` sérialisé (group `mission:read`).
- `PATCH /api/missions/{missionId}/interventions/{id}` (via `MissionVoter::EDIT_ENCODING`) — body `MissionInterventionUpdateRequest`.
  - Response: `MissionIntervention` (group `mission:read`).
- `DELETE /api/missions/{missionId}/interventions/{id}` (via `MissionVoter::EDIT_ENCODING`).
  - Response: 204 No Content.
- `POST /api/interventions/{interventionId}/firms` (via `MissionVoter::EDIT_ENCODING`) — body `MissionInterventionFirmCreateRequest`.
  - Response: `MissionInterventionFirm` (group `mission:read`).
- `PATCH /api/interventions/{interventionId}/firms/{id}` (via `MissionVoter::EDIT_ENCODING`) — body `MissionInterventionFirmUpdateRequest`.
  - Response: `MissionInterventionFirm` (group `mission:read`).
- `DELETE /api/interventions/{interventionId}/firms/{id}` (via `MissionVoter::EDIT_ENCODING`).
  - Response: 204 No Content.
- `POST /api/missions/{missionId}/material-lines` (via `MissionVoter::EDIT_ENCODING`) — body `MaterialLineCreateRequest`.
  - Response: `MaterialLine` (group `mission:read`).
- `PATCH /api/missions/{missionId}/material-lines/{id}` (via `MissionVoter::EDIT_ENCODING`) — body `MaterialLineUpdateRequest`.
  - Response: `MaterialLine` (group `mission:read`).
- `DELETE /api/missions/{missionId}/material-lines/{id}` (via `MissionVoter::EDIT_ENCODING`).
  - Response: 204 No Content.

## Services & Disputes

- `PATCH /api/missions/{missionId}/service` (via `ServiceVoter::UPDATE` : manager/admin ou instrumentist assigné) — body `ServiceUpdateRequest`.
  - Response: `InstrumentistService` (group `service:read` ou `service:read_manager`).
  - Note sécurité: aucune donnée financière ne doit être exposée aux rôles SURGEON/INSTRUMENTIST.
- `POST /api/services/{serviceId}/disputes` (via `ServiceVoter::DISPUTE_CREATE` : chirurgien de la mission) — body `ServiceDisputeCreateRequest`.
  - Response: `ServiceHoursDispute` (group `dispute:read`).
- `GET /api/disputes` (via `ServiceVoter::DISPUTE_MANAGE` : manager/admin) — query `status`, `page`, `limit`.
  - Response: `dispute:read_manager`.
- `PATCH /api/disputes/{id}` (via `ServiceVoter::DISPUTE_MANAGE` : manager/admin) — body `ServiceDisputeUpdateRequest`.
  - Response: `ServiceHoursDispute` (group `dispute:read_manager`).

## Ratings

- `POST /api/missions/{id}/instrumentist-rating` (via `RatingVoter::RATE_INSTRUMENTIST` : chirurgien de la mission) — body `InstrumentistRatingRequest`.
  - Response: `Rating` (group `rating:read`).
- `POST /api/missions/{id}/surgeon-rating` (via `RatingVoter::RATE_SURGEON` : instrumentist de la mission) — body `SurgeonRatingRequest`.
  - Response: `Rating` (group `rating:read`).

## Instrumentists

- `GET /api/instrumentists` (ROLE_MANAGER/ROLE_ADMIN via `InstrumentistVoter::LIST`) — body vide.
  - Response: `{ items: InstrumentistListItemResponse[], total }`.
  - Note sécurité: aucun champ financier.
- `GET /api/instrumentists/with-rates` (ROLE_MANAGER/ROLE_ADMIN via `InstrumentistVoter::LIST_WITH_RATES`) — body vide.
  - Response: `{ items: InstrumentistWithRatesListItemResponse[], total }`.
  - Note sécurité: contient `hourlyRate` / `consultationFee` (strictement managers/admin).

## Surgeons

- `GET /api/surgeons` (ROLE_MANAGER/ROLE_ADMIN via security.yaml) — query `q`, `active` (default "1").
  - Response: `{ items: [{ id, email, firstname, lastname, active, displayName }], total }`.
  - Note sécurité: aucun champ financier.

## Sites

- `GET /api/sites` (authentifié via access_control global) — body vide.
  - Response: `Hospital` sérialisé (group `site:list`).

## Me

- `GET /api/me` (IS_AUTHENTICATED_FULLY) — body vide.
  - Response: `MeResponse` (avec `instrumentistProfile` uniquement si rôle INSTRUMENTIST, aucun champ financier).

## Exports

- `POST /api/exports/surgeon-activity` (via `ExportVoter::SURGEON_ACTIVITY` : soi-même ou manager/admin) — body `ExportSurgeonActivityRequest`.
  - Response: `ExportLog` (group `export:read`, sans montants).

## DTO Response (résumé)

- `MissionListDto` / `MissionDetailDto` : `id`, `site` (`HospitalSlimDto`), `startAt`, `endAt`, `schedulePrecision`, `type`, `status`, `surgeon` (`UserSlimDto`), `instrumentist` (`UserSlimDto|null`), `allowedActions[]`.
- `InstrumentistListItemResponse` : `id`, `email`, `firstname`, `lastname`, `active`, `employmentType`, `defaultCurrency`, `displayName`.
- `InstrumentistWithRatesListItemResponse` : idem + `hourlyRate`, `consultationFee`.
- `MeResponse` : `id`, `email`, `firstname`, `lastname`, `role`, `instrumentistProfile`, `sites[]`, `activeSiteId`.
- `InstrumentistProfileResponse` : `employmentType`, `defaultCurrency`.

## Changelog API

- 18-01-26 — Ajout documentation complète des routes Instrumentists/Surgeons/Me/Sites, ajout PATCH mission, précisions RBAC par Voters, mise en avant des restrictions financières.
