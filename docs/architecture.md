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
PATCH /api/users/{id}/email
```


### Stockage des dates métier — convention timezone (D-066)

`Mission.startAt`/`Mission.endAt` sont mappés sur un type Doctrine sur mesure,
`business_datetime_immutable` (`App\Doctrine\Type\BusinessDateTimeImmutableType`), et
non le type intégré `datetime_immutable` — **c'est la seule différence** : la colonne
SQL reste un `DATETIME` MySQL classique, aucune migration n'est requise pour l'utiliser.

**Pourquoi** : une colonne `datetime_immutable` classique reçoit une chaîne brute
`Y-m-d H:i:s` sans information de fuseau (MySQL `DATETIME` ne stocke pas d'offset).
`date.timezone` du conteneur PHP étant `UTC`, Doctrine hydrate cette chaîne comme si elle
représentait un instant UTC — alors qu'en pratique ces chiffres représentent l'heure
locale de Bruxelles. Résultat : `format(ATOM)` expose un faux `+00:00` au lieu du vrai
`+01:00`/`+02:00`. Voir `docs/decisions.md` D-065 (diagnostic complet, audit des 9
endroits affectés) et D-066 (la correction structurelle elle-même).

**Comportement du type** :
- **Lecture** : le cadran stocké est réétiqueté `Europe/Brussels` (jamais UTC) — les
  chiffres ne bougent jamais, seule l'interprétation du fuseau change.
- **Écriture** : la valeur PHP reçue (quel que soit son offset d'origine) est
  **convertie** vers son équivalent réel en `Europe/Brussels` avant d'être stockée sous
  forme de cadran local sans offset. Un `DateTimeImmutable` client soumis en `+02:00` et
  un autre soumis en `+00:00` représentant le même instant réel produisent **la même**
  valeur stockée.

**Règle de construction obligatoire pour tout code interne** (générateurs de planning,
scripts, imports…) qui construit un `Mission.startAt`/`endAt` sans passer par une
requête HTTP client : toujours fournir un fuseau explicite —

```php
// ❌ Ne jamais faire — naïf, implicitement étiqueté UTC par le conteneur, sera
//    décalé de l'offset DST à l'écriture (converti comme si c'était un instant UTC réel).
$day = new \DateTimeImmutable($dateString);

