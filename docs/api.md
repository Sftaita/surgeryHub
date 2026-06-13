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

- Transactionnel
- Anti-double
- `409` si déjà claimée

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
      "specialties": ["GENOU", "EPAULE"]
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

**Réponse succès — 200 :**

```json
{ "id": 44, "deleted": true }
```

**Erreurs possibles :** `404` — messages : `Instrumentist not found` / `Site membership not found`

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
- `profilePictureUrl` est l'URL complète (absolue) construite par le backend
- `profilePicturePath` dans `instrumentistProfile` est le chemin relatif
- Pour les rôles `MANAGER` / `ADMIN` / `SURGEON`, `instrumentistProfile` est `null`

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

#### `GET /api/firms/{id}/pricing-rules`

Retourne toutes les règles (actives + inactives) pour la firme.

**Réponse — 200 :**

```json
[
  {
    "id": 1,
    "ruleType": "INTERVENTION_FEE",
    "interventionCode": "LCA",
    "materialItem": null,
    "unitPrice": "100.00",
    "active": true
  },
  {
    "id": 2,
    "ruleType": "IMPLANT_FEE",
    "interventionCode": null,
    "materialItem": { "id": 45, "label": "Anchor 5.5mm", "referenceCode": "SN-001", "firm": { "id": 1, "name": "S&N" } },
    "unitPrice": "35.00",
    "active": true
  }
]
```

#### `POST /api/firms/{id}/pricing-rules`

**Body JSON (INTERVENTION_FEE) :**

```json
{
  "ruleType": "INTERVENTION_FEE",
  "interventionCode": "LCA",
  "unitPrice": 100
}
```

**Body JSON (IMPLANT_FEE) :**

```json
{
  "ruleType": "IMPLANT_FEE",
  "materialItemId": 45,
  "unitPrice": 35
}
```

**Contraintes :**
- `materialItem` doit appartenir à la même firme que la règle (`IMPLANT_FEE`)
- `interventionCode` est sensible à la casse (matche `MissionIntervention.code` exactement)

**Réponse — 201 :** PricingRule complète (même format que GET)

#### `PATCH /api/firms/{id}/pricing-rules/{ruleId}`

**Body JSON :**

```json
{
  "unitPrice": 110,
  "active": false
}
```

**Réponse — 200 :** PricingRule mise à jour

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

**Modèle Absence :**

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant |
| `user` | object | `{ id, firstname, lastname, email, specialties[] }` |
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
  "selectedUncoveredMissionIds": [101, 102, 103],
  "sendChangeSummary": true
}
```

| Champ | Type | Requis | Description |
|---|---|---|---|
| `from` | string (date) | ✓ | Début de période |
| `to` | string (date) | ✓ | Fin de période |
| `siteId` | int \| null | — | Filtre par site (null = tous) |
| `versionId` | int \| null | — | ID de la `PlanningVersion` DRAFT à déployer |
| `selectedUncoveredMissionIds` | int[] | — | IDs des missions sans instrumentiste à publier en pool (défaut `[]`) |
| `sendChangeSummary` | bool | — | Si `true`, le worker envoie un email de diff aux instrumentistes et chirurgiens concernés (défaut `false`) |

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

> **Async** : PDFs, emails, notifications et `sendChangeSummary` s'exécutent dans le worker Messenger (`PlanningDeployPdfsMessageHandler`). La réponse HTTP retourne immédiatement après le flush DB.
>
> **Idempotence** : si le worker reçoit le même message deux fois (retry Messenger), il vérifie `PlanningDeployment.status == DONE` avant d'agir — aucun double envoi dans ce cas.
>
> **Worker requis** : `php bin/console messenger:consume async`

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
