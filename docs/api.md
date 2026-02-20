# SurgicalHub — API (Single Source of Truth)

Last updated: 2026-02-20

## 1. Principes fondamentaux

- Aucun fallback métier côté frontend
- RBAC strict (Voters / Guards)
- Les erreurs backend sont renvoyées telles quelles
- Aucune donnée patient
- FK strictes (cohérence item ↔ firm)
- Encodage modifiable jusqu'au verrouillage comptable
- Aucune mission déclarée (DECLARED) ne peut être facturée sans validation manager
- Toute transition de statut passe par un endpoint dédié (pas de mutation libre via PATCH générique)

## 2. Référentiel Firm (Manager/Admin uniquement)

(Inchangé)

## 3. Missions — Cycle de vie

Statuts

- DRAFT
- OPEN
- ASSIGNED
- DECLARED
- REJECTED
- SUBMITTED
- VALIDATED
- CLOSED

## 4. Missions standard

### POST /api/missions

AuthZ: MANAGER / ADMIN

Crée une mission planning classique (DRAFT).

### POST /api/missions/{id}/publish

AuthZ: MANAGER / ADMIN

Transition :

DRAFT → OPEN

### POST /api/missions/{id}/claim

AuthZ: INSTRUMENTIST

Transition :

OPEN → ASSIGNED

Transactionnel

Anti-double

409 si déjà claimée

### POST /api/missions/{id}/submit

AuthZ: MissionVoter::SUBMIT

Transition :

ASSIGNED → SUBMITTED

Règles :

- Autorisé aussi si status = DECLARED
- Ne verrouille pas l’encodage

## 5. 🆕 Missions déclarées (Unforeseen activity)

### POST /api/missions/declare

AuthZ: INSTRUMENTIST uniquement

Body

```json
{
  "siteId": 1,
  "surgeonId": 45,
  "type": "BLOCK",
  "startAt": "2026-02-20T14:00:00+01:00",
  "endAt": "2026-02-20T18:30:00+01:00",
  "comment": "Urgence fin de journée"
}
```

Effet backend

- status = DECLARED
- instrumentist_user_id = currentUser
- createdBy = currentUser
- declaredAt = now()
- publication interdite
- audit MISSION_DECLARED

Réponse

MissionDetailDto standard avec :

```json
{
  "id": 123,
  "status": "DECLARED",
  "allowedActions": ["view", "encoding", "submit"]
}
```

Erreurs possibles

- 403 si rôle ≠ INSTRUMENTIST
- 400 si données invalides
- 403 si instrumentiste non autorisé sur site

### POST /api/missions/{id}/approve-declared

AuthZ: MANAGER / ADMIN

Précondition

mission.status = DECLARED

Transition

DECLARED → ASSIGNED

audit MISSION_DECLARED_APPROVED

notification instrumentiste

Erreurs

- 400 si mission non DECLARED
- 403 si non manager

### POST /api/missions/{id}/reject-declared

AuthZ: MANAGER / ADMIN

Précondition

mission.status = DECLARED

Transition

DECLARED → REJECTED

audit MISSION_DECLARED_REJECTED

mission non supprimée

statut terminal

Erreurs

- 400 si mission non DECLARED
- 403 si non manager

## 6. Règles spécifiques DECLARED

Une mission DECLARED :

- ne peut pas être publiée
- ne peut pas être claimée
- ne peut pas être VALIDATED
- ne peut pas être CLOSED
- ne peut pas générer d’ImplantSubMission facturable
- ne peut pas déclencher facturation

Transitions autorisées uniquement :

DECLARED → ASSIGNED
DECLARED → REJECTED

## 7. Encodage Mission

### GET /api/missions/{id}/encoding

AuthZ: MissionVoter::EDIT_ENCODING

Inclut :

- mission (id, type, status, allowedActions)
- interventions
- materialLines
- catalog

Fonctionne aussi pour missions DECLARED.

## 8. Interventions

(Inchangé)

AuthZ: Instrumentiste assigné
Autorisé également si mission.status = DECLARED
Interdit si mission.status = REJECTED

## 9. Material Lines

(Inchangé)

Contraintes supplémentaires :

- Interdit si mission.status = REJECTED
- Interdit si mission.type = CONSULTATION
- Interdit si encodingLockedAt ou invoiceGeneratedAt non null

## 10. Verrouillage encodage

submittedAt :

- indique que l'instrumentiste s'est déclaré "fini"
- ne verrouille PAS l'encodage

Encodage modifiable tant que :

- encodingLockedAt IS NULL
- invoiceGeneratedAt IS NULL
- mission.status ≠ REJECTED

## 11. MissionClaim

(Inchangé)

Non applicable aux missions DECLARED.

## 12. allowedActions[] (contrat frontend)

Calculé dynamiquement.

Si status = DECLARED :

Instrumentiste (owner) :

- view
- encoding
- submit
- edit_hours

Manager/Admin :

- approve
- reject
- edit

Surgeon :

- view

Le frontend ne déduit jamais les droits.

## 13. Erreurs standard

400 — violation règle métier
403 — action interdite
404 — ressource inexistante
409 — conflit métier

Cas supplémentaires :

- 400 si transition invalide (ex: approve mission non DECLARED)
- 403 si tentative publish mission DECLARED
- 403 si tentative claim mission DECLARED

## 14. Audit obligatoire

Événements supplémentaires :

- MISSION_DECLARED
- MISSION_DECLARED_APPROVED
- MISSION_DECLARED_REJECTED

Fin du document
