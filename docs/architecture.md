# SurgicalHub — Architecture système

_Last updated: 2026-05-29 (v7 — observabilité, push fixes)_

---

## 1. Vue d'ensemble

SurgicalHub est une plateforme de gestion des missions chirurgicales. Elle connecte trois rôles :

| Rôle | Périmètre |
|---|---|
| `MANAGER` / `ADMIN` | Création et gestion des missions, des instrumentistes, du catalogue matériel, validation |
| `INSTRUMENTIST` | Prise en charge des missions, encodage des actes, déclarations, demandes matériel |
| `SURGEON` | Consultation uniquement |

---

## 2. Stack technique

### Backend
- **Symfony** (PHP) — API REST JSON
- **Doctrine ORM** — persistance MySQL
- **Symfony Security** — authentification JWT (Lexik) + refresh token DB-backed (Gesdinet) + RBAC via Voters
- **Symfony Mailer + Messenger** — emails transactionnels asynchrones
- **Stockage fichiers** — système de fichiers local (`public/uploads/`)
- **Sentry** (`sentry/sentry-symfony`) — capture des exceptions en prod ; channel Monolog `push` pour les événements push

### Frontend
- **React 19 + TypeScript** — Vite
- **MUI (Material UI v7)** — composants UI
- **TanStack React Query** — cache serveur, mutations, invalidation
- **React Router v7** — navigation
- **FullCalendar** — affichage planning (instrumentiste + drawer manager)
- **Axios** — client HTTP avec intercepteur JWT + refresh
- **Sentry** (`@sentry/react`) — capture des erreurs JS et crashes `AppErrorBoundary`

---

## 3. Architecture backend

### Structure des controllers

```
Api/
├── AuthGoogleController                 — login Google OAuth (+ rememberMe)
│   (login email/password géré par le firewall `login`, refresh par `refresh_jwt`,
│    logout par `logout` — voir D-047 « Remember me » dans decisions.md)
├── MissionController                    — CRUD missions + transitions de statut
├── InstrumentistController              — gestion manager des instrumentistes
├── SurgeonController                    — CRUD /api/surgeons + planning + site-memberships
├── InvitationController                 — flux complétion de compte (public)
├── MeController                         — profil utilisateur connecté
├── FirmController                       — GET /api/firms
├── MaterialCatalogController            — CRUD /api/material-items
├── MaterialItemRequestController        — POST demande (instrumentiste)
├── MaterialItemRequestManagerController — gestion demandes manager (list/resolve/ignore)
├── MaterialLineController               — CRUD /api/missions/{id}/material-lines
├── FirmBillingController               — PATCH billing-contact + CRUD /api/firms/{id}/pricing-rules
├── FirmInvoiceController               — CRUD /api/firm-invoices + preview/generate/send/mark-paid
├── InstrumentistStatementController    — CRUD /api/instrumentist-statements + preview/generate/send/mark-paid
├── PlanningTemplateController          — CRUD /api/planning/templates + slots
├── AbsenceController                   — CRUD /api/absences
├── PlanningGenerationController        — POST /api/planning/preview + /generate
├── PlanningDeployController            — POST /api/planning/deploy
├── PlanningVersionController           — GET /api/planning/versions/{id} + diff
├── SiteController                      — GET /api/sites
├── UserController                      — PATCH /api/users/{id}/specialties
├── InstrumentistMissionSyncController  — GET /api/instrumentist/missions/sync (polling)
├── AdminUserController                 — GET/POST/PATCH /api/admin/users + transitions
├── AdminInvitationController           — GET /api/admin/invitations
└── AdminAuditController                — GET /api/admin/audit
```

### Autorisation — RBAC strict via Voters

Toute logique d'accès passe par des Voters Symfony (`InstrumentistVoter`, `MissionVoter`, etc.).
Aucun contrôle de rôle direct dans les controllers.

### Endpoints d'action métier

Chaque mutation d'état passe par un endpoint dédié — aucune mutation libre via `PATCH` générique :

```
POST /api/missions/{id}/publish
POST /api/missions/{id}/claim
POST /api/missions/{id}/submit
POST /api/missions/{id}/approve-declared
POST /api/missions/{id}/reject-declared
POST /api/instrumentists/{id}/suspend
POST /api/instrumentists/{id}/activate
POST /api/material-item-requests/{id}/resolve
POST /api/material-item-requests/{id}/ignore
POST /api/missions/{missionId}/material-lines
PATCH /api/missions/{missionId}/material-lines/{lineId}
DELETE /api/missions/{missionId}/material-lines/{lineId}
POST /api/missions/{missionId}/material-item-requests
POST /api/planning/generate
POST /api/planning/deploy
POST /api/admin/users/{id}/suspend
POST /api/admin/users/{id}/activate
POST /api/admin/users/{id}/change-role
POST /api/admin/users/{id}/resend-invitation
POST /api/admin/users/{id}/site-memberships
DELETE /api/admin/users/{id}/site-memberships/{membershipId}
```


### Stockage des fichiers

Photos de profil stockées dans `public/uploads/profile-pictures/`.

```
ProfilePictureStorage
├── upload_dir       → {project}/public/uploads/profile-pictures
├── public_base_path → /uploads/profile-pictures
└── replaceUserProfilePicture(User, UploadedFile): string
    ├── déplace le fichier uploadé, nom généré (user-{id}-{random}.{ext})
    ├── supprime l'ancien fichier si l'utilisateur en avait déjà un (remplacement)
    └── retourne le chemin public relatif (à absolutiser par l'appelant si besoin)
```

Deux endpoints réutilisent ce service, avec la même validation (`Assert\Image`, jpeg/png/webp, 5 Mo max) :
- `POST /api/invitations/complete` — upload optionnel pendant la complétion de compte.
- `POST /api/me/profile-picture` — upload/remplacement pour l'utilisateur déjà actif (D-060).

**Deux formats de retour selon l'endpoint** (attention en cas d'ajout d'un nouveau consommateur) :
- `GET /api/me` / `POST /api/me/profile-picture` : `MeController::buildAbsoluteUrl()` construit une URL **absolue** (`profilePictureUrl` à la racine, `profilePicturePath` dans `instrumentistProfile` — même valeur absolue malgré le nom).
- `GET /api/instrumentists`, `GET /api/surgeons` (listes manager) : `profilePicturePath` est le chemin **relatif** brut (`getProfilePicturePath()` sans transformation) ; le frontend construit l'URL complète lui-même (`VITE_API_BASE_URL + profilePicturePath`, cf. `buildProfilePictureUrl()` dans `InstrumentistDrawer.tsx`/`SurgeonDrawer.tsx`).

