# SurgicalHub — API (Single Source of Truth)

_Last updated: 2026-03-18 (v6 — module planning)_

---

## 1. Principes fondamentaux

- Aucun fallback métier côté frontend
- RBAC strict (Voters / Guards)
- Les erreurs backend sont renvoyées telles quelles
- Aucune donnée patient
- FK strictes (cohérence item ↔ firm)
- Encodage modifiable jusqu'au verrouillage comptable
- Aucune mission déclarée (`DECLARED`) ne peut être facturée sans validation manager
- Toute transition de statut passe par un endpoint dédié (pas de mutation libre via `PATCH` générique)

---

## 2. Référentiel Firm

### `GET /api/firms`

**AuthZ :** `MANAGER` / `ADMIN`

Liste toutes les firmes actives, triées par nom.

**Réponse — 200 :**

```json
[
  { "id": 1, "name": "Arthrex" },
  { "id": 2, "name": "Zimmer Biomet" }
]
```

**Notes :**
- Lecture seule — aucun endpoint de création/édition firm (géré en base)
- Utilisé pour peupler le select dans les formulaires matériel

---

## 3. Missions — Cycle de vie

| Statut | Description |
|---|---|
| `DRAFT` | Mission créée, non publiée |
| `OPEN` | Publiée, disponible à la prise en charge |
| `ASSIGNED` | Prise en charge par un instrumentiste |
| `CANCELLED` | Annulée post-déploiement (manager) |
| `DECLARED` | Activité imprévue déclarée |
| `REJECTED` | Rejetée par le manager |
| `SUBMITTED` | Soumise par l'instrumentiste |
| `VALIDATED` | Validée |
| `CLOSED` | Fermée |

---

## 4. Missions standard

### `POST /api/missions`

**AuthZ :** `MANAGER` / `ADMIN`

Crée une mission planning classique (`DRAFT`).

---

### `POST /api/missions/{id}/publish`

**AuthZ :** `MANAGER` / `ADMIN`

**Transition :** `DRAFT → OPEN`

---

### `POST /api/missions/{id}/claim`

**AuthZ :** `INSTRUMENTIST`

**Transition :** `OPEN → ASSIGNED`

- Transactionnel (verrouillage pessimiste)
- Anti-double
- `409` si déjà claimée, non `OPEN`, ou instrumentiste non éligible (`MissionEligibilityService`) (Batch 15D)
- Crée un `AuditEvent(MISSION_CLAIMED_FROM_POOL)` (Batch 15B)
- Dispatche `MissionLifecycleChangedMessage(CLAIMED)` (Batch 15B)

---

### `GET /api/missions/{id}/eligible-instrumentists`

**AuthZ :** `MANAGER` / `ADMIN` (`MissionVoter::VIEW_ELIGIBLE_INSTRUMENTISTS`)

Retourne la liste de tous les instrumentistes du site de la mission, séparés en éligibles et inéligibles, avec les raisons de non-éligibilité.

**Réponse — 200 :**

```json
{
  "missionId": 42,
  "missionStatus": "OPEN",
  "eligible": [
    { "id": 5, "name": "Alice Martin", "email": "alice@example.com" }
  ],
  "ineligible": [
    {
      "id": 7,
      "name": "Bob Dupont",
      "email": "bob@example.com",
      "reasons": ["ABSENT", "SCHEDULE_CONFLICT"]
    }
  ]
}
```

**Valeurs de `reasons` :**

| Valeur | Signification |
|---|---|
| `INACTIVE` | Compte inactif |
| `NO_SITE_MEMBERSHIP` | Pas d'affiliation au site |
| `ABSENT` | Absent ce jour |
| `SCHEDULE_CONFLICT` | Conflit d'horaire avec une autre mission |
| `ALREADY_ASSIGNED` | Mission déjà attribuée à un autre |
| `INCOMPATIBLE_STATUS` | Statut de la mission incompatible (non `OPEN`) |

**Notes :**
- Délégue à `MissionEligibilityService::evaluateAllCandidates()` (≤ 3 requêtes DB — D-036)
- Ajouté en Batch 15D

---

### `GET /api/missions/{id}/audit`

**AuthZ :** `MANAGER` / `ADMIN` ou chirurgien de la mission (`MissionVoter::VIEW_AUDIT`)

Retourne le journal chronologique (DESC) des `AuditEvent` liés à une mission déployée.

**Réponse — 200 :**

```json
[
  {
    "eventType": "MISSION_CLAIMED_FROM_POOL",
    "occurredAt": "2026-06-05T10:30:00+00:00",
    "actorId": 7,
    "actorName": "Bob Dupont",
    "payload": {}
  }
]
```

**Notes :**
- Lecture seule — aucun effet de bord, aucun `flush`
- Trié par `createdAt DESC`
- Ajouté en Batch 15F

---

### `POST /api/missions/{id}/assign-instrumentist`

**AuthZ :** `MANAGER` / `ADMIN` (`MissionVoter::ASSIGN_INSTRUMENTIST`) — corrigé en RC1-C (auparavant un check `ROLE_MANAGER` brut, qui excluait un compte `ADMIN` sans le rôle `MANAGER` explicite)

**Précondition :** `mission.status = DRAFT` uniquement — c'est le chemin d'assignation pré-déploiement. Une mission déployée doit passer par `/release`, `/cancel` ou `/reassign` (R-04, D-056).

**Body :**

```json
{ "instrumentistId": 12 }
```

`instrumentistId` peut être `null` pour retirer l'instrumentiste affecté.

**Réponse — 200 :** Mission complète avec le nouvel instrumentiste (ou `instrumentist: null`)

**Erreurs :**

| Code | Description |
|---|---|
| `403` | Non autorisé (ni `MANAGER` ni `ADMIN`) |
| `404` | Mission ou instrumentiste introuvable |
| `409` `MISSION_NOT_DRAFT` | Mission non `DRAFT` — utiliser `/release`, `/cancel` ou `/reassign` |

**Effets backend :**
- `mission.instrumentist → newInstrumentist` (ou `null`)
- Statut inchangé (reste `DRAFT`)
- Pas d'`AuditEvent`, pas de `MissionLifecycleChangedMessage` — une mission `DRAFT` n'a aucune publication ni destinataire de notification, cohérent avec `create()`/`patch()` (RC1-C)
- Délègue à `MissionService::assignInstrumentistDraft()` — plus de mutation directe dans le contrôleur

---

### `POST /api/missions/{id}/release`

**AuthZ :** `MANAGER` / `ADMIN` (`MissionVoter::RELEASE`)

**Transition :** `ASSIGNED → OPEN`

Relâche la mission vers le pool. L'instrumentiste assigné est désengagé.

**Body :** `{}` (vide)

**Réponse — 200 :** Mission complète avec `status: "OPEN"`

**Erreurs :**

| Code | Description |
|---|---|
| `403` | Non autorisé |
| `404` | Mission introuvable |
| `409` | Mission non `ASSIGNED` |

**Effets backend :**
- `status → OPEN`, `instrumentist → null`
- `AuditEvent(MISSION_RELEASED_TO_POOL)` créé avec snapshot `fromInstrumentistName`
- `MissionLifecycleChangedMessage(RELEASED)` dispatché (async)

---

### `POST /api/missions/{id}/cancel`

**AuthZ :** `MANAGER` / `ADMIN` (`MissionVoter::CANCEL`)

**Transition :** `OPEN → CANCELLED`

Annule une mission ouverte qui ne peut pas être couverte.

**Body :**

```json
{ "reason": "Chirurgien absent" }
```

`reason` est optionnel.

**Réponse — 200 :** Mission complète avec `status: "CANCELLED"`

**Erreurs :**

| Code | Description |
|---|---|
| `403` | Non autorisé |
| `404` | Mission introuvable |
| `409` | Mission non `OPEN` |

**Effets backend :**
- `status → CANCELLED`
- `AuditEvent(MISSION_CANCELLED_POST_DEPLOY)` créé avec snapshot `reason` + `actorName`
- `MissionLifecycleChangedMessage(CANCELLED)` dispatché (async)

---

### `POST /api/missions/{id}/reassign`

**AuthZ :** `MANAGER` / `ADMIN` (`MissionVoter::REASSIGN`)

**Transition :** `ASSIGNED → ASSIGNED` (nouvel instrumentiste)

Réassigne une mission à un autre instrumentiste. Le statut reste `ASSIGNED`.

**Body :**

```json
{ "instrumentistId": 12 }
```

**Réponse — 200 :** Mission complète avec `status: "ASSIGNED"` et le nouvel instrumentiste

**Erreurs :**

| Code | Description |
|---|---|
| `403` | Non autorisé |
| `404` | Mission introuvable / instrumentiste cible introuvable |
| `409` | Mission non `ASSIGNED` |
| `422` | `instrumentistId` manquant ou invalide |

**Effets backend :**
- `mission.instrumentist → newInstrumentist`
- `AuditEvent(MISSION_REASSIGNED_POST_DEPLOY)` avec snapshot `fromInstrumentistName` + `toInstrumentistName`
- `MissionLifecycleChangedMessage(REASSIGNED)` dispatché (async)

---

### `POST /api/missions/{id}/submit`

**AuthZ :** `MissionVoter::SUBMIT`

**Transition :** `ASSIGNED → SUBMITTED`

**Règles :**
- Autorisé aussi si `status = DECLARED`
- Ne verrouille pas l'encodage

---

## 5. Missions déclarées (Unforeseen activity)

### `POST /api/missions/declare`

**AuthZ :** `INSTRUMENTIST` uniquement

**Body :**

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

**Effet backend :**

- `status = DECLARED`
- `instrumentist_user_id = currentUser`
- `createdBy = currentUser`
- `declaredAt = now()`
- Publication interdite
- Audit `MISSION_DECLARED`

**Réponse :**

```json
{
  "id": 123,
  "status": "DECLARED",
  "allowedActions": ["view", "encoding", "submit"]
}
```

**Erreurs possibles :**

| Code | Description |
|---|---|
| `403` | Rôle ≠ `INSTRUMENTIST` |
| `400` | Données invalides |
| `403` | Instrumentiste non autorisé sur site |

---

### `POST /api/missions/{id}/approve-declared`

**AuthZ :** `MANAGER` / `ADMIN`

**Précondition :** `mission.status = DECLARED`

**Transition :** `DECLARED → ASSIGNED`

- Audit `MISSION_DECLARED_APPROVED`
- Notification instrumentiste

**Erreurs :**

| Code | Description |
|---|---|
| `400` | Mission non `DECLARED` |
| `403` | Non manager |

---

### `POST /api/missions/{id}/reject-declared`

**AuthZ :** `MANAGER` / `ADMIN`

**Précondition :** `mission.status = DECLARED`

**Transition :** `DECLARED → REJECTED`

- Audit `MISSION_DECLARED_REJECTED`
- Mission non supprimée — statut terminal

**Erreurs :**

| Code | Description |
|---|---|
| `400` | Mission non `DECLARED` |
| `403` | Non manager |

---

## 6. Règles spécifiques `DECLARED`

Une mission `DECLARED` **ne peut pas** :

- Être publiée
- Être claimée
- Passer à `VALIDATED`
- Passer à `CLOSED`
- Générer d'`ImplantSubMission` facturable
- Déclencher une facturation

**Transitions autorisées uniquement :**

```
DECLARED → ASSIGNED
DECLARED → REJECTED
```

---

## 7. Encodage Mission

### `GET /api/missions/{id}/encoding`

**AuthZ :** `MissionVoter::EDIT_ENCODING`

**Inclut :**
- `mission` (id, type, status, allowedActions)
- `interventions`
- `materialLines`
- `catalog`

> Fonctionne aussi pour les missions `DECLARED`.

> **materialItemRequests dans l'encoding :** seules les demandes avec `status = PENDING` sont incluses. Les demandes RESOLVED ou IGNORED n'apparaissent pas (la RESOLVED génère une MaterialLine visible dans `materialLines`).

---

## 8. Interventions

_(Inchangé)_

**AuthZ :** Instrumentiste assigné

- Autorisé également si `mission.status = DECLARED`
- Interdit si `mission.status = REJECTED`

---

## 9. Material Lines

### `POST /api/missions/{missionId}/material-lines`

**AuthZ :** `MissionVoter::EDIT_ENCODING` (instrumentiste assigné)

Ajouter une ligne matériel à une intervention.

**Body JSON :**

```json
{
  "missionInterventionId": 12,
  "itemId": 45,
  "quantity": "3",
  "comment": "Commentaire optionnel"
}
```

| Champ | Requis | Description |
|---|---|---|
| `missionInterventionId` | ✓ | ID de l'intervention de la mission |
| `itemId` | ✓ | ID du `MaterialItem` dans le catalogue |
| `quantity` | ✓ | Quantité (string décimale : `"1"`, `"0.5"`, `"2,5"`) |
| `comment` | — | Commentaire libre |

**Effets backend :**
- Vérifie que l'intervention appartient bien à la mission
- Normalise la quantité en `DECIMAL(10,2)` (ex: `"3"` → `"3.00"`)
- Set `createdBy = currentUser`

**Réponse — 201 :**

```json
{
  "id": 88,
  "missionInterventionId": 12,
  "item": {
    "id": 45,
    "firm": { "id": 1, "name": "Arthrex" },
    "label": "Anchor suture 5.5mm",
    "referenceCode": "AR-1234",
    "unit": "pièce",
    "isImplant": false
  },
  "quantity": "3.00",
  "comment": "Commentaire optionnel"
}
```

**Erreurs :**

| Code | Description |
|---|---|
| `403` | Non autorisé / encoding guard |
| `404` | Mission, intervention ou item introuvable |

---

### `PATCH /api/missions/{missionId}/material-lines/{lineId}`

**AuthZ :** `MissionVoter::EDIT_ENCODING`

Mise à jour partielle d'une ligne matériel.

**Body JSON :**

```json
{
  "quantity": "2",
  "comment": "Nouveau commentaire"
}
```

Tous les champs sont optionnels. `missionInterventionId` peut aussi être modifié.

**Réponse — 200 :** MaterialLine mise à jour (même format que POST)

---

### `DELETE /api/missions/{missionId}/material-lines/{lineId}`

**AuthZ :** `MissionVoter::EDIT_ENCODING`

Supprimer une ligne matériel.

**Réponse — 204 :** Pas de corps

**Erreurs :** `404` si la ligne n'appartient pas à la mission

---

**Contraintes supplémentaires (toutes opérations material-lines) :**

- Interdit si `mission.status = REJECTED`
- Interdit si `mission.type = CONSULTATION`
- Interdit si `encodingLockedAt` ou `invoiceGeneratedAt` non null

---

## 10. Verrouillage encodage

- `submittedAt` indique que l'instrumentiste s'est déclaré "fini" — **ne verrouille PAS l'encodage**

**Encodage modifiable tant que :**
- `encodingLockedAt IS NULL`
- `invoiceGeneratedAt IS NULL`
- `mission.status ≠ REJECTED`

---

## 11. MissionClaim

_(Inchangé)_

> Non applicable aux missions `DECLARED`.

---

## 12. `allowedActions[]` — Contrat frontend

Calculé dynamiquement. **Le frontend ne déduit jamais les droits.**

**Si `status = DECLARED` :**

| Rôle | Actions autorisées |
|---|---|
| Instrumentiste (owner) | `view`, `encoding`, `submit`, `edit_hours` |
| Manager / Admin | `approve`, `reject`, `edit` |
| Surgeon | `view` |