// ✅ Toujours faire — l'intention (cadran Bruxelles) est explicite, le type n'a
//    rien à convertir.
$day = new \DateTimeImmutable(
    $dateString,
    new \DateTimeZone(App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE),
);
```

`PlanningGeneratorService`/`PlanningGeneratorServiceV2` (génération à partir des
gabarits) suivent cette convention. Un test d'architecture,
`tests/Architecture/BusinessDateTimeColumnConventionTest.php`, scanne toutes les
entités par réflexion et échoue si une nouvelle colonne `DateTimeImmutable` métier est
ajoutée sans décision explicite (soit `business_datetime_immutable`, soit une entrée
d'allowlist justifiée pour les colonnes date-only ou toujours `new
\DateTimeImmutable()` côté serveur) — voir D-066 pour le détail.

**Périmètre volontairement restreint à `Mission.startAt`/`endAt`** : c'est la seule
colonne du schéma qui reçoit aujourd'hui des valeurs client avec un offset réel et
significatif (déclaration de mission, création/modification standard). Les autres
colonnes candidates (`Absence.dateStart/dateEnd`, `PlanningVersion.periodStart/periodEnd`,
etc.) sont toutes date-only (`Y-m-d`, jamais d'heure ni d'offset) — voir l'allowlist
commentée du test d'architecture pour la justification colonne par colonne.

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
- `GET /api/instrumentists`, `GET /api/surgeons` (listes manager) : `profilePicturePath` est le chemin **relatif** brut (`getProfilePicturePath()` sans transformation) ; le frontend construit l'URL complète lui-même (`VITE_API_BASE_URL + profilePicturePath`, `buildProfilePictureUrl()` dans `manager-instrumentists/utils/instrumentists.utils.ts`, réutilisé par `InstrumentistDrawer`/`SurgeonDrawer` et par les deux `DataGrid` des listes, jamais recréé).

### Modification sécurisée de l'adresse email (D-063)

`PATCH /api/users/{id}/email` (`UserController`, générique — jamais dupliqué dans
`InstrumentistController`/`SurgeonController`, l'email appartenant au même agrégat
`User` quel que soit le rôle) — RBAC via `UserAdministrationVoter::UPDATE_EMAIL`
(MANAGER ou ADMIN, distinct de `UPDATE` qui reste ADMIN-only pour `/api/admin/users`).
Logique intégralement dans `UserEmailChangeService` : validation → mutation → audit
(`UserAuditEventType::USER_EMAIL_CHANGED`) → `flush()` → dispatch de deux
`SendTemplatedEmailMessage` indépendants (ancienne puis nouvelle adresse), chacun catché
séparément (`warnings[]` dans la réponse, jamais un échec de la requête). Emails envoyés
même à un compte suspendu — leur objet est la sécurité du compte, jamais gaté par
`NotificationPreference`.

**Risque JWT documenté (non corrigé, comportement voulu)** : le provider Doctrine
(`security.yaml`) charge l'utilisateur par `email`, et le firewall `api` recharge
l'utilisateur à chaque requête via le claim `username` du JWT (figé à l'ancienne adresse
au moment de l'émission) — après un changement d'email, la session en cours et son
refresh token (`RefreshToken.username`, même provider) cessent de fonctionner au prochain
appel, forçant une reconnexion. Conséquence structurelle du provider (même mécanisme que
la suspension via `UserChecker`), jamais une invalidation codée en dur — volontairement
non contournée. **Risque Google OAuth documenté (non corrigé)** :
`AuthGoogleController` retrouve l'utilisateur par email Google réel ; `User::$googleId`
n'est jamais renseigné en pratique, donc une divergence entre la nouvelle adresse et
l'email Google réel peut créer un compte dupliqué à la prochaine connexion Google — voir
D-063 dans `docs/decisions.md` pour le détail complet.

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
├── firm → Firm (obligatoire, immuable dès qu'une MaterialLine réelle existe)
├── label, referenceCode (unique par firme), unit, active: bool
└── isImplant: bool (information médicale pure — sans rôle financier, voir D-067)

InterventionType (Lot 1 — référentiel médical fermé)
├── id, code (unique, immuable), label
├── specialty (nullable)
└── active: bool

FirmServiceOffering ("Prestation" à l'écran — Lot 1)
├── firm → Firm
├── interventionType → InterventionType
├── UNIQUE(firm, interventionType)
├── label (nullable), active: bool
└── SuggestedMaterial[] (ordonnée par displayOrder)
    — jamais lue par le moteur financier (invariant D-067) : accélère la saisie
      manager uniquement, aucune donnée facturante n'y est stockée.

SuggestedMaterial (Lot 1)
├── firmServiceOffering → FirmServiceOffering
├── materialItem → MaterialItem (même firme, garanti par FK composée en base)
└── displayOrder

Mission
├── id, status, type, schedulePrecision
├── startAt, endAt
├── site → Hospital
├── surgeon → User
├── instrumentist → User (nullable)
├── submittedAt (nullable — "encodage terminé" instrumentiste, ne verrouille pas)
├── encodingStartedAt (nullable — Lot 7, D-070 : instant du dernier POST .../encoding/start)
├── encodingLockedAt (nullable — Lot 7 : non-null ⇔ status VALIDATED, seul reopen() le remet à null)
├── invoiceGeneratedAt (nullable — verrou définitif, hors périmètre Lot 7)
├── allowedActions[] (calculé dynamiquement)
├── MissionIntervention[]
├── MissionEncodingComment[] (Lot 7 — commentaires manager reject/reopen, historisés)
└── execution → MissionExecution (nullable — EPIC Exécution & Valorisation, Lot 1, D-071 : le RÉALISÉ, absent tant que rien n'a été déclaré)

MissionEncodingComment (Lot 7, D-070)
├── mission → Mission
├── author → User (toujours un manager/admin — reject/reopen)
├── comment (text, obligatoire au reject, optionnel au reopen)
└── createdAt (TimestampableTrait) — jamais mis à jour, une ligne par reject/reopen

MissionExecution (EPIC Exécution & Valorisation, Lot 1, D-071 — le RÉALISÉ)
├── mission → Mission (1—0..1, UNIQUE — remplace InstrumentistService, renommage de
│   table, pas une nouvelle table + copie)
├── actualStartAt, actualEndAt (nullable, business_datetime_immutable — D-066 : peuvent
│   être soumis par un client, jamais un simple now() serveur)
├── actualDurationMinutes (nullable — toujours dérivée de actualStartAt/actualEndAt
│   quand les deux sont connus ; sinon durée explicite déclarée seule)
├── hoursSource (nullable — INSTRUMENTIST | MANAGER | SYSTEM, qui a déterminé le réalisé)
└── MissionExecutionDispute[] — jamais de montant, tarif, ou statut financier ici (voir
    docs/decisions.md D-071 pour la séparation planifié/réalisé/valorisation)

MissionExecutionDispute (EPIC Exécution & Valorisation, Lot 1, D-071)
├── mission → Mission · missionExecution → MissionExecution
├── raisedBy → User (le chirurgien concerné par la mission, jamais un autre acteur)
├── reasonCode (DURATION_INCOHERENT | WRONG_DATE | DUPLICATE | OTHER), comment (nullable)
├── status (OPEN | IN_REVIEW | RESOLVED | REJECTED) — une seule OPEN à la fois par MissionExecution
└── resolutionComment (nullable, renseigné par le manager)

MissionIntervention
├── code, label (instantané figé à la création, copié depuis interventionType — Lot 5,
│   jamais recalculé si le type est renommé/désactivé ensuite ; seule source de vérité
│   pour les lignes historiques pré-Lot 5 non mappées, ex: mission #529)
├── interventionType → InterventionType (nullable — null uniquement pour les lignes
│   pré-Lot 5 ; obligatoire pour tout nouvel encodage, imposé par InterventionService,
│   pas par une contrainte NOT NULL en base — voir D-068)
├── primaryFirm → Firm (nullable, toujours facultative)
├── MaterialLine[]
└── MaterialItemRequest[]

InterventionTypeRequest (Lot 5, D-068 — miroir de MaterialItemRequest)
├── mission → Mission (pas de missionIntervention : la demande précède toujours la
│   création de l'intervention, aucune ne peut exister sans type valide)
├── label, suggestedCode (nullable), comment (nullable)
├── status: 'PENDING' | 'RESOLVED' | 'IGNORED'
├── resolvedInterventionType → InterventionType (nullable, renseigné à la résolution)
└── createdBy → User

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
├── ruleType: 'INTERVENTION_FEE' | 'MATERIAL_FEE' (renommé depuis IMPLANT_FEE, Lot 1)
├── interventionType → InterventionType (nullable — Lot 1, remplace interventionCode texte libre)
├── materialItem → MaterialItem (nullable)
├── currency (défaut EUR)
├── validFrom (nullable — null = "valide depuis toujours", legacy D-067, conservé) /
│   validTo (nullable = borne ouverte) — INCLUSIF/EXCLUSIF depuis D-072 (Lot 2, voir
│   PricingRule::coversDate()/overlapsWith())
├── append-only depuis D-072 : jamais réécrite en place une fois validFrom <= aujourd'hui
│   — seul PricingRuleVersioningService peut muter (jamais un contrôleur directement)
└── anti-chevauchement bloquant à l'écriture sur (firm, ruleType, cible) — voir PricingRuleResolver

InstrumentistRate (EPIC Exécution & Valorisation, Lot 2, D-072)
├── instrumentist → User · rateType: 'HOURLY_RATE' | 'CONSULTATION_FEE'
├── amount, currency (défaut EUR)
├── validFrom (NOT NULL, contrairement à PricingRule — table neuve, aucune donnée
│   historique à préserver, donc aucune raison de reproduire l'ambiguïté "null =
│   toujours") / validTo (nullable = ouvert), même convention INCLUSIF/EXCLUSIF
├── append-only, même discipline que PricingRule — seul InstrumentistRateService écrit
├── remplace progressivement User.hourlyRate/consultationFee comme source de vérité
│   financière — ces deux champs restent en place (compatibilité legacy explicite,
│   endpoint PATCH /api/instrumentists/{id}/rates inchangé dans ce lot) mais aucun
│   nouveau code financier ne doit plus s'y brancher
└── ne contient jamais de durée ni de montant calculé — uniquement la règle tarifaire

FirmInvoice
├── firm, number (FIRM-YYYY-NNN), status (DRAFT|GENERATED|SENT|PAID)
├── periodStart, periodEnd, totalAmount
├── billingEmailTo (snapshot), billingEmailCc (snapshot JSON)
└── FirmInvoiceLine[]

FirmInvoiceLine
├── invoice, mission, lineType (INTERVENTION_FEE|MATERIAL_FEE)
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

### Flux catalogue financier — prestations firmes (Lot 1, voir D-067)

```
Manager → InterventionType (référentiel médical fermé, indépendant des firmes)
        → Firm.pricing-rules / service-offerings (par firme)

