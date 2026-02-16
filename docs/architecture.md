# Architecture — SurgicalHub Backend (Symfony)

Dernière mise à jour : 2026-02-01 (Europe/Brussels)

---

## 1) Contexte et objectifs

SurgicalHub est une API Symfony orientée “missions” permettant :

- aux managers/admins de créer/publier des missions,
- aux instrumentistes de consulter des offres éligibles, claim, encoder
  (interventions + matériel), puis submit,
- aux chirurgiens d’évaluer l’instrumentiste et de gérer des litiges d’heures
  via services/disputes,
- à l’équipe support (manager/admin) d’avoir une visibilité élargie
  (vues `*:read_manager`), sans exposer de données financières aux rôles non autorisés.

Contraintes clés :

- Aucune donnée patient (conception v2.1).
- RBAC strict via Voters.
- Flux mobile-first instrumentiste : rapide, peu de friction, endpoints dédiés.

---

## 2) Architecture du code (dossiers)

Racine : `backend/src`

- `Controller/` : endpoints HTTP (API JSON), validation input, appels services.
- `Dto/Request/` : DTO d’entrée (validation Symfony Assert) + DTO de réponse.
- `Entity/` : modèle Doctrine (entités + relations).
- `Enum/` : enums métier (MissionStatus, MissionType, PublicationScope, etc.).
- `Security/Voter/` : règles d’accès (MissionVoter, ServiceVoter, RatingVoter,
  ExportVoter, InstrumentistVoter).
- `Service/` : logique métier (orchestration, règles, mapping DTO).
- `EventSubscriber/ApiExceptionSubscriber.php` : format d’erreur JSON homogène.

### Règles d’implémentation

- Contrôleurs “minces” :
  - désérialisation + validation DTO,
  - `denyAccessUnlessGranted(...)`,
  - délégation vers un service métier,
  - retour JSON via DTO / groupes.
- Logique métier exclusivement dans `Service/*`.

---

## 3) Sécurité & Auth

- JWT obligatoire sur `/api/*` (sauf login/refresh/google).
- `POST /api/auth/login` : JSON login (intercepté par le firewall).
- `POST /api/auth/refresh` : refresh token (bundle Gesdinet).
- `POST /api/auth/google` : login via Google ID token.
- `GET /healthz` : endpoint public de santé.

---

## 4) Modèle métier — Missions & offres

### 4.1 Mission

Une Mission représente un créneau (site + start/end + type) avec cycle de vie :

- `DRAFT` : créée, éditable planning.
- `OPEN` : publiée, visible/offerte selon règles.
- `ASSIGNED` / `IN_PROGRESS` : instrumentiste affecté.
- `SUBMITTED` : encodage soumis.
- `VALIDATED` / `CLOSED` : post-traitement manager (à affiner).

Champs clés :

- site (Hospital),
- startAt, endAt,
- schedulePrecision (EXACT/APPROXIMATE),
- type (BLOCK / CONSULTATION / …),
- surgeon (User),
- instrumentist (User|null),
- status (MissionStatus),
- createdBy (User manager/admin).

### 4.2 Publications (offres)

Une Mission `OPEN` doit avoir au moins une MissionPublication :

- scope = `POOL` : visible aux instrumentistes éligibles,
- scope = `TARGETED` : visible uniquement à un instrumentiste cible,
- channel = `IN_APP`,
- publishedAt.

### 4.3 Claim (anti-double)

Un claim :

- matérialisé par MissionClaim (historique possible via OneToMany),
- met à jour la mission :
  - instrumentist = currentUser,
  - status = `ASSIGNED`,
- anti-double géré côté service (transaction + verrouillage + statut mission),
- verrouillage transactionnel : `PESSIMISTIC_WRITE`.

---

## 5) Encodage opératoire (Option B)

### 5.1 Problème métier

Une mission peut contenir plusieurs interventions
(ex. mission 13–18 : 2 LCA + 1 PTG).

L’instrumentiste doit :

1. ajouter une intervention,
2. optionnel : ajouter une firm/fournisseur,
3. encoder des lignes de matériel,
4. si item absent du catalogue : le signaler.

### 5.2 Entités d’encodage (modèle cible)

**Principe :** une intervention ne possède **pas** de firms. Les firms apparaissent uniquement via les items consommés.

Firm (référentiel — manager/admin)

- id
- name
- active

MaterialItem (catalogue)

- manufacturer → Firm (FK)
- referenceCode
- label
- unit
- isImplant (bool)
- active (bool)

MissionIntervention

- mission (ManyToOne)
- code (ex: ACL/LCA, TKA/PTG)
- label
- orderIndex
- materialLines (OneToMany -> MaterialLine)
- materialItemRequests (OneToMany -> MaterialItemRequest)

MaterialLine (ligne consommée/utilisée)

- mission (ManyToOne, obligatoire)
- intervention (ManyToOne -> MissionIntervention, nullable)
- item (MaterialItem, obligatoire)
- quantity (decimal string)
- comment (nullable)
- createdBy (User, obligatoire)
- implantSubMission (nullable, manager/admin)

**Firm exposée** : via `MaterialLine.item.manufacturer`.

Règles :

- pas de material lines sur mission type `CONSULTATION` (400)
- encodage modifiable tant que `encodingLockedAt` ET `invoiceGeneratedAt` sont null

MaterialItemRequest (signalement item manquant)

- mission (ManyToOne)
- intervention (nullable)
- label (obligatoire)
- referenceCode (nullable)
- comment (nullable)
- createdBy (User)

### 5.3 Catalogue MaterialItem

MaterialItem :

