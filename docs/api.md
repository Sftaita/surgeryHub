# SurgicalHub — API (Single Source of Truth)

_Last updated: 2026-03-14_

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

## 2. Référentiel Firm (Manager/Admin uniquement)

_(Inchangé)_

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

## 5. 🆕 Missions déclarées (Unforeseen activity)

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

---

## 8. Interventions

_(Inchangé)_

**AuthZ :** Instrumentiste assigné

- Autorisé également si `mission.status = DECLARED`
- Interdit si `mission.status = REJECTED`

---

## 9. Material Lines

_(Inchangé)_

**Contraintes supplémentaires :**

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
      "displayName": "Ole Salve"
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
  "defaultCurrency": "EUR"
}
```

**Notes frontend :**
- Pas de tarifs ni d'affiliations détaillées
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
