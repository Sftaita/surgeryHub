# SurgicalHub — Architecture système

_Last updated: 2026-07-25 (v8 — Lot 1 : fiabilisation du socle Web Push, D-081)_

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
├── AbsenceController                   — CRUD /api/absences
├── PlanningVersionController           — GET /api/planning/versions (list) + apply-modifications/
│                                           cancel-all/coverage-summary/history (Planning V2 only —
│                                           show/diff/delete/pdf removed in D-079, see errata)
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

`PlanningGeneratorServiceV2` (génération à partir des postes récurrents — son
prédécesseur V1, `PlanningGeneratorService`, a été supprimé en D-079) suit cette
convention. Un test d'architecture,
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

**Routes manager** (réorganisées en D-079 — sidebar groupée par domaine + Dashboard) :

```
/app/m                            — redirect → /app/m/dashboard
/app/m/dashboard                  — Dashboard (agrège des requêtes déjà existantes, aucun nouvel endpoint)
/app/m/missions                   — liste missions (Tabs "Toutes" / "À valider", pilotées par route)
/app/m/missions/to-validate       — alias conservé (redirige vers l'onglet "À valider") — compat liens/favoris
/app/m/missions/new                — création mission
/app/m/missions/:id                — détail mission
/app/m/instrumentists              — liste + drawer instrumentistes
/app/m/surgeons                    — liste + drawer chirurgiens
/app/m/hospitals                   — liste établissements
/app/m/firms                       — fiche administrative firmes (jamais de tarifs ici)
/app/m/catalogue/prestations       — Prestations : sidebar firmes + Tabs Prestations/Matériel facturable,
                                       remplace l'ancien "Configuration" (ex-BillingConfigPage)
/app/m/catalogue/requests          — Demandes : matériel + types d'intervention fusionnés (Tabs
                                       En attente/Résolues/Ignorées, badge nav = somme des deux PENDING)
/app/m/catalogue                   — catalogue matériel (retirée du menu, route conservée — la création
                                       d'article vit désormais aussi dans Prestations > Matériel facturable)
/app/m/intervention-types          — page standalone (jamais liée au menu) — sa logique CRUD est extraite
                                       dans InterventionTypesManager, réutilisée par le dialog "Gérer les
                                       types d'intervention" de Prestations (pas de duplication)
/app/m/billing/config               — redirect → /app/m/catalogue/prestations (compat liens existants)
/app/m/billing/firm-invoices        — liste + génération factures firmes
/app/m/billing/firm-invoices/:id    — détail facture firme
/app/m/billing/statements           — liste + génération décomptes instrumentistes
/app/m/billing/statements/:id       — détail décompte instrumentiste
/app/m/billing/firm-invoice-corrections/:id          — détail correction facture firme
/app/m/billing/instrumentist-statement-corrections/:id — détail correction décompte instrumentiste
/app/m/finance/statistics           — statistiques financières ("Statistiques" dans le menu)
/app/m/planning                     — redirect → /app/m/planning/v2
/app/m/planning/v2                  — Planning V2 ("Construire" dans le menu) — postes récurrents,
                                        génération, alertes, réglages
/app/m/planning/living              — "Planning publié" (ex-Planning vivant) — retirée du menu, reste
                                        accessible via un bouton dans Construire (PlanningV2Page.tsx)
/app/m/planning/absences            — gestion des absences
```