Prestation ("FirmServiceOffering") = firm + interventionType (UNIQUE)
  ├── matériels suggérés (SuggestedMaterial, ordonnés) — accélèrent l'encodage, ne le limitent jamais
  └── forfait éventuel — une PricingRule INTERVENTION_FEE indépendante, jamais rattachée à la prestation

Moteur financier (PricingRuleResolver) :
  MissionIntervention.interventionTypeId + .primaryFirmId  →  PricingRule(INTERVENTION_FEE)
  MaterialLine.materialItemId                              →  PricingRule(MATERIAL_FEE)
  (ne lit jamais FirmServiceOffering ni SuggestedMaterial — invariant vérifié par test)
```

`MissionIntervention.interventionType`/`.primaryFirm` sont implémentés depuis le Lot 5
(D-068) — voir flux dédié ci-dessous. Le rebranchement de `FirmInvoiceService` sur cette
relation directe (au lieu du rapprochement par convention de code partagée,
`InterventionType.code === MissionIntervention.code`, inchangé dans ce lot) appartient
au Lot 7.

### Flux encodage — rattachement au catalogue fermé (Lot 5, D-068)

```
Instrumentiste → encodage → sélectionne un InterventionType actif (+ firme principale, optionnel)
              → POST /api/missions/{id}/interventions {interventionTypeId, primaryFirmId?}
              → code/label dérivés côté serveur (instantané), jamais fournis par le client