- manufacturer (nullable),
- referenceCode,
- label,
- unit,
- isImplant (bool),
- active (bool).

Utilisé par MaterialLine.item.

### 5.4 Regroupement implants (ImplantSubMission)

Quand `MaterialItem.isImplant = true` :

- association à ImplantSubMission (mission + firm),
- résolution via firmName ou manufacturer.

Objectif : reporting / validation / workflow implants.

---

## 6) Endpoints clés (résumé)

### Missions

- `POST /api/missions`
- `PATCH /api/missions/{id}`
- `POST /api/missions/{id}/publish`
- `POST /api/missions/{id}/claim`
- `GET /api/missions`
- `GET /api/missions/{id}`
- `POST /api/missions/{id}/submit`
- `GET /api/missions/{id}/encoding`

### Encodage

- `POST /api/missions/{missionId}/interventions`
- `PATCH /api/missions/{missionId}/interventions/{id}`
- `DELETE /api/missions/{missionId}/interventions/{id}`
- `GET /api/firms` (manager/admin)
- `POST /api/firms` (manager/admin)
- `PATCH /api/firms/{id}` (manager/admin)
- `DELETE /api/firms/{id}` (manager/admin)
- `POST /api/missions/{missionId}/material-lines`
- `PATCH /api/missions/{missionId}/material-lines/{id}`
- `DELETE /api/missions/{missionId}/material-lines/{id}`
- `POST /api/missions/{missionId}/material-item-requests`

### Catalogue matériel

- `GET /api/material-items`
- `GET /api/material-items/quick-search?q=...`
- `GET /api/material-items/{id}`

### Services & litiges

- `PATCH /api/missions/{missionId}/service`
- `POST /api/services/{serviceId}/disputes`
- `GET /api/disputes`
- `PATCH /api/disputes/{id}`

### Ratings

- `POST /api/missions/{id}/instrumentist-rating`
- `POST /api/missions/{id}/surgeon-rating`

### Utilisateurs

- `GET /api/instrumentists`
- `GET /api/instrumentists/with-rates`
- `GET /api/surgeons`
- `GET /api/me`

### Sites & exports

- `GET /api/sites`
- `POST /api/exports/surgeon-activity`

### Auth & health

- `POST /api/auth/login`
- `POST /api/auth/refresh`
- `POST /api/auth/google`
- `GET /healthz`

---

## 7) Services applicatifs (responsabilités)

### MissionService

- create/patch/publish/claim/submit,
- listing `GET /api/missions`,
- règles `eligibleToMe`,
- claim transactionnel anti-double,
- dates en ATOM via Mapper.

### MissionActionsService

- calcule `allowedActions[]` selon rôle + statut + ownership,
- claim visible uniquement si éligible.

### MissionVoter

- `VIEW`, `CREATE`, `PUBLISH`, `CLAIM`, `SUBMIT`, `EDIT`, `EDIT_ENCODING`.

### InterventionService

- CRUD interventions/firms/material lines,
- interdit sur `CONSULTATION`,
- résolution implants,
- vérifications NotFound.

### MissionEncodingService

- construit l’agrégat `MissionEncodingDto`,
- utilisé pour `GET /api/missions/{id}/encoding`.

### MaterialCatalogService

- listing / filtres MaterialItem,
- quick-search pour mobile.

### MaterialItemRequestService

- création signalements item manquant,
- workflow manager review futur.

### InstrumentistServiceManager

- update service instrumentiste,
- création / listing / update des litiges.

### RatingService

- rating instrumentiste / chirurgien.

### ExportService

- export activité chirurgien.

---

## 8) Mapping DTO (sorties)

### Missions standard

MissionListDto / MissionDetailDto :

- id, site, startAt, endAt, schedulePrecision, type, status,
  surgeon, instrumentist, allowedActions[].

MissionMapper :

- dates en `DateTimeInterface::ATOM`,
- DTO explicites (pas dépendants des groupes Doctrine).

### Encoding DTO

- MissionEncodingDto,
- MissionEncodingInterventionDto,
- MissionEncodingFirmDto,
- MissionEncodingMaterialLineDto,
- MissionEncodingMaterialItemRequestDto.

Principe : structure stable pour UI mobile :
Intervention -> MaterialLines (item -> firm) + Requests, + `catalog` (items + firms).

---

## 9) Sérialisation & groupes

- `mission:read` : vue non-financière instrumentiste/chirurgien.
- `mission:read_manager` : ajoute champs internes manager-only.
- `service:read` / `service:read_manager`.
- `dispute:read` / `dispute:read_manager`.
- `rating:read`, `export:read`, `site:list`.

Règle :
aucun champ financier exposé hors managers/admins.

---

## 10) Erreurs API (format)

Géré par ApiExceptionSubscriber :

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_FAILED",
    "message": "Validation failed",
    "violations": [],
    "debug": {
      "exceptionClass": "...",
      "exceptionMessage": "..."
    }
  }
}
```

- `debug` seulement en environnement dev.
- `UniqueConstraintViolationException` -> `409 CONFLICT` (utile pour claim).

---

## 11) Points d’attention techniques

### 11.1 Migrations Doctrine

TableNotFoundException indique :

- entité créée mais migration non exécutée.

Action :

- générer + exécuter migrations avant tests encoding.

### 11.2 Intégrité des liens missionId

Pour endpoints nested :

- vérifier appartenance intervention/line à la mission,
- éviter modifications cross-mission par ID.

### 11.3 Timezone / dates

- stockage `datetime_immutable`,
- sérialisation ATOM,
- filtres `periodStart`/`periodEnd` stables,
- tri `eligibleToMe` : ASC (prochaines missions d’abord),
  sinon DESC.