Routes V1 supprimées en D-079 (gabarits/générer/liste versions/spécialités/détail version) : voir
l'errata D-048 ci-dessous — les 5 premières étaient déjà hors routeur depuis `26d66ef`
(26/06/2026), donc déjà
inatteignables, cette section documentait encore leurs anciennes routes par erreur.

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
│   │   ├── api/      — catalogue.types.ts, catalogue.api.ts, interventionTypeRequests.api.ts
│   │   └── components/  — MaterialItemFormDialog
│   ├── intervention-types/
│   │   ├── api/      — interventionTypes.api.ts
│   │   └── components/  — InterventionTypesManager (extrait de InterventionTypesPage en D-079,
│   │                        réutilisé tel quel par le dialog "Gérer les types d'intervention" de Prestations)
│   ├── billing-firm/
│   │   └── api/      — firmInvoice.api.ts, firmBilling.api.ts
│   ├── billing-instrumentist/
│   │   └── api/      — statement.api.ts
│   ├── billing-shared/
│   │   └── components/  — RateVersionManager (versions PricingRule/InstrumentistRate append-only)
│   ├── financial-statistics/
│   │   ├── api/      — financialStatistics.api.ts
│   │   └── components/  — StatFilterBar, RankingTable, DrilldownTable
│   ├── planning-manager/
│   │   ├── api/      — planning.api.ts (Absences + listPlanningVersions, seul reliquat de l'API
│   │   │                Version encore appelé, par GeneratePlanningTab en V2 — tout le reste du
│   │   │                module Version/Génération/Déploiement V1 a été supprimé en D-079)
│   │   └── components/  — PersonSearchSelect, AbsenceReminderDialog (utilisés par AbsencesPage) ;
│   │                        DeployModal supprimé en D-079 (n'était utilisé que par la page ci-dessous)
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
│   │   ├── DashboardPage                 — point d'entrée manager (D-079), agrège des requêtes existantes
│   │   ├── MissionsListPage, MissionDetailPage, MissionCreatePage
│   │   ├── InstrumentistsPage, SurgeonsPage, HospitalsPage, FirmsPage
│   │   ├── PrestationsPage               — ex-billing/BillingConfigPage (D-079) : sidebar firmes
│   │   │                                    (SideList) + Tabs Prestations/Matériel facturable
│   │   ├── InterventionTypesPage         — wrapper fin autour de InterventionTypesManager
│   │   ├── CataloguePage                 — catalogue matériel (hors menu, route conservée)
│   │   ├── CatalogueRequestsPage         — Demandes fusionnées (matériel + types d'intervention)
│   │   ├── FinancialStatisticsPage
│   │   ├── billing/
│   │   │   ├── FirmInvoicesPage, FirmInvoiceDetailPage
│   │   │   ├── InstrumentistStatementsPage, InstrumentistStatementDetailPage
│   │   │   └── CorrectionDetailPage
│   │   └── planning/
│   │       ├── PlanningV2Page               — "Construire", postes récurrents (Batches 1–13)
│   │       ├── PlanningSchedulePage         — "Planning publié" (hors menu, bouton dans Construire)
│   │       └── AbsencesPage                 — gestion des absences
│   └── instrumentist/
├── layouts/          — DesktopLayout (sidebar MUI permanente), MobileLayout
├── router/           — AppRouter, guards RequireAuth / RequireManager / RequireAdmin
└── ui/               — composants UI manager partagés (D-079) : EmptyState, PageHeader, StatusBadge/
                          ActiveBadge, StatCard, SearchBox (+ hooks/useDebouncedValue), EntityHeader,
                          SideList, hooks/useNavBadgeCount ; ui/toast/ (Toast)
```

Pages V1 supprimées en D-079 (gabarits/générer/liste versions/spécialités/détail version) — voir
errata D-048 : `PlanningTemplatesPage`, `PlanningTemplateEditorPage`, `PlanningGeneratePage`,
`PlanningVersionsListPage`, `SpecialtiesPage`, `PlanningVersionDetailPage` (+ `DeployModal`, son
composant exclusif).

### Layout manager — Sidebar permanente

`DesktopLayout` utilise un `Drawer` MUI permanent (largeur 220px). Navigation regroupée par
domaine depuis D-079 (avant : 8 items à plat + 2 groupes + 1 page orpheline jamais liée) :

```
SurgicalHub
─────────────
Dashboard
Missions
Instrumentistes
Chirurgiens
Établissements
CATALOGUE
  Firmes
  Prestations
  Demandes [badge]      ← somme demandes matériel + types d'intervention PENDING
PLANNING
  Construire
  Absences
FACTURATION
  Factures Firmes
  Décomptes
  Statistiques
─────────────         ← affiché uniquement si role === 'ADMIN'
ADMINISTRATION
  Utilisateurs
  Sites
  Invitations
  Audit
─────────────
Déconnexion
```

La navigation utilise `NavLink` de React Router — l'item actif est mis en surbrillance (`selected`).
Le badge "Demandes" utilise `ui/hooks/useNavBadgeCount` (généralisé en D-079 depuis le `useQuery` +
`Badge` auparavant câblé en dur pour les seules demandes matériel) — deux appels (matériel PENDING +
types d'intervention PENDING), un seul nombre affiché, `refetchInterval` 60 s.

"Planning publié" (`PlanningSchedulePage`) et "Configuration" (fondue dans Prestations) n'ont plus
d'entrée directe dans le menu — leurs routes restent actives (compat liens/favoris/historique) :
"Planning publié" reste accessible via un bouton dans Construire (`PlanningV2Page.tsx`),
"Configuration" (`/app/m/billing/config`) redirige vers `/app/m/catalogue/prestations`.

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

### Aide contextuelle (HelpButton/HelpDrawer) — D-078

Un panneau d'aide spécifique à l'écran, déclenché par un bouton « ? » discret dans le
header — jamais une FAQ générique. Trois couches, chacune ignorant le contenu des
autres :

```
frontend/src/app/features/help/
├── types.ts              — HelpTopic { title, intro, sections: HelpSection[] }
│                            HelpSection { heading, paragraphs?, bullets? }
├── HelpButton.tsx         — <HelpButton topicId="firms" /> : seul composant à poser
│                            dans un header. Gère son propre état d'ouverture.
├── HelpDrawer.tsx         — MUI Drawer, width 100% sous le breakpoint sm / 420px
│                            au-dessus. Même composant desktop et mobile.
└── content/
    ├── registry.ts        — Record<topicId, HelpTopic>, agrège tous les topics
    └── topics/
        ├── planningV2.ts
        ├── firms.ts
        ├── materialCatalogue.ts
        ├── billingConfig.ts
        └── missionEncoding.ts
```

**Ajouter l'aide d'un nouvel écran** (aucun composant à modifier) :

1. Créer `content/topics/monEcran.ts` exportant un `HelpTopic` — contenu orienté
   workflow métier (objectif, ordre des actions, impacts, erreurs fréquentes, bonnes
   pratiques), jamais "Cette page permet de gérer X".
2. L'importer dans `content/registry.ts` et l'ajouter à `HELP_REGISTRY` avec une clé
   stable (`topicId`).
3. Poser `<HelpButton topicId="mon-ecran" />` dans le header de la page. Pour un header
   non-MUI (fond coloré, boutons custom — voir `EncodeHeader.tsx`), passer un `sx` pour
   assortir le style plutôt que dupliquer le composant.

Écrans couverts actuellement : `planning-v2`, `firms`, `material-catalogue`,
`billing-config`, `mission-encoding`. Le contenu (`intro`/`sections`) est du texte
structuré versionné dans le dépôt (pas de CMS externe) — toute modification passe par
une PR comme le reste du code.

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
├── isImplant: bool (information médicale pure — sans rôle financier, voir D-067)
└── billingStatus: UNSPECIFIED | BILLABLE | NOT_BILLABLE (D-092 — distingue "volontairement
    non facturé" de "tarif pas encore configuré" ; auto-promu à BILLABLE dès la première
    PricingRule MATERIAL_FEE créée, voir PricingRuleWriteService::create())

InterventionType (Lot 1 — référentiel médical fermé)
├── id, code (unique, immuable), label
├── specialty (nullable)
└── active: bool

FirmServiceOffering ("Prestation" à l'écran — Lot 1)
├── firm → Firm
├── interventionType → InterventionType
├── UNIQUE(firm, interventionType)
├── label (nullable), active: bool
├── representativePresenceRelevant: bool (D-092, défaut false — la question "délégué
│   présent ?" doit-elle être posée à l'encodage pour cette prestation ?)
├── representativeSuppressesInterventionFee: bool (D-092, défaut false)
├── representativeSuppressesOwnMaterialFees: bool (D-092, défaut false)
├── feeApplicable: bool (D-092, défaut true — un forfait INTERVENTION_FEE est-il
│   seulement attendu ? false = "pas de forfait", jamais confondu avec un tarif manquant)
│   — ces 4 champs sont lus EXCLUSIVEMENT par FinancialCalculationService (via
│   RepresentativePolicyResolver), jamais par PricingRuleResolver — exception scopée et
│   documentée à l'invariant D-067 (voir amendement dans D-067, docs/decisions.md)
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
├── representativePresent: bool|null (D-092 — donnée FACTUELLE encodée par
│   l'instrumentiste, "un délégué de la firme principale était-il présent ?" ; null =
│   jamais répondu ; jamais une donnée financière, jamais sur Mission globale)
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

FinancialCalculation (EPIC Exécution & Valorisation, Lot 3, D-073)
├── mission → Mission (1 — 0..n, plusieurs versions successives possibles)
├── version (int, séquentiel par mission, unicité (mission_id, version))
├── status: CALCULATED | APPROVED | LOCKED | SUPERSEDED | CANCELLED — pas de DRAFT
│   (construit entièrement en mémoire puis persisté en un bloc ou pas du tout)
├── effectiveAt (date retenue pour la résolution des tarifs — jamais now() implicite,
│   voir FinancialCalculationService::resolveEffectiveAt())
├── currencyPolicy: PER_CURRENCY_NO_CONVERSION (pas de taux de change)
├── calculatedAt/calculatedBy, approvedAt/approvedBy, lockedAt, cancelledAt/cancelledBy,
│   supersededAt/supersededByCalculation (self-FK, renseigné uniquement sur l'ancien)
├── append-only : jamais réécrit en place une fois CALCULATED — un recalcul crée une
│   nouvelle version, l'ancienne passe SUPERSEDED
├── hasUnassignedFirmLines()/hasUnassignedInstrumentistLines()/isFullyDocumented()
│   (Lot 4, D-074 — méthodes dérivées depuis les lignes, jamais un booléen stocké en
│   doublon : un calcul peut être partiellement documenté, verrouillé dès la première
│   ligne facturée/décomptée sans empêcher l'affectation des autres)
└── FinancialCalculationLine[] — jamais un total opaque, toujours détaillé

FinancialCalculationLine
├── financialCalculation, beneficiaryType: FIRM | INSTRUMENTIST
├── beneficiaryFirm / beneficiaryInstrumentist (FK nullables explicites, polymorphe
│   mais explicite — même pattern que FirmInvoiceLine)
├── lineType: FIRM_INTERVENTION_FEE | FIRM_MATERIAL_FEE | INSTRUMENTIST_HOURLY |
│   INSTRUMENTIST_CONSULTATION_FEE
├── sourceType: MISSION_INTERVENTION | MATERIAL_LINE | MISSION_EXECUTION
├── missionIntervention/materialLine/pricingRule/instrumentistRate (FK nullables,
│   navigation uniquement — le snapshot JSON reste la source de vérité historique)
├── descriptionSnapshot, quantity (decimal 10,4), durationMinutes (nullable, HOURLY
│   uniquement), unitAmount (decimal 10,2), currency, effectiveAt
├── grossAmount (decimal 10,2, D-092 — toujours renseigné, montant AVANT toute politique
│   délégué ; identique à totalAmount quand aucun ajustement)
├── adjustmentAmount (decimal 10,2, D-092 — toujours renseigné, "0.00" = aucun ajustement ;
│   totalAmount = grossAmount + adjustmentAmount, jamais recalculé implicitement)
├── totalAmount (decimal 10,2) — montant réellement facturé, sémantique inchangée (D-073)
├── warnings (JSON, D-092 — {code, message}[], non bloquant, distinct des anomalies qui
│   empêchent tout le calcul ; ex. STALE_REPRESENTATIVE_PRESENCE_ANSWER)
├── firmInvoiceLine / instrumentistStatementLine (Lot 4, D-074 — côté INVERSE de la
│   relation 1—0..1 vers le document qui a consommé cette ligne ; isAssigned() = true
│   si l'un des deux est non-null — jamais les deux à la fois, voir beneficiaryType)
└── snapshot (JSON) — nom firme/instrumentiste/intervention/matériel au moment du
    calcul ; une suppression/modification future du catalogue ne rend jamais un ancien
    calcul incompréhensible

FirmInvoice
├── firm, number (FIRM-YYYY-NNN standard ; FIRM-CN-YYYY-NNN / FIRM-DN-YYYY-NNN pour une
│   correction — Lot 6, D-076, null tant que non émise), status
│   (DRAFT|GENERATED|SENT|PAID|CANCELLED — Lot 4)
├── periodStart, periodEnd, totalAmount
├── currency (Lot 4, D-074, défaut EUR), legacySource (Lot 4 — true si créé avant ce
│   lot ou via le chemin legacy recalculant, false via createFromEligibleLines())
├── documentType: STANDARD | CREDIT_NOTE | DEBIT_NOTE (Lot 6, D-076, défaut STANDARD)
├── correctsDocument (Lot 6 — self-FK nullable, toujours vers un document STANDARD
│   racine, jamais une correction de correction — voir §6 de D-076)
├── billingEmailTo (snapshot), billingEmailCc (snapshot JSON)
└── FirmInvoiceLine[]

FirmInvoiceLine
├── invoice, mission, lineType (INTERVENTION_FEE|MATERIAL_FEE)
├── missionIntervention (nullable FK — anti-doublon, legacy)
├── materialLine (nullable FK — anti-doublon, legacy)
├── financialCalculationLine (Lot 4, D-074 — FK nullable, UNIQUE en base : côté
│   propriétaire de la relation 1—0..1 vers la ligne financière figée dont cette ligne
│   provient ; NULL = ligne legacy, voir FirmInvoiceLine::isLegacy())
├── currency, unitSnapshot, sourceSnapshot (Lot 4 — copie intégrale de
│   FinancialCalculationLine.snapshot, NULL pour les lignes legacy), createdAt
├── descriptionSnapshot, unitPrice (snapshot — = unitAmount pour une ligne nouvelle,
│   copié exactement depuis FinancialCalculationLine, jamais recalculé), quantity,
│   totalAmount
├── reasonCode (Lot 6, D-076 — nullable, motif de correction, NULL sur une ligne
│   STANDARD)
└── originalDocumentLine (Lot 6 — self-FK nullable, SANS contrainte d'unicité
    contrairement à financialCalculationLine : une même ligne d'origine peut être
    référencée par plusieurs corrections successives)

InstrumentistStatement
├── instrumentist, periodYear, periodMonth
├── number (Lot 6, D-076 — nullable, UNIQUE ; **jamais** attribué à un décompte
│   STANDARD, uniquement STMT-CN-YYYY-NNN / STMT-DN-YYYY-NNN pour une correction émise)
├── status (DRAFT|GENERATED|SENT|PAID|CANCELLED — Lot 4), totalAmount
├── currency, legacySource (Lot 4, D-074 — même contrat que FirmInvoice)
├── documentType, correctsDocument (Lot 6 — même contrat que FirmInvoice)
└── InstrumentistStatementLine[]

InstrumentistStatementLine
├── statement, mission, lineType (BLOC|CONSULTATION)
├── durationMinutesRaw, durationMinutesRounded
├── rateSnapshot (snapshot hourlyRate/consultationFee legacy, ou unitAmount de
│   FinancialCalculationLine pour une ligne nouvelle — jamais recalculé)
├── financialCalculationLine, currency, unitSnapshot, sourceSnapshot, createdAt
│   (Lot 4, D-074 — même contrat que FirmInvoiceLine)
├── quantity, totalAmount, surgeonNameSnapshot, siteNameSnapshot, missionDateSnapshot
├── descriptionSnapshot (Lot 6, D-076 — nullable, NULL pour toute ligne STANDARD,
│   n'existait pas avant ce lot contrairement à FirmInvoiceLine)
└── reasonCode, originalDocumentLine (Lot 6 — même contrat que FirmInvoiceLine)

Payment (EPIC Exécution & Valorisation, Lot 5, D-075 ; direction ajoutée Lot 6, D-076)
├── documentType: FIRM_INVOICE | INSTRUMENTIST_STATEMENT, documentId (int — pas de FK
│   Doctrine directe, table polymorphe unique servant les deux types de document ;
│   documentId validé au niveau applicatif par DocumentPaymentService, jamais deviné —
│   toujours l'id du document STANDARD racine, jamais celui d'une correction, Lot 6)
├── direction: INBOUND | OUTBOUND (Lot 6, D-076, défaut INBOUND — un remboursement est
│   un Payment OUTBOUND, jamais une modification d'un Payment INBOUND existant)
├── amount (decimal 10,2, CHECK > 0), currency (doit être strictement celle du document)
├── paidAt (date réelle du paiement, date-only), recordedAt (horodatage serveur de la
│   saisie), recordedBy → User
├── reference (nullable), method: BANK_TRANSFER | CASH | OTHER, comment (nullable)
├── createdAt
└── append-only : jamais modifié ni supprimé une fois créé (seuls points d'écriture :
    DocumentPaymentService::recordPayment()/recordRefund())

FirmInvoice/InstrumentistStatement implémentent PayableDocument (Lot 5, étendu Lot 6) :
getId()/getStatus()/setStatus()/getCurrency()/getTotalAmount()/getPaymentDocumentType()/
getDocumentType()/getCorrectsDocument()/getLines() — contrat minimal partagé pour que
DocumentPaymentService/FinancialCorrectionService restent uniques (§18/§20 des lots)
sans dupliquer leur logique par type de document ; les deux restent deux agrégats et
deux tables distinctes (même principe que leur coexistence au Lot 4).

Absence
├── id
├── user → User
├── dateStart, dateEnd (date)
├── reason: string (nullable)
└── createdBy → User
```

> `PlanningTemplate`/`PlanningSlot` (gabarits de semaine V1) supprimées en D-079 — voir errata
> D-048 dans `docs/decisions.md`. Les tables SQL `planning_template`/`planning_slot` existent
> toujours en base (aucune migration de suppression exécutée) mais n'ont plus de mapping ORM.

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

### Flux politique délégué — neutralisation post-résolution (D-092)

```
FirmServiceOffering (Firm × InterventionType) porte 4 indicateurs NON-MONÉTAIRES :
  representativePresenceRelevant           — la question doit-elle être posée à l'encodage ?
  representativeSuppressesInterventionFee  — neutralise le forfait si délégué présent
  representativeSuppressesOwnMaterialFees  — neutralise le matériel de CETTE firme si délégué présent
  feeApplicable                            — un forfait est-il seulement attendu (défaut true) ?

MissionIntervention.representativePresent (bool|null) — donnée FACTUELLE encodée par
  l'instrumentiste, jamais une donnée financière, jamais sur Mission globale.

Ordre de résolution (FinancialCalculationService, jamais PricingRuleResolver) :
  1. RepresentativePolicyResolver.resolve(firm, interventionType) → RepresentativePolicy
     (4 booléens ; défaut neutre si aucune FirmServiceOffering n'existe pour ce couple)
  2. policy.representativePresenceRelevant && representativePresent === null
       → anomalie bloquante MISSING_REPRESENTATIVE_PRESENCE_ANSWER (aucune persistance)
  3. !policy.feeApplicable → aucune ligne INTERVENTION_FEE, aucune anomalie ("pas de forfait")
  4. PricingRuleResolver résout le tarif normalement (INCHANGÉ, ne connaît jamais la politique)
  5. Si résolu ET policy.representativePresenceRelevant && representativePresent===true
       && policy.representativeSuppresses*  → adjustmentAmount = -grossAmount, totalAmount = 0
     Sinon → adjustmentAmount = "0.00", totalAmount = grossAmount

Neutralisation matériel : uniquement si material.firm === intervention.primaryFirm — un
matériel d'une autre firme dans la même intervention n'est jamais affecté (§20).
```

**Exception scopée à l'invariant D-067** (voir amendement inséré directement dans D-067,
`docs/decisions.md`) : `PricingRuleResolver` reste pur et sans dépendance vers
`FirmServiceOffering` (preuve structurelle : `PricingRuleResolverArchitectureTest`).
`RepresentativePolicyResolver` est le seul point de lecture des 4 indicateurs, appelé
uniquement par `FinancialCalculationService`, jamais avant la résolution normale du
tarif, jamais comme source de montant.

**Facturable/non facturable** (`MaterialItem.billingStatus`) et **forfait attendu**
(`FirmServiceOffering.feeApplicable`) suivent le même principe : distinguer une décision
commerciale explicite (état valide, aucune ligne, aucune anomalie) d'un oubli de
configuration (anomalie bloquante inchangée, jamais un 0€ silencieux). Auto-promotion :
poser une première `PricingRule MATERIAL_FEE` fait automatiquement passer
`billingStatus` à `BILLABLE` (`PricingRuleWriteService::create()`).

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

### Flux valorisation financière (EPIC Exécution & Valorisation, Lot 3, D-073)

```
Mission (VALIDATED) + MissionExecution (réalisé, Lot 1)
       + MissionIntervention[]/MaterialLine[] (interventions/matériel encodés)
       + PricingRule/InstrumentistRate (tarifs historisés, Lot 2)
                              │
                              ▼  FinancialCalculationService::calculate(mission, actor)
                    verrou pessimiste sur Mission
                    assertEligible() : VALIDATED + instrumentiste assigné
                    aucun calcul actif existant (sinon 409, utiliser recalculate())
                              │
                    résout TOUS les tarifs, collecte TOUTES les anomalies
                              │
              ┌───────────────┴────────────────┐
              ▼ anomalies                       ▼ aucune anomalie
    audit FINANCIAL_CALCULATION_FAILED    FinancialCalculation (CALCULATED, v1)
    (committé — voir piège ci-dessous)    + FinancialCalculationLine[]
    422 FINANCIAL_CALCULATION_ANOMALIES   audit FINANCIAL_CALCULATION_CREATED
    aucun calcul persisté                          │
                                          approve() → APPROVED → lock() → LOCKED
                                                       │
                                          recalculate() tant que non LOCKED :
                                          ancien → SUPERSEDED, nouveau → CALCULATED (v+1)
```

`FinancialCalculationService` — seul point d'entrée métier ; les contrôleurs ne
construisent jamais les lignes. `resolveEffectiveAt(Mission)` centralise l'unique règle
de date : `MissionExecution.actualStartAt` si connu, sinon `Mission.startAt` — jamais
`now()`. Durée instrumentiste exclusivement via
`MissionExecutionService::resolveEffectiveDuration()` (Lot 1) — jamais dupliquée.

**Pas de statut DRAFT** : un calcul est construit entièrement en mémoire (résolution de
tous les tarifs, collecte de toutes les anomalies) puis persisté en un seul bloc, ou pas
du tout — un `DRAFT` n'aurait jamais eu de transition observable.

**Piège de transaction découvert et corrigé pendant ce lot** : auditer un échec puis
lever l'exception *à l'intérieur* du même `wrapInTransaction()` provoque le rollback de
l'audit lui-même (`EntityManager::wrapInTransaction()` appelle `close()` sur
l'EntityManager dès qu'une exception le traverse). `buildAndPersist()` ne lève donc plus
jamais d'exception elle-même — elle retourne `null` + les anomalies par référence ;
`calculate()`/`recalculate()` auditent l'échec **à l'intérieur** de la transaction (qui
se termine alors normalement, committant l'audit), puis lèvent
`FinancialCalculationAnomaliesException` **après** la fin de `wrapInTransaction()`.
Trouvé par un test d'intégration réel (pas un mock) vérifiant la présence de l'AuditEvent
après l'échec.

**Politique d'arrondi** : convention decimal-string du projet (jamais de float), comme
`FirmInvoiceService`/`InstrumentistStatementService`. Heures arrondies à 4 décimales,
montant total à 2 décimales — une seule fois, jamais en cascade.

**Intégration Lot 7** : `MissionEncodingWorkflowService::reopen()` (D-070) gagne une
garde — une mission avec un `FinancialCalculation` `LOCKED` ne peut plus être réouverte
(409). Requête directe via l'`EntityManager` déjà injecté, pas de dépendance au
`FinancialCalculationService` complet (seule exception sanctionnée à "ne pas toucher les
lots précédents sans nécessité démontrée").

**Concurrence** : verrou pessimiste sur `Mission`, même mécanisme que
`PricingRuleWriteService`/`InstrumentistRateService` (Lot 2) — prouvé par un test de
concurrence à connexions DBAL réellement distinctes.

**Hors périmètre de ce lot** : bascule de `FirmInvoiceService`/
`InstrumentistStatementService` vers les lignes figées de `FinancialCalculation`, tout
paiement, toute correction financière additive, table `Settlement`, conversion de
devises.

---

### Flux bascule des documents financiers (EPIC Exécution & Valorisation, Lot 4, D-074)

```
FinancialCalculation (APPROVED ou LOCKED) + FinancialCalculationLine[] (FIRM_*/INSTRUMENTIST_*)
                              │
                              ▼  previewEligibleLines(cible, devise, période) — lecture seule
                    lignes non assignées, devise/bénéficiaire/période/statut filtrés
                              │
                              ▼  createFromEligibleLines(cible, devise, période, lineIds, actor)
                    verrou pessimiste sur chaque FinancialCalculation distinct (id croissant)
                    revérifie CHAQUE ligne sous verrou (jamais confiance dans le preview)
                              │
              ┌───────────────┴────────────────┐
              ▼ une ligne inéligible            ▼ toutes éligibles
    DocumentLineSelectionException        FirmInvoice/InstrumentistStatement (GENERATED)
    (toutes les anomalies, un seul       + lignes documentaires (snapshots copiés,
     rapport) — RIEN persisté             financialCalculationLine rattachée, UNIQUE)
                                           FinancialCalculationService::lock() par calcul
                                           (APPROVED → LOCKED, idempotent si déjà LOCKED)
                                                       │
                                          markSent() (existant, inchangé) → SENT
                                          audit FIRM_INVOICE_ISSUED / _STATEMENT_ISSUED
                                                       │
                                          cancel() : GENERATED → CANCELLED uniquement,
                                          libère les lignes documentaires (jamais le calcul)
```

`FinancialCalculationLine` = vérité monétaire ; `FirmInvoiceLine`/
`InstrumentistStatementLine` = présentation documentaire et affectation. Les services ne
résolvent plus jamais `PricingRule`/`InstrumentistRate`, ne relisent plus
`User.hourlyRate`/`consultationFee`, ne recalculent plus de durée/quantité/prix
unitaire/total — montants et snapshots copiés exactement depuis
`FinancialCalculationLine`.

**Deux chemins coexistent (§18 du lot)** : `preview()`/`generate()` (legacy, recalcule
encore lui-même depuis `PricingRule` — seul chemin utilisé par le frontend actuel,
jamais retouché) et `previewEligibleLines()`/`createFromEligibleLines()` (nouveau,
consomme `FinancialCalculationLine`). `FirmInvoiceLine.financialCalculationLine`/
`InstrumentistStatementLine.financialCalculationLine` distinguent les deux
(`isLegacy()`). Un même document ne mélange jamais les deux chemins.

**Pas de nouvel état DRAFT observable** : les deux chemins produisent un document
`GENERATED` en un seul appel atomique — même raisonnement que "pas de DRAFT" pour
`FinancialCalculation` (D-073). `SENT` (transition `markSent()` existante, inchangée)
reste le vrai point d'engagement vis-à-vis du tiers.

**Calcul partiellement documenté (§11/§30)** : une mission produit typiquement plusieurs
lignes (intervention firme, matériel firme, prestation instrumentiste) sur le **même**
`FinancialCalculation` — verrouillé dès la première ligne facturée/décomptée, les autres
restent sélectionnables tant qu'elles ne sont pas elles-mêmes affectées
(`hasUnassignedFirmLines()`/`hasUnassignedInstrumentistLines()`/`isFullyDocumented()`).
Annuler un document **ne déverrouille jamais** le calcul (politique explicite, jamais
automatique).

**Anti-double facturation — trois niveaux** : applicatif (revérification sous verrou
avant rattachement), base de données (`UNIQUE(financial_calculation_line_id)` sur les
deux tables de lignes), transaction (sélection + création + rattachement + verrouillage,
atomique). Concurrence prouvée par `FirmInvoiceConcurrencyTest` (connexions DBAL
réellement distinctes, même méthode que le Lot 3).

**PDF inchangés** : les templates ne lisaient déjà que des champs snapshot — aucune
modification nécessaire (confirmé, pas de refonte graphique).

**Hors périmètre de ce lot** : gestion des documents `SENT`/`PAID`, paiements,
rapprochement bancaire, corrections financières additives, notes de crédit, table
`Settlement`, conversion de devises, refonte UX.

---

### Flux émission et paiement (EPIC Exécution & Valorisation, Lot 5, D-075)

```
FirmInvoice/InstrumentistStatement (GENERATED)
                              │
                              ▼  issue(document, actor)
                    numéro attribué si absent, sentAt, audit *_ISSUED
                              │
                        (GENERATED → SENT)
                              │
                              ▼  DocumentPaymentService::recordPayment(document, ...)
                    verrou pessimiste sur le document, refresh() sous verrou
                    solde recalculé (somme des Payment existants)
                              │
              ┌───────────────┴────────────────┐
              ▼ dépasse le solde / devise ≠     ▼ montant valide
    PAYMENT_EXCEEDS_REMAINING (422)       Payment créé (append-only)
    PAYMENT_CURRENCY_MISMATCH (422)       audit DOCUMENT_PAYMENT_RECORDED
    aucun Payment créé                    + DOCUMENT_PARTIALLY_PAID ou _FULLY_PAID
                                                       │
                                          PaymentStatus dérivé : UNPAID → PARTIALLY_PAID
                                          → PAID (remainingAmount = 0, automatique)
```

**Deux dimensions jamais mélangées** : `InvoiceStatus` (documentaire — `GENERATED`/
`SENT`/`PAID`/`CANCELLED`, ce dernier cas legacy conservé pour compatibilité) reste la
seule source de vérité sur *où en est le document dans son cycle d'émission* ;
`PaymentStatus` (financier — `UNPAID`/`PARTIALLY_PAID`/`PAID`, **jamais persisté**,
toujours dérivé par `DocumentPaymentService::computeBalance()`) répond à *combien reste
dû*. Un document intégralement payé via le nouveau flux reste `InvoiceStatus::SENT` —
jamais réécrit en `PAID`, cette valeur restant réservée au chemin legacy
`markPaid()` (Lot 1, inchangé).

**Modèle Payment — table unique polymorphe** : un seul `DocumentPaymentService` sert
`FirmInvoice` et `InstrumentistStatement` via l'interface partagée `PayableDocument`
(§18 du lot) — jamais de duplication de la logique de paiement par type de document.
`Payment.documentType`/`documentId` (pas de FK Doctrine, impossible nativement vers deux
tables) sont validés au niveau applicatif, jamais devinés.

**Solde toujours calculé** : `grossAmount`/`paidAmount`/`remainingAmount` ne sont jamais
stockés en doublon — `paidAmount` = somme des `Payment` existants (ou `grossAmount` si
un document legacy est déjà `PAID` sans aucun `Payment`, compatibilité assurée sans
reconstruction rétroactive). `PaymentStatus::PAID` est une conséquence directe du calcul
dès que `remainingAmount <= 0`, jamais un champ à muter explicitement.

**Anti-surpaiement + concurrence** : `recordPayment()` reverrouille le document
(`PESSIMISTIC_WRITE`) et relit le solde sous ce verrou avant d'accepter un nouveau
paiement — un dépassement concurrent est structurellement impossible, prouvé par
`DocumentPaymentConcurrencyTest` (connexions DBAL réellement distinctes, même méthode
que les Lots 2-4).

**Émission (`issue()`) sans rupture du contrat existant** : `markSent()` (endpoint
`/send` existant, inchangé pour le frontend) délègue désormais à `issue()` pour la
transition elle-même — `issue()` est le nouveau point d'entrée canonique
(`POST /{id}/issue`, sans envoi d'email), `markSent()`/`/send` restent responsables de
l'email en plus. Réutilise les audits `FIRM_INVOICE_ISSUED`/
`INSTRUMENTIST_STATEMENT_ISSUED` (créés au Lot 4, jamais câblés jusqu'ici) plutôt que
d'introduire un `DOCUMENT_SENT` redondant.

**Annulation inchangée** : la politique du Lot 4 (`GENERATED` annulable,
`SENT`/`PAID` jamais) s'applique identiquement, avec ou sans paiement enregistré.

**Hors périmètre de ce lot** : notes de crédit, corrections financières additives après
paiement, recalcul de montants existants, rapprochement bancaire automatisé, conversion
de devises, refonte UX.

---

### Flux corrections financières additives (EPIC Exécution & Valorisation, Lot 6, D-076)

```
FirmInvoice/InstrumentistStatement STANDARD (SENT ou PAID)
                              │
                              ▼  FinancialCorrectionService::createCreditNote()/createDebitNote()
                    verrou pessimiste sur le document RACINE, refresh() sous verrou
                    assertRootEligible() — STANDARD + SENT/PAID uniquement
                    validation de CHAQUE ligne (anomalies collectées, jamais un échec
                    sur la première trouvée)
                              │
              ┌───────────────┴────────────────────┐
              ▼ racine GENERATED / déjà une         ▼ toutes les lignes valides
                correction                    Note de crédit/débit créée (GENERATED,
    CORRECTION_NOT_ELIGIBLE (409)             sans numéro, sans effet sur le solde)
              ▼ ≥1 ligne invalide              audit CREDIT_NOTE_CREATED/DEBIT_NOTE_CREATED
    CORRECTION_VALIDATION_FAILED (422)                    │
    aucun document créé                                   ▼  FinancialCorrectionService::issueCorrection()
                                                  délègue à FirmInvoiceService::issue()/
                                                  InstrumentistStatementService::issue()
                                                  (numéro définitif, sentAt, audit *_ISSUED)
                                                  + FINANCIAL_CORRECTION_ISSUED
                                                  + DOCUMENT_NET_BALANCE_CHANGED (si le
                                                    solde net racine change réellement)
                                                             │
                                                  (GENERATED → SENT — c'est SEULEMENT
                                                   maintenant qu'elle compte dans
                                                   computeBalance() du document racine)
```

**Modèle retenu — extension, jamais de nouvelle entité "Settlement"** :
`FirmInvoice`/`InstrumentistStatement` gagnent `documentType`
(`STANDARD`/`CREDIT_NOTE`/`DEBIT_NOTE`) + `correctsDocument` (self-FK, toujours vers la
racine `STANDARD`, jamais une correction de correction). Les lignes documentaires
gagnent `reasonCode` + `originalDocumentLine` (self-FK **sans** contrainte d'unicité,
contrairement à `financialCalculationLine` — une même ligne d'origine peut être
référencée par plusieurs corrections).

**Convention de signe centralisée** : montants toujours positifs en base ;
`FinancialDocumentType::signCoefficient()` porte seul le signe économique
(`CREDIT_NOTE` = -1, sinon +1) — jamais de double convention.

**Seules les corrections `ISSUED` comptent** : `DocumentPaymentService::computeBalance()`
(étendu Lot 6) ignore toute correction encore `GENERATED` dans `creditNotesAmount`/
`debitNotesAmount` — une correction en brouillon reste annulable sans jamais influencer
silencieusement ce qui est dû. `netDocumentAmount = originalGrossAmount -
creditNotesAmount + debitNotesAmount` ; `remainingAmount`/`overpaidAmount` en dérivent
(voir D-076 pour les formules complètes).

**Deux garde-fous cumulés (notes de crédit)** : par ligne d'origine (crédits cumulés
`GENERATED`/`SENT`/`PAID`, jamais `CANCELLED`, ≤ montant original de la ligne) et pour
le document entier (`netDocumentAmount` projeté jamais négatif) —
`FinancialCorrectionService::assertCreditLimits()`, exécuté sous le même verrou
pessimiste que la création elle-même.

**Paiements et remboursements — toujours rattachés à la racine** : politique explicite
retenue parmi les deux proposées par le lot — `DocumentPaymentService::resolveRoot()`
(`$document->getCorrectsDocument() ?? $document`) garantit qu'un paiement/remboursement
n'est jamais rattaché à une correction, même si l'appelant lui en passe une par erreur.
`Payment` (Lot 5, append-only, inchangé) étend uniquement avec `direction`
(`INBOUND`/`OUTBOUND`) — un remboursement est un nouveau `Payment` `OUTBOUND`, jamais
une modification d'un `Payment` existant. `recordRefund()` reverrouille le document
racine et relit `overpaidAmount` sous ce verrou avant d'accepter — même mécanisme
anti-dépassement que `recordPayment()` (Lot 5), prouvé par
`FinancialCorrectionConcurrencyTest` (connexions DBAL réellement distinctes).

**Numérotation — préfixe distinct, même stratégie** : `FIRM-CN-YYYY-NNN`/
`FIRM-DN-YYYY-NNN` (factures), `STMT-CN-YYYY-NNN`/`STMT-DN-YYYY-NNN` (décomptes,
`InstrumentistStatement.number` — champ nouveau, un décompte STANDARD n'a jamais reçu
de numéro et continue de ne pas en recevoir). Attribué uniquement à l'émission, jamais
sur un brouillon — même garantie `COUNT(...) + 1` + `UNIQUE` en base que les Lots 4/5.

**Endpoints dédiés pour l'émission** : `POST /api/firm-invoice-corrections/{id}/issue`
et `POST /api/instrumentist-statement-corrections/{id}/issue` vivent dans deux
contrôleurs séparés (`FirmInvoiceCorrectionController`/
`InstrumentistStatementCorrectionController`) — la ressource "correction" partage l'id
de sa table avec le document racine et n'est donc pas adressable sous le préfixe de
route existant (`/api/firm-invoices/{id}`, déjà pris par le document lui-même).

**Legacy — aucune reconstruction** : une correction sur un document `legacySource =
true` référence uniquement la ligne documentaire legacy ; aucune
`FinancialCalculationLine` n'est jamais reconstruite rétroactivement (§24 du lot, même
politique que D-074 §19).

**Atomicité** : toute la construction d'une correction (validation de toutes les
lignes, limites cumulées, persistance, numérotation, audit) tient dans une seule
transaction — une ligne invalide annule tout, aucun document partiel, prouvé par
`test_invalid_correction_line_creates_no_partial_document`.

**Hors périmètre de ce lot** : rapprochement bancaire automatisé, conversion de
devises, refonte UX, modification/suppression d'un paiement existant (toujours
interdite).