Type introuvable → "Faire une demande au manager"
                 → POST /api/missions/{id}/intervention-type-requests (PENDING, pas de MissionIntervention créée)

Manager → GET /api/intervention-type-requests?status=PENDING
        → [Créer/choisir un type] → POST /api/intervention-types (si besoin)
                                   → POST /api/intervention-type-requests/{id}/resolve (interventionTypeId, primaryFirmId?)
                                   → status=RESOLVED + MissionIntervention créée sur la mission d'origine
        ou [Ignorer]              → POST /api/intervention-type-requests/{id}/ignore → status=IGNORED
```

### Flux encodage intelligent — le catalogue pilote l'encodage (Lot 6, D-069)

Toute l'intelligence (résolution de firme, matériels suggérés, signaux de cohérence)
reste côté backend — le frontend affiche/sélectionne/valide, il ne recalcule rien.
`suggestedMaterials`/`coherence` sont des champs **calculés à la lecture**, jamais
persistés sur `MissionIntervention` (pas de colonne, pas de migration) : ils dérivent de
`(interventionType, primaryFirm)` au moment de la requête, donc toujours à jour même si
la configuration change entre deux lectures.

```
Instrumentiste → sélectionne un InterventionType
              → GET /api/intervention-types/{id}/encoding-context
                 → offerings actives (toutes firmes) + matériels suggérés par offering
                 → suggestedPrimaryFirm non nul SEULEMENT si une seule offering active
                   (aucune ambiguïté à deviner sinon)
              → POST /api/missions/{id}/interventions (inchangé, Lot 5)

GET /api/missions/{id}/encoding → chaque intervention porte désormais :
  suggestedMaterials  = matériels de l'offering (interventionType, primaryFirm) de CETTE intervention
  coherence           = { hasNoMaterialLines, unusedSuggestedMaterialItemIds,
                           unexpectedMaterialItemIds, materialLineIdsFromOtherFirm }
                         — informationnel uniquement, ne bloque jamais l'encodage ;
                           prépare de futurs contrôles qualité manager (UX pas encore développée)

Recherche matériel : un seul point d'entrée, GET /api/material-items/quick-search
  (logique dans MaterialCatalogService::quickSearch(), plus de duplication) ; firmId
  optionnel pour scoper à la firme principale déjà connue.

Matériel introuvable : même principe que "demande de nouveau type" — jamais de
  MaterialItem créé directement par l'instrumentiste (MaterialItemRequest, D-016,
  déjà en place et vérifié inchangé par ce lot).
```

**Performance :** `suggestedMaterials`/`coherence` de toutes les interventions d'une
mission sont résolus par **une seule requête groupée** sur les couples
`(interventionType, primaryFirm)` réellement présents (`MissionEncodingService::
loadSuggestedMaterialsByTypeAndFirm()`), jamais une requête par intervention.

**Compatibilité :** une intervention pré-Lot 5 (`interventionType = null`, ex: mission
#529) reçoit `suggestedMaterials: []` et un `coherence` toujours valide — jamais
d'erreur, jamais de donnée devinée.

### Flux workflow de l'encodage — cycle de vie métier (Lot 7, D-070)

Avant ce lot, une mission restait modifiable tant qu'elle était "ouverte" — insuffisant
pour la facturation, qui a besoin d'un état *définitif*. Le cycle de vie de l'encodage
introduit une distinction stricte entre "l'instrumentiste dit avoir fini" (`SUBMITTED`,
ne verrouille rien) et "le manager a contrôlé et figé" (`VALIDATED`, verrouille tout).

```
ASSIGNED ──┐
           ├──start()────▶ ENCODING_IN_PROGRESS ──complete()────▶ SUBMITTED