### Prompt post-onboarding (D-060)

La photo de profil reste optionnelle à la complétion de compte (`/complete-account`), mais un modal (`ProfilePhotoPromptModal`, monté une seule fois via `ProfilePhotoPromptGate` dans `RequireAppAccess` — couvre tous les rôles/layouts) invite l'utilisateur actif sans photo à en ajouter une après connexion. Dismiss stocké en `sessionStorage` par utilisateur (`surgicalhub.profilePhotoPrompt.dismissed.<userId>`) : ne bloque jamais la navigation, peut réapparaître à une session ultérieure.

### Emails transactionnels

```
Service métier → NotificationService → Messenger (async) → Worker → Symfony Mailer → SMTP
```

L'envoi est découplé de la logique métier : une erreur SMTP ne fait jamais échouer la requête API.

---

## 4. Architecture frontend

### Structure des routes

```
/login                      — public
/complete-account?token=    — public (invitation instrumentiste)
/app/m/*                    — Manager / Admin (desktop, sidebar permanente)
/app/i/*                    — Instrumentiste (mobile-first)
/app/s/*                    — Surgeon
```

**Routes manager :**

```
/app/m/missions              — liste missions
/app/m/missions/to-validate  — missions DECLARED à valider
/app/m/missions/new          — création mission
/app/m/missions/:id          — détail mission
/app/m/instrumentists        — liste + drawer instrumentistes
/app/m/surgeons              — liste + drawer chirurgiens
/app/m/catalogue             — catalogue matériel
/app/m/catalogue/requests    — demandes matériel
/app/m/billing/config            — configuration tarifs firmes (PricingRules + billing contact)
/app/m/billing/firm-invoices     — liste + génération factures firmes
/app/m/billing/firm-invoices/:id — détail facture firme
/app/m/billing/statements        — liste + génération décomptes instrumentistes
/app/m/billing/statements/:id    — détail décompte instrumentiste
/app/m/planning/templates        — liste des gabarits de semaine
/app/m/planning/templates/:id    — éditeur de gabarit (timeline par jour, drag & drop)
/app/m/planning/generate         — prévisualisation, résolution, génération et déploiement (modal 2 étapes)
/app/m/planning/versions         — liste de toutes les PlanningVersions avec filtres et actions
/app/m/planning/versions/:id     — détail d'une PlanningVersion (compteurs, diff, déploiement, suppression)
/app/m/planning/schedule         — planning publié (vue tableau lecture + modification instrumentiste)
/app/m/planning/absences         — gestion des absences
/app/m/planning/specialties      — compétences & spécialités instrumentistes/chirurgiens
```

### Organisation du code

```
src/app/
├── api/              — apiClient (Axios + intercepteur JWT)
├── auth/             — AuthContext, tokens, refresh mutex
├── features/         — features métier
│   ├── missions/
│   │   └── sync/     — polling intelligent instrumentiste (D-045) : useInstrumentistMissionSync,
│   │                    applyMissionSyncToCache, missionSyncBus
│   ├── encoding/
│   ├── manager-instrumentists/
│   │   ├── api/      — types, fonctions API
│   │   ├── components/  — InstrumentistDrawer, InstrumentistPlanningSection, ...
│   │   ├── hooks/    — useInstrumentistDrawer
│   │   └── utils/
│   ├── manager-surgeons/
│   │   ├── api/      — surgeons.types.ts, surgeons.api.ts
│   │   ├── components/  — SurgeonDrawer, SurgeonPlanningSection, ...
│   │   └── hooks/    — useSurgeonDrawer
│   ├── manager-catalogue/
│   │   ├── api/      — catalogue.types.ts, catalogue.api.ts
│   │   └── components/  — MaterialItemFormDialog
│   ├── billing-firm/
│   │   └── api/      — firmInvoice.api.ts, firmBilling.api.ts
│   ├── billing-instrumentist/
│   │   └── api/      — statement.api.ts
│   ├── planning-manager/
│   │   ├── api/      — planning.api.ts (types + fonctions API planning)
│   │   └── components/  — DeployModal (modal 2 étapes partagé entre PlanningGeneratePage et PlanningVersionDetailPage)
│   ├── invitation/
│   ├── admin/
│   │   ├── api/         — admin.types.ts, admin.api.ts (CRUD utilisateurs, invitations, audit)
│   │   └── components/  — AdminUserDrawer, AdminCreateUserModal, AdminChangeRoleModal,
│   │                        AdminSuspendModal, InvitationStatusChip
│   └── sites/
│       └── api/      — sites.api.ts (fetchSites partagé)
├── pages/            — pages (orchestration uniquement)
│   ├── admin/
│   │   ├── AdminUsersPage        — liste + filtres + drawer + création
│   │   ├── AdminInvitationsPage  — liste invitations avec filtres par statut + renvoi
│   │   └── AdminAuditPage        — journal d'audit en lecture seule
│   ├── manager/
│   │   ├── MissionsListPage, MissionDetailPage, MissionCreatePage
│   │   ├── InstrumentistsPage
│   │   ├── CataloguePage, CatalogueRequestsPage
│   │   ├── SurgeonsPage
│   │   └── planning/
│   │       ├── PlanningTemplatesPage        — liste des gabarits
│   │       ├── PlanningTemplateEditorPage   — éditeur de gabarit (timeline par jour, drag & drop)
│   │       ├── PlanningGeneratePage         — prévisualisation, résolution, génération, déploiement
│   │       ├── PlanningVersionsListPage     — liste des PlanningVersions avec filtres et actions
│   │       ├── PlanningVersionDetailPage    — détail d'une PlanningVersion (diff, déploiement, suppression)
│   │       ├── PlanningSchedulePage         — planning publié (tableau lecture + edit instrumentiste)
│   │       ├── AbsencesPage                 — gestion des absences
│   │       └── SpecialtiesPage              — compétences & spécialités
│   └── instrumentist/
├── layouts/          — DesktopLayout (sidebar MUI permanente), MobileLayout
├── router/           — AppRouter, guards RequireAuth / RequireManager / RequireAdmin
└── ui/               — composants UI partagés (Toast...)
```