---

## 13. Erreurs standard

| Code HTTP | Description |
|---|---|
| `400` | Violation règle métier / payload invalide |
| `403` | Action interdite |
| `404` | Ressource inexistante |
| `409` | Conflit métier |
| `422` | Validation métier / validation de formulaire |

**Cas supplémentaires :**
- `400` si transition invalide (ex : approve mission non `DECLARED`)
- `403` si tentative de publish mission `DECLARED`
- `403` si tentative de claim mission `DECLARED`

**Format d'erreur API normalisé :**

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_FAILED",
    "message": "Validation failed",
    "violations": [
      {
        "field": "email",
        "message": "This value is not a valid email address."
      }
    ]
  }
}
```

**Codes `error.code` possibles :**

| Code | Description |
|---|---|
| `BAD_REQUEST` | Requête invalide |
| `UNAUTHORIZED` | Non authentifié |
| `FORBIDDEN` | Accès refusé |
| `NOT_FOUND` | Ressource introuvable |
| `CONFLICT` | Conflit |
| `VALIDATION_FAILED` | Échec de validation |
| `HTTP_ERROR` | Erreur HTTP générique |
| `INTERNAL_ERROR` | Erreur interne serveur |

---

## 14. Audit obligatoire

**Événements supplémentaires :**

| Événement | Déclencheur |
|---|---|
| `MISSION_DECLARED` | Déclaration d'une mission imprévue |
| `MISSION_DECLARED_APPROVED` | Approbation par le manager |
| `MISSION_DECLARED_REJECTED` | Rejet par le manager |

---

## 15. Instrumentistes — Gestion manager (V1)

**Périmètre V1 retenu :**

- Ressource instrumentiste portée par `User`
- `employmentType` global au user
- `hourlyRate` et `consultationFee` globaux au user
- `profilePicturePath` : chemin relatif de la photo de profil, défini lors de la complétion du compte
- Affiliations portées par `SiteMembership`
- Invitation stockée dans `User`
- Statut manager mappé sur `User.active` :
  - `active = true` → **Active**
  - `active = false` → **Suspended**
- Aucun droit métier déduit côté frontend
- Aucune donnée patient

**Endpoints V1 implémentés :**

```
GET    /api/instrumentists
GET    /api/instrumentists/{id}
POST   /api/instrumentists
GET    /api/invitations/{token}
POST   /api/invitations/complete
PATCH  /api/instrumentists/{id}/rates
POST   /api/instrumentists/{id}/suspend
POST   /api/instrumentists/{id}/activate
POST   /api/instrumentists/{id}/site-memberships
DELETE /api/instrumentists/{id}/site-memberships/{membershipId}
GET    /api/instrumentists/{id}/planning?from=...&to=...
```

**Sécurité :**

- Endpoints instrumentistes manager : `AuthZ` via `ROLE_MANAGER` ou `ROLE_ADMIN` — `InstrumentistVoter`
- Capacités couvertes : `INSTRUMENTIST_LIST`, `INSTRUMENTIST_CREATE`, `INSTRUMENTIST_UPDATE_RATES`, `INSTRUMENTIST_ADD_SITE_MEMBERSHIP`, `INSTRUMENTIST_DELETE_SITE_MEMBERSHIP`, `INSTRUMENTIST_SUSPEND`, `INSTRUMENTIST_ACTIVATE`
- Endpoints invitation : **publics** — aucun `denyAccessUnlessGranted()` dans `InvitationController`

---

## 16. Instrumentistes — Endpoints manager

### 16.1 `GET /api/instrumentists`

**AuthZ :** `MANAGER` / `ADMIN`

Alimente la liste _Ressources > Instrumentistes_.

**Query params :**

| Param | Type | Description |
|---|---|---|
| `search` | string (optionnel) | Filtrage textuel — ignoré si vide après trim |
| `active` | boolean (optionnel) | `true`, `false`, `1`, `0` — défaut : `true` si absent |
| `siteId` | integer (optionnel) | Filtre par site — aucun filtre si absent |

**Réponse — 200 :**

```json
{
  "items": [
    {
      "id": 12,
      "email": "ole@example.com",
      "firstname": "Ole",
      "lastname": "Salve",
      "active": true,
      "employmentType": null,
      "defaultCurrency": "EUR",
      "displayName": "Ole Salve",
      "specialties": ["GENOU", "EPAULE"],
      "profilePicturePath": "/uploads/profile-pictures/user-12-abc123.jpg"
    }
  ],
  "total": 1
}
```

**Notes frontend :**
- Pas de pagination
- Pas de filtre `employmentType`
- Pas d'affiliations détaillées ni de tarifs dans la réponse
- `displayName` : `firstname + lastname` si disponible, sinon `email`
- `specialties` : tableau de codes spécialité (voir module planning pour les valeurs possibles)
- `profilePicturePath` : chemin relatif au web root du backend (ou `null`), même convention que `GET /api/instrumentists/{id}` — construire l'URL complète avec `VITE_API_BASE_URL + profilePicturePath`

**Erreurs possibles :**

| Code | Description |
|---|---|
| `400` | `active` invalide / `siteId` invalide |

```json
{
  "error": {
    "status": 400,
    "code": "BAD_REQUEST",
    "message": "Query parameter \"siteId\" must be a positive integer.",
    "violations": []
  }
}
```

---

### 16.2 `GET /api/instrumentists/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

Alimente le drawer instrumentiste.

**Réponse — 200 :**

```json
{
  "id": 12,
  "email": "ole@example.com",
  "firstname": "Ole",
  "lastname": "Salve",
  "displayName": "Ole Salve",
  "active": true,
  "employmentType": null,
  "defaultCurrency": "EUR",
  "hourlyRate": "350",
  "consultationFee": "120",
  "profilePicturePath": "/uploads/profile-pictures/user-12-abc123.jpg",
  "siteMemberships": [
    {
      "id": 44,
      "site": { "id": 3, "name": "Delta" },
      "siteRole": "INSTRUMENTIST"
    }
  ]
}
```

**Notes frontend :**
- `hourlyRate` et `consultationFee` sont des chaînes décimales ou `null`
- `profilePicturePath` est un chemin relatif au web root du backend — construire l'URL complète avec `VITE_API_BASE_URL + profilePicturePath`
- `siteMemberships` liste toutes les affiliations de l'instrumentiste
- L'instrumentiste doit porter le rôle `ROLE_INSTRUMENTIST`, sinon traité comme introuvable

**Erreurs possibles :**

```json
{
  "error": {
    "status": 404,
    "code": "NOT_FOUND",
    "message": "Instrumentist not found",
    "violations": []
  }
}
```

---

### 16.3 `POST /api/instrumentists`

**AuthZ :** `MANAGER` / `ADMIN`

Création rapide d'un instrumentiste depuis le manager.

**Body JSON :**

```json
{
  "email": "ole@example.com",
  "firstname": "Ole",
  "lastname": "Salve",
  "phone": "+32470000000",
  "siteIds": [1, 2]
}
```

**Champs acceptés :**

| Champ | Type | Contrainte |
|---|---|---|
| `email` | string | Obligatoire |
| `firstname` | string | Nullable |
| `lastname` | string | Nullable |
| `phone` | string | Nullable (non persisté à la création) |
| `siteIds` | integer[] | Obligatoire, min. 1 élément |

**Validations :**
- `email` : `NotBlank`, `Email`
- `siteIds` : `NotNull`, `Count(min: 1)`, chaque élément `NotNull` et `Positive`

**Effets backend :**
- Création d'un `User` (`roles = ['ROLE_INSTRUMENTIST']`, `active = true`, `password = null`)
- Génération d'un `invitationToken` et `invitationExpiresAt = now + 48h`
- Création d'une `SiteMembership` par site
- Envoi (ou mise en queue) de l'email d'invitation — la création est conservée même si l'envoi échoue

**Réponse succès — 201 :**

```json
{
  "instrumentist": {
    "id": 12,
    "email": "ole@example.com",
    "firstname": "Ole",
    "lastname": "Salve",
    "displayName": "Ole Salve",
    "active": true,
    "employmentType": null,
    "defaultCurrency": "EUR",
    "siteIds": [1, 2],
    "invitationExpiresAt": "2026-03-16T10:00:00+00:00"
  },
  "warnings": []
}
```

**Réponse avec warning email — 201 :**

```json
{
  "instrumentist": { "...": "..." },
  "warnings": [
    {
      "code": "INVITATION_EMAIL_NOT_SENT",
      "message": "Instrumentist created successfully but the invitation email could not be queued."
    }
  ]
}
```

**Notes frontend :**
- `employmentType` non alimenté à la création
- `phone` accepté dans le DTO, mais non écrit sur le `User`
- Pas de `profilePicture`, `companyName` ou `vatNumber` à la création manager

**Erreurs possibles :**

| Code | Description |
|---|---|
| `400` | JSON invalide / email vide / payload mal formé |
| `404` | `siteId` inconnu |
| `409` | Email déjà utilisé |
| `422` | Validation DTO |

---

### 16.4 `PATCH /api/instrumentists/{id}/rates`

**AuthZ :** `MANAGER` / `ADMIN`

Mise à jour partielle des tarifs globaux d'un instrumentiste.

**Body JSON :**

```json
{
  "hourlyRate": 350,
  "consultationFee": 120
}
```

> Les deux champs sont optionnels, mais **au moins un doit être présent**.

**Validations :**
- Type numérique, valeur `>= 0`
- Au moins un de `hourlyRate` ou `consultationFee` requis

**Réponse succès — 200 :**

```json
{
  "id": 12,
  "hourlyRate": "350",
  "consultationFee": "120"
}
```

**Notes frontend :**
- Les tarifs sont persistés comme chaînes décimales
- Si un seul champ est envoyé, l'autre reste inchangé
- Compatible autosave

**Erreurs possibles :**

| Code | Description |
|---|---|
| `400` | JSON invalide |
| `404` | Instrumentiste introuvable |
| `422` | Aucune valeur / type non numérique / valeur négative |

---

### 16.5 `POST /api/instrumentists/{id}/suspend`

**AuthZ :** `MANAGER` / `ADMIN`

Suspendre un instrumentiste. Endpoint **idempotent**.

- `active = true` → passe à `false`
- `active = false` → aucun changement

**Réponse succès — 200 :**

```json
{ "id": 12, "active": false }
```

**Erreurs :** `404` si instrumentiste introuvable.

---

### 16.6 `POST /api/instrumentists/{id}/activate`

**AuthZ :** `MANAGER` / `ADMIN`

Réactiver un instrumentiste. Endpoint **idempotent**.

- `active = false` → passe à `true`
- `active = true` → aucun changement

**Réponse succès — 200 :**

```json
{ "id": 12, "active": true }
```

**Erreurs :** `404` si instrumentiste introuvable.

---

### 16.7 `POST /api/instrumentists/{id}/site-memberships`

**AuthZ :** `MANAGER` / `ADMIN`

Ajouter une affiliation site à un instrumentiste.

**Body JSON :**

```json
{ "siteId": 3 }
```

**Validations :** `siteId` — `NotNull`, `Positive`

**Effets backend :**
- Chargement du site
- Refus si le couple (site, user) existe déjà
- Création d'une `SiteMembership` avec `siteRole = "INSTRUMENTIST"`

**Réponse succès — 201 :**

```json
{
  "id": 44,
  "site": { "id": 3, "name": "Delta" },
  "siteRole": "INSTRUMENTIST"
}
```

**Notes frontend :**
- Pas d'édition d'affiliation en V1
- `siteRole` fixé côté backend

**Erreurs possibles :**

| Code | Description |
|---|---|
| `400` | JSON invalide |
| `404` | Instrumentiste introuvable / site introuvable |
| `409` | Affiliation déjà existante |
| `422` | `siteId` absent ou invalide |

---

### 16.8 `DELETE /api/instrumentists/{id}/site-memberships/{membershipId}`

**AuthZ :** `MANAGER` / `ADMIN`

Supprimer une affiliation site d'un instrumentiste.

**Règles backend :**
- La membership doit exister
- Elle doit appartenir à l'instrumentiste ciblé
- Sinon : `404`
- Refusé (`409`) si c'est la **dernière** affiliation de l'instrumentiste — un instrumentiste doit
  toujours garder au moins un site (D-049)

**Réponse succès — 200 :**

```json
{ "id": 44, "deleted": true }
```

**Erreurs possibles :** `404` — messages : `Instrumentist not found` / `Site membership not found` ;
`409` — `Cannot remove the last site of an instrumentist — at least one site is required.`

---

### 16.9 `GET /api/instrumentists/{id}/planning?from=...&to=...`

**AuthZ :** `MANAGER` / `ADMIN`

Lecture du planning instrumentiste sur une fenêtre temporelle.

**Query params (obligatoires) :**

| Param | Format | Exemples valides |
|---|---|---|
| `from` | ISO 8601 strict avec fuseau | `2026-03-14T08:00:00Z`, `2026-03-14T08:00:00+01:00` |
| `to` | ISO 8601 strict avec fuseau | `2026-03-14T08:00:00.000000+01:00` |

**Règles backend :**
- `from` et `to` obligatoires, scalaires, format ISO 8601 strict
- `from < to`
- Filtre par intersection : `mission.startAt < to` ET `mission.endAt > from`

**Réponse succès — 200 :**

```json
[
  {
    "id": 19,
    "title": "Samy Ftaita",
    "start": "2026-03-14T08:00:00+01:00",
    "end": "2026-03-14T12:00:00+01:00",
    "allDay": false,
    "surgeon": {
      "id": 7,
      "firstname": "Samy",
      "lastname": "Ftaita",
      "displayName": "Samy Ftaita"
    },
    "site": { "id": 2, "name": "Delta" }
  }
]
```

**Notes frontend :**
- Endpoint lecture seule — aucune mutation métier
- `title` / `surgeon.displayName` : nom du chirurgien → email → `Mission #{id}`
- `allDay` vaut toujours `false`

**Erreurs possibles :**

| Code | Description |
|---|---|
| `404` | Instrumentiste introuvable |
| `422` | `from` manquant / `to` manquant / format invalide / `from >= to` |

Messages backend : `Query parameter "from" is required` / `Invalid from datetime format (ISO 8601 expected)` / `from must be strictly before to`

---

## 17. Invitations instrumentistes — Activation / Complétion du compte

**Flux V1 :**

1. Le manager crée le compte
2. L'instrumentiste reçoit un email
3. L'instrumentiste ouvre le lien frontend
4. L'instrumentiste complète son profil et définit son mot de passe
5. Le token est invalidé après activation

**Champs `User` visibles dans ce flux :** `invitationToken`, `invitationExpiresAt`, `phone`, `companyName`, `vatNumber`, `profilePicturePath`

**Lien d'invitation généré :**

```
{FRONTEND_URL}/complete-account?token=XXXX
```

---

### 17.1 `GET /api/invitations/{token}`

**AuthZ :** Public

Vérifie l'état d'un token d'invitation avant affichage du formulaire frontend.

**Règles backend :**
- Token doit être un hexadécimal de 64 caractères
- Format invalide → `invalid`
- Utilisateur inexistant → `invalid`
- Password déjà défini → `used`
- `invitationExpiresAt` absent ou expiré → `expired`

**Réponse — token valide — 200 :**