IN_PROGRESS┘                        ▲                                 │
                                     │                      ┌─validate()──┐
                          reject()   │                      │             │
                    (commentaire     │                      ▼             ▼
                     obligatoire) ◀──┴──────────────── (refus)      VALIDATED
                                                                          │
                                                                   reopen()
                                                              (commentaire optionnel)
                                                                          │
                                                                          ▼
                                                              ENCODING_IN_PROGRESS
```

**Pourquoi pas un statut linéaire strict :** `start()` est une invite optionnelle, pas un
préalable obligatoire — `complete()` reste atteignable directement depuis `ASSIGNED`,
`IN_PROGRESS` ou `DECLARED` (comportement préexistant conservé à l'identique, liberté
instrumentiste établie avant ce lot). `POST /api/missions/{id}/submit` (legacy) et
`POST /api/missions/{id}/encoding/complete` (Lot 7) sont donc **le même point d'entrée
métier** — `MissionEncodingWorkflowService::complete()` — jamais deux implémentations.

**Endpoints** (`MissionEncodingWorkflowController`, préfixe `/api/missions/{id}/encoding`) :

| Endpoint | Transition | Acteur |
|---|---|---|
| `POST .../start` | `ASSIGNED\|IN_PROGRESS → ENCODING_IN_PROGRESS` | Instrumentiste assigné |
| `POST .../complete` | `DECLARED\|ASSIGNED\|IN_PROGRESS\|ENCODING_IN_PROGRESS\|SUBMITTED → SUBMITTED` | Instrumentiste assigné |
| `POST .../validate` | `SUBMITTED → VALIDATED` (verrouille — `encodingLockedAt`) | Manager/Admin |
| `POST .../reject` | `SUBMITTED → ENCODING_IN_PROGRESS`, commentaire **obligatoire** | Manager/Admin |
| `POST .../reopen` | `VALIDATED → ENCODING_IN_PROGRESS`, commentaire optionnel, déverrouille | Manager/Admin |

**Verrouillage — le backend est l'unique garant :** `MissionEncodingGuard` (partagé avec
les endpoints d'écriture Lot 5/6) bloque toute mutation dès que `encodingLockedAt` ou
`invoiceGeneratedAt` est non-null, quel que soit l'acteur. Seul `reopen()` remet
`encodingLockedAt` à `null` — c'est le seul chemin qui le fait. `CLOSED` est un statut
terminal : `reopen()` le rejette explicitement (`ConflictHttpException` dédiée, jamais un
message générique de "mauvais état"). Le frontend ne décide jamais — il reflète
`allowedActions[]`.

**Commentaires manager (`MissionEncodingComment`) :** un reject/reopen avec commentaire
crée une **nouvelle ligne**, jamais une mise à jour d'un champ unique — rien n'est jamais
écrasé ni perdu. Distinct de l'`AuditEvent` de la même transition : l'AuditEvent trace
*que* la transition a eu lieu (fait technique) ; le commentaire porte le *contenu* métier
(matériel manquant, quantité incorrecte, mauvaise firme, intervention incomplète…), pensé
pour être lu par un manager. Exposé dans `GET .../encoding` (`encodingComments[]`).

**Contrôles de cohérence (`coherenceSummary`) :** agrégation mission-level, en mémoire,
des signaux déjà calculés par intervention (Lot 6, D-069) — aucune requête
supplémentaire. Purement informationnel, ne bloque jamais une transition : sert
uniquement au manager pour décider lui-même de valider/refuser.

**Audit — une transition, un événement, jamais sans trace :**
`MISSION_ENCODING_STARTED` / `_COMPLETED` / `_VALIDATED` / `_REJECTED` / `_REOPENED`,
tous écrits par `AuditService::record()` avant le `flush()` (R-05), donc jamais un statut
muté sans audit correspondant.

**Notifications — événement préparé, pas branché :** chaque transition dispatche
`MissionLifecycleChangedMessage` avec un nouveau `MissionChangeType`
(`ENCODING_STARTED`/`_COMPLETED`/`_VALIDATED`/`_REJECTED`/`_REOPENED`, D-056). Le handler
existant (`MissionLifecycleChangedMessageHandler`) les reçoit tous via sa branche par
défaut documentée "unhandled changeType — forward-compatible skip" : le mécanisme
d'instrumentiste-notifié-au-reopen est prêt, aucune notification réelle n'est câblée dans
ce lot (délibéré — l'UX/le contenu des notifications sera décidé avec les maquettes).

**Point d'entrée facturation (Lot 8+, non implémenté ici) :**
`MissionEncodingWorkflowService::isBillable(Mission $mission): bool` — vrai uniquement
si `status === VALIDATED`. `findMissionsReadyForBilling(): Mission[]` — la seule requête
que le futur moteur financier doit poser. Aucune autre logique de facturation n'existe
dans ce service : le découpage garde la validation opérationnelle et le calcul financier
strictement séparés (une facture ne se génère jamais sur une mission "juste encodée").

**Permissions (`MissionVoter`) :** `ENCODING_START` est **instrumentiste-only** — un
manager n'a jamais besoin de "démarrer" l'encodage de quelqu'un d'autre (spec : "manager
consulte / valide / refuse / rouvre", jamais "démarre"). Le Voter et le service imposent
le **même jeu de statuts autorisés** pour chaque transition (défense en profondeur) : un
appel HTTP hors statut est donc toujours refusé au niveau du Voter (`403`), jamais du
service (`409`) — ce dernier ne reste atteignable qu'en cas d'appel direct au service
(tests unitaires, futur appelant interne).

### Flux exécution & valorisation — le réalisé (EPIC Exécution & Valorisation, Lot 1, D-071)

Trois réalités distinctes, jamais fusionnées dans une seule entité :

```
Mission            — le PLANIFIÉ + le cycle de vie du processus (statut, encodage Lot 7)
      │
