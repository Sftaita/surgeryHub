# SurgicalHub --- API (Single Source of Truth)

Last updated: 2026-02-12

---

# 1. Principes fondamentaux

- Aucun fallback métier côté frontend
- RBAC strict (Voters / Guards)
- Les erreurs backend sont renvoyées telles quelles
- Aucune donnée patient
- FK strictes (cohérence item ↔ firm)
- Encodage modifiable jusqu'au verrouillage comptable

---

# 2. Référentiel Firm (Manager/Admin uniquement)

Les firms (fabricants) sont des données de référence.

## GET /api/firms

AuthZ: MANAGER / ADMIN\
Response: \[ { "id": 1, "name": "Smith & Nephew", "active": true }\]

## POST /api/firms

AuthZ: MANAGER / ADMIN\
Body: { "name": "Arthrex" }

## PATCH /api/firms/{id}

AuthZ: MANAGER / ADMIN\
Body: { "name"?: "...", "active"?: true/false }

## DELETE /api/firms/{id}

AuthZ: MANAGER / ADMIN\
Soft delete recommandé (active=false)\
Response: 204

---

# 3. Encodage Mission

## GET /api/missions/{id}/encoding

AuthZ: MissionVoter::EDIT_ENCODING

Inclut : - mission (id, type, status, allowedActions) - interventions -
materialLines - catalog (items + firms)

### Structure JSON

{ "mission": { "id": 17, "type": "BLOCK", "status": "ASSIGNED",
"allowedActions": \["view","encoding","submit"\] }, "interventions": \[
{ "id": 3, "code": "LCA", "label": "Ligament croisé antérieur",
"orderIndex": 1, "materialLines": \[ { "id": 2, "item": { "id": 1,
"label": "Fast-Fix", "referenceCode": "FF-123", "firm": { "id": 1,
"name": "Smith & Nephew" } }, "quantity": "2", "comment": "Implant
principal" } \] } \], "catalog": { "items": \[...\], "firms": \[...\] }
}

Règle métier : - Une intervention ne possède PAS de firms. - Les firms
apparaissent uniquement via MaterialLine.item.firm.

---

# 4. Interventions

## POST /api/missions/{id}/interventions

## PATCH /api/missions/{id}/interventions/{interventionId}

## DELETE /api/missions/{id}/interventions/{interventionId}

AuthZ: Instrumentiste assigné

---

# 5. Material Lines

## POST /api/missions/{id}/material-lines

Body: { "missionInterventionId": 3, "itemId": 1, "quantity": "2",
"comment": "Implant principal" }

## PATCH /api/missions/{id}/material-lines/{lineId}

## DELETE /api/missions/{id}/material-lines/{lineId}

Contraintes : - mission.type ≠ CONSULTATION - itemId obligatoire - firm
dérivée via MaterialItem - aucune surcharge firm côté frontend

---

# 6. Verrouillage encodage

submittedAt : - indique que l'instrumentiste s'est déclaré "fini" - ne
verrouille PAS l'encodage

Encodage modifiable tant que : - encodingLockedAt IS NULL -
invoiceGeneratedAt IS NULL

Si l'un des deux est défini : → toute mutation encodage renvoie 403

---

# 7. MissionClaim

- Historique possible (OneToMany)
- Anti-double géré côté service
- Pas de contrainte unique DB

---

# 8. Erreurs standard

400 --- violation règle métier\
403 --- action interdite\
404 --- ressource inexistante\
409 --- conflit métier

---

# Fin du document