---

### Flux statistiques financières manager (EPIC Pilotage financier, Lot 7, D-077)

```
GET /api/financial-statistics/overview?from=...&to=...&firmId=...
                              │
                              ▼  FinancialStatisticsRequestParser::parseFilter()
                    résolution période (timezone métier D-066, jamais now()),
                    validation stricte (422 si incohérente)
                              │
                              ▼  FinancialStatisticsQueryService::overview()
        ┌─────────────┬───────────────┬────────────────┬──────────────────┐
        ▼             ▼               ▼                ▼                  ▼
   activityRow() generatedValue  documentedValue   cashFlowByCurrency  openBalance
   (Mission/     ByCurrency()    ByCurrency()      ()                  ByCurrency()
   MissionExec.) (FinancialCalc  (FirmInvoice/     (Payment,           (documentBalance
                 ulationLine,    InstrumentistStmt sens réel dérivé    DerivedTable(),
                 calcul ACTIF)   + corrections     de (documentType,   même formule que
                                 ISSUED, même       direction) —       DocumentPayment
                                 formule que        D-077)             Service::compute
                                 computeBalance())                     Balance())
        └─────────────┴───────────────┴────────────────┴──────────────────┘
                              │  fusion par devise en PHP (union des clés, jamais un
                              ▼  total artificiel entre devises différentes — §5 du lot)
                    FinancialOverviewDto { activity, currencies[] }
```