MissionExecution   — le RÉALISÉ : heures réelles, source, contestations. Aucun montant,
      │                aucun tarif, aucune règle financière, aucun statut financier.
      ▼
FinancialCalculation — la VALORISATION FINANCIÈRE (non implémentée dans ce lot — fondation
                        structurelle uniquement, voir resolveEffectiveDuration() ci-dessous)
```

**MissionExecution remplace InstrumentistService** — renommage de table + colonnes
(migration `Version20260718065425`), jamais une nouvelle table + copie. Relation
`Mission` 1 — 0..1 `MissionExecution` : une mission peut ne pas encore avoir de réalisé
déclaré. Nommage délibérément non-ambigu (`actualStartAt`/`actualEndAt`/
`actualDurationMinutes`, jamais `startAt`/`endAt`/`hours`) pour ne jamais pouvoir être
confondu avec le planifié — voir docs/decisions.md D-071 pour l'analyse complète ayant
mené à cette séparation (challengée et tranchée sur deux tours : d'abord "migrer vers
Mission ?", puis "à quel niveau exactement ?").

**Règle de résolution du réalisé — `MissionExecutionService::resolveEffectiveDuration()` :**
centralisée, déterministe, seul point que le futur moteur financier devra appeler.

```
MissionExecution existe ?
  ├── actualStartAt ET actualEndAt renseignés → durée = end - start (ACTUAL_TIMES)
  ├── sinon, actualDurationMinutes renseigné  → durée = cette valeur (ACTUAL_EXPLICIT)
  └── sinon                                    → repli sur Mission.startAt/endAt (PLANNED)
