# API Missions v2.1 (Symfony 7 / JSON)

Toutes les routes sont préfixées par `/api`, réponses JSON, erreurs standard HTTP (400 validation, 401, 403, 404, 409).
Pagination : `page` (default 1), `limit` (default 20).

## AuthZ
- Voters : `MissionVoter` (view/create/publish/claim/submit/encoding), `ServiceVoter` (update/dispute), `RatingVoter`, `ExportVoter`.
- Managers/Admin uniquement pour création/publication et vues manager (`*:read_manager`).

## Missions
- `POST /api/missions` (manager/admin) — body:
```json
{ "siteId": 1, "type": "BLOCK", "schedulePrecision": "EXACT", "startAt": "2025-01-02T08:00:00Z", "endAt": "2025-01-02T12:00:00Z", "surgeonUserId": 5, "instrumentistUserId": 9 }
```
- `POST /api/missions/{id}/publish` (manager/admin) — body:
```json
{ "scope": "POOL" } 
// ou { "scope": "TARGETED", "targetUserId": 9 }
```
- `POST /api/missions/{id}/claim` (instrumentist ciblé/pool) — transaction + verrou pessimiste; 409 si déjà claim.
- `GET /api/missions?siteId=1&status=OPEN&type=BLOCK&assignedToMe=true&page=1&limit=20`
- `GET /api/missions/{id}`
- `POST /api/missions/{id}/submit` (instrumentist assigné ou manager/admin) — body optionnel:
```json
{ "noMaterial": true, "comment": "RAS" }
```

## Encoding
- `POST /api/missions/{id}/interventions` — body `{ "code": "ABC", "label": "Chirurgie", "orderIndex": 0 }`
- `PATCH /api/missions/{id}/interventions/{interventionId}` — partial update.
- `DELETE /api/missions/{id}/interventions/{interventionId}`
- `POST /api/interventions/{interventionId}/firms` — body `{ "firmName": "Zimmer" }`
- `PATCH /api/interventions/{interventionId}/firms/{firmId}` / `DELETE ...`
- `POST /api/missions/{id}/material-lines` — body:
```json
{ "missionInterventionId": 10, "missionInterventionFirmId": 3, "itemId": 7, "quantity": 2, "comment": "ajout" }
```
  - Retour 400 si `mission.type = CONSULTATION`.
- `PATCH /api/missions/{id}/material-lines/{lineId}` / `DELETE ...`

## Hours & Disputes
- `PATCH /api/missions/{id}/service` — body:
```json
{ "hours": 3.5, "hoursSource": "INSTRUMENTIST", "status": "CALCULATED" }
```
- `POST /api/services/{serviceId}/disputes` (surgeon) — body `{ "reasonCode": "DURATION_INCOHERENT", "comment": "Too long" }`
- `GET /api/disputes?status=OPEN&page=1&limit=20` (manager/admin)
- `PATCH /api/disputes/{id}` (manager/admin) — body `{ "status": "RESOLVED", "resolutionComment": "OK" }`

## Ratings
- `POST /api/missions/{id}/instrumentist-rating` (surgeon) — body:
```json
{ "sterilityRespect": 5, "equipmentKnowledge": 4, "attitude": 5, "punctuality": 5, "comment": "Top", "isFirstCollaboration": true }
```
- `POST /api/missions/{id}/surgeon-rating` (instrumentist) — body:
```json
{ "cordiality": 4, "punctuality": 5, "missionRespect": 4, "comment": "Bon contact", "isFirstCollaboration": false }
```

## Exports
- `POST /api/exports/surgeon-activity` (surgeon ou manager/admin) — body:
```json
{ "periodStart": "2025-01-01", "periodEnd": "2025-01-31", "siteIds": [1,2], "status": "SUBMITTED", "type": "BLOCK" }
```
- Génère un `ExportLog`, réponse JSON sans montants.