### Layout manager — Sidebar permanente

`DesktopLayout` utilise un `Drawer` MUI permanent (largeur 220px) avec la navigation :

```
SurgicalHub
─────────────
Missions
Instrumentistes
Chirurgiens
CATALOGUE
  Matériel
  Demandes matériel
FACTURATION
  Configuration
  Factures Firmes
  Décomptes
PLANNING
  Gabarits
  Générer
  Planning
  Absences
  Spécialités
─────────────         ← affiché uniquement si role === 'ADMIN'
ADMINISTRATION
  Utilisateurs
  Invitations
  Audit
─────────────
Déconnexion
```

La navigation utilise `NavLink` de React Router — l'item actif est mis en surbrillance (`selected`).

### MissionDetailContent — export nommé

`MissionDetailPage` exporte deux éléments :
- `export default MissionDetailPage` — page route `/app/m/missions/:id`
- `export function MissionDetailContent({ missionId, embedded?, onCloseEmbedded? })` — utilisable en dialog (drawer planning instrumentiste, etc.)

En mode `embedded` : pas de bouton retour, après approve/reject appelle `onCloseEmbedded()` au lieu de naviguer.

### Règles frontend

- **Pas de fallback métier** : le frontend reflète strictement l'état serveur
- **`allowedActions[]`** : les droits sur les missions sont calculés par le backend et consommés sans inférence côté client
- **React Query** : toutes les mutations invalident ou mettent à jour le cache via `setQueryData` / `invalidateQueries`
- **Optimistic updates** : utilisés pour les affiliations de site, le renommage de templates et le drag & drop de slots (avec rollback sur erreur dans les trois cas)
- **Badge sidebar** : le composant `DesktopLayout` poll toutes les 60s les demandes PENDING et affiche un badge sur "Demandes matériel"
- **`SlotUser`** : dans les slots de planning, surgeon et instrumentist sont sérialisés sous la forme compacte `{ id, name }` — ne pas utiliser `UserRef` (`{ id, firstname, lastname }`) pour ces champs
- **`fetchSites`** : la fonction partagée `fetchSites()` de `sites.api.ts` est utilisée partout pour charger la liste des sites (clé React Query : `["sites"]`)

### Composants partagés — PersonSearchSelect

`PersonSearchSelect` (`frontend/src/app/features/planning-manager/components/PersonSearchSelect.tsx`)
est un composant générique de recherche/sélection de personnes — **pas spécifique aux
absences**, réutilisable partout où un manager doit choisir un instrumentiste et/ou un
chirurgien.

- **Chargement unique puis recherche locale** : la population active est chargée une seule
  fois (au montage du composant, ou plus tôt via `qc.prefetchQuery`) puis tout le filtrage se
  fait côté client (`filterOptions` du MUI `Autocomplete`). **Aucun appel API n'est déclenché
  pendant la frappe** — choix UX délibéré après retour terrain défavorable sur une recherche
  serveur débouncée.
- **`scope` (prop, défaut `"all"`)** : `PersonSearchScope = "all" | "instrumentists" | "surgeons"`.
  - `"all"` → charge instrumentistes + chirurgiens
  - `"instrumentists"` → charge uniquement `getInstrumentists`, n'appelle jamais `getSurgeons`
  - `"surgeons"` → charge uniquement `getSurgeons`, n'appelle jamais `getInstrumentists`
- **Cache React Query par scope** : `personOptionsQueryKey(scope)` → `["personOptions", "active", scope]` — chaque scope a sa propre entrée de cache, jamais partagée entre scopes différents.
- **Tri** : rôle (instrumentistes avant chirurgiens) → nom de famille → prénom → email (repli).
- **Affichage** : avatar, nom complet **Prénom Nom** (ordre conservé volontairement — voir note
  ci-dessous), rôle, email en second niveau.
- **Recherche locale insensible aux accents/casse/espaces, sur les deux ordres du nom complet** :
  taper "Arnaud Deltour" **ou** "Deltour Arnaud" trouve la même personne, même si `firstname`
  contient une espace finale parasite (anomalie de donnée réelle constatée en prod, neutralisée
  par un trim à la source dans `fetchActivePersonOptions`). Avant ce correctif, la recherche ne
  comparait la requête qu'à chaque champ séparément (`firstname`, `lastname`, `email`, `rôle`),
  jamais au nom complet — un nom à deux mots ne pouvait donc jamais matcher.
- **Affichage Prénom Nom conservé délibérément** : la recherche accepte désormais les deux
  ordres, mais l'affichage reste Prénom Nom pour ne pas casser l'UX et les tests existants sur
  tous les écrans consommateurs. Une éventuelle inversion vers "Nom Prénom" doit être traitée
  comme un **lot UX séparé et explicite**, pas comme un effet de bord d'un correctif de
  recherche.
- **Usage actuel** : `AbsencesPage` avec `scope="all"`.

### Synchronisation instrumentiste — polling intelligent (D-045)