MissionExecution absente                       → repli direct sur Mission.startAt/endAt (PLANNED)
```

**Cohérence actualStartAt/actualEndAt/actualDurationMinutes** — une seule règle,
appliquée par `MissionExecutionService::updateActuals()`, jamais côté frontend : si les
deux horaires réels sont fournis (dans l'état résultant, pas seulement dans la requête
courante), la durée est **toujours dérivée** des deux — jamais deux sources de vérité.
Fournir les deux horaires ET une durée explicite contradictoire est un `422`, jamais une
acceptation silencieuse. Fournir un seul horaire sans l'autre est également un `422` (un
horaire seul ne décrit aucune durée).

**Contestations (`MissionExecutionDispute`, ex-`ServiceHoursDispute`)** — workflow
inchangé : le chirurgien concerné ouvre, le manager traite et résout. Une seule
contestation `OPEN` à la fois par `MissionExecution` (contrainte unique en base +
vérifiée en code).

**Endpoints legacy conservés** (`ServiceController`, `PATCH /api/missions/{id}/service`,
`POST /api/services/{id}/disputes`, `GET`/`PATCH /api/disputes`) — mêmes URLs, même
forme de payload, désormais délégués à `MissionExecutionService`. `hours` (décimal,
legacy) converti en `actualDurationMinutes` (entier) à l'entrée ; `consultationFeeApplied`/
`status` acceptés sans erreur mais ignorés (champs financiers morts retirés par ce lot).
Nouveaux endpoints additifs `GET`/`PATCH /api/missions/{id}/execution` — la forme cible.

**Correction de sécurité au passage** : l'ancien flux créait une `InstrumentistService`
vide *avant* de vérifier la permission (`findOrCreateService()` appelé avant
`denyAccessUnlessGranted()`). `MissionExecutionVoter::UPDATE`/`VIEW` sont désormais
évalués sur `Mission` — la permission est vérifiée avant toute création paresseuse.

**Champs financiers morts supprimés** (vérifié : aucun chemin de production n'en
dépendait) : `serviceType`, `employmentTypeSnapshot`, `consultationFeeApplied`,
`computedAmount`, statut financier `CALCULATED`/`APPROVED`/`PAID`. Ce dernier était un
vestige d'une tentative antérieure de construire ce que `FinancialCalculation`
construira réellement — jamais piloté ni lu par aucun code de production.

**Hors périmètre de ce lot** (fondation structurelle uniquement) : `FinancialCalculation`
elle-même, tout montant, tout tarif, la bascule de `InstrumentistStatementService`/
`FirmInvoiceService` vers ce nouveau modèle.

### Flux historisation des tarifs (EPIC Exécution & Valorisation, Lot 2, D-072)

Objectif du lot : garantir qu'une modification future d'un tarif ne puisse jamais
modifier rétroactivement la valeur financière d'une mission déjà calculée. Répond de
façon déterministe à "quel tarif était applicable à cette date, pour cette
firme/instrumentiste/intervention/matériel ?" — sans encore construire
`FinancialCalculation` (Lot 3+).

**Append-only — le principe non négociable :** un tarif dont `validFrom` est déjà
atteint (aujourd'hui ou passé) n'est **jamais** réécrit en place — ni le montant, ni la
devise, ni le périmètre. La seule façon de "changer un tarif actif" est
`replaceCurrentRuleFrom()`/`replaceCurrentRateFrom()` : ferme l'ancienne période
(`validTo = effectiveFrom`) et ouvre une nouvelle règle, dans **une seule transaction**
— jamais d'état intermédiaire avec deux règles ouvertes, aucune règle active, ou un
chevauchement.

```
Tarif actuel : 250 EUR, validFrom=2026-01-01, validTo=null
Remplacement : 275 EUR à partir du 2026-08-01
  →  ancienne règle : validFrom=2026-01-01, validTo=2026-08-01 (montant inchangé : 250)
     nouvelle règle : validFrom=2026-08-01, validTo=null, amount=275
Le 1er août 2026 n'utilise QUE la nouvelle règle (validTo EXCLUSIF, voir ci-dessous).
```

**Convention temporelle centralisée** : `validFrom` INCLUSIF, `validTo` EXCLUSIF — le
jour `validTo` lui-même n'appartient déjà plus à cette règle, il appartient à la
suivante. Implémentée une seule fois dans `PricingRule::coversDate()`/`overlapsWith()`
et `InstrumentistRate::coversDate()`/`overlapsWith()` (miroirs exacts). C'est un
changement de comportement assumé par rapport à Lot 1/D-067 (qui traitait `validTo`
comme inclusif) — les tests existants ont été mis à jour en conséquence, jamais
silencieusement laissés à décrire l'ancien comportement.

**Opérations métier disponibles** (`PricingRuleVersioningService`/
`InstrumentistRateService`, seuls points d'écriture — les contrôleurs ne mutent jamais
l'entité directement) :

| Opération | Quand | Ce qu'elle permet |
|---|---|---|
| `createInitialRule()`/`createInitialRate()` | Première règle sur une cible | Toute date (passée documentée, aujourd'hui, future) |
| `scheduleRule()`/`scheduleRate()` | Pré-provisionner avant une entrée en vigueur | `validFrom` strictement future exigée |
| `replaceCurrentRuleFrom()`/`replaceCurrentRateFrom()` | Le cas principal | Ferme l'actuelle + ouvre la nouvelle, atomique |
| `updateFutureRule()`/`updateFutureRate()` | Corriger une erreur de saisie | Seulement si `validFrom` pas encore atteint |
| `cancelFutureRule()`/`cancelFutureRate()` | Annuler avant l'entrée en vigueur | Seule suppression physique légitime |
| `resolveAt()` | Lecture | Date explicite obligatoire, jamais `now()` implicite |

**Immutabilité historique — dès que `validFrom <= aujourd'hui`, la règle appartient à
l'histoire.** "Jamais utilisée" ne suffit pas à autoriser une réécriture rétroactive —
seule la date compte. `PricingRuleWriteService::delete()`/`InstrumentistRateWriteService::
delete()` portent eux-mêmes ce garde-fou (défense en profondeur, pas seulement au niveau
métier) : une suppression physique d'une règle déjà applicable est refusée même en cas
d'appel direct au service bas niveau.

