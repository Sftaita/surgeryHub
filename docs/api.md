# SurgicalHub — API (Single Source of Truth)

Last updated: 2026-03-11

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

## 15. Instrumentistes — gestion manager (V1)

Périmètre V1 retenu :

- ressource instrumentiste portée par `User`
- `employmentType` global au user
- `hourlyRate` et `consultationFee` globaux au user
- affiliations portées par `SiteMembership`
- statut manager mappé sur `User.active`
  - `active = true` → `Active`
  - `active = false` → `Suspended`
- aucun droit métier déduit côté frontend
- aucune donnée patient

### Endpoints cibles V1

```text
GET  /api/instrumentists
GET  /api/instrumentists/{id}
POST /api/instrumentists
PATCH /api/instrumentists/{id}/rates
POST /api/instrumentists/{id}/suspend
POST /api/instrumentists/{id}/activate
POST /api/instrumentists/{id}/site-memberships
DELETE /api/instrumentists/{id}/site-memberships/{membershipId}
GET /api/instrumentists/{id}/planning?from=...&to=...
```

### GET /api/instrumentists

AuthZ: MANAGER / ADMIN

Objectif : alimenter la liste `Ressources > Instrumentistes`.

Filtres attendus côté V1 :

- `search`
- `active`
- `employmentType`
- `siteId`
- pagination si déjà présente dans le socle existant

Réponse attendue : liste légère compatible table manager.

Champs utiles minimum :

- `id`
- `firstname`
- `lastname`
- `fullName`
- `email`
- `active`
- `employmentType`
- `sites`
- `allowedActions`

### GET /api/instrumentists/{id}

AuthZ: MANAGER / ADMIN

Objectif : alimenter le drawer instrumentiste.

Champs utiles minimum :

- identité complète
- email
- `active`
- `employmentType`
- `hourlyRate`
- `consultationFee`
- `defaultCurrency`
- `siteMemberships`
- `allowedActions`

## 16. Instrumentistes — création manager + invitation

### POST /api/instrumentists

AuthZ: MANAGER / ADMIN

Objectif : création rapide d’un instrumentiste depuis le manager.

Règles V1 retenues :

- création d’un `User`
- attribution du rôle instrumentiste
- `active = true`
- `password = null` à la création
- `employmentType` global obligatoire à la création
- au moins un site obligatoire à la création
- création des affiliations initiales (`SiteMembership`)
- génération d’un `invitationToken`
- génération d’un `invitationExpiresAt = now + 48h`
- envoi d’un email d’invitation contenant un lien frontend
- si email déjà utilisé : `409`
- si l’envoi email échoue : la création reste valide, avec warning backend exploitable

Body cible V1 :

```json
{
  "firstname": null,
  "lastname": null,
  "email": "ole@example.com",
  "employmentType": "FREELANCE",
  "siteIds": [1, 2]
}
```

Notes :

- `firstname` / `lastname` peuvent rester vides à la création manager si le flux retenu est de laisser l’instrumentiste compléter son profil via invitation.
- le backend reste source de vérité pour la validation exacte du payload final.

Lien envoyé par email :

```text
{FRONTEND_URL}/complete-account?token=XXXX
```

`FRONTEND_URL` doit venir de la configuration backend.

## 17. Invitations instrumentistes — activation / complétion du compte

Flux V1 retenu :

- le manager crée le compte
- l’instrumentiste reçoit un email
- l’instrumentiste ouvre le lien frontend
- l’instrumentiste complète son profil
- l’instrumentiste définit son mot de passe
- le token est invalidé après activation

Champs à compléter côté frontend lors de l’activation :

- `firstname` (obligatoire)
- `lastname` (obligatoire)
- `phone` (obligatoire)
- `password` (obligatoire)
- `confirmPassword` (obligatoire côté frontend)
- `profilePicture` (optionnel)

Stockage V1 retenu dans `User` :

- `invitationToken` nullable
- `invitationExpiresAt` nullable
- `phone`
- `profilePicture` nullable

Endpoints cibles V1 :

```text
GET  /api/invitations/{token}
POST /api/invitations/complete
```

### GET /api/invitations/{token}

Objectif : vérifier qu’un token d’invitation est valide avant affichage / soumission finale du formulaire frontend.

Réponses attendues :

- token valide
- token expiré
- token introuvable
- compte déjà activé

### POST /api/invitations/complete

Objectif : finaliser l’activation du compte invité.

Effets backend attendus :

- validation du token
- vérification expiration
- mise à jour du profil utilisateur
- hash du mot de passe
- nullification de `invitationToken`
- nullification de `invitationExpiresAt`

Si le token correspond à un compte déjà activé :

- réponse claire de type `Account already activated`
- le frontend pourra rediriger vers login

## 18. Distinction future — auto-inscription instrumentiste

Hors périmètre V1 actuel.

Décision retenue pour plus tard :

- si l’instrumentiste se crée lui-même un compte, un flux séparé devra exiger une validation email préalable.
- ce flux n’impacte pas la V1 manager décrite ci-dessus.

Fin du document