`useInstrumentistMissionSync()` (monté dans `MobileLayout`) interroge
`GET /api/instrumentist/missions/sync?since=...` (voir `docs/api.md` §27) toutes les 30s,
en pause si l'onglet est caché ou hors-ligne, avec refresh immédiat au retour focus/online
ou via `requestMissionSync()` (bus d'événements appelé après claim/submit/declare).
`applyMissionSyncToCache` patche en place le cache React Query `["missions", ...]` (mise à
jour/suppression des missions existantes, ajout des nouvelles offres OPEN et des missions
nouvellement assignées) et déclenche un toast groupé pour les nouvelles offres.

---

## 5. Modèle de données (entités principales)

```
User
├── id, email, password (nullable)
├── roles: ['ROLE_INSTRUMENTIST' | 'ROLE_MANAGER' | 'ROLE_ADMIN' | 'ROLE_SURGEON']
├── active: bool
├── firstname, lastname, displayName
├── phone, profilePicturePath
├── defaultCurrency, employmentType
├── hourlyRate, consultationFee
├── invitationToken, invitationExpiresAt
├── specialties: string[] (JSON) — codes spécialité (GENOU, EPAULE, …)
└── SiteMembership[]

SiteMembership
├── id
├── user → User
├── site → Hospital
└── siteRole: 'INSTRUMENTIST' | ...
```

`SiteMembership` est une propriété générique : tout utilisateur peut avoir 0 à N sites, mais le
nombre minimum requis dépend du rôle métier (D-049) :

| Rôle | Sites autorisés | Site obligatoire |
|---|---|---|
| INSTRUMENTIST | 1..N | Oui |
| SURGEON | 1..N | Oui |
| MANAGER | 0..N | Non |
| ADMIN | 0..N | Non |

Un chirurgien (ou instrumentiste) est une entité globale unique, jamais dupliquée par hôpital :
plusieurs `SiteMembership` peuvent pointer vers le même `User`, un par site d'activité (ex. un
chirurgien affilié à Delta, Saint-Jean et Parc Léopold reste un seul compte). L'invariant "au
moins un site pour INSTRUMENTIST/SURGEON" est vérifié côté backend à la création, à la suppression
d'une affiliation (refus si c'est la dernière) et au changement de rôle — jamais côté frontend
(pas de fallback métier, cf. conventions générales).

```
Hospital (site)
├── id, name

Firm
├── id, name (unique)
└── active: bool

MaterialItem
├── id
├── firm → Firm
├── label, referenceCode, unit
└── isImplant: bool

Mission
├── id, status, type, schedulePrecision
├── startAt, endAt
├── site → Hospital
├── surgeon → User
├── instrumentist → User (nullable)
├── allowedActions[] (calculé dynamiquement)
└── MissionIntervention[]

MissionIntervention
├── MaterialLine[]
└── MaterialItemRequest[]

MaterialLine
├── mission → Mission
├── missionIntervention → MissionIntervention (nullable)
├── item → MaterialItem
├── quantity (decimal)
└── comment (nullable)

MaterialItemRequest
├── mission → Mission
├── missionIntervention → MissionIntervention (nullable)
├── label, referenceCode, comment
├── status: 'PENDING' | 'RESOLVED' | 'IGNORED'
├── materialItem → MaterialItem (nullable, renseigné lors de la résolution)
└── createdBy → User

PricingRule
├── firm → Firm
├── ruleType: 'INTERVENTION_FEE' | 'IMPLANT_FEE'
├── interventionCode (string nullable — matche MissionIntervention.code)
└── materialItem → MaterialItem (nullable)

FirmInvoice
├── firm, number (FIRM-YYYY-NNN), status (DRAFT|GENERATED|SENT|PAID)
├── periodStart, periodEnd, totalAmount
├── billingEmailTo (snapshot), billingEmailCc (snapshot JSON)
└── FirmInvoiceLine[]

FirmInvoiceLine
├── invoice, mission, lineType (INTERVENTION_FEE|IMPLANT_FEE)
├── missionIntervention (nullable FK — anti-doublon)
├── materialLine (nullable FK — anti-doublon)
└── descriptionSnapshot, unitPrice (snapshot), quantity, totalAmount

InstrumentistStatement
├── instrumentist, periodYear, periodMonth
├── status (DRAFT|GENERATED|SENT|PAID), totalAmount
└── InstrumentistStatementLine[]

InstrumentistStatementLine
├── statement, mission, lineType (BLOC|CONSULTATION)
├── durationMinutesRaw, durationMinutesRounded
├── rateSnapshot (snapshot hourlyRate ou consultationFee)
└── quantity, totalAmount, surgeonNameSnapshot, siteNameSnapshot, missionDateSnapshot

PlanningTemplate
├── id
├── type: 'PAIR' | 'IMPAIR' | 'TOUTES'
├── label: string (nullable) — nom personnalisé
├── site → Hospital (obligatoire)
├── createdBy → User
├── createdAt
└── PlanningSlot[]

PlanningSlot
├── id
├── template → PlanningTemplate
├── dayOfWeek: int (1=Lundi … 7=Dimanche)
├── period: 'AM' | 'PM'
├── startTime, endTime (HH:MM:SS)
├── missionType: 'BLOCK' | 'CONSULTATION'
├── surgeon → User
├── instrumentist → User (nullable)
└── site → Hospital (nullable — surcharge par rapport au template)

Absence
├── id
├── user → User
├── dateStart, dateEnd (date)
├── reason: string (nullable)
└── createdBy → User
```

---

## 6. Flux principaux

### Flux invitation instrumentiste

```
Manager → POST /api/instrumentists
        → User créé (active=true, password=null, token généré)
        → Email envoyé (async via Messenger)
        → Instrumentiste ouvre /complete-account?token=XXXX
        → GET /api/invitations/{token} (vérification)
        → POST /api/invitations/complete (multipart/form-data)
        → token invalidé, password défini, profil complété
```

### Flux mission standard

```
Manager → POST /api/missions (DRAFT)
        → POST /api/missions/{id}/publish (OPEN)
        → Instrumentiste → POST /api/missions/{id}/claim (ASSIGNED)
        → Instrumentiste → encodage + POST /api/missions/{id}/submit (SUBMITTED)
        → Manager → validation
```

### Flux mission déclarée (imprévue)

```
Instrumentiste → POST /api/missions/declare (DECLARED)
Manager → POST /api/missions/{id}/approve-declared (ASSIGNED)
       ou POST /api/missions/{id}/reject-declared (REJECTED)
```

### Flux demande matériel

```
Instrumentiste → encodage → matériel absent
              → POST /api/missions/{missionId}/material-item-requests (PENDING)

Manager → GET /api/material-item-requests?status=PENDING
        → [Créer produit] → POST /api/material-items (crée MaterialItem)
                          → POST /api/material-item-requests/{id}/resolve (materialItemId)
                          → status=RESOLVED + MaterialLine créée sur la mission
        ou [Ignorer]     → POST /api/material-item-requests/{id}/ignore
                          → status=IGNORED
```

### Flux encodage matériel (instrumentiste)