```json
{
  "status": "valid",
  "valid": true,
  "invitation": {
    "email": "ole@example.com",
    "firstname": "Ole",
    "lastname": "Salve",
    "displayName": "Ole Salve",
    "expiresAt": "2026-03-16T10:00:00+00:00"
  }
}
```

**Autres réponses — 200 :**

```json
{ "status": "invalid",  "valid": false }
{ "status": "used",     "valid": false }
{ "status": "expired",  "valid": false }
```

**Notes frontend :**
- Pas de `404` sur token invalide — piloter l'UX avec `status` / `valid`
- Le token en URL est masqué dans les logs d'erreur API

---

### 17.2 `POST /api/invitations/complete`

**AuthZ :** Public

Finalise l'activation du compte invité.

**Content-Type supportés :** `application/json` | `multipart/form-data`

**Body JSON :**

```json
{
  "token": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "firstname": "Ole",
  "lastname": "Salve",
  "phone": "+32470000000",
  "companyName": "Ole SRL",
  "vatNumber": "BE0123456789",
  "password": "StrongPass123",
  "confirmPassword": "StrongPass123"
}
```

**Champs multipart/form-data :** `token`, `firstname`, `lastname`, `phone`, `companyName` _(optionnel)_, `vatNumber` _(optionnel)_, `password`, `confirmPassword`, `profilePicture` _(optionnel)_

**Validations DTO :**

| Champ | Règle |
|---|---|
| `token` | `NotBlank` |
| `firstname` | `NotBlank` |
| `lastname` | `NotBlank` |
| `phone` | `NotBlank` |
| `password` | `NotBlank`, `Length(min: 8)` |
| `confirmPassword` | `NotBlank`, égal à `password` |

**Validation fichier `profilePicture` :**
- Type image, taille max 5 Mo
- MIME acceptés : `image/jpeg`, `image/png`, `image/webp`

**Effets backend :**
- Recherche par `invitationToken` — refus si introuvable / expiré / déjà utilisé
- Hash du mot de passe
- Mise à jour : `firstname`, `lastname`, `phone`, `companyName`, `vatNumber`, `password`
- Nullification : `invitationToken`, `invitationExpiresAt`
- Remplacement de la photo si `profilePicture` fourni

**Réponse succès — 200 :**

```json
{ "status": "account_completed" }
```

**Erreurs possibles :**

| Code | Description |
|---|---|
| `400` | JSON invalide / upload `profilePicture` invalide |
| `404` | Invitation introuvable |
| `409` | Invitation expirée / déjà utilisée |
| `422` | Validation formulaire / fichier image invalide / mots de passe différents |

**Notes frontend :**
- `companyName` et `vatNumber` acceptés uniquement dans ce flux
- `profilePicture` n'existe pas dans la création manager
- Sur `409` + message `Invitation already used` → rediriger vers login

---

## 18. Distinction future — Auto-inscription instrumentiste

> Hors périmètre V1 actuel.

Si l'instrumentiste se crée lui-même un compte, un flux séparé devra exiger une **validation email préalable**. Ce flux n'impacte pas la V1 manager décrite ci-dessus.

---

## 19. Catalogue matériel — Gestion manager

**Principe :** 1 MaterialItem = 1 Firm. Un matériel appartient toujours à une seule firme.

**Modèle MaterialItem :**

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant |
| `firm` | object | `{ id, name }` |
| `label` | string | Nom du matériel |
| `referenceCode` | string | Référence fabricant (peut être vide) |
| `unit` | string | Unité (ex: `pièce`, `boîte`) |
| `isImplant` | bool | `true` = implantable |

---

### 19.1 `GET /api/material-items`

**AuthZ :** Tous rôles authentifiés

**Query params :**

| Param | Type | Description |
|---|---|---|
| `search` | string | Recherche sur `label` et `referenceCode` |
| `firmId` | int | Filtre par firme |
| `implantOnly` | bool | Filtre implants uniquement |
| `active` | bool | Défaut : tous |
| `page` | int | Défaut : 1 |
| `limit` | int | Défaut : 50 |

**Réponse — 200 :**

```json
{
  "items": [
    {
      "id": 1,
      "firm": { "id": 1, "name": "Arthrex" },
      "label": "FiberTape",
      "referenceCode": "AR-7234",
      "unit": "pièce",
      "isImplant": true
    }
  ],
  "total": 42,
  "page": 1,
  "limit": 50
}
```

---

### 19.2 `GET /api/material-items/quick-search?q=fiber`

**AuthZ :** Tous rôles authentifiés

Autocomplete ultra-rapide (mobile-first, max 20 résultats).

**Réponse — 200 :**

```json
{
  "items": [
    { "id": 1, "firm": { "id": 1, "name": "Arthrex" }, "label": "FiberTape", "referenceCode": "AR-7234", "unit": "pièce", "isImplant": true }
  ]
}
```

---

### 19.3 `POST /api/material-items`

**AuthZ :** `MANAGER` / `ADMIN`

Créer un matériel dans le catalogue.

**Body JSON :**

```json
{
  "firmId": 1,
  "label": "FiberTape",
  "unit": "pièce",
  "referenceCode": "AR-7234",
  "isImplant": true
}
```

| Champ | Requis | Description |
|---|---|---|
| `firmId` | ✓ | ID de la firme |
| `label` | ✓ | Nom du matériel |
| `unit` | ✓ | Unité |
| `referenceCode` | — | Référence fabricant |
| `isImplant` | — | Défaut `false` |

**Réponse — 201 :** MaterialItem complet (même format que GET)

**Erreurs :**

| Code | Description |
|---|---|
| `404` | Firm introuvable |
| `422` | `firmId`, `label` ou `unit` manquant |

---

### 19.4 `PATCH /api/material-items/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

Mise à jour partielle — tous les champs sont optionnels.

**Body JSON :**

```json
{
  "label": "FiberTape v2",
  "referenceCode": "AR-7235",
  "isImplant": false
}
```

**Réponse — 200 :** MaterialItem mis à jour

**Erreurs :** `404` si introuvable, `422` si `label`/`unit` vide

---

## 20. Demandes matériel — Gestion manager

Les `MaterialItemRequest` sont créées par les instrumentistes lors de l'encodage quand un matériel est absent du catalogue.

**Modèle MaterialItemRequest :**

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant |
| `status` | string | `PENDING` / `RESOLVED` / `IGNORED` |
| `label` | string | Nom demandé |
| `referenceCode` | string\|null | Référence demandée |
| `comment` | string\|null | Commentaire libre |
| `createdAt` | ISO 8601 | Date de création |
| `mission` | object | `{ id, site }` |
| `requestedBy` | object | `{ id, displayName }` |
| `materialItem` | object\|null | Matériel associé après résolution |

**Transitions de statut :**

```
PENDING → RESOLVED  (via resolve)
PENDING → IGNORED   (via ignore)
```

---

### 20.1 `GET /api/material-item-requests`

**AuthZ :** `MANAGER` / `ADMIN`

**Query params :**

| Param | Valeurs | Description |
|---|---|---|
| `status` | `PENDING` \| `RESOLVED` \| `IGNORED` | Filtre (optionnel — tous si absent) |

**Réponse — 200 :**

```json
{
  "items": [
    {
      "id": 14,
      "status": "PENDING",
      "label": "FiberTape",
      "referenceCode": "AR-7234",
      "comment": "Utilisé lors de la ligamentoplastie",
      "createdAt": "2026-03-15T10:00:00+01:00",
      "mission": { "id": 42, "site": "Delta" },
      "requestedBy": { "id": 5, "displayName": "Ole Salve" },
      "materialItem": null
    }
  ],
  "total": 1
}
```

---

### 20.2 `POST /api/material-item-requests/{id}/resolve`

**AuthZ :** `MANAGER` / `ADMIN`

**Précondition :** `status = PENDING`

**Body JSON :**

```json
{ "materialItemId": 45 }
```

**Effets backend :**
1. Lie la demande au `MaterialItem` (`materialItem_id`)
2. Passe `status → RESOLVED`
3. Crée une `MaterialLine` sur la mission (quantity=1, même intervention)

**Réponse — 200 :**

```json
{
  "request": { "id": 14, "status": "RESOLVED", "materialItem": { "...": "..." }, "...": "..." },
  "materialLine": { "id": 88 }
}
```

**Erreurs :**

| Code | Description |
|---|---|
| `404` | Demande introuvable |
| `404` | MaterialItem introuvable |
| `409` | Demande non `PENDING` |
| `422` | `materialItemId` manquant |

---

### 20.3 `POST /api/material-item-requests/{id}/ignore`

**AuthZ :** `MANAGER` / `ADMIN`

**Précondition :** `status = PENDING`

Body vide.

**Réponse — 200 :** MaterialItemRequest avec `status: "IGNORED"`

**Erreurs :** `404` introuvable, `409` non PENDING

---

## 21. Profil utilisateur connecté

### `GET /api/me`

**AuthZ :** Tout utilisateur authentifié

Retourne le profil de l'utilisateur connecté, adapté à son rôle.

**Réponse — 200 (instrumentiste) :**

```json
{
  "id": 12,
  "email": "ole@example.com",
  "firstname": "Ole",
  "lastname": "Salve",
  "profilePictureUrl": "http://localhost/uploads/profile-pictures/user-12.jpg",
  "role": "INSTRUMENTIST",
  "sites": [
    { "id": 2, "name": "Delta", "timezone": "Europe/Brussels" }
  ],
  "activeSiteId": null,
  "instrumentistProfile": {
    "id": 12,
    "email": "ole@example.com",
    "firstname": "Ole",
    "lastname": "Salve",
    "displayName": "Ole Salve",
    "active": true,
    "employmentType": null,
    "defaultCurrency": "EUR",
    "hourlyRate": "350",
    "consultationFee": "120",
    "profilePicturePath": "/uploads/profile-pictures/user-12.jpg",
    "siteMemberships": [
      {
        "id": 44,
        "site": { "id": 2, "name": "Delta" },
        "siteRole": "INSTRUMENTIST"
      }
    ]
  }
}
```

**Notes :**
- `profilePictureUrl` (racine) et `profilePicturePath` (dans `instrumentistProfile`) sont tous les deux l'URL complète (absolue) construite par le backend à partir de `User.profilePicturePath` — malgré le nom `...Path`, `instrumentistProfile.profilePicturePath` n'est **pas** un chemin relatif.
- Pour les rôles `MANAGER` / `ADMIN` / `SURGEON`, `instrumentistProfile` est `null`

### `POST /api/me/profile-picture`

**AuthZ :** Tout utilisateur authentifié — aucune autorisation supplémentaire : on ne modifie jamais que sa propre ressource, pas de Voter dédié.

**Requête :** `multipart/form-data`, champ `profilePicture` (fichier).

**Validation (source de vérité serveur) :**
- Types acceptés : `image/jpeg`, `image/png`, `image/webp`
- Taille max : 5 Mo
- Fichier manquant → `400`
- Type/taille invalide → `422`

**Comportement :**
- Stocke le fichier dans `/public/uploads/profile-pictures/` via `ProfilePictureStorage` (même service que `POST /api/invitations/complete`).
- Si l'utilisateur avait déjà une photo, l'ancien fichier est supprimé du disque après le déplacement du nouveau (remplacement atomique côté service).
- Persiste `User.profilePicturePath`.

**Réponse — 200 :** le même objet `MeResponse` que `GET /api/me` (voir ci-dessus), avec `profilePictureUrl` à jour.

**Erreurs :** `400` fichier manquant/invalide, `422` type/taille refusé, `401` non authentifié.

**Usage produit :** utilisé à la fois par l'écran "Mon profil" (changement direct) et par le modal de rappel post-onboarding (`ProfilePhotoPromptModal`) affiché aux utilisateurs actifs sans photo — voir D-060 dans `docs/decisions.md`.

---

## 22. Demandes matériel — Côté instrumentiste

### `POST /api/missions/{missionId}/material-item-requests`

**AuthZ :** `MissionVoter::EDIT_ENCODING` (instrumentiste assigné)

Déclarer un matériel absent du catalogue lors de l'encodage. La demande est transmise au manager.

**Body JSON :**

```json
{
  "missionInterventionId": 12,
  "label": "Anchor suture 5.5mm Smith & Nephew",
  "referenceCode": "REF-12345",
  "comment": "Utilisé lors de la ligamentoplastie LCA"
}
```

| Champ | Requis | Description |
|---|---|---|
| `missionInterventionId` | — | Intervention concernée (recommandé) |
| `label` | ✓ | Nom du matériel demandé |
| `referenceCode` | — | Référence fabricant connue |
| `comment` | — | Informations complémentaires pour le manager |

**Effets backend :**
- Crée un `MaterialItemRequest` avec `status = PENDING`
- Set `createdBy = currentUser`
- La mission peut continuer sans bloquer l'encodage

**Réponse — 201 :**

```json
{ "id": 14 }
```

**Erreurs :**

| Code | Description |
|---|---|
| `403` | Non autorisé / encoding guard |
| `404` | Mission introuvable / intervention introuvable |
| `422` | `label` manquant |

**Notes frontend :**
- La demande n'est visible dans `GET /api/missions/{id}/encoding` que tant qu'elle est `PENDING`
- Une fois résolue par le manager, une `MaterialLine` est créée automatiquement et la demande disparaît de l'encoding

---

## 23. Chirurgiens — Gestion manager

Même principe que les instrumentistes : création par le manager, invitation par email, complétion du profil via `/complete-account?token=XXX`.

**Différences par rapport aux instrumentistes :**
- Pas de tarifs (`hourlyRate`, `consultationFee`)
- Pas de toggle actif/suspendu
- Planning : missions où le chirurgien est `mission.surgeon` (pas instrumentiste)

**Endpoints implémentés :**

```
GET    /api/surgeons
GET    /api/surgeons/{id}
POST   /api/surgeons
GET    /api/surgeons/{id}/planning?from=...&to=...
POST   /api/surgeons/{id}/site-memberships
DELETE /api/surgeons/{id}/site-memberships/{membershipId}
```

---

### 23.1 `GET /api/surgeons`

**AuthZ :** `MANAGER` / `ADMIN`

**Query params :**

| Param | Type | Description |
|---|---|---|
| `q` | string | Recherche sur email / prénom / nom |
| `active` | bool | Défaut : `true` |

**Réponse — 200 :**

```json
{
  "items": [
    {
      "id": 7,
      "email": "dr.martin@example.com",
      "firstname": "Jean",
      "lastname": "Martin",
      "displayName": "Jean Martin",
      "active": true,
      "profilePicturePath": null,
      "specialties": ["RACHIS"]
    }
  ],
  "total": 1
}
```

---

### 23.2 `GET /api/surgeons/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 200 :**

```json
{
  "id": 7,
  "email": "dr.martin@example.com",
  "firstname": "Jean",
  "lastname": "Martin",
  "displayName": "Jean Martin",
  "active": true,
  "profilePicturePath": null,
  "siteMemberships": [
    { "id": 12, "site": { "id": 2, "name": "Delta" }, "siteRole": "SURGEON" }
  ]
}
```

**Erreurs :** `404` si introuvable ou rôle ≠ `ROLE_SURGEON`

---

### 23.3 `POST /api/surgeons`

**AuthZ :** `MANAGER` / `ADMIN`

**Body JSON :**