**Sources de vérité disjointes, jamais mélangées (§2 du lot, D-077)** : activité
(Mission/MissionExecution, sans devise propre), valeur générée
(FinancialCalculationLine d'un calcul ACTIF — CALCULATED/APPROVED/LOCKED, jamais
SUPERSEDED/CANCELLED), valeur documentée (FirmInvoice/InstrumentistStatement STANDARD
émis + corrections ISSUED, jamais GENERATED), flux monétaires (Payment append-only).
Chaque service (`FinancialStatisticsQueryService` pour overview/timeseries/pipeline,
`FinancialStatisticsRankingService` pour les classements,
`FinancialStatisticsDrilldownService` pour missions/calculations/documents) agrège en
SQL natif (DBAL, jamais DQL avec hydratation complète) — §22 du lot.

**Table dérivée `documentBalanceDerivedTable()` — formule unique, jamais dupliquée en
PHP** : réplique exactement `DocumentPaymentService::computeBalance()` (Lot 5/6) en SQL
pour chaque document racine STANDARD (crédits/débits ISSUED, paiements/remboursements,
compatibilité legacy PAID-sans-Payment) — réutilisée par `invoicedNetAmount`/
`statementNetAmount`, `openFirmBalance`/`openInstrumentistBalance`, et les compteurs du
pipeline (`issuedInvoicesWithOpenBalance`, `overpaidDocumentsAwaitingRefund`) — jamais
un appel PHP par document, jamais une seconde formule qui pourrait diverger de Lot 6.