**Résolution par relations métier réelles, jamais par code libre** : une règle
`INTERVENTION_FEE` référence `InterventionType` par FK (pas par
`MissionIntervention.code`, un simple instantané figé à l'affichage) ; une règle
`MATERIAL_FEE` référence `MaterialItem` par FK, dont la firme est celle du catalogue —
une intervention d'une firme peut contenir du matériel d'autres firmes, chaque ligne se
résout indépendamment. `PricingRuleResolver`/`InstrumentistRateResolver` restent
inchangés dans leur signature (déjà conformes : date explicite, jamais `now()`).

**Concurrence** : verrouillage pessimiste déterministe réutilisé tel quel
(`PricingRuleWriteService`, prouvé par `PricingRuleConcurrencyTest` — inchangé) ;
`InstrumentistRateWriteService` en est le miroir exact, verrou posé sur l'instrumentiste
lui-même (seule entité garantie présente avant qu'une `InstrumentistRate` ne puisse
exister). `replaceCurrentRuleFrom()`/`replaceCurrentRateFrom()` orchestrent
`update()`+`create()` dans une transaction imbriquée unique (Doctrine DBAL gère
nativement le nesting via son compteur de transactions — le commit réel n'a lieu qu'à
la sortie de la méthode orchestratrice).

**Audit sans Mission à rattacher** : `AuditEvent.mission` devient nullable (élargissement
de contrainte, aucune donnée existante affectée) — `AuditService::recordGlobal()`
couvre les événements catalogue/tarifaires (`PRICING_RULE_*`, `INSTRUMENTIST_RATE_*`),
`AuditService::record()` reste inchangé pour tout événement de cycle de vie mission.

**Legacy `User.hourlyRate`/`consultationFee`** : conservés tels quels, endpoint
`PATCH /api/instrumentists/{id}/rates` inchangé dans ce lot (autosave manager existant).
Backfill : une première `InstrumentistRate` créée pour chaque utilisateur ayant
actuellement un tarif, `validFrom = DATE(User.createdAt)` (meilleure date métier-
compatible réellement disponible dans le schéma actuel — voir migration
`Version20260718121937` pour la justification complète). Aucun backfill appliqué aux
`PricingRule` existantes : une règle `validFrom = null` est la sémantique D-067 "valide
depuis toujours", délibérée et correcte — pas une donnée ambiguë à deviner.

**Hors périmètre de ce lot** : `FinancialCalculation`, `RemunerationLine`, toute
bascule de `FirmInvoiceService`/`InstrumentistStatementService`, tout paiement, toute
correction financière.

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
| Instrumentiste retiré | Absence instrumentiste → mission `ASSIGNED` libérée | `ABSENCE_INSTRUMENTIST_RELEASED` (email récap + in-app par mission) | `AbsenceMissionsReactedMessageHandler` (D-062) |
| Chirurgien concerné | Absence instrumentiste → mission désormais `OPEN` | `ABSENCE_SURGEON_MISSION_OPENED` (email récap + in-app par mission) | `AbsenceMissionsReactedMessageHandler` (D-062) |
| Instrumentiste concerné | Absence chirurgien → mission annulée | `ABSENCE_MISSION_CANCELLED` (email récap + in-app par mission) | `AbsenceMissionsReactedMessageHandler` (D-062) |

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

**Réaction automatique aux absences (D-062)** : `AbsenceImpactService` conserve
intégralement son contrat "jamais de mutation" — `AbsenceController` appelle en plus,
et **avant**, `App\Service\AbsenceMissionReactionService`, qui mute directement les
missions déjà déployées pour le sous-ensemble non ambigu : instrumentiste absent sur
une mission `ASSIGNED` → libérée (`MissionPostDeployService::release()`, `OPEN`,
instrumentiste retiré) ; chirurgien absent sur une mission `OPEN`/`ASSIGNED` → annulée
(`MissionPostDeployService::cancel()`, étendu pour accepter `ASSIGNED`). `DRAFT`,
`SUBMITTED`, `VALIDATED`, `IN_PROGRESS`, `DECLARED` restent hors périmètre — l'alerte
manuelle existante d'`AbsenceImpactService` continue de les couvrir sans changement.
L'ordre d'appel (mutation avant détection d'alerte) suffit à empêcher toute alerte
obsolète : la requête de chevauchement d'`AbsenceImpactService` exclut naturellement
une mission déjà mutée (FK/statut ne correspondent plus). Jamais de mutation sur
`SurgeonSchedulePost`. Voir D-062 dans `docs/decisions.md` pour le détail complet
(statuts, idempotence, concurrence, notifications).

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