```json
{
  "email": "dr.martin@example.com",
  "firstname": "Jean",
  "lastname": "Martin",
  "phone": "+32470000000",
  "siteIds": [1, 2]
}
```

**Effets backend :**
- Création `User` (`roles = ['ROLE_SURGEON']`, `active = true`, `password = null`)
- Token d'invitation généré (`invitationToken`, `invitationExpiresAt = now + 48h`)
- `SiteMembership` créée par site avec `siteRole = "SURGEON"`
- Email d'invitation envoyé (async via Messenger, même template que les instrumentistes)

**Réponse — 201 :**

```json
{
  "surgeon": {
    "id": 7,
    "email": "dr.martin@example.com",
    "firstname": "Jean",
    "lastname": "Martin",
    "displayName": "Jean Martin",
    "active": true,
    "siteIds": [1, 2],
    "invitationExpiresAt": "2026-03-17T10:00:00+00:00"
  },
  "warnings": []
}
```

**Erreurs :**

| Code | Description |
|---|---|
| `409` | Email déjà utilisé |
| `404` | Site introuvable |
| `422` | Validation DTO |

---

### 23.4 `GET /api/surgeons/{id}/planning?from=...&to=...`

**AuthZ :** `MANAGER` / `ADMIN`

Même format de params que `GET /api/instrumentists/{id}/planning`.

**Réponse — 200 :** Tableau d'événements FullCalendar.

Le `title` de chaque événement est le nom de l'instrumentiste assigné (ou l'email, ou `"Mission #id"`).

```json
[
  {
    "id": 19,
    "title": "Ole Salve",
    "start": "2026-03-14T08:00:00+01:00",
    "end": "2026-03-14T12:00:00+01:00",
    "allDay": false,
    "instrumentist": { "id": 5, "firstname": "Ole", "lastname": "Salve" },
    "site": { "id": 2, "name": "Delta" }
  }
]
```

---

### 23.5 `POST /api/surgeons/{id}/site-memberships`

**AuthZ :** `MANAGER` / `ADMIN`

**Body JSON :** `{ "siteId": 3 }`

**Réponse — 201 :**

```json
{ "id": 12, "site": { "id": 3, "name": "Delta" }, "siteRole": "SURGEON" }
```

**Erreurs :** `404` site/chirurgien introuvable, `409` affiliation déjà existante

---

### 23.6 `DELETE /api/surgeons/{id}/site-memberships/{membershipId}`

**AuthZ :** `MANAGER` / `ADMIN`

**Erreurs :** `404` membership inconnu/n'appartient pas au chirurgien ; `409` si c'est la
**dernière** affiliation du chirurgien — un chirurgien doit toujours garder au moins un site
(D-049).

**Réponse — 200 :** `{ "id": 12, "deleted": true }`

---

## 24. Facturation Firmes

**AuthZ :** `MANAGER` / `ADMIN` (via `BillingVoter::MANAGE`)

### 24.1 Configuration contact facturation

#### `PATCH /api/firms/{id}/billing-contact`

**Body JSON :**

```json
{
  "billingEmail": "facturation@arthrex.com",
  "billingEmailCc": ["comptabilite@arthrex.com"]
}
```

**Réponse — 200 :**

```json
{
  "id": 1,
  "billingEmail": "facturation@arthrex.com",
  "billingEmailCc": ["comptabilite@arthrex.com"]
}
```

---

### 24.2 Règles tarifaires — CRUD

> Modèle évolué par le Lot 1 (voir D-067, `docs/decisions.md`, et §30) :
> `interventionCode` (texte libre) → `interventionType` (référentiel fermé
> `InterventionType`) ; `IMPLANT_FEE` → `MATERIAL_FEE`. `isImplant` sur `MaterialItem`
> n'a plus aucun rôle dans la décision de facturabilité — seule l'existence d'une
> `PricingRule` active fait foi.

#### `GET /api/firms/{id}/pricing-rules`

Retourne toutes les règles (actives + inactives) pour la firme.

**Réponse — 200 :**

```json
[
  {
    "id": 1,
    "ruleType": "INTERVENTION_FEE",
    "interventionType": { "id": 3, "code": "LCA", "label": "Ligamentoplastie" },
    "materialItem": null,
    "unitPrice": "100.00",
    "currency": "EUR",
    "validFrom": null,
    "validTo": null,
    "active": true
  },
  {
    "id": 2,
    "ruleType": "MATERIAL_FEE",
    "interventionType": null,
    "materialItem": { "id": 45, "label": "Anchor 5.5mm", "referenceCode": "SN-001", "firm": { "id": 1, "name": "S&N" } },
    "unitPrice": "35.00",
    "currency": "EUR",
    "validFrom": null,
    "validTo": null,
    "active": true
  }
]
```

#### `POST /api/firms/{id}/pricing-rules`

**Body JSON (INTERVENTION_FEE) :**

```json
{
  "ruleType": "INTERVENTION_FEE",
  "interventionTypeId": 3,
  "unitPrice": 100,
  "currency": "EUR",
  "validFrom": null,
  "validTo": null
}
```

**Body JSON (MATERIAL_FEE) :**

```json
{
  "ruleType": "MATERIAL_FEE",
  "materialItemId": 45,
  "unitPrice": 35
}
```