```
Instrumentiste → sélectionne firm → sélectionne item → quantité
              → POST /api/missions/{id}/material-lines (optimistic update)

Matériel absent → "Matériel non trouvé ?" → modal
               → POST /api/missions/{id}/material-item-requests (PENDING)
               → affiché sous l'intervention dans l'encoding
               → Manager résout → MaterialLine créée automatiquement
               → Request disparaît de l'encoding (filtre PENDING uniquement)
```

---

## 7. Flux planning

### Génération du planning

```
Manager → définit PlanningTemplates (PAIR/IMPAIR/TOUTES) + PlanningSlots
        → enregistre les Absences des instrumentistes/chirurgiens

        ① POST /api/planning/preview   — simulation sans écriture
             → tableau par semaine (COVERED / UNCOVERED / SKIPPED / CONFLICT / MODIFIED)

        ② [Si UNCOVERED] Bouton "Résoudre les non-attribués"
             → modal par ligne UNCOVERED :
               • Instrumentiste libéré détecté (slot SKIPPED → chirurgien absent)
                   → POST /api/missions { instrumentistUserId }   (DRAFT avec instrumentiste direct)
                   → ligne passe COVERED — le déploiement (④) le publiera en ASSIGNED
               • Aucun libéré disponible
                   → POST /api/missions   (DRAFT)
                   → POST /api/missions/{id}/publish  { scope: POOL }
                   → ligne affiche "Demande envoyée" (fond bleu)

        ③ POST /api/planning/generate  — crée les missions DRAFT restantes
             (les missions déjà publiées en ② ne sont pas écrasées)

        ④ POST /api/planning/deploy    — publie les DRAFT + envoie les PDFs
```

### Auto-assignation des instrumentistes libérés (backend — second passage)

`PlanningGeneratorService::preview()` effectue un **second passage** après la boucle principale pour réaffecter automatiquement les instrumentistes libérés aux créneaux sans instrumentiste.

**Définition "libéré"** : un instrumentiste dont **tous** les slots du jour sont `SKIPPED` (son chirurgien est absent). Il peut couvrir plusieurs créneaux non-chevauchants le même jour.

**Algorithme :**