**Cash flow réel ≠ Payment.direction seul (§20 du lot, piège identifié et documenté)** :
`direction=INBOUND` signifie "règle le document", pas "argent entrant en caisse" — sur
un `InstrumentistStatement`, `INBOUND` est un décaissement réel (l'entreprise paie
l'instrumentiste). `paymentsIn`/`paymentsOut` dérivent donc de
`(Payment.documentType, Payment.direction)` combinés :
```
paymentsIn  = (FIRM_INVOICE, INBOUND) + (INSTRUMENTIST_STATEMENT, OUTBOUND)
paymentsOut = (INSTRUMENTIST_STATEMENT, INBOUND) + (FIRM_INVOICE, OUTBOUND)
```
Un bug réel de ce type (`documentPopulationClause()` ne propageait `instrumentistId`
que vers `InstrumentistStatement`, jamais vers `FirmInvoice`, contrairement à `firmId`
qui était propagé symétriquement) a été détecté et corrigé pendant le développement de
ce lot — voir `test_instrumentist_statement_inbound_payment_counts_as_cash_out`.

**Classements (`by-firm`/`by-instrumentist`/`by-surgeon`/`by-intervention`/
`top-materials`)** : agrégation SQL groupée par (bénéficiaire, devise) — un résultat
borné par le nombre de firmes/instrumentistes/chirurgiens/types d'intervention/matériels
distincts, jamais par le volume de missions ou de lignes financières brutes — puis
tri/pagination en PHP sur cet ensemble déjà réduit (§22 : ce n'est pas l'agrégation PHP
interdite). Libellés toujours issus des snapshots de `FinancialCalculationLine` (jamais
le catalogue actuel) sauf deux limites documentées (D-077) : `surgeonNameSnapshot`
(aucun snapshot chirurgien n'existe dans le modèle, lu en direct via `Mission.surgeon`)
et `materialReferenceSnapshot` (idem, lu en direct via `MaterialItem.referenceCode`,
donnée d'identification jamais tarifaire).

**Pipeline financier** : neuf compteurs disjoints construits sur les mêmes tables,
jamais un double comptage (ex. "calcul approuvé sans document" et "calcul partiellement
documenté" utilisent des `EXISTS`/`NOT EXISTS` structurellement exclusifs sur les mêmes
lignes financières).

**Drill-down** : `FinancialStatisticsDrilldownService` réutilise exactement les mêmes
filtres de population/période que les agrégats (mêmes noms de query params) — un
manager retrouve toujours la liste source exacte d'un chiffre affiché.

**Index ajoutés (Lot 7 uniquement, aucune colonne/contrainte nouvelle)** :
`financial_calculation_line(effective_at, line_type)`, `firm_invoice`/
`instrumentist_statement(sent_at, status, document_type, currency)`,
`payment(paid_at, direction, document_type, document_id)`, `mission(start_at, status)`,
`mission_execution(actual_start_at)` — voir migration `Version20260719065527`.

**Hors périmètre de ce lot** : export comptable, prévisionnel financier, rapprochement
bancaire, graphiques frontend complexes, refonte UX complète.

---

## 7. Flux planning

> **Note D-079 (2026-07-20)** : le moteur Planning V1 (`PlanningGeneratorService`,
> `PlanningTemplate`/`PlanningSlot`, `PlanningGenerationController`,
> `PlanningDeployController`, `PlanningVersionDetailPage.tsx`, `DeployModal.tsx`) a
> été entièrement supprimé — un audit de réachabilité a confirmé zéro lien UI restant
> et une incompatibilité de fond (l'aperçu V1 est recalculé depuis des gabarits sans
> rapport avec le contenu réel d'une version générée par V2). Voir l'errata "2" de
> D-048 dans `docs/decisions.md` pour le détail complet de l'audit et de la
> suppression. Planning V2 (`PlanningGeneratorServiceV2`, fin de ce chapitre) est
> désormais l'unique moteur de génération/déploiement — il n'a jamais dépendu de
> `PlanningTemplate`/`PlanningSlot`, il utilise `ShiftPeriodConfig`.
>
> `PlanningDeploymentService` (déploiement DB) et `PlanningDeployPdfsMessageHandler`
> (PDFs/emails asynchrones post-déploiement) restent actifs — ils étaient déjà
> partagés entre V1 et V2 avant cette suppression, et servent maintenant
> exclusivement le flux V2 (`/api/planning/v2/deploy`,
> `PlanningV2GenerationController`). `PlanningScoreService` reste actif également
> (utilisé par les alertes de réassignation V2), seul l'endpoint V1
> `GET /api/missions/{id}/suggested-instrumentists` qui l'exposait a été supprimé
> (0 appelant frontend).

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
| Chirurgien | Manager publie une mission OPEN pour lui (pré-déploiement, `MissionController::publish()`) | `SURGEON_MISSION_OPEN_PUBLISHED` (push priorité, repli email, aucune donnée patient) | `MissionPublishedMessageHandler` (Point 8, audit UX) |
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
Batch 13, voir D-048 dans `docs/decisions.md`) et, depuis D-079 (2026-07-20), l'unique
moteur de génération/déploiement — le moteur V1 (`PlanningTemplate`/`PlanningSlot`,
parité PAIR/IMPAIR/TOUTES, `PlanningGeneratorService`) a été supprimé (voir errata D-048).**
Le menu latéral "Planning" et le chemin nu `/app/m/planning` pointent vers
`/app/m/planning/v2`.

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

**Réutilisation confirmée à 100 %** (audité Batch 8, vérifié à nouveau Batch 9, et une
dernière fois lors de la suppression V1 de D-079) : `PlanningVersion`, `Mission`,
`PlanningDeploymentService`, `PlanningDiffService`, `PdfService` et tous les templates PDF
(`planning_global/instrumentist/surgeon.html.twig`) n'ont **jamais** référencé
`PlanningTemplate`/`PlanningSlot`/PAIR/IMPAIR/TOUTES — ce qui a permis de supprimer le
moteur V1 en D-079 sans toucher à aucun de ces éléments partagés.

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

## 9. Notifications Web Push (Lot 1, D-081)

Socle fiabilisé au Lot 1 (audit PWA/push 24-07-2026) — installation PWA, préférences
sélectives complètes, rappel de 19 h et mises à jour applicatives restent hors périmètre
(voir D-081 pour le détail des décisions).

### 9.1 Composants

**Backend**
- `App\Entity\PushSubscription` — une ligne par *navigateur/profil × SW registration ×
  utilisateur actuellement rattaché*. `endpoint` `UNIQUE` (migration
  `Version20260724222527`, additive — table vide en dev/test au moment de la migration).
- `App\Controller\Api\PushSubscriptionController` — `GET .../vapid-public-key`,
  `POST .../subscribe` (idempotent, upsert), `DELETE .../unsubscribe` (idempotent, scopé
  au propriétaire). Voir `docs/api.md` §40 pour le contrat complet.
- `App\Service\WebPushService` (implémente `WebPushServiceInterface`) —
  `sendToUser()`/`sendToUsers()`/`sendToSiteInstrumentists()`. Seul point d'envoi HTTP
  réel (`minishlink/web-push`) ; tout appelant métier passe par l'interface, jamais par
  un appel HTTP direct.
- Canal Monolog `push` (`config/packages/monolog.yaml`) + handler `sentry` (type
  `service`, `Sentry\Monolog\LogToSentryIssueHandler`, seuil `ERROR`, `services.yaml`) —
  toute erreur d'envoi non-expirée devient une issue Sentry en prod, no-op si
  `SENTRY_DSN` est vide.

**Frontend**
- `frontend/src/app/features/push/PushProvider.tsx` — provider React monté une seule
  fois à la toute racine de l'app (`main.tsx` → `AppProviders.tsx` → `PushProvider`,
  au-dessus du router, à l'intérieur d'`AuthProvider` mais **jamais conditionné par
  l'état d'authentification** — la page publique `/` avant login le monte tout autant),
  donc disponible pour `MobileLayout` **et** `DesktopLayout` sans duplication (avant ce
  lot, seul `MobileLayout` le montait — chirurgiens/managers/admins ne pouvaient jamais
  s'abonner). Expose l'état `PushNotificationStatus` (`unsupported` /
  `permission-default` / `permission-denied` / `subscribing` / `subscribed` / `error`) et
  `subscribe()`/`unsubscribe()`/`refreshStatus()`.
  - **Enregistrement du service worker — indépendant du Push (confirmé, revue
    post-rapport 2026-07-29) :** l'effet qui appelle `registerServiceWorker()`
    (`useEffect` gardé uniquement par la détection de support navigateur, jamais par
    `Notification.permission`, ni par un `PushSubscription`, ni par le rôle, ni par la
    page visitée) s'exécute au montage de ce provider — donc dès le premier chargement
    de l'app, pour tout le monde, y compris `Notification.permission === "denied"` ou
    un visiteur non authentifié. La création d'un `PushSubscription` (consentement
    explicite, `subscribe()`) reste un chemin strictement séparé, jamais déclenché
    automatiquement. Couvert par `frontend/src/app/features/push/PushProvider.test.tsx`
    (describe "enregistrement du service worker").
  - **Nudge temps réel à la réception d'un push :** un second effet écoute les messages
    `postMessage` du service worker (`type: "PUSH_NOTIFICATION"`) et, à leur réception,
    affiche un toast et invalide la query react-query `["notifications"]` — c'est le
    seul rôle restant de ce canal côté client, la persistance/le compteur/le statut lu
    viennent uniquement du backend (`GET /api/notifications`, voir §13.1).
- `frontend/src/app/features/push/pushSubscriptionClient.ts` — primitives non-React
  (`registerServiceWorker`, `subscribeToPush`, `unsubscribeFromPush`,
  `detachCurrentPushSubscription`) : gardées libres de toute dépendance à un contexte
  React pour qu'`AuthContext.tsx` puisse appeler `detachCurrentPushSubscription()`
  directement au logout sans import circulaire.
- `frontend/src/app/features/push/usePushNotifications.ts` — point d'entrée public
  ré-exporté depuis `PushProvider` ; les sites d'appel existants (`MobileLayout`,
  `DesktopLayout`) n'ont pas besoin de connaître l'implémentation.
- `frontend/public/sw.js` — service worker existant, enregistrement centralisé dans
  `PushProvider`. Toujours hors périmètre de ce lot : cache offline, `install`/
  `activate`/`fetch`, `SKIP_WAITING`, mise à jour forcée.

### 9.2 Flux abonnement (frontend → backend → navigateur)

1. `PushProvider` enregistre le service worker au montage (`registerServiceWorker()`),
   pour tous les rôles, sans jamais demander la permission ni s'abonner.
2. Si la permission était déjà accordée, `PushProvider` retente silencieusement
   `subscribeToPush()` une seule fois **par identité de session** (`state.user.id`, pas
   à chaque render/route, et pas seulement "une fois par montage du provider" — le
   provider ne démonte jamais entre deux connexions dans le même onglet puisqu'il est
   monté au-dessus du `Router`, voir D-081 "le socle réagit à l'identité de session").
   Une transition `A → B` sans passer par l'état anonyme redéclenche donc bien ce
   réabonnement pour B, avec réutilisation de la subscription navigateur existante.
3. Activation volontaire : `MobileLayout` affiche un bandeau (« Activer ») quand
   `status === "permission-default"`. `DesktopLayout` porte aujourd'hui un item de menu
   compte équivalent (« Activer les notifications ») dans le code, mais celui-ci est
   imbriqué dans une refonte de navigation manager hors périmètre de ce lot et n'entre
   pas dans son commit (voir D-081, "activation volontaire" — distinction socle
   technique / point d'entrée visuel) ; il sera livré avec le commit de cette refonte.
   Dans tous les cas, seul un clic déclenche `Notification.requestPermission()` — jamais
   au montage.
4. `subscribeToPush()` : récupère la clé VAPID (`GET .../vapid-public-key`), réutilise
   une `PushSubscription` navigateur existante via `getSubscription()` ou en crée une,
   puis `POST .../subscribe` avec `{ endpoint, keys: { p256dh, auth } }`.
5. `unsubscribeFromPush()` (désactivation complète sur cet appareil, action utilisateur
   explicite) : `DELETE .../unsubscribe` **puis** `subscription.unsubscribe()`
   côté navigateur — distinct du détachement au logout (point 6), qui ne révoque jamais
   la subscription navigateur elle-même (réactivation fluide au prochain login).

### 9.3 Politique de logout (D-081)

`AuthContext::logout()` appelle `detachCurrentPushSubscription(accessToken)` avec le
token capturé de façon synchrone, **avant** `clearAuth()` — fire-and-forget : jamais
bloquant, jamais de délai sur la révocation du refresh token, le nettoyage local ou la
redirection vers `/login`. Un détachement raté (offline, timeout, session déjà expirée)
s'auto-corrige au prochain `subscribe()` via la réattribution explicite et journalisée
côté backend (§9.4) — aucune complexité multi-compte par appareil n'a été introduite.

### 9.4 Observabilité

- **Réattribution d'endpoint** (`PushSubscriptionController::subscribe()`, endpoint
  déjà rattaché à un autre utilisateur) : jamais silencieuse — `warning
  push.subscription_reassigned` (`from_user_id`/`to_user_id`).
- **Envoi** (`WebPushService::sendToSubscriptions()`) : abonnement expiré (404/410) →
  suppression automatique inchangée + `info push.subscription_expired` ; tout autre
  échec → `error push.send_failed` (raison, type de notification), routé vers Sentry.
  Un échec individuel n'interrompt jamais le traitement des abonnements suivants ni la
  mutation métier d'origine qui a déclenché l'envoi.
- **Jamais loggé** : endpoint complet (`WebPushService::hintForLog()` ne conserve que
  les premiers/derniers caractères), clé `p256dh`, `auth`, payload de notification.

### 9.5 Dette technique connue

`MissionController::publish()` appelle `WebPushService::sendToSiteInstrumentists()` de
façon synchrone dans la requête HTTP, contrairement au reste des envois push
post-déploiement (tous async via `MissionLifecycleChangedMessage`, D-043/D-056).
`publish()` agit en pré-déploiement (`DRAFT`), hors du domaine couvert par
`MissionChangeType` — migrer proprement aurait exigé d'étendre ce domaine au-delà de la
fiabilisation visée par ce lot. Documenté et non traité ici (D-081) ; sous-lot proposé :
`MissionPublishedMessage` dédié, routé async.

## 10. Installation PWA Android/iOS (Lot 2, D-082)

Module `frontend/src/app/features/pwa-install/` — aucun changement backend.

### 10.1 Composants

- `PwaInstallProvider.tsx` — monté une seule fois à la racine authentifiée
  (`AppProviders.tsx`, à côté de `PushProvider`, D-081), même principe : socle unique,
  jamais dupliqué par layout. Capture `beforeinstallprompt`/`appinstalled`, expose
  `status` (`installed`/`android-installable`/`ios-installable`/`not-installable`/
  `dismissed`), `platform`, `showBanner`, et les actions (`promptAndroidInstall`,
  `openIosGuide`/`closeIosGuide`, `dismissBanner`). Monte aussi
  `IosInstallGuideModal` en permanence (état piloté par le contexte), disponible
  partout sans qu'aucun layout n'ait à le monter lui-même.
- `pwaInstallDetection.ts` — `isStandalone()` (source de vérité unique sur
  l'installation réelle : `display-mode` + `navigator.standalone` iOS),
  `detectPlatform()` (`ios`/`android`/`other`, gère le cas iPadOS 13+ qui usurpe l'UA
  Mac via `maxTouchPoints`).
- `pwaInstallStorage.ts` — politique de report (`localStorage`, 3 clés
  `surgicalhub.pwaInstall.*`), dégrade silencieusement si le stockage est indisponible.
- `PwaInstallBanner.tsx` — bannière automatique, montée uniquement dans `MobileLayout`
  (jamais `DesktopLayout` — voir D-082 pour la justification).
- `IosStepAnimation.tsx` — aperçu animé 4 étapes, recréé en React à partir du handoff
  design (`handoff-install-guide/`, jamais copié tel quel — voir D-082), respecte
  `prefers-reduced-motion`.
- `IosInstallGuideModal.tsx` — guide manuel iOS, composant dédié avec son propre
  `useFocusTrap.ts` (focus trap + `Échap`, scopé à ce module — voir D-082 pour pourquoi
  `SheetModal.tsx` partagé n'a pas été modifié).
- `usePwaInstallMenuState.ts` + `PwaInstallMenuItem.tsx` — point d'entrée permanent
  (§7 du lot), consommé par `ProfilePage.tsx` (instrumentiste) et par un `MenuItem`
  dédié dans le menu compte de `DesktopLayout` — jamais soumis à la politique de
  report.

### 10.2 Flux

```
beforeinstallprompt (Android/Chromium) ──┐
                                           ├─→ PwaInstallProvider ─→ status
navigator.standalone / display-mode ──────┘        │
                                                     ├─→ showBanner (MobileLayout only,
                                                     │   authentifié, jamais login)
                                                     └─→ usePwaInstallMenuState (partout,
                                                         jamais de report)
```

Android : bouton "Installer" → `deferredPrompt.prompt()` natif, jamais recréé.
iOS : bouton "Voir comment faire" → `IosInstallGuideModal` (bottom sheet mobile /
dialogue centré ≥ 900px, jamais de bouton "Installer" — iOS ne permet pas de déclencher
l'installation par script).

### 10.3 Manifest et service worker

Audités, non modifiés — déjà conformes (voir D-082) : `manifest.json` réunit déjà tous
les critères d'installabilité Chrome, `sw.js` est déjà enregistré globalement par
`PushProvider` avant même l'authentification. Aucun cache offline, aucune logique
`install`/`activate`/`fetch` ajoutée.

### 10.4 Séparation avec Web Push

`PwaInstallProvider` n'importe rien de `features/push/` ; aucune demande de permission
de notification depuis la bannière ou le guide d'installation. `PushProvider` inchangé.

## 11. Rappel d'encodage D+1 (D-083)

Remplace le rappel 19 h jamais implémenté (D-081) — un seul rappel, le lendemain matin,
jamais de relance quotidienne.

### 11.1 Composants

- `SendEncodingRemindersCommand` (`app:notifications:send-encoding-reminders`) — commande
  planifiée, orchestration uniquement (garde 08 h Europe/Brussels, boucle isolée par
  mission, résumé). Pas de logique métier.
- `EncodingReminderService` — décide (éligibilité) et envoie (Push prioritaire, repli
  email). `findEligibleMissions()` : requête DQL réelle (statuts soumissibles, `endAt`
  dans le jour civil précédent en Europe/Brussels, non verrouillée, jamais déjà
  rappelée). `processMission()` : réservation atomique puis envoi.
- `Mission.encodingReminderSentAt` — champ nullable, marque le rappel envoyé ; garantit
  l'idempotence stricte (au plus un rappel par mission) par construction, indépendamment
  des logs ou d'un verrou applicatif.
- `WebPushService::sendToUserAndReportSuccess()` — nouvelle méthode (pas dans
  `WebPushServiceInterface`, même précédent que `sendToSiteInstrumentists()`) qui
  rapporte si le Push a réellement été livré, condition du repli email.
- `NotificationService::missionEncodingReminderNotifyInstrumentist()` +
  `templates/emails/mission_encoding_reminder.html.twig` — repli email, même mécanisme
  asynchrone Messenger que le reste du projet.

### 11.2 Canal

Push tenté en premier ; email seulement si Push n'est pas livrable (aucune subscription,
toutes expirées, tous les envois échouent). Jamais les deux à la fois.

### 11.3 Planification

Cron serveur (pas de Symfony Scheduler dans ce projet), garde horaire interne dans la
commande elle-même — absorbe tout décalage été/hiver même si le cron serveur est
planifié en UTC fixe. Voir docs/production.md pour le déploiement.

## 12. Historique des notifications sortantes (D-084)

Traçabilité durable de tous les Push et emails envoyés — snapshot du contenu exact,
chronologie des tentatives, statut honnête (`SENT` ≠ lu). Réservé à ROLE_ADMIN.

### 12.1 Modèle

- `OutboundNotification` — une ligne par communication sortante (Push OU email, jamais
  les deux). Snapshot : `title`/`subject`/`bodyText`/`bodyHtml`/`payload` (nettoyé, liste
  blanche). Un repli Push → email crée une seconde ligne distincte, liée via
  `fallbackOf`/`fallbackReason` — jamais un statut composite sur une ligne partagée.
- `OutboundNotificationAttempt` — append-only, une ligne par tentative réelle de
  transport (une par subscription Push, une par essai/retry Messenger pour un email).
  Ne stocke jamais d'endpoint, seulement le `provider` (`FCM`/`APPLE`/`OTHER`/`SMTP`)
  dérivé du host, et une `reason` normalisée (jamais un message d'exception brut).

### 12.2 Branchement Push

`WebPushService::sendToUserWithAttempts()` (nouvelle méthode, pas dans
`WebPushServiceInterface`) retourne le détail par abonnement au lieu d'un simple bool.
`OutboundNotificationService::recordPushSend()` agrège : `SENT` si au moins un envoi
réussit, `FAILED` si tous échouent, `SKIPPED` si aucun abonnement.

### 12.3 Branchement email

`SendTemplatedEmailMessage` transporte désormais un `outboundNotificationId` optionnel
(nullable, rétrocompatible avec les appelants antérieurs à D-084). La ligne
`OutboundNotification` (statut `QUEUED`) est créée AVANT le dispatch Messenger, pour que
son id circule dans le message. `SendTemplatedEmailMessageHandler` marque `SENT` au
succès (et rétro-remplit `bodyText`/`bodyHtml` avec le contenu réellement rendu — le
Twig n'est rendu que dans le handler, pas au moment de la mise en file). Un échec ne
marque `FAILED` que si Messenger a épuisé ses tentatives
(`OutboundNotificationEmailFailureListener` sur `WorkerMessageFailedEvent`,
`!$event->willRetry()`) — un échec en attente de retry laisse le statut à `QUEUED`,
jamais `FAILED` prématurément.

### 12.4 API et interface ADMIN

`GET /api/admin/outbound-notifications` (liste paginée/filtrable) et `/{id}` (détail),
`OutboundNotificationVoter` (ROLE_ADMIN strict). Page `AdminOutboundNotificationsPage`
+ `AdminOutboundNotificationDrawer` (tableau + filtres + détail en panneau latéral,
même convention que les autres écrans admin). Aperçu HTML d'un email affiché dans une
`<iframe sandbox="">` vide (aucune librairie de sanitization n'existe dans ce projet ;
un sandbox vide interdit scripts/formulaires/popups sans ajouter de dépendance).

### 12.5 Rétention

Politique initiale documentée (D-084) : contenu complet conservé 12 mois. Aucune purge
automatique implémentée dans ce lot — hors périmètre, à traiter séparément.

## 13. Notifications internes lisibles, préférences par catégorie, badge offres non lues (audit PWA/mobile/admin, D-086/D-087, 2026-07-29)

Distinct de la section 12 (`OutboundNotification`, supervision ADMIN des envois
Push/email) : cette section couvre `NotificationEvent` (in-app, ce que voit
l'utilisateur destinataire lui-même) et le badge offres instrumentiste, deux modèles qui
existaient déjà en base mais sans API de lecture ni UI avant ce lot.

### 13.1 `NotificationEvent` — de l'écriture seule à un vrai cycle de vie

`NotificationEvent` était persistée par `NotificationService::createInApp*()` à chaque
événement métier pertinent (D-... Batch 7 et suivants), avec un champ `seenAt` jamais
renseigné (`setSeenAt()` n'était appelé nulle part). `NotificationController`
(`/api/notifications`, scopé `#[CurrentUser]`, sans Voter dédié — même convention que
`PushSubscriptionController`) ajoute : liste + `unreadCount`, marquage individuel et
global. Voir `docs/api.md` §41.1.

Frontend : `useNotificationsFeed` (react-query, `frontend/src/app/features/notifications/`)
sert la cloche + l'historique pour les deux familles de rôles — manager/admin
(`ManagerNotificationsPage`, entrée sidebar `DesktopLayout.tsx` — ce layout n'a pas de
topbar, le badge vit dans la nav groupée) et instrumentiste (`NotificationsPage.tsx`,
entrée cloche `MobileLayout.tsx`). Même hook, même source de vérité serveur, cohérente
entre appareils et entre les deux écrans.

**Révision (revue post-rapport, 2026-07-29) :** l'ancien cache `localStorage`
(`features/push/notifications.store.ts` + `useNotifications.ts`) — qui servait
auparavant l'affichage côté instrumentiste, alimenté par le service worker — a été
**entièrement retiré**, pas seulement synchronisé en plus : il ne restait qu'un
doublon partiel et non fiable (perdu au changement d'appareil) de la source de vérité
serveur désormais en place pour les deux rôles. Le nudge temps réel à la réception
d'un push (toast immédiat + invalidation de la query `["notifications"]`) est assuré
par `PushProvider` (§9), indépendamment de l'état de la permission Push de l'appareil
courant — sans lui, le badge reste correct mais se met à jour au prochain
`refetchInterval` (60 s) plutôt qu'instantanément.

### 13.2 `NotificationPreference` — premier lecteur/écrivain

`NotificationPreference` (Batch 15A) avait un resolver de défauts par catégorie
(`DefaultNotificationPreferenceResolver`, déjà consommé par l'envoi réel) mais aucune
API ni UI. `GET`/`PATCH /api/me/notification-preferences[/{type}]` (`MeController`) et
`NotificationPreferencesSection` (frontend, montée sur les deux pages Profil) comblent
ce vide. Un `PATCH` partiel matérialise une ligne amorcée depuis les valeurs déjà
résolues — jamais de régression silencieuse d'un canal non touché. Le canal `push` est
volontairement absent de cette UI : il dépend d'un abonnement d'appareil réel (§9,
D-081), représenté par `PushPermissionCard` (les 4 états de permission, désormais
partagés entre manager et instrumentiste — seul manager les avait tous avant ce lot).

### 13.3 Badge "offres non lues" — checkpoint serveur, pas un inventaire

`User.offersLastSeenAt` (nullable, migration `Version20260729140000`) remplace un badge
qui affichait `items.length` du fetch `eligibleToMe=true` — un inventaire total, sans
notion de lecture. `GET /api/missions/offers/unread-count` réutilise
`MissionService::list()` (même règle d'éligibilité que `GET /api/missions`) avec un
filtre additionnel programmatique (`MissionFilter::$createdAfter`, jamais exposé dans
`fromQuery()` — pas de nouveau paramètre public sur l'endpoint générique). `POST
/api/me/offers-seen` pose le checkpoint, appelé une fois par montage réussi
d'`OffersPage`. Voir `docs/api.md` §41.3, D-087.