**Contraintes :**
- `materialItem` doit appartenir à la même firme que la règle (`MATERIAL_FEE`)
- `currency` : défaut `EUR` si omis
- `validFrom`/`validTo` : nullables, `null` = borne ouverte (date au format `Y-m-d`)
- `409` si la règle créée ou modifiée chevauche, en dates, une autre règle active déjà
  posée sur la même cible (firme + type d'intervention, ou firme + matériel) — refus
  bloquant, jamais un choix silencieux à la lecture (voir `PricingRuleResolver::hasOverlap()`)

**Réponse — 201 :** PricingRule complète (même format que GET)

#### `PATCH /api/firms/{id}/pricing-rules/{ruleId}`

**Body JSON :**

```json
{
  "unitPrice": 110,
  "validTo": "2026-12-31",
  "active": false
}
```

**Réponse — 200 :** PricingRule mise à jour (même contrôle anti-chevauchement que POST)

#### `DELETE /api/firms/{id}/pricing-rules/{ruleId}`

**Réponse — 200 :** `{ "id": 1, "deleted": true }`

---

### 24.3 Preview facture

#### `POST /api/firm-invoices/preview`

**Body JSON :**

```json
{
  "firmId": 1,
  "periodStart": "2026-03-01T00:00:00+01:00",
  "periodEnd": "2026-03-31T23:59:59+01:00"
}
```

**Réponse — 200 :**

```json
{
  "firm": { "id": 1, "name": "Arthrex" },
  "period": { "start": "2026-03-01", "end": "2026-03-31" },
  "lines": [
    {
      "missionId": 42,
      "missionDate": "2026-03-14",
      "interventionId": 19,
      "materialLineId": null,
      "lineType": "INTERVENTION_FEE",
      "descriptionSnapshot": "[LCA] Ligament croisé antérieur",
      "firmNameSnapshot": "Arthrex",
      "unitPrice": 100.0,
      "quantity": 1.0,
      "totalAmount": 100.0
    }
  ],
  "totalAmount": 100.0
}
```

**Notes :**
- Exclut automatiquement les interventions/materialLines déjà dans une facture `GENERATED/SENT/PAID` pour cette firme
- Seules les missions `VALIDATED` sont incluses

---

### 24.4 Générer une facture

#### `POST /api/firm-invoices`

**Body JSON :**

```json
{
  "firmId": 1,
  "periodStart": "2026-03-01T00:00:00+01:00",
  "periodEnd": "2026-03-31T23:59:59+01:00",
  "selectedInterventionIds": [19],
  "selectedMaterialLineIds": []
}
```

**Effets backend :**
- Crée `FirmInvoice` avec `status = GENERATED`
- Génère le numéro `FIRM-YYYY-NNN`
- Crée les `FirmInvoiceLine` avec snapshot complet
- Snapshote `billingEmailTo` / `billingEmailCc` depuis la `Firm`

**Réponse — 201 :** FirmInvoice détaillée

**Erreurs :**

| Code | Description |
|---|---|
| `404` | Firm introuvable |
| `422` | Champs requis manquants / aucune ligne sélectionnée |

---

### 24.5 Détail, PDF, Envoi, Paiement

#### `GET /api/firm-invoices/{id}`

**Réponse — 200 :**

```json
{
  "id": 1,
  "number": "FIRM-2026-001",
  "firm": { "id": 1, "name": "Arthrex" },
  "status": "GENERATED",
  "periodStart": "2026-03-01",
  "periodEnd": "2026-03-31",
  "totalAmount": "100.00",
  "billingEmailTo": "facturation@arthrex.com",
  "billingEmailCc": [],
  "generatedAt": "2026-03-18T10:00:00+01:00",
  "sentAt": null,
  "paidAt": null,
  "lines": [ { "...": "..." } ]
}
```

#### `GET /api/firm-invoices/{id}/pdf`

**Réponse :** PDF binaire (`Content-Type: application/pdf`)

#### `POST /api/firm-invoices/{id}/send`

**Précondition :** `status = GENERATED`

**Body JSON :**

```json
{
  "emailTo": "facturation@arthrex.com",
  "emailCc": ["comptabilite@arthrex.com"]
}
```

**Effets :** `status → SENT`, snapshot email mis à jour, PDF envoyé en pièce jointe via Messenger.

**Réponse — 200 :** FirmInvoice mise à jour

#### `POST /api/firm-invoices/{id}/mark-paid`

**Effets :** `status → PAID`, `paidAt = now()`

**Réponse — 200 :** FirmInvoice mise à jour

---

## 25. Décomptes Instrumentistes

**AuthZ :** `MANAGER` / `ADMIN` (via `BillingVoter::MANAGE`)

### 25.1 Preview décompte

#### `POST /api/instrumentist-statements/preview`

**Body JSON :**

```json
{
  "instrumentistId": 12,
  "year": 2026,
  "month": 3
}
```

**Réponse — 200 :**

```json
{
  "instrumentist": {
    "id": 12,
    "displayName": "Ole Salve",
    "email": "ole@example.com",
    "hourlyRate": "350",
    "consultationFee": "120"
  },
  "period": { "year": 2026, "month": 3 },
  "lines": [
    {
      "missionId": 42,
      "missionDate": "2026-03-14",
      "lineType": "BLOC",
      "durationMinutesRaw": 240,
      "durationMinutesRounded": 240,
      "rateSnapshot": 350.0,
      "quantity": 4.0,
      "totalAmount": 1400.0,
      "surgeonName": "Jean Martin",
      "siteName": "Delta"
    }
  ],
  "totalAmount": 1400.0,
  "alreadyBilledMissionIds": []
}
```

**Calcul BLOC :**
- `durationMinutesRaw` = `endAt - startAt` en minutes
- `durationMinutesRounded` = `ceil(raw / 15) * 15`
- `quantity` = `durationMinutesRounded / 60`
- `totalAmount` = `quantity × hourlyRate`

**Calcul CONSULTATION :** `quantity = 1`, `totalAmount = 1 × consultationFee`

---

### 25.2 Générer un décompte

#### `POST /api/instrumentist-statements`

**Body JSON :**

```json
{
  "instrumentistId": 12,
  "year": 2026,
  "month": 3,
  "selectedMissionIds": [42, 43]
}
```

**Effets backend :**
- Vérifie l'absence d'un décompte `GENERATED+` pour (instrumentiste, mois, année) → `409` si doublon
- Crée `InstrumentistStatement` avec snapshot instrumentiste
- Crée les `InstrumentistStatementLine` avec snapshot complet (tarifs, noms)

**Réponse — 201 :** InstrumentistStatement détaillé

**Erreurs :**

| Code | Description |
|---|---|
| `404` | Instrumentiste introuvable |
| `409` | Décompte GENERATED+ déjà existant pour ce mois |
| `422` | Champs requis manquants |

---

### 25.3 Détail, PDF, Envoi, Paiement

#### `GET /api/instrumentist-statements/{id}`

**Réponse — 200 :**

```json
{
  "id": 1,
  "instrumentist": { "id": 12, "displayName": "Ole Salve", "email": "ole@example.com" },
  "periodYear": 2026,
  "periodMonth": 3,
  "status": "GENERATED",
  "totalAmount": "1400.00",
  "sentAt": null,
  "paidAt": null,
  "lines": [
    {
      "id": 1,
      "missionId": 42,
      "missionDate": "2026-03-14",
      "lineType": "BLOC",
      "durationMinutesRaw": 240,
      "durationMinutesRounded": 240,
      "rateSnapshot": "350.00",
      "quantity": "4.0000",
      "totalAmount": "1400.00",
      "surgeonName": "Jean Martin",
      "siteName": "Delta"
    }
  ]
}
```

#### `GET /api/instrumentist-statements/{id}/pdf`

**Réponse :** PDF binaire

#### `POST /api/instrumentist-statements/{id}/send`

**Précondition :** `status = GENERATED`

**Body JSON :**

```json
{ "emailTo": "ole@example.com" }
```

**Effets :** `status → SENT`, PDF envoyé en pièce jointe.

#### `POST /api/instrumentist-statements/{id}/mark-paid`

**Effets :** `status → PAID`, `paidAt = now()`

---

## 26. Module Planning — Gestion manager

Le module Planning permet au manager de définir des gabarits de semaine (templates), de gérer les absences des utilisateurs et de générer/déployer un planning de missions sur une plage de dates.

### Spécialités disponibles

Les codes de spécialité suivants sont reconnus dans tout le module :

| Code | Libellé |
|---|---|
| `GENOU` | Genou |
| `EPAULE` | Épaule |
| `HANCHE` | Hanche |
| `RACHIS` | Rachis |
| `MAIN` | Main / Poignet |
| `PIED` | Pied / Cheville |
| `NEUROCHIRURGIE` | Neurochirurgie |
| `CARDIOTHORACIQUE` | Cardiothoracique |
| `VISCERAL` | Viscéral |
| `UROLOGIE` | Urologie |
| `GYNECOLOGIE` | Gynécologie |
| `PEDIATRIQUE` | Pédiatrique |

---

### 26.1 Templates de planning

Un `PlanningTemplate` définit un gabarit de semaine récurrent. Il est associé à un site obligatoire et possède un type de semaine.

**Modèle PlanningTemplate :**

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant |
| `type` | string | `PAIR` / `IMPAIR` / `TOUTES` |
| `label` | string\|null | Nom personnalisé (optionnel) |
| `site` | object | `{ id, name }` — obligatoire |
| `slots` | PlanningSlot[] | Créneaux du gabarit |
| `createdAt` | ISO 8601 | Date de création |

**Modèle PlanningSlot :**

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant |
| `dayOfWeek` | int | 1 = Lundi … 7 = Dimanche |
| `period` | string | `AM` / `PM` |
| `startTime` | string | Format `HH:MM:SS` |
| `endTime` | string | Format `HH:MM:SS` |
| `missionType` | string | `BLOCK` / `CONSULTATION` |
| `surgeon` | object | `{ id, name }` |
| `instrumentist` | object\|null | `{ id, name }` |
| `site` | object\|null | `{ id, name }` |

> **Important :** `surgeon` et `instrumentist` dans les slots sont sérialisés sous la forme compacte `{ id, name }` (pas `{ id, firstname, lastname }`).

---

#### `GET /api/planning/templates`

**AuthZ :** `MANAGER` / `ADMIN`

Retourne tous les templates du site courant, triés par `createdAt DESC`.

**Réponse — 200 :**

```json
[
  {
    "id": 1,
    "type": "PAIR",
    "label": "Semaine standard genou",
    "site": { "id": 2, "name": "Delta" },
    "slots": [],
    "createdAt": "2026-03-18T10:00:00+01:00"
  }
]
```

---

#### `POST /api/planning/templates`

**AuthZ :** `MANAGER` / `ADMIN`

**Body JSON :**

```json
{
  "type": "PAIR",
  "siteId": 2,
  "label": "Semaine standard genou"
}
```

| Champ | Requis | Description |
|---|---|---|
| `type` | ✓ | `PAIR` / `IMPAIR` / `TOUTES` |
| `siteId` | ✓ | Site du template |
| `label` | — | Nom personnalisé |

**Réponse — 201 :** PlanningTemplate créé (sans slots)

---

#### `GET /api/planning/templates/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 200 :** PlanningTemplate avec tous ses slots

**Erreurs :** `404` si introuvable

---

#### `PATCH /api/planning/templates/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

Renommer (ou effacer le nom de) un template.

**Body JSON :**

```json
{ "label": "Nouveau nom" }
```

Passer `null` pour effacer le nom.

**Réponse — 200 :** PlanningTemplate mis à jour

---

#### `DELETE /api/planning/templates/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 204 :** Pas de corps

---

### 26.2 Slots

#### `POST /api/planning/templates/{templateId}/slots`

**AuthZ :** `MANAGER` / `ADMIN`

Ajouter un créneau au template.

**Body JSON :**

```json
{
  "dayOfWeek": 1,
  "period": "AM",
  "startTime": "08:00:00",
  "endTime": "13:00:00",
  "surgeonId": 7,
  "missionType": "BLOCK",
  "instrumentistId": 12,
  "siteId": 2
}
```

| Champ | Requis | Description |
|---|---|---|
| `dayOfWeek` | ✓ | 1–7 (lundi–dimanche) |
| `period` | ✓ | `AM` ou `PM` |
| `startTime` | ✓ | `HH:MM:SS` |
| `endTime` | ✓ | `HH:MM:SS` |
| `surgeonId` | ✓ | ID du chirurgien |
| `missionType` | ✓ | `BLOCK` ou `CONSULTATION` |
| `instrumentistId` | — | ID de l'instrumentiste |
| `siteId` | — | Surcharge de site pour ce slot |

**Réponse — 201 :** PlanningSlot créé

---

#### `PUT /api/planning/templates/{templateId}/slots/{slotId}`

**AuthZ :** `MANAGER` / `ADMIN`

Mise à jour complète d'un slot (tous les champs sont optionnels dans le body).

**Body JSON :**

```json
{
  "dayOfWeek": 2,
  "period": "PM",
  "startTime": "13:00:00",
  "endTime": "17:00:00",
  "surgeonId": 7,
  "missionType": "BLOCK",
  "instrumentistId": 12
}
```

**Réponse — 200 :** PlanningSlot mis à jour

Utilisé notamment pour les déplacements drag & drop (changement de `dayOfWeek` + `period`).

---

#### `DELETE /api/planning/templates/{templateId}/slots/{slotId}`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 204 :** Pas de corps

---

### 26.3 Absences

> ⚠️ **Effet de bord (D-062)** : `POST`/`PATCH`/`DELETE /api/absences[/{id}]` déclenchent
> désormais, en plus de la détection d'alertes existante (`PlanningAlertService`), une
> réaction automatique sur les **missions déjà déployées** qui chevauchent la période
> (`AbsenceMissionReactionService`) : une mission `ASSIGNED` dont l'instrumentiste devient
> absent est libérée (`OPEN`, instrumentiste retiré) ; une mission `OPEN`/`ASSIGNED` dont le
> chirurgien devient absent est annulée (`CANCELLED`). Voir D-062 pour le détail complet
> (statuts concernés, notifications, idempotence). La forme de la requête/réponse de ces
> endpoints n'a pas changé — seul cet effet de bord est nouveau.

**Modèle Absence :**

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant |
| `user` | object | `{ id, name, firstname, lastname, email, role }` — `role` est `INSTRUMENTIST`\|`SURGEON`\|`null` (D-051) |
| `dateStart` | string | `YYYY-MM-DD` |
| `dateEnd` | string | `YYYY-MM-DD` |
| `reason` | string\|null | Motif libre |
| `createdAt` | ISO 8601 | Date de création |

---

#### `GET /api/absences`

**AuthZ :** `MANAGER` / `ADMIN`

**Query params :**

| Param | Type | Description |
|---|---|---|
| `userId` | int | Filtre par utilisateur |
| `from` | string | Date de début (`YYYY-MM-DD`) |
| `to` | string | Date de fin (`YYYY-MM-DD`) |

**Réponse — 200 :** Tableau d'absences

---

#### `POST /api/absences`

**AuthZ :** `MANAGER` / `ADMIN`

**Body JSON :**

```json
{
  "userId": 12,
  "dateStart": "2026-03-24",
  "dateEnd": "2026-03-28",
  "reason": "Congé"
}
```

**Réponse — 201 :** Absence créée

---

#### `DELETE /api/absences/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 204 :** Pas de corps

---

### 26.3b Relances congés manager (D-051)

Cible : instrumentistes + chirurgiens actifs uniquement. **Les deux actions envoient
désormais un email individuel par personne sélectionnée, à sa propre adresse — jamais à
`boost.conge@gmail.com`** (cette adresse n'apparaît plus que comme texte dans le message de
`request-missing`, invitant son destinataire à y répondre).

Chaque email commence par une salutation personnalisée (rendue par le template, jamais par le
texte éditable) : « Bonjour Dr {nom} » pour un chirurgien, « Bonjour {prénom} » pour un
instrumentiste.

**Périodes — différentes selon l'action, calculées côté backend uniquement :**
- `request-missing` → sélection bornée à aujourd'hui → aujourd'hui + 3 mois (qui n'a *rien*
  d'encodé sur cette fenêtre).
- `confirm-encoded` → **tous les congés futurs, sans plafond de 3 mois** (`dateEnd >=
  aujourd'hui` uniquement).

Les deux endpoints `POST` acceptent un `userIds: number[]` optionnel dans le body pour
restreindre l'envoi à une sélection (absent = tout le monde dans le périmètre).

#### `GET /api/planning/absences/missing-preview`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 200 :**

```json
{ "count": 3, "people": [{ "id": 12, "name": "Jean Martin", "email": "...", "role": "SURGEON" }] }
```

#### `GET /api/planning/absences/encoded-preview`

**AuthZ :** `MANAGER` / `ADMIN`

**Réponse — 200 :**

```json
{
  "count": 2,
  "groups": [{
    "user": { "id": 12, "name": "Jean Martin", "email": "...", "role": "SURGEON" },
    "absences": [{ "dateStart": "2026-09-10", "dateEnd": "2026-09-15", "reason": null }]
  }]
}
```

#### `POST /api/planning/absences/request-missing`

**AuthZ :** `MANAGER` / `ADMIN`

**Body JSON (optionnel) :** `{ "message": "Texte personnalisé", "userIds": [12, 18] }` — `message` absent → message par défaut backend (explique qu'aucun congé n'est encodé et invite à répondre à `boost.conge@gmail.com`). `userIds` absent (champ non présent dans un JSON valide) → toutes les personnes sans absence sur la période. **Si le body n'est pas un JSON valide → 400**, jamais de fallback silencieux vers "tout le monde".

**Effet :** dispatch d'**un `SendTemplatedEmailMessage` par personne sélectionnée**, à sa propre
adresse (jamais vers `boost.conge@gmail.com`, qui n'apparaît que comme texte dans le message).
Enregistre un `UserAuditEvent` (`ABSENCES_REQUEST_SENT`, `targetUser` null,
`payload.count` = nombre d'emails individuels envoyés).

**Réponse — 200 :**

```json
{ "sent": true, "count": 3 }
```

#### `POST /api/planning/absences/confirm-encoded`

**AuthZ :** `MANAGER` / `ADMIN`

**Body JSON (optionnel) :** `{ "message": "Texte personnalisé", "userIds": [12, 18] }` — `userIds` absent (champ non présent dans un JSON valide) → toutes les personnes avec au moins une absence future. **Si le body n'est pas un JSON valide → 400**, jamais de fallback silencieux vers "tout le monde".

**Effet :** dispatch d'**un `SendTemplatedEmailMessage` par personne sélectionnée**, à sa propre
adresse, contenant **tous ses congés futurs** (`dateEnd >= aujourd'hui`, sans plafond de 3
mois) — jamais vers `boost.conge@gmail.com`. Enregistre un `UserAuditEvent`
(`ABSENCES_CONFIRMATION_SENT`, `payload.count` = nombre d'emails individuels envoyés).

**Réponse — 200 :**

```json
{ "sent": true, "count": 2 }
```

Aucune des deux réponses n'a de champ `recipient` — il y a toujours N destinataires
individuels, jamais une adresse unique.

---

### 26.4 Preview planning

#### `POST /api/planning/preview`

**AuthZ :** `MANAGER` / `ADMIN`

Simule la génération d'un planning sur une plage de dates sans créer de missions.

**Body JSON :**

```json
{
  "from": "2026-03-23",
  "to": "2026-03-27",
  "siteId": 2,
  "surgeonId": null
}
```

| Champ | Requis | Description |
|---|---|---|
| `from` | ✓ | Date de début (`YYYY-MM-DD`) |
| `to` | ✓ | Date de fin (`YYYY-MM-DD`) |
| `siteId` | — | Filtre par site |
| `surgeonId` | — | Filtre par chirurgien |

**Réponse — 200 :** Tableau de `PreviewLine`

```json
[
  {
    "date": "2026-03-23",
    "slotId": 1,
    "surgeonId": 7,
    "surgeonName": "Jean Martin",
    "missionType": "BLOCK",
    "startTime": "08:00:00",
    "endTime": "13:00:00",
    "siteId": 2,
    "siteName": "Delta",
    "instrumentistId": 12,
    "instrumentistName": "Ole Salve",
    "status": "COVERED",
    "existingMissionId": null,
    "existingInstrumentistId": null,
    "existingInstrumentistName": null,
    "freedFrom": false
  }
]
```

**Champs `PreviewLine` :**

| Champ | Type | Description |
|---|---|---|
| `instrumentistId` | `int\|null` | Instrumentiste suggéré (gabarit ou libéré auto) |
| `existingMissionId` | `int\|null` | ID de la mission existante en base (si COVERED/MODIFIED) |
| `existingInstrumentistId` | `int\|null` | Instrumentiste actuellement dans la mission existante (MODIFIED uniquement) |
| `existingInstrumentistName` | `string\|null` | Nom de l'instrumentiste actuel de la mission existante (MODIFIED uniquement) |
| `freedFrom` | `bool` | `true` si l'instrumentiste a été auto-assigné depuis un slot SKIPPED (chirurgien absent) |

**Valeurs `status` :**

| Valeur | Description |
|---|---|
| `COVERED` | Créneau couvert — instrumentiste assigné (gabarit ou libéré auto via `freedFrom`) |
| `UNCOVERED` | Aucun instrumentiste disponible après application des absences et des libérés |
| `MODIFIED` | Mission existante avec un instrumentiste différent du gabarit — `existingInstrumentistName` indique qui est actuellement là |
| `CONFLICT` | Instrumentiste en double-booking détecté (DB ou intra-preview) |
| `SKIPPED` | Chirurgien absent — slot ignoré, instrumentiste marqué comme libéré |

**Règle `freedFrom` :** un instrumentiste est dit "libéré" quand **tous** ses slots du jour sont `SKIPPED`. Il peut alors être auto-assigné à plusieurs créneaux non-chevauchants sur le même jour (ex : AM chez Jérôme + PM chez Samy si son chirurgien est absent toute la journée).

---

### 26.5 Générer le planning

#### `POST /api/planning/generate`

**AuthZ :** `MANAGER` / `ADMIN`

Génère effectivement les missions à partir des templates et de la plage de dates.

**Body JSON :** Même format que `/api/planning/preview`

**Effets backend :**
- Pour chaque slot actif sur la plage : crée ou met à jour une `Mission` (`DRAFT`)
- Applique les absences pour exclure les instrumentistes indisponibles
- Sélectionne l'instrumentiste selon l'algorithme de scoring (historique + spécialité)

**Réponse — 200 :**

```json
{
  "versionId": 2,
  "created": 8,
  "updated": 2,
  "skipped": 1
}
```

**Sémantique de `skipped` :**

| Cas | Comptabilisé dans `skipped` |
|---|---|
| Chirurgien absent (slot SKIPPED) | ✓ |
| Slot avec mission existante déjà couverte (préservée) | ✓ |

> `skipped` ne signifie **pas** que les missions ont été supprimées ou ignorées — les missions existantes sont **préservées**. Le frontend affiche "X mission(s) existantes préservées" si `created === 0 && updated === 0`.

---

### 26.5b Résumé de version

#### `GET /api/planning/versions/{id}`

**AuthZ :** `MANAGER` / `ADMIN`

Retourne l'état courant de toutes les missions de la période+site de la version.

> Important : ce endpoint requête **toutes les missions de la période** (pas seulement celles liées à cette version par FK). Cela évite d'afficher 0 lorsque la génération n'a rien créé car tout était déjà couvert.

**Réponse — 200 :**

```json
{
  "id": 2,
  "versionNumber": 2,
  "status": "DRAFT",
  "periodStart": "2026-04-28",
  "periodEnd": "2026-06-28",
  "generatedAt": "2026-04-29T10:00:00+02:00",
  "deployedAt": null,
  "archivedAt": null,
  "site": { "id": 1, "name": "CHIREC - Hôpital Delta" },
  "generatedBy": { "id": 5, "email": "manager@example.com" },
  "summary": {
    "total": 205,
    "draft": 195,
    "open": 0,
    "assigned": 10,
    "withoutInstrumentist": 12,
    "surgeonCount": 8,
    "instrumentistCount": 6
  }
}
```

**Champs `summary` :**

| Champ | Description |
|---|---|
| `total` | Toutes les missions non-rejetées de la période |
| `draft` | Statut DRAFT — créées, pas encore publiées |
| `open` | Statut OPEN — publiées, disponibles au pool |
| `assigned` | ASSIGNED, SUBMITTED, VALIDATED, CLOSED — avec instrumentiste confirmé |
| `withoutInstrumentist` | DRAFT ou OPEN sans instrumentiste attribué |
| `surgeonCount` | Nombre de chirurgiens distincts |
| `instrumentistCount` | Nombre d'instrumentistes distincts assignés |

---

### 26.6 Déployer le planning

#### `POST /api/planning/deploy`

**AuthZ :** `MANAGER` / `ADMIN`

Publie les missions `DRAFT` de la version selon les règles ci-dessous, puis dispatche la génération des PDFs, les emails et les notifications **de façon asynchrone** via Symfony Messenger.

**Règles de publication des statuts :**

| Mission DRAFT | Condition | Nouveau statut |
|---|---|---|
| Avec instrumentiste assigné | Toujours | `ASSIGNED` |
| Sans instrumentiste + ID dans `selectedUncoveredMissionIds` | Manager a coché | `OPEN` (pool) |
| Sans instrumentiste + ID absent de `selectedUncoveredMissionIds` | Manager a décoché | reste `DRAFT` |

**Body JSON :**

```json
{
  "from": "2026-03-23",
  "to": "2026-03-27",
  "siteId": 2,
  "versionId": 5,
  "selectedUncoveredMissionIds": [101, 102, 103]
}
```

| Champ | Type | Requis | Description |
|---|---|---|---|
| `from` | string (date) | ✓ | Début de période |
| `to` | string (date) | ✓ | Fin de période |
| `siteId` | int \| null | — | Filtre par site (null = tous) |
| `versionId` | int \| null | — | ID de la `PlanningVersion` DRAFT à déployer |
| `selectedUncoveredMissionIds` | int[] | — | IDs des missions sans instrumentiste à publier en pool (défaut `[]`) |

> **Historique** : `sendChangeSummary` a été retiré de cet endpoint (email policy redesign, voir D-058). Le déploiement initial n'envoie plus jamais l'email de récapitulatif de changements — voir "Emails envoyés" ci-dessous.

**Réponse — 200 (immédiate, avant la fin des PDFs) :**

```json
{
  "deploymentId": 7,
  "missionCount": 45,
  "openPoolCount": 3
}
```

| Champ | Description |
|---|---|
| `deploymentId` | ID du `PlanningDeployment` créé (status `PENDING`, puis `DONE`/`FAILED` par le worker) |
| `missionCount` | Total de missions publiées (ASSIGNED + OPEN) |
| `openPoolCount` | Missions publiées en pool (sans instrumentiste, cochées par le manager) |

> **Async** : PDFs, emails, notifications s'exécutent dans le worker Messenger (`PlanningDeployPdfsMessageHandler`). La réponse HTTP retourne immédiatement après le flush DB.
>
> **Idempotence** : si le worker reçoit le même message deux fois (retry Messenger), il vérifie `PlanningDeployment.status == DONE` avant d'agir — aucun double envoi dans ce cas.
>
> **Worker requis** : `php bin/console messenger:consume async`

**Emails envoyés (email policy redesign, D-058) — exactement UN email de déploiement par destinataire :**

| Destinataire | Email | Contenu | Pièce jointe |
|---|---|---|---|
| Instrumentiste | `Planning du {from} au {to}` | Salutation, période, nombre de missions assignées | PDF personnel uniquement |
| Chirurgien | `Planning du {from} au {to}` | Salutation, période, total/couvertes/non couvertes ; si non couvertes > 0, explication non technique | PDF personnel uniquement (**pas** le PDF global) |
| Manager (déployeur) | `Déploiement confirmé — planning {from} au {to}` | Confirmation, missions/assignées/ouvertes | PDF global (site complet) |

Aucun email "récapitulatif de changements" (`planning_change_summary_*`) n'est envoyé lors du déploiement initial — voir §26.6c.

**Notifications in-app créées par le worker (Batch 15C) :**

Toutes les notifications in-app sont créées via `NotificationEvent` (channel `IN_APP`) et gérées par `NotificationPreferenceResolver`. Les emails et les notifications in-app sont désactivables par préférence utilisateur.

| `eventType` | Destinataire | Payload |
|---|---|---|
| `PLANNING_DEPLOYED_INSTRUMENTIST` | Chaque instrumentiste avec au moins 1 mission | `{ periodLabel, missionCount, deployedAt }` |
| `PLANNING_DEPLOYED_SURGEON` | Chaque chirurgien avec au moins 1 poste | `{ periodLabel, posts[] }` |
| `PLANNING_DEPLOYED_MANAGER` | Le manager qui a déclenché le déploiement | `{ missionCount, assignedCount, openPoolCount, periodLabel }` |
| `OPEN_MISSION_AVAILABLE` | Chaque instrumentiste du site (missions en pool uniquement) | `{ openMissionIds, missionCount, siteName, periodLabel }` |

**Format d'un élément `posts[]` (payload `PLANNING_DEPLOYED_SURGEON`) :**

```json
{
  "missionId": 101,
  "date": "2026-03-24",
  "dayLabel": "Monday",
  "siteName": "Clinique Alpha",
  "periodLabel": "Matin",
  "covered": false,
  "instrumentistName": null,
  "uncoveredReasonLabel": "Tous les instrumentistes sont absents"
}
```

| Champ | Description |
|---|---|
| `missionId` | ID de la mission |
| `date` | Date au format `Y-m-d` |
| `dayLabel` | Jour en anglais (p. ex. `Monday`) |
| `siteName` | Nom du site opératoire |
| `periodLabel` | `Matin` ou `Après-midi` |
| `covered` | `true` si la mission est `ASSIGNED`, `false` si `OPEN` |
| `instrumentistName` | Nom de l'instrumentiste (null si non couvert) |
| `uncoveredReasonLabel` | Raison heuristique (null si couvert). Valeurs : `Aucun instrumentiste affilié au site`, `Tous les instrumentistes sont absents`, `Tous les instrumentistes ont un conflit`, `Recherche en cours` |

> Ce payload `posts[]` reste **in-app uniquement** depuis le redesign D-058 — l'email chirurgien (`planning_surgeon.html.twig`) n'affiche plus le détail par poste, seulement des compteurs agrégés (total/couvertes/non couvertes). Voir D-058, qui remplace D-053 sur ce point.

---

### 26.6b Diff de version (pré-déploiement)

#### `GET /api/planning/versions/{id}/diff`

**AuthZ :** `MANAGER` / `ADMIN`

Calcule le diff "planning visible" entre la version `{id}` et la version ACTIVE/ARCHIVED la plus récente pour la même période+site. À appeler **avant déploiement** pour prévisualiser ce qui va changer.

**Réponse — 200 :**

```json
{
  "added": [
    {
      "date": "2026-03-24",
      "period": "AM",
      "startAt": "08:00",
      "endAt": "13:00",
      "missionType": "BLOCK",
      "surgeonId": 10,
      "surgeonName": "Jean Dupont",
      "instrumentistId": 20,
      "instrumentistName": "Marie Martin",
      "siteName": "Alpha"
    }
  ],
  "removed": [ ... ],
  "modified": [
    {
      "mission": { ... },
      "changes": {
        "instrumentist": {
          "from": { "id": 20, "name": "Marie Martin" },
          "to":   { "id": 21, "name": "Sophie Bernard" }
        },
        "schedule": {
          "from": { "startAt": "08:00", "endAt": "13:00" },
          "to":   { "startAt": "08:00", "endAt": "12:30" }
        }
      }
    }
  ]
}
```

**Champs comparés (planning visible uniquement) :**
`startAt`, `endAt`, `surgeon`, `site`, `instrumentist`

**Exclus du diff :** statut, notes, champs financiers, metadata.

**Clé de matching** : `siteId_surgeonId_missionType_date_startAt(arrondi 15 min)`.
L'arrondi de 15 min absorbe les micro-décalages (08:00 ↔ 08:07 → même slot) sans masquer les vrais changements (08:00 ↔ 08:30 → clés distinctes → add + remove).

**Si diff vide :** `{ "added": [], "removed": [], "modified": [] }` — premier déploiement ou planning identique.

---

#### `GET /api/planning/versions/{id}/coverage-summary`

**AuthZ :** `MANAGER` / `ADMIN`

Retourne les KPI de couverture de la version (lecture seule, aucune persistance).

**Réponse — 200 :**

```json
{
  "versionId": 12,
  "total": 40,
  "covered": 30,
  "open": 10,
  "cancelled": 2,
  "coveragePercent": 75.0
}
```

**Sémantique :**
- `total` = missions `OPEN + ASSIGNED + SUBMITTED + VALIDATED + CLOSED + IN_PROGRESS`
- `covered` = missions `ASSIGNED + SUBMITTED + VALIDATED + CLOSED + IN_PROGRESS`
- `open` = missions `OPEN` (pool, en attente de prise en charge)
- `cancelled` = missions `CANCELLED` (informatif, exclus de `total`)
- `coveragePercent` = `covered / total × 100` (arrondi 1 décimale) — `null` si `total = 0`

**Réponse — 404 :** version inexistante.

**Notes :**
- Exécute 1 requête `GROUP BY` — aucun `flush`, aucune persistance
- Ajouté en Batch 15F

---

#### `GET /api/planning/versions/{id}/history`

**AuthZ :** `MANAGER` / `ADMIN`

Retourne la timeline chronologique (ASC) de tous les événements d'une version déployée.

**Réponse — 200 :**

```json
[
  {
    "type": "DEPLOYED",
    "occurredAt": "2026-06-01T08:00:00+00:00",
    "deployedById": 5,
    "deployedByName": "Alice Martin",
    "missionCount": 40,
    "openPoolCount": 10
  },
  {
    "type": "MISSION_CLAIMED_FROM_POOL",
    "occurredAt": "2026-06-05T10:30:00+00:00",
    "missionId": 55,
    "actorId": 7,
    "actorName": "Bob Dupont",
    "payload": {}
  }
]
```

**Structure :**
1. Entrée `DEPLOYED` (si `deployedAt` non nul) — dérivée de `PlanningVersion.deployedAt + generatedBy + summaryJson`
2. Entrées `AuditEvent` liées aux missions de la version — triées `createdAt ASC`

**Réponse — 404 :** version inexistante.

**Notes :**
- `PlanningDeployment` n'a pas de FK vers `PlanningVersion` — le déploiement est reconstitué depuis la version elle-même
- 2 requêtes DB au total (find + DQL JOIN)
- Lecture seule — aucun `flush`, aucune persistance
- Ajouté en Batch 15F

---

### 26.6c Mode Modification — édition d'un planning déjà déployé (Batch 15K)

#### `POST /api/planning/versions/{id}/apply-modifications`

**AuthZ :** `MANAGER` / `ADMIN`

Applique en un seul lot des changements édités (réassignation, libération, annulation,
changement d'horaire/site/type, nouvelle mission) à une `PlanningVersion` **déjà
déployée** — jamais un nouveau cycle `generate`/`deploy`. Mute directement les `Mission`
existantes via `MissionPostDeployService` (D-052/D-056), chaque appel avec `notify:
false` — les notifications unitaires `PLANNING_MISSION_*` (§26.4, L4) ne sont **pas**
déclenchées ligne par ligne ; voir "Emails envoyés" ci-dessous.

**Body JSON :**

```json
{
  "lines": [
    {
      "date": "2026-09-15",
      "postId": 42,
      "surgeonId": 10,
      "surgeonName": "Jean Dupont",
      "missionType": "BLOCK",
      "startTime": "08:00",
      "endTime": "13:00",
      "siteId": 3,
      "siteName": "CHIREC",
      "instrumentistId": 20,
      "instrumentistName": "Marie Martin",
      "status": "COVERED",
      "existingMissionId": 501,
      "existingInstrumentistId": 19,
      "existingInstrumentistName": "Ancien Instrumentiste",
      "freedFrom": false
    }
  ]
}
```

| Champ | Type | Description |
|---|---|---|
| `lines[]` | array | Même forme que `PreviewLineV2` (l'éditeur unifié Génération/Modification, voir `docs/architecture.md` "Éditeur unifié Génération / Modification") |
| `existingMissionId` | int \| null | `null` = nouvelle mission à créer (`MissionPostDeployService::createPostDeploy()`). Sinon, ID de la `Mission` existante à muter. |
| `status` | string | `"SKIPPED"` = annuler la mission (`release()` si `ASSIGNED`, `cancel()` si `OPEN`). Toute autre valeur = pas d'annulation. |
| `instrumentistId` | int \| null | Comparé à l'instrumentiste courant de la mission — `null`→valeur = `assign()`, valeur→`null` = `release()`, valeur→autre valeur = `reassign()`. |
| `date`/`startTime`/`endTime`/`siteId`/`missionType` | — | Comparés à l'état courant ; tout écart sur une mission `OPEN`/`ASSIGNED` déclenche `updateSchedule()`. |

Une ligne dont l'`existingMissionId` référence une mission déjà mutée ailleurs (ou étrangère à cette version) est **silencieusement ignorée** (pas d'échec du lot entier).

**Réponse — 200 :**

```json
{ "created": 1, "updated": 2, "cancelled": 1, "released": 0, "unchanged": 3 }
```

**Réponse — 404 :** `PlanningVersion` inexistante.

**Emails envoyés — un seul récapitulatif ciblé par personne réellement affectée :**

À la fin du lot, `PlanningModificationService` calcule un diff avant/après sur
l'ensemble des missions de la version (`PlanningDiffService::computeDiffFromSnapshots()`
— pas `diff()`, qui compare deux versions différentes) et appelle **une seule fois**
`PlanningChangeSummaryService::sendChangeSummaryEmails()` avec ce diff précalculé.

| Destinataire | Condition d'envoi | Template |
|---|---|---|
| Instrumentiste | Au moins une mission le concernant apparaît dans le diff (ajoutée, modifiée, ou dont il a perdu/gagné l'affectation) | `emails/planning_change_summary_instrumentist.html.twig` |
| Chirurgien | Au moins une de ses propres interventions apparaît dans le diff | `emails/planning_change_summary_surgeon.html.twig` |

Chaque email contient une liste unifiée "Modifications (N)" (une carte par changement,
quel que soit le type — ajout/modification/annulation) et le PDF planning personnel à
jour en pièce jointe (même mécanisme que le déploiement initial, §26.6). **Jamais de
renvoi global** aux chirurgiens/instrumentistes non concernés — un utilisateur avec une
mission non modifiée ailleurs dans la même version ne reçoit rien.

Si le lot ne produit aucun changement effectif (toutes les lignes `unchanged`), le diff
est vide et **aucun email n'est envoyé**.

**Notes :**
- Aucune notification in-app `PLANNING_MISSION_*` (celles-ci restent réservées aux
  actions unitaires post-déploiement hors mode Modification — release/cancel/reassign/
  assign appelés individuellement, §26.4).
- Envoi synchrone (`MessageBusInterface::dispatch`, transport `async`) — une erreur de
  rendu PDF ou de dispatch pour un destinataire est loguée (`logger->error`, jamais
  silencieuse) et n'empêche ni la mutation déjà persistée, ni l'envoi aux autres
  destinataires.
- Aucune nouvelle migration — cette capacité mute uniquement des `Mission` déjà
  existantes en base.
- Voir D-058 (`docs/decisions.md`) pour la politique email de déploiement initial, dont
  ce mode est explicitement le "futur déclencheur" annoncé ; `docs/planning-v2-architecture-freeze.md`
  §L9 pour le détail architecture de l'éditeur unifié.

---

### 26.6d Suppression d'un mois généré (Batch 16B)

#### `POST /api/planning/versions/{id}/cancel-all`

**AuthZ :** `MANAGER` / `ADMIN`

"Supprimer ce mois" côté UI — annule en un seul lot toutes les missions annulables
(`ASSIGNED`/`OPEN`) d'une `PlanningVersion` **déjà déployée** (`ACTIVE`). **Jamais une
suppression physique** : l'historique (`AuditEvent`, D-055) et la `PlanningVersion`
elle-même sont conservés — chaque mission passe par la même chaîne post-déploiement
`release()`→`cancel()` qu'une ligne individuelle marquée `SKIPPED` dans
`apply-modifications` (§26.6c). Une version "vidée" (toutes ses missions `CANCELLED`)
reste `ACTIVE` et consultable — ce n'est pas un statut spécial.

Les missions déjà au-delà de `ASSIGNED`/`OPEN` (`SUBMITTED`, `VALIDATED`, `CLOSED`,
`IN_PROGRESS`) ne sont **pas** annulables par cet endpoint — elles ne sont même pas
tentées (évite un comptage trompeur d'une "annulation" qui ne mute rien).

**Body :** aucun (POST sans payload).

**Réponse — 200 :** même forme que `apply-modifications` :
```json
{ "created": 0, "updated": 0, "cancelled": 12, "released": 0, "unchanged": 0 }
```

**Réponse — 400 :** version non `ACTIVE`. Une `DRAFT` a son propre endpoint de
suppression physique (`DELETE /api/planning/versions/{id}`, §26.6 ci-dessus) ; une
`ARCHIVED` est déjà remplacée par une version plus récente, rien à annuler dessus dans
ce flux.

**Réponse — 404 :** version inexistante.

**Emails envoyés :** identique à `apply-modifications` — un seul récapitulatif ciblé par
personne réellement affectée, calculé via le même diff avant/après. Une mission `OPEN`
sans instrumentiste qui est annulée ne produit **aucune entrée de diff** (son
instrumentiste reste `null` avant/après — `PlanningDiffService::detectChanges()` ignore
le statut) : son chirurgien ne reçoit donc rien pour cette mission-là spécifiquement.

---

### 26.7 Instrumentistes suggérés

#### `GET /api/missions/{missionId}/suggested-instrumentists`

**AuthZ :** `MANAGER` / `ADMIN`

Retourne une liste ordonnée d'instrumentistes adaptés à la mission, basée sur l'algorithme de scoring.

**Réponse — 200 :**

```json
[
  {
    "id": 12,
    "name": "Ole Salve",
    "email": "ole@example.com",
    "score": 85,
    "hasHistory": true,
    "specialtyMatch": true
  }
]
```

**Algorithme de scoring :**
- `hasHistory` : l'instrumentiste a déjà travaillé avec ce chirurgien
- `specialtyMatch` : spécialité de l'instrumentiste correspond au type de mission
- `score` : combinaison pondérée des facteurs ci-dessus

---

### 26.8 Spécialités utilisateur

#### `PATCH /api/users/{id}/specialties`

**AuthZ :** `MANAGER` / `ADMIN`

Met à jour les spécialités d'un utilisateur (instrumentiste ou chirurgien).

**Body JSON :**

```json
{ "specialties": ["GENOU", "EPAULE", "RACHIS"] }
```

**Réponse — 204 :** Pas de corps

**Erreurs :** `404` si utilisateur introuvable, `422` si valeurs invalides

---

### 26.8b Email utilisateur (D-063)

#### `PATCH /api/users/{id}/email`

**AuthZ :** `MANAGER` / `ADMIN` (`UserAdministrationVoter::UPDATE_EMAIL` — distinct de
`UserAdministrationVoter::UPDATE`, réservé `ADMIN` seul sur `/api/admin/users/{id}`)

Modifie l'adresse email de connexion d'un instrumentiste ou d'un chirurgien (utilisé
depuis les drawers `/app/m/instrumentists` et `/app/m/surgeons`, endpoint générique
partagé, jamais dupliqué par rôle). Envoie systématiquement deux emails transactionnels
(ancienne et nouvelle adresse), y compris si le compte est suspendu — voir D-063 pour le
détail complet (session JWT, refresh token, risque Google OAuth documentés).

**Body JSON :**

```json
{ "email": "nouvelle.adresse@example.com" }
```

**Réponse — 200 :**

```json
{
  "user": {
    "id": 123,
    "email": "nouvelle.adresse@example.com",
    "firstname": "Prénom",
    "lastname": "Nom",
    "displayName": "Prénom Nom",
    "profilePicturePath": "/uploads/profile-pictures/user-123.jpg"
  },
  "warnings": []
}
```

**Réponse avec échec de mise en file d'un email — 200 :**

```json
{
  "user": { "id": 123, "email": "nouvelle.adresse@example.com" },
  "warnings": [
    {
      "code": "EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED",
      "recipient": "old",
      "message": "The email address was changed, but the notification to the previous address could not be queued."
    }
  ]
}
```

**Erreurs :**

| Code | Cas |
|---|---|
| `400` | `email` manquant/non-string, vide après trim, ou identique à l'adresse actuelle |
| `403` | rôle non autorisé |
| `404` | utilisateur introuvable |
| `409` | adresse déjà utilisée par un autre compte (comparaison insensible à la casse) |
| `422` | format d'adresse invalide (`Assert\Email`) |

Format d'erreur normalisé (§13) — `error.code` parmi `BAD_REQUEST`/`NOT_FOUND`/
`CONFLICT`/`VALIDATION_FAILED`.

**Notes :**
- Un échec de mise en file d'email (Messenger) ne fait jamais échouer la requête —
  la mutation est déjà persistée avant tout dispatch, remontée via `warnings[]`.
- Aucune donnée patient, aucun token ni secret dans les emails envoyés.

---

### 26.9 Sites

#### `GET /api/sites`

**AuthZ :** Tout utilisateur authentifié

Retourne la liste de tous les sites (hôpitaux).

**Réponse — 200 :**

```json
[
  { "id": 1, "name": "Alpha" },
  { "id": 2, "name": "Delta" }
]
```

---

### 26.10 Module Planning V2 — Postes récurrents (Batches 1–9, UI lancée Batch 13)

> **V2 est désormais l'interface planning officielle des managers** (cutover UI, voir
> `docs/decisions.md` D-048) — le menu latéral "Planning" et `/app/m/planning` pointent
> vers V2. **Les endpoints V1 (sections 26.1–26.9 ci-dessus) restent actifs et inchangés**
> — aucune route supprimée, aucun comportement modifié ; seule la navigation manager a
> changé. V2 utilise un modèle centré sur des **postes récurrents de chirurgien**
> (`SurgeonSchedulePost` + `RecurrenceRule`) au lieu de gabarits par parité de semaine.
> Voir `docs/planning-v2-architecture-freeze.md` pour l'architecture complète et la
> stratégie de suppression définitive de V1 (flag par site, critère de sortie — toujours
> non implémentée).
>
> **Récurrences mensuelles non exposées côté UI** (Batch 13) : `monthlyNthWeekday` reste
> un champ valide sur l'API (voir ci-dessous) mais le formulaire de création/édition de
> poste ne propose plus les presets "1ère/2e/3e/4e semaine du mois" — la branche
> d'expansion de récurrence correspondante n'a pas de couverture de test. Toujours
> appelable directement via l'API.

**AuthZ : `MANAGER` / `ADMIN` sur tous les endpoints V2, sans exception.**

#### Entités de configuration (CRUD — Batch 6)

| Ressource | Endpoints |
|---|---|
| Groupes de sites | `GET/POST /api/planning/site-groups`, `GET/PATCH/DELETE /api/planning/site-groups/{id}`, `POST /api/planning/site-groups/{id}/sites`, `DELETE /api/planning/site-groups/{id}/sites/{siteId}` |
| Périodes horaires par site | `GET/POST /api/planning/shift-periods`, `PATCH/DELETE /api/planning/shift-periods/{id}` — `DELETE` désactive (`active=false`), ne supprime jamais |
| Postes chirurgien | `GET/POST /api/planning/surgeon-posts`, `GET/PATCH/DELETE /api/planning/surgeon-posts/{id}` — `DELETE` désactive, ne supprime jamais |
| Exceptions d'occurrence | `GET/POST /api/planning/surgeon-posts/{postId}/exceptions`, `PATCH/DELETE /api/planning/exceptions/{id}` — `DELETE` ici est une vraie suppression (métadonnée de planification pure, pas de donnée historique/auditée) |

`SurgeonSchedulePost.recurrence` (objet `RecurrenceRule`) :

```json
{ "frequency": "WEEKLY", "interval": 1, "weekdays": [1, 3], "anchorDate": "2026-01-05", "monthlyNthWeekday": null }
```

`interval=2` généralise PAIR/IMPAIR (n'importe quelle phase, pas seulement la parité ISO).
`weekdays` est requis et non vide pour `WEEKLY` ; ignoré pour `MONTHLY`.

#### Alertes (Batch 3–5)

| Endpoint | Description |
|---|---|
| `GET /api/planning/alerts` | Liste filtrable (`status`, `type`, `siteId`, `surgeonId`, `instrumentistId`, `missionStatus`, `from`, `to`) |
| `GET /api/planning/alerts/{id}` | Détail + bloc `actions` (`canAcknowledge`, `canResolve`, `canIgnore`, `canReassign`, `canOpenAsAvailable`, `recommendedAction`) |
| `POST /api/planning/alerts/{id}/acknowledge` | OPEN → ACKNOWLEDGED |
| `POST /api/planning/alerts/{id}/resolve` | OPEN/ACKNOWLEDGED → RESOLVED |
| `POST /api/planning/alerts/{id}/ignore` | OPEN/ACKNOWLEDGED → IGNORED |
| `POST /api/planning/alerts/{id}/reassign` | `{instrumentistId, note?}` — change l'instrumentiste de la mission, résout l'alerte |
| `POST /api/planning/alerts/{id}/open-as-available` | Vide l'instrumentiste, mission → `OPEN`, résout l'alerte |
| `GET /api/planning/alerts/{id}/eligible-instrumentists` | Instrumentistes actifs, affiliés au site, ni absents ni en conflit — `{items: [{id, email, name, sites: string[]}]}` (`sites` ajouté Batch 12 pour le modal de réassignation) |

Statuts : `OPEN`/`ACKNOWLEDGED`/`RESOLVED`/`IGNORED`. Répéter la même transition est
idempotent (200, pas d'erreur — la première résolution gagne). Croiser les deux états
terminaux (`RESOLVED`↔`IGNORED`) ou agir sur un état terminal → `409 CONFLICT`.
Types : `SURGEON_ABSENCE`, `INSTRUMENTIST_ABSENCE`, `REASSIGNMENT_REQUIRED`,
`OCCURRENCE_CANCELLED`, `SURGEON_CONFLICT`, `INSTRUMENTIST_CONFLICT` (les deux derniers
sont définis mais pas encore déclenchés — voir freeze §G).

#### Génération de planning V2 (Batch 9)

Parallèle complet de `/api/planning/preview|generate` (26.1) et `/api/planning/deploy`
(26.6/26.6b) ci-dessus, mais piloté par mois calendaire + `SurgeonSchedulePost` au lieu
de plage de dates + `PlanningTemplate`.

##### `POST /api/planning/v2/preview`

**Body :**

```json
{ "siteId": 1, "siteGroupId": null, "year": 2026, "month": 9 }
```

Exactement un de `siteId` / `siteGroupId` requis (`400` sinon, dans les deux sens).
`year`/`month` requis. Aucune écriture en base.

**Réponse — 200 :**

```json
{
  "lines": [{
    "date": "2026-09-07", "postId": 12, "surgeonId": 3, "surgeonName": "Dr X",
    "missionType": "BLOCK", "startTime": "08:00", "endTime": "13:00",
    "siteId": 1, "siteName": "Alpha", "instrumentistId": 5, "instrumentistName": "Y",
    "status": "COVERED",
    "existingMissionId": null, "existingInstrumentistId": null, "existingInstrumentistName": null,
    "freedFrom": false
  }],
  "summary": { "total": 8, "covered": 6, "uncovered": 1, "skipped": 1, "conflict": 0, "modified": 0 }
}
```

`status` : `SKIPPED` (chirurgien absent) | `UNCOVERED` | `COVERED` | `MODIFIED` | `CONFLICT`.

##### `POST /api/planning/v2/generate`

Même body que preview. Crée une `PlanningVersion` (`DRAFT`) + des `Mission` (`DRAFT`) —
réutilise intégralement le cycle de vie `PlanningVersion`/`Mission` existant, aucune
table V2-spécifique pour les missions. Ne déploie pas, n'envoie aucun PDF.

**Rejet explicite des doublons (`409 CONFLICT`)** : si un brouillon (`DRAFT`) non déployé
existe déjà pour exactement le même site/groupe + période, le second appel est rejeté
plutôt que de créer un second brouillon silencieusement. Une fois la version
déployée/archivée, regénérer la même période est autorisé (nouvelle version, numéro
incrémenté — comportement V1 inchangé).

> **Limite connue** : pour une génération par groupe de sites, `PlanningVersion.site`
> vaut `null` (pas de colonne `siteGroupId` sur `PlanningVersion`) — deux groupes
> différents générés pour le même mois partagent le même "bucket" de détection de
> doublon que le cas "tous sites" de V1. Documenté dans le freeze §B/§I, non corrigé.

**Réponse — 200 :**

```json
{ "versionId": 7, "created": 8, "updated": 0, "skipped": 1 }
```

##### `POST /api/planning/v2/deploy`

**Body :**

```json
{ "planningVersionId": 7 }
```

Réutilise **sans aucune logique V2-spécifique** `PlanningDeploymentService::deploy()` —
le même service que V1 (section 26.6). `from`/`to`/`siteId` sont dérivés automatiquement
de la `PlanningVersion` ciblée.

> **Historique (D-058)** : ce endpoint acceptait auparavant un champ `sendPdf`, transmis
> au paramètre `sendChangeSummary` de `PlanningDeploymentService::deploy()` — en pratique
> toujours `true` par défaut, donc l'email de récapitulatif de changements partait à
> **chaque** déploiement V2. `sendChangeSummary` a été retiré du service partagé ; ce
> comportement n'existe plus. Les PDFs standard par destinataire sont toujours envoyés
> inconditionnellement (un seul email par destinataire, voir §26.6).

**Réponse — 200 :** identique à 26.6 (`deploymentId`, `missionCount`, `openPoolCount`).

> **Correctif appliqué dans ce batch** (affecte V1 et V2 — service partagé) :
> `PlanningDeploymentService::deploy()` appelait `$em->clear(Mission::class)`, mais
> Doctrine ORM 3.x ignore l'argument et vide **tout** l'identity map — la `PlanningVersion`
> activée était détachée avant le `flush()` final et son statut `ACTIVE` n'était jamais
> persisté. Corrigé par un `flush()` immédiatement après l'activation. Trouvé par le
> premier test fonctionnel (EntityManager réel) à exercer ce chemin de code — les tests
> unitaires existants mockaient `EntityManager` et ne pouvaient pas révéler ce bug.

---

## 27. Synchronisation missions instrumentiste (polling intelligent V1)

### `GET /api/instrumentist/missions/sync?since=ISO_DATE`

**AuthZ :** `ROLE_INSTRUMENTIST`

**Contexte :** V1 "polling intelligent" — synchronise les missions OPEN/ASSIGNED de
l'instrumentiste connecté sans Mercure/WebSocket (compatible hébergement mutualisé).

**Query params :**

| Param | Type | Requis | Description |
|---|---|---|---|
| `since` | string (ISO 8601) | Non | Ne retourne que les missions créées/modifiées depuis cette date. Absent = premier sync (état complet pertinent). |

**Réponse — 200 :**

```json
{
  "serverTime": "2026-06-12T10:00:00+00:00",
  "changed": true,
  "missions": [
    {
      "id": 42,
      "type": "BLOCK",
      "schedulePrecision": "EXACT",
      "startAt": "2026-06-12T08:00:00+00:00",
      "endAt": "2026-06-12T12:00:00+00:00",
      "status": "OPEN",
      "site": { "id": 1, "name": "Alpha" },
      "allowedActions": ["claim"]
    }
  ],
  "removedMissionIds": [123, 124]
}
```

**Contenu de `missions[]` :**
- Missions `OPEN` éligibles (publication POOL/TARGETED + appartenance site, mêmes règles que `eligibleToMe=true`)
- Missions `ASSIGNED` (et autres statuts pertinents) déjà attribuées à l'utilisateur courant
- Toute mission créée/modifiée depuis `since` qui concerne l'utilisateur (changement de statut pertinent)

**`removedMissionIds[]` :** identifiants de missions précédemment visibles (offres) qui ne sont
plus éligibles pour l'utilisateur (ex : claimées par un autre instrumentiste) — le frontend doit
les retirer de la liste "Offres".

**Règles :**
- `serverTime` est l'horloge serveur — le frontend doit dériver son prochain `since` de cette
  valeur, jamais de l'heure locale.
- Aucune donnée patient n'est jamais incluse (`MissionListDto` uniquement).
- `allowedActions[]` reste la seule source de vérité côté frontend pour les droits.
- Le `claim` reste transactionnel côté `POST /api/missions/{id}/claim`, avec `409` si déjà prise —
  cet endpoint de sync ne fait que refléter l'état serveur.

**Erreurs :**
- `422` si `since` est fourni mais n'est pas un ISO 8601 valide

---

## 28. Module Administration (ROLE_ADMIN)

> Tous les endpoints ci-dessous requièrent `ROLE_ADMIN`. Tout autre rôle reçoit `403 Forbidden`.

---

### 28.1 Liste des utilisateurs

```
GET /api/admin/users
```

**Query params :**

| Paramètre | Type    | Description |
|-----------|---------|-------------|
| `search`  | string  | Filtre sur email, prénom, nom (insensible à la casse) |
| `role`    | string  | `ROLE_INSTRUMENTIST` \| `ROLE_SURGEON` \| `ROLE_MANAGER` \| `ROLE_ADMIN` |
| `active`  | bool    | `true` / `false` / absent = tous |
| `siteId`  | integer | ID du site (filtre par appartenance) |

**Réponse 200 :**

```json
{
  "items": [
    {
      "id": 1,
      "email": "alice@example.com",
      "firstname": "Alice",
      "lastname": "Dupont",
      "displayName": "Alice Dupont",
      "role": "INSTRUMENTIST",
      "active": true,
      "invitationStatus": "used",
      "sites": [{ "id": 1, "name": "CHU Liège" }]
    }
  ],
  "total": 1
}
```

**`invitationStatus` possibles :** `used` | `pending` | `expired` | `email_not_sent` | `none`

---

### 28.2 Détail utilisateur

```
GET /api/admin/users/{id}
```

**Réponse 200 :**

```json
{
  "id": 1,
  "email": "alice@example.com",
  "firstname": "Alice",
  "lastname": "Dupont",
  "phone": "+32 470 12 34 56",
  "displayName": "Alice Dupont",
  "role": "INSTRUMENTIST",
  "active": true,
  "invitationStatus": "used",
  "invitationExpiresAt": null,
  "invitationLastSentAt": "2026-06-01T10:00:00+00:00",
  "siteMemberships": [
    { "id": 12, "site": { "id": 1, "name": "CHU Liège" }, "siteRole": "INSTRUMENTIST" }
  ]
}
```

**Erreurs :** `404` si l'utilisateur n'existe pas.

---

### 28.3 Créer un utilisateur

```
POST /api/admin/users
```

**Body :**

```json
{
  "email": "bob@example.com",
  "firstname": "Bob",
  "lastname": "Martin",
  "phone": "+32 470 00 00 00",
  "role": "ROLE_INSTRUMENTIST",
  "siteIds": [1, 2]
}
```

**Champs :** `role` ∈ `ROLE_INSTRUMENTIST` | `ROLE_SURGEON` | `ROLE_MANAGER`.

**Règle d'affiliation aux sites (D-049)** — `siteIds` n'a **pas** de minimum statique : le nombre
de sites obligatoire dépend du rôle choisi, vérifié côté `UserAdministrationService::createUser()` :

| Rôle | Site obligatoire |
|---|---|
| `ROLE_INSTRUMENTIST` | Oui (≥1) |
| `ROLE_SURGEON` | Oui (≥1) |
| `ROLE_MANAGER` | Non (`siteIds` peut être `[]`) |

(`ROLE_ADMIN` n'est pas créable via cet endpoint — uniquement via les commandes console
`app:user:create`/`app:create-dev-user` — mais suit la même règle "non obligatoire" si jamais
exposé ici.)

**Réponse 201 :**

```json
{
  "user": { /* AdminUserDetail */ },
  "warnings": []
}
```

Un `warning` `INVITATION_EMAIL_NOT_SENT` apparaît si l'email n'a pas pu être mis en file.
L'utilisateur est créé dans tous les cas.

**Erreurs :** `409` si l'email est déjà utilisé, `404` si un `siteId` est invalide, `400` si le
rôle requiert un site et qu'aucun `siteId` n'est fourni, `422` si validation échoue.

---

### 28.4 Modifier les champs d'identité

```
PATCH /api/admin/users/{id}
```

**Body (partiel) :**

```json
{ "firstname": "Bobby", "lastname": "Martin", "phone": "" }
```

Seuls `firstname`, `lastname`, `phone` sont modifiables. Une chaîne vide `""` met le champ à null.

**Réponse 200 :** `AdminUserDetail`

---

### 28.5 Suspendre un utilisateur

```
POST /api/admin/users/{id}/suspend
```

Idempotent : si déjà suspendu, retourne simplement l'état actuel sans relancer d'audit.

**Réponse 200 :**

```json
{ "id": 1, "active": false }
```

---

### 28.6 Réactiver un utilisateur

```
POST /api/admin/users/{id}/activate
```

Idempotent : si déjà actif, no-op.

**Réponse 200 :**

```json
{ "id": 1, "active": true }
```

---

### 28.7 Changer le rôle

```
POST /api/admin/users/{id}/change-role
```

**Body :**

```json
{ "newRole": "ROLE_SURGEON" }
```

**Règles :**
- `newRole` ∈ `ROLE_INSTRUMENTIST` | `ROLE_SURGEON` | `ROLE_MANAGER`.
- L'admin ne peut pas changer son propre rôle (`400`).
- Si `newRole` est `ROLE_INSTRUMENTIST` ou `ROLE_SURGEON` et que l'utilisateur cible n'a aucune
  `SiteMembership`, le changement est refusé (`400`) — ces rôles requièrent toujours au moins un
  site (D-049). Aucune restriction si `newRole` est `ROLE_MANAGER`.
- Met également à jour le `siteRole` de toutes les `SiteMembership` existantes.

**Réponse 200 :** `AdminUserDetail`

---

### 28.8 Renvoyer l'invitation

```
POST /api/admin/users/{id}/resend-invitation
```

**Règles :** Impossible si le compte est déjà activé (password ≠ null → `409`).
Régénère le token et repart pour 48 h.

**Réponse 200 :**

```json
{
  "id": 1,
  "invitationStatus": "pending",
  "invitationExpiresAt": "2026-06-18T10:00:00+00:00",
  "invitationLastSentAt": "2026-06-16T10:00:00+00:00"
}
```

---

### 28.9 Ajouter une affiliation site

```
POST /api/admin/users/{id}/site-memberships
```

**Body :**

```json
{ "siteId": 3 }
```

**Erreurs :** `404` si site inconnu, `409` si affiliation déjà existante.

**Réponse 201 :**

```json
{
  "id": 15,
  "site": { "id": 3, "name": "CHR Namur" },
  "siteRole": "INSTRUMENTIST"
}
```

---

### 28.10 Retirer une affiliation site

```
DELETE /api/admin/users/{id}/site-memberships/{membershipId}
```

**Erreurs :** `404` si le membership n'appartient pas à l'utilisateur ; `409` si l'utilisateur est
`ROLE_INSTRUMENTIST` ou `ROLE_SURGEON` et que c'est sa **dernière** affiliation (ces rôles
requièrent toujours au moins un site, D-049). Aucune restriction pour `ROLE_MANAGER`/`ROLE_ADMIN`.

**Réponse 200 :**

```json
{ "id": 15, "deleted": true }
```

---

### 28.11 Liste des invitations

```
GET /api/admin/invitations
```

**Query params :**

| Paramètre | Type            | Description |
|-----------|-----------------|-------------|
| `status`  | string (répété) | Filtrer par `pending` \| `expired` \| `used` \| `email_not_sent` \| `none` |

Exemple : `?status[]=pending&status[]=expired`

**Réponse 200 :**

```json
{
  "items": [
    {
      "id": 1,
      "email": "charlie@example.com",
      "displayName": "Charlie",
      "role": "INSTRUMENTIST",
      "active": true,
      "invitationStatus": "pending",
      "invitationExpiresAt": "2026-06-18T10:00:00+00:00",
      "invitationLastSentAt": "2026-06-16T10:00:00+00:00"
    }
  ],
  "total": 1
}
```

---

### 28.12 Journal d'audit

```
GET /api/admin/audit
```

**Query params :**

| Paramètre      | Type    | Description |
|----------------|---------|-------------|
| `from`         | ISO8601 | Depuis (inclusif) |
| `to`           | ISO8601 | Jusqu'au (inclusif) |
| `targetUserId` | integer | Filtrer par utilisateur cible |
| `eventType`    | string  | Voir liste ci-dessous |
| `limit`        | integer | Max 500, défaut 200 |
| `offset`       | integer | Pagination |

**`eventType` possibles :** `USER_CREATED` | `USER_INVITATION_SENT` | `USER_INVITATION_RESENT` |
`USER_INVITATION_COMPLETED` | `USER_SUSPENDED` | `USER_REACTIVATED` | `USER_ROLE_CHANGED` |
`USER_SITE_ADDED` | `USER_SITE_REMOVED`

**Réponse 200 :**

```json
{
  "items": [
    {
      "id": 1,
      "eventType": "USER_CREATED",
      "description": "Utilisateur créé avec le rôle ROLE_INSTRUMENTIST",
      "payload": { "role": "ROLE_INSTRUMENTIST" },
      "createdAt": "2026-06-16T10:00:00+00:00",
      "actor": { "id": 10, "email": "admin@example.com", "displayName": "Admin" },
      "targetUser": { "id": 1, "email": "alice@example.com", "displayName": "Alice Dupont" }
    }
  ],
  "total": 1,
  "limit": 200,
  "offset": 0
}
```

**Note :** `targetUser` peut être `null` si l'utilisateur a été supprimé (ON DELETE SET NULL).

---

## 29. Authentification — login, refresh, logout, « Se souvenir de moi »

**AuthZ :** `PUBLIC_ACCESS` pour les trois endpoints ci-dessous.

### `POST /api/auth/login`

```json
{ "email": "user@example.com", "password": "...", "rememberMe": false }
```

`rememberMe` est optionnel ; absent ou omis, il est traité comme `false` (rétrocompatible avec les clients existants).

**Réponse — 200 :**

```json
{ "token": "<jwt access token>", "refresh_token": "<refresh token>" }
```

Durée de vie du refresh token retourné :

| `rememberMe` | TTL refresh token |
|---|---|
| `false` (ou absent) | 1 jour (86400 s) |
| `true` | 30 jours (2592000 s) |

L'access token (JWT) a une durée de vie fixe de 1h, indépendante de `rememberMe`.

### `POST /api/auth/google`

Même contrat que `/api/auth/login` : accepte un champ optionnel `rememberMe` (boolean, défaut `false`) en plus de `credential`.

### `POST /api/auth/refresh`

```json
{ "refresh_token": "..." }
```

Retourne un nouvel access token. Le refresh token n'est **pas rotaté** : le même `refresh_token` reste valable jusqu'à son expiration (TTL fixée au login selon `rememberMe`). Aucun nouveau refresh token n'est créé en base à cette étape.

**Réponse — 200 :**

```json
{ "token": "<nouveau jwt>", "refresh_token": "<même refresh token>" }
```

**401** si le refresh token est invalide, inconnu ou expiré.

### `POST /api/auth/logout`

```json
{ "refresh_token": "..." }
```

Invalide (supprime) le refresh token correspondant en base. Idempotent : si le token est déjà invalide/inconnu, répond `200` avec un message explicite plutôt qu'une erreur.

**Réponse — 200 :**

```json
{ "code": 200, "message": "The supplied refresh_token has been invalidated." }
```

**400** si `refresh_token` est manquant dans le corps de la requête.

---

## 30. Catalogue financier — types d'intervention, prestations firmes, règles tarifaires (Lot 1)

Voir D-067 (`docs/decisions.md`) pour l'invariant central : les prestations et leurs matériels
suggérés accélèrent la saisie mais ne sont **jamais** lus par le moteur financier.
`MANAGER`/`ADMIN` uniquement pour toute mutation (voir `BillingVoter::MANAGE`).

### `GET|POST /api/intervention-types`

Référentiel médical fermé. `code` unique et immuable après création (aucun champ `code`
dans le body de `PATCH`). `POST` : `{ code, label, specialty? }`.

### `PATCH /api/intervention-types/{id}`

`{ label?, specialty?, active? }` — jamais `code`.

### `DELETE /api/intervention-types/{id}`

`409` si référencé par une prestation ou une règle tarifaire (désactiver plutôt).

### `GET|POST /api/firms/{firmId}/service-offerings`

« Prestations » à l'écran (nom technique `FirmServiceOffering`). `POST` :
`{ interventionTypeId, label? }`. `409` si une prestation existe déjà pour ce couple
firme + type d'intervention (`UNIQUE(firm_id, intervention_type_id)`).

### `PATCH /api/firms/{firmId}/service-offerings/{offeringId}`

`{ label?, active? }`.

### `POST /api/firms/{firmId}/service-offerings/{offeringId}/suggested-materials`

`{ materialItemId }` — `422` si le matériel n'appartient pas à la même firme que la
prestation (doublé par une contrainte FK composée en base, voir migration
`Version20260716120000`).

### `PATCH .../suggested-materials/reorder`

`{ orderedIds: number[] }` — réordonnancement complet, pas de mise à jour partielle
d'un seul `displayOrder`.

### `DELETE .../suggested-materials/{suggestionId}`

Suppression toujours physique — aucune incidence historique.

### `GET|POST|PATCH|DELETE /api/firms/{firmId}/pricing-rules` (évolution)

`ruleType`: `INTERVENTION_FEE` (nécessite `interventionTypeId`) ou `MATERIAL_FEE`
(nécessite `materialItemId`, renommé depuis `IMPLANT_FEE` — `isImplant` n'a plus aucun
rôle dans la décision de facturabilité). Nouveaux champs : `currency` (défaut `EUR`),
`validFrom`/`validTo` (nullables, `null` = borne ouverte). `409` si la règle créée ou
modifiée chevauche, en dates, une autre règle active déjà posée sur la même cible
(refus bloquant, jamais un choix silencieux — voir `PricingRuleResolver`).

### `PATCH /api/material-items/{id}` (évolution)

Accepte désormais `active` (auparavant présent en base mais jamais exposé). Le
changement de `firmId` est refusé (`409`) dès qu'une `MaterialLine` réelle référence ce
matériel.

---