1. **Construction du pool** — collecter les instrumentistes présents uniquement sur des lignes `SKIPPED`. Retirer immédiatement tout instrumentiste qui apparaît aussi sur au moins une ligne non-SKIPPED (il n'est pas vraiment libre).

2. **Traitement des créneaux candidats** — pour chaque ligne qui a besoin d'un instrumentiste :
   - `status === 'UNCOVERED'` (aucun instrumentiste, aucune mission existante)
   - `status === 'COVERED' && instrumentistId === null` (mission existante sans instrumentiste)

3. **Vérification d'overlap** — pour chaque libéré candidat :
   - Aucune ligne non-SKIPPED avec cet instrumentiste ne doit chevaucher le créneau cible (check sur `$lines`)
   - Aucune affectation du second passage lui-même ne doit chevaucher (check sur `$secondPassAssignments`)

4. **Affectation** — si disponible : `instrumentistId` mis à jour, `status → COVERED`, `freedFrom = true`. L'instrumentiste reste dans le pool (peut couvrir un autre créneau non-chevauchant).

**Champ `freedFrom`** : exposé dans `PreviewLine`, consommé par `generate()` pour mettre à jour l'instrumentiste des missions existantes sans instrumentiste, et par le frontend pour afficher le badge "Libéré".

### Vue tableau de la page Générer (`PlanningGeneratePage`)

La page `/app/m/planning/generate` affiche les lignes de prévisualisation sous forme de **tableau groupé par semaine**, calqué sur le format du planning Excel interne.

**Structure :**
- Chaque semaine a une barre d'en-tête colorée : bleu = semaine paire, violet = semaine impaire
- Colonnes : **Jour** (rowspan) | **Date** (rowspan) | **Chirurgien** | **Période** | **Instrumentiste** | **Site** | **État**
- Les lignes d'un même jour partagent les cellules Jour et Date (`rowSpan`)
- Tri au sein de chaque jour : chirurgien A→Z, puis Matin avant Après-midi

**Couleur de ligne par statut :**

| Statut | Couleur |
|---|---|
| `COVERED` | Blanc |
| `UNCOVERED` | Jaune clair |
| `MODIFIED` | Bleu clair |
| `CONFLICT` | Rouge clair |
| `SKIPPED` | Gris (chirurgien absent) |

**Attribution inline d'instrumentiste :**
- La colonne Instrumentiste contient un `<Select>` MUI directement dans chaque cellule
- La liste est chargée une fois depuis `GET /api/instrumentists?active=true`
- Avant génération : la sélection met à jour l'état local uniquement (`previewLines`)
- Après génération (`existingMissionId` présent) : la sélection appelle `POST /api/missions/{id}/assign-instrumentist` puis met à jour l'état local

### Performances de preview() — 3 requêtes DB pour toute période

`preview()` pré-charge tout en 3 requêtes avant la boucle sur les jours, puis travaille entièrement en mémoire :

| # | Méthode | Token DQL | Ce qui est chargé |
|---|---|---|---|
| 1 | `loadAllTemplates()` | QB | Tous les templates + slots (filtré par site) |
| 2 | `loadAbsencesMap()` | `absencesFrom` | Toutes les absences → `[userId => [[start, end]]]` |
| 3 | `loadExistingMissionsPool()` | `poolFrom` | Toutes les missions → `["{surgeonId}_{siteId}_{date}" => Mission[]]` |

Le filtrage PAIR/IMPAIR, les vérifications d'absence (`isAbsentFast`) et les conflits (`hasConflictFast`) sont 100% en mémoire. Sans ça, un planning de 2 mois × 10 slots/jour = ~1 830 requêtes DB → timeout.

**Test de régression :** `PlanningPreviewPerformanceTest::test_two_month_preview_uses_only_3_db_queries`

### Déploiement asynchrone — PlanningDeployPdfsMessageHandler

`PlanningDeploymentService::deploy()` ne fait que le travail DB (rapide) et retourne immédiatement `{ missionCount }`. La génération des PDFs et l'envoi des emails sont délégués à `PlanningDeployPdfsMessageHandler` via Messenger (worker asynchrone). Cela évite le timeout HTTP sur les plannings avec beaucoup de chirurgiens/instrumentistes.

### Détection de conflits

`PlanningGeneratorService::preview()` détecte deux types de conflits :

1. **Conflit pool** : l'instrumentiste a une mission dans `$missionsByInstrumentist` (index en mémoire du pool) qui chevauche le créneau → statut `CONFLICT`
2. **Conflit intra-preview** : deux slots de templates différents assignent le même instrumentiste à des créneaux qui se chevauchent dans la même preview → le second slot reçoit `CONFLICT`

La détection intra-preview utilise une map en mémoire `$previewAssignments[instrumentistId]` qui accumule les plages `[dateStr, startMinutes, endMinutes]` au fil du traitement.

Les missions `DRAFT` sont incluses dans le check DB (seules les `REJECTED` sont exclues), ce qui permet de détecter les conflits lors d'une re-preview après génération.

### Algorithme de sélection d'instrumentiste (suggestions)

`PlanningScoreService::suggestForMission()` — alimenté par `GET /api/missions/{id}/suggested-instrumentists` :

1. Charge tous les instrumentistes actifs du même site
2. Filtre les absents (via `Absence`)
3. Filtre les instrumentistes avec une mission en conflit horaire
4. Score les candidats restants (sur 100 pts) :
   - Spécialité correspondante : 0–40 pts
   - Historique avec ce chirurgien (missions VALIDATED) : 0–35 pts
   - Expérience du type de mission (BLOCK/CONSULTATION) : 0–25 pts
5. Tri : historique + spécialité en premier, puis spécialité seule, puis score décroissant

### Page planning publié (`PlanningSchedulePage`)

La page `/app/m/planning/schedule` ("Planning" dans la sidebar) affiche les missions publiées dans le **même format de tableau** que `PlanningGeneratePage`.

**Source :** `GET /api/missions?from=...&to=...&siteId=...` (limit 500). DRAFT et REJECTED exclus côté frontend.

**Colonnes :** Jour (rowSpan) | Date (rowSpan) | Chirurgien | Période | Instrumentiste | Site | Statut

**Statut mission :** chip coloré — À réserver (OPEN, bleu outlined), Assigné (vert), Soumis, Validé, Déclaré, Clôturé.

**Instrumentiste éditable inline :** `<Select>` pour OPEN et ASSIGNED → `POST /api/missions/{id}/assign-instrumentist`. Read-only pour SUBMITTED / VALIDATED / CLOSED.

**Chargement manuel :** bouton "Charger le planning" — `useQuery({ enabled: false })` + `refetch()` explicite.

---

### Planning vivant — vie du planning après déploiement (D-052)

Un planning déployé n'est pas figé. Chaque `Mission` publiée continue d'évoluer indépendamment de la `PlanningVersion` qui l'a créée.

**Cycle de vie d'une Mission post-déploiement :**

```
  DRAFT → OPEN → ASSIGNED → SUBMITTED → VALIDATED → CLOSED
              ↑        ↓
          (release) (claim / réassignation)
               ↓
           CANCELLED  (Batch 15 — mission annulée par manager)
```

**Règle structurante** : toute modification post-déploiement opère via un endpoint Mission dédié, jamais par un nouveau cycle generate/deploy.

**Endpoints post-déploiement (Batch 15) :**

```
POST /api/missions/{id}/release                 ASSIGNED → OPEN (manager ouvre au pool)
POST /api/missions/{id}/cancel                  OPEN → CANCELLED (manager annule)
POST /api/missions/{id}/claim                   OPEN → ASSIGNED (instrumentiste claim — existant)
POST /api/missions/{id}/assign-instrumentist    Réassignation directe (manager — existant, étendu)
GET  /api/missions/{id}/audit                   Historique des modifications
GET  /api/planning/versions/{id}/coverage-summary  Bilan de couverture en temps réel
```

**Séparation des responsabilités post-déploiement (D-056) :**

```
Contrôleur / Alert handler
        │
        ▼
MissionPostDeployService          — validation, mutation d'état, AuditEvent, dispatch message
        │ dispatch (async)
        ▼
MissionLifecycleChangedMessage    — snapshot: missionId, changeType, actorId, payload, occurredAt
        │
        ▼
MissionLifecycleChangedMessageHandler   — tous les effets de bord:
        │  ├── CLAIMED  → NotificationEvent(SURGEON_POST_COVERED) + push chirurgien
        │  ├── RELEASED → NotificationEvent(SURGEON_POST_UNCOVERED) + push chirurgien
        │  ├── Autres changeTypes → log + skip (forward-compatible)
        │  └── Future: coverage hook, history projection, webhooks, Slack, analytics
        ▼
EntityManager::flush()            — chaque effet de bord isolé dans try/catch
```

**Traçabilité** : chaque action post-deploy crée un `AuditEvent` (acteur, type, payload snapshot) et déclenche les `NotificationEvent` appropriés via `NotificationPreferenceResolver`. Les noms des personnes sont snapshotés dans le payload pour préserver la lisibilité à long terme.

**Notifications post-déploiement :**

| Acteur | Déclencheur | Type de notification | Handler |
|---|---|---|---|
| Chirurgien | Déploiement initial | `PLANNING_DEPLOYED_SURGEON` (email : compteurs agrégés ; in-app : par poste) | `PlanningDeployPdfsMessageHandler` |
| Chirurgien | OPEN → ASSIGNED (claim) | `SURGEON_POST_COVERED` | `MissionLifecycleChangedMessageHandler` (Batch 15E) |
| Chirurgien | ASSIGNED → OPEN (release) | `SURGEON_POST_UNCOVERED` | `MissionLifecycleChangedMessageHandler` (Batch 15E) |
| Instrumentiste | Déploiement initial | `PLANNING_DEPLOYED_INSTRUMENTIST` (avec PDF) | `PlanningDeployPdfsMessageHandler` |
| Instrumentiste éligible | Mission mise en OPEN au déploiement | `OPEN_MISSION_AVAILABLE` | `PlanningDeployPdfsMessageHandler` (Batch 15D) |
| Instrumentiste | Changement post-deploy (action unitaire hors Mode Modification) | `PLANNING_MISSION_REASSIGNED` / `CANCELLED` / `ADDED` / `UPDATED` | `MissionLifecycleChangedMessageHandler` (Batch 15F+) |
| Instrumentiste / Chirurgien réellement concernés | Redéploiement après Mode Modification (lot d'édits) | Un seul email récapitulatif ciblé, jamais de global resend | `PlanningModificationService` → `PlanningChangeSummaryService` (Batch 15K) |
| Manager (déployeur) | Déploiement initial | `PLANNING_DEPLOYED_MANAGER` (email + in-app, avec PDF global) | `PlanningDeployPdfsMessageHandler` |

Exactement UN email de déploiement par destinataire (D-058). `PlanningChangeSummaryService` (récapitulatif de changements) était écrit mais non câblé jusqu'au Batch 15K, qui le déclenche depuis `PlanningModificationService` : un planning déjà déployé peut être édité dans l'éditeur unifié (§ ci-dessous), et son redéploiement calcule un diff avant/après pour n'envoyer un récapitulatif qu'aux personnes dont au moins une mission a réellement changé — jamais un renvoi global aux chirurgiens/instrumentistes non concernés.

Voir D-052, D-053, D-054, D-055, D-056, D-057, D-058, D-059 dans `docs/decisions.md` pour le détail des payloads et des règles.

**Responsabilités — Mission / MissionClaim / AuditEvent / MissionEligibilityService (D-059) :**

| Composant | Responsabilité | Ne fait jamais |
|---|---|---|
| `Mission` (entité) | Source de vérité unique et exclusive de l'état courant : `status`, `instrumentist`, horaires, site. Toute décision métier (claim/reassign/cancel possible ?) se base uniquement sur ces champs. | Conserver l'historique de ses propres transitions. |
| `MissionClaim` (entité) | Enregistrement **append-only** du moment où une mission a été revendiquée (`mission`, `instrumentist`, `claimedAt`). Sert uniquement l'historique/reporting/statistiques de charge par instrumentiste. | Participer à une décision métier — plus jamais consultée comme garde d'état par `claim()` ou tout autre service. |
| `AuditEvent` | Journal général et transverse de tous les changements post-déploiement (claim, release, reassign, cancel — D-055), avec acteur/horodatage/payload snapshot. Source de `GET /api/missions/{id}/audit` et de la timeline `GET /api/planning/versions/{id}/history`. | Remplacer `MissionClaim` pour des requêtes typées/structurées ciblées sur les claims (payload JSON générique, pas des colonnes dédiées). |
| `MissionEligibilityService` | Seule source de vérité pour l'éligibilité (D-057), progressivement mission-centrique (D-059) : la question canonique est « qui est éligible pour **cette** mission ? », pas « pour ce site ». | Dupliquer sa logique ailleurs (Voter, handlers) — tout délègue à ce service. |

---

### Éditeur unifié Génération / Modification (Batch 15K)

Planning V2 s'appuie sur **un seul composant éditeur** (`GeneratePlanningTab.tsx`) pour les
deux usages : générer un nouveau planning et modifier un planning déjà déployé. L'état
`PlanningEditorMode = "generation" | "modification"` est dérivé (`modificationVersionId
!== null`), jamais un composant séparé — même liste groupée jour/chirurgien, même
inspecteur permanent (panneau latéral toujours monté, pas de popover), même système de
sélection/filtres/actions groupées des deux côtés.

Seuls changent, selon le mode :
- **Source des lignes** : Génération lit le `Preview` (calcul pur, rien en base) ; Modification
  lit les vraies `Mission` de la `PlanningVersion` éditée (`GET /api/missions?planningVersionId=`),
  adaptées dans la même forme `PreviewLineV2` — l'utilisateur retrouve exactement son planning.
- **Palette et libellés** : bleu/"Générer"/"Déployer" en Génération, ambre/"Modifier"/"Redéployer"
  en Modification. Les couleurs sémantiques de statut (couvert/non couvert/conflit) ne changent
  jamais avec le mode.
- **Action de soumission** : Génération passe par `preview()` → `generate()` → `deploy()`
  (nouveau cycle, missions DRAFT). Modification envoie le lot d'édits à
  `POST /api/planning/versions/{id}/apply-modifications`, qui mute les `Mission` existantes
  directement via `MissionPostDeployService` (D-052/D-056 — jamais un nouveau cycle
  generate/deploy pour une correction opérationnelle).

Deux points d'entrée ouvrent le mode Modification dans le même composant : une ligne
"Modifier" dans l'historique des plannings, ou un clic sur un chip de mois déjà généré.
Voir `docs/planning-v2-architecture-freeze.md` §L9 et `docs/planning-v2-roadmap.md` Batch 15K.

---

### Éditeur de gabarit — UX clé

- **Timeline par jour** : accordéon par jour, timeline Google Calendar–style (6h–22h), clic sur le fond pour créer un créneau à l'heure cliquée
- **Samedi/Dimanche masqués** par défaut — toggle pour les afficher (auto-révélés si des slots existent le week-end)
- **Inline title edit** : clic sur l'icône crayon → TextField → validation optimiste (UI mise à jour immédiatement, rollback sur erreur API)
- **Doublon** : même instrumentiste sur deux créneaux qui se chevauchent → bordure orange + chip "Doublon"
- **Clic pour éditer** : clic sur une carte de slot ouvre le dialog d'édition pré-rempli
- **Autocomplete chirurgien / instrumentiste** : les champs Chirurgien et Instrumentiste dans `SlotDialog` utilisent `<Autocomplete>` MUI — l'utilisateur peut taper pour filtrer la liste en temps réel ; l'instrumentiste est optionnel (bouton ✕ pour effacer)
- **Couleurs par chirurgien** : chaque chirurgien reçoit une couleur déterministe (`surgeonId % 10` sur une palette de 10 paires) appliquée à tous ses `SlotBlock` quelle que soit la colonne jour — identique sur Lundi, Mercredi, Vendredi etc.
- **Doublon instrumentiste** : `DayTimeline` calcule via `React.useMemo` quels instrumentistes apparaissent sur des créneaux qui se chevauchent (`overlaps()`). Les `SlotBlock` concernés reçoivent `isDuplicate=true` → fond orange, outline orange, badge "Doublon"
- **Raccourcis période** : le `SlotDialog` propose deux boutons "Matin (08h–12h)" / "Après-midi (12h–17h)" qui pré-remplissent les champs Début/Fin ; le bouton actif s'affiche en `contained`

---

### Flux planning V2 — postes récurrents (Batches 1–13)

**Planning V2 est désormais l'interface planning officielle des managers (cutover UI,
Batch 13, voir D-048 dans `docs/decisions.md`).** Le menu latéral "Planning" et le chemin
nu `/app/m/planning` pointent vers `/app/m/planning/v2`. La section 7 ci-dessus
(`PlanningTemplate`/`PlanningSlot`, parité PAIR/IMPAIR/TOUTES) **n'est pas supprimée** —
son code et ses routes restent actifs, atteignables par URL directe uniquement, comme
filet de repli pendant la période de rodage. Voir `docs/planning-v2-architecture-freeze.md`
pour la stratégie de suppression définitive de V1 (flag par site, critère de sortie —
toujours non implémentée, seule la bascule UI manager est faite).

**Modèle** : `SurgeonSchedulePost` (poste récurrent d'un chirurgien, rattaché à un site
obligatoire) porte une `RecurrenceRule` embarquée (`frequency` WEEKLY/MONTHLY,
`interval`, `weekdays`, `anchorDate`, `monthlyNthWeekday`) qui généralise PAIR/IMPAIR
(`interval=2` avec une phase arbitraire, pas seulement la parité ISO calendaire) et
ajoute des récurrences que V1 ne pouvait pas exprimer (toutes les 3 semaines, jours
spécifiques, mensuel). Les horaires viennent de `ShiftPeriodConfig` (MATIN/APRES_MIDI/
JOURNEE configurables par site), jamais codés en dur sur le poste.

```
Manager → définit SurgeonSchedulePosts (site obligatoire, période, récurrence, instrumentiste optionnel)
        → configure ShiftPeriodConfig par site si besoin (sinon, valeurs par défaut migrées depuis V1)

        ① POST /api/planning/v2/preview   { siteId|siteGroupId, year, month }
             → lignes COVERED/UNCOVERED/SKIPPED/CONFLICT/MODIFIED + résumé agrégé

        ② POST /api/planning/v2/generate  — même body — crée PlanningVersion (DRAFT) + Missions (DRAFT)
             → rejet explicite (409) si un brouillon non déployé existe déjà pour la même période/site

        ③ POST /api/planning/v2/deploy    { planningVersionId, sendPdf }
             → réutilise PlanningDeploymentService SANS AUCUNE logique V2-spécifique
```

**Réutilisation confirmée à 100 %** (audité Batch 8, vérifié à nouveau Batch 9) :
`PlanningVersion`, `Mission`, `PlanningDeploymentService`, `PlanningDiffService`,
`PdfService` et tous les templates PDF (`planning_global/instrumentist/surgeon.html.twig`)
ne référencent **jamais** `PlanningTemplate`/`PlanningSlot`/PAIR/IMPAIR/TOUTES — le
cutover (non implémenté) n'aura jamais qu'à changer quel générateur produit les
`Mission`, rien en aval.

**Alertes (Batch 3–5)** : `PlanningAlert` détecte l'impact d'une absence sur des
missions déjà générées/publiées (`AbsenceImpactService`), jamais avant. Types
implémentés : `SURGEON_ABSENCE`, `INSTRUMENTIST_ABSENCE`, `REASSIGNMENT_REQUIRED`,
`OCCURRENCE_CANCELLED`. `SURGEON_CONFLICT`/`INSTRUMENTIST_CONFLICT` sont définis mais
pas encore déclenchés — le détecteur de conflit actuel (preview, en mémoire) est scopé
au site/groupe d'un seul appel de génération ; un instrumentiste multi-site doublement
réservé via deux générations séparées sur des sites différents n'est jamais détecté
aujourd'hui (gap documenté dans le freeze §G, pas corrigé).

**Notifications (Batch 7)** : `PlanningAlertRaisedMessage` (Messenger, routé `async`)
fan-out vers manager/admin + personne concernée, chaque canal (in-app/email/push) gated
par `NotificationPreferenceResolver` — jamais codé en dur. Une seule granularité
(`NotificationType::PLANNING_ALERT`) existe aujourd'hui ; conçu pour évoluer sans
changement d'architecture (voir freeze §H).

**Bug corrigé Batch 9** (service partagé V1/V2) : `PlanningDeploymentService::deploy()`
appelait `$em->clear(Mission::class)` — argument ignoré silencieusement par Doctrine
ORM 3.x (`clear()` n'accepte plus de paramètre et vide tout l'identity map), détachant
la `PlanningVersion` avant son `flush()` final et perdant son passage à `ACTIVE`. Trouvé
par le premier test fonctionnel (EntityManager réel, pas un mock) à exercer ce chemin.
Corrigé par un `flush()` immédiat après l'activation — aucun changement de comportement
métier, V1 et V2 en bénéficient également.

**Frontend (Batches 10–12) puis cutover UI (Batch 13)** : module React/MUI dédié à 4
onglets (Postes / Générer / Alertes / Paramètres), design system propre (`theme/tokens.ts`,
palette bleu médical distincte du vert mobile — vert réservé aux statuts OK/résolu),
`SearchableSelect` (combobox accessible réutilisé partout). Deux décisions de lancement
notables (Batch 13) :
- **Récurrences mensuelles non exposées** dans le formulaire de poste — la branche
  `MONTHLY`+`monthlyNthWeekday` de `PlanningGeneratorServiceV2::isOccurrenceActive()`
  n'a aucune couverture de test sur l'expansion de récurrence (seule la validation de
  saisie est testée) ; le code reste en place côté backend et frontend
  (`recurrencePresets.ts`), mais seules les récurrences validées (hebdomadaire,
  paire/impaire, une semaine sur deux, jours sélectionnés) sont proposées à la création.
- **"Fin de poste proche" vit dans l'onglet Postes, pas Alertes** — ce n'est pas une
  `PlanningAlert` (calcul de date 100% frontend depuis `SurgeonSchedulePost.endDate`,
  aucune entité backend), donc jamais mélangée visuellement avec les vraies alertes qui
  exigent une action serveur.

---

## 8. Variables d'environnement frontend

| Variable | Usage |
|---|---|
| `VITE_API_BASE_URL` | Base URL du backend (ex: `http://localhost`) |

Les URLs de fichiers uploadés sont construites comme `VITE_API_BASE_URL + profilePicturePath`.
