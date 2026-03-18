# SurgicalHub — Architecture système

_Last updated: 2026-03-18 (v5 — module facturation)_

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
- **Symfony Security** — authentification JWT + RBAC via Voters
- **Symfony Mailer + Messenger** — emails transactionnels asynchrones
- **Stockage fichiers** — système de fichiers local (`public/uploads/`)

### Frontend
- **React 18 + TypeScript** — Vite
- **MUI (Material UI v5)** — composants UI
- **TanStack React Query** — cache serveur, mutations, invalidation
- **React Router v6** — navigation
- **FullCalendar** — affichage planning (instrumentiste + drawer manager)
- **Axios** — client HTTP avec intercepteur JWT + refresh

---

## 3. Architecture backend

### Structure des controllers

```
Api/
├── AuthController                       — login / refresh token
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
└── InstrumentistStatementController    — CRUD /api/instrumentist-statements + preview/generate/send/mark-paid
```

### Autorisation — RBAC strict via Voters

Toute logique d'accès passe par des Voters Symfony (`InstrumentistVoter`, `MissionVoter`, etc.).
Aucun contrôle de rôle direct dans les controllers.

### Transitions de statut

Chaque changement d'état métier passe par un endpoint dédié :

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
```

Pas de mutation libre via `PATCH` générique pour les transitions.

### Stockage des fichiers

Photos de profil stockées dans `public/uploads/profile-pictures/`.

```
ProfilePictureStorage
├── upload_dir       → {project}/public/uploads/profile-pictures
└── public_base_path → /uploads/profile-pictures
```

`profilePicturePath` retourné par l'API est un chemin relatif au web root (`/uploads/profile-pictures/filename.jpg`).
Le frontend construit l'URL complète : `VITE_API_BASE_URL + profilePicturePath`.

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
```

### Organisation du code

```
src/app/
├── api/              — apiClient (Axios + intercepteur JWT)
├── auth/             — AuthContext, tokens, refresh mutex
├── features/         — features métier
│   ├── missions/
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
│   ├── invitation/
│   └── sites/
├── pages/            — pages (orchestration uniquement)
│   ├── manager/
│   │   ├── MissionsListPage, MissionDetailPage, MissionCreatePage
│   │   ├── InstrumentistsPage
│   │   ├── CataloguePage
│   │   └── CatalogueRequestsPage
│   └── instrumentist/
├── layouts/          — DesktopLayout (sidebar MUI permanente), MobileLayout
├── router/           — AppRouter, guards RequireAuth / RequireManager
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
- **Optimistic updates** : utilisés pour les affiliations de site (avec rollback sur erreur)
- **Badge sidebar** : le composant `DesktopLayout` poll toutes les 60s les demandes PENDING et affiche un badge sur "Demandes matériel"

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
└── SiteMembership[]

SiteMembership
├── id
├── user → User
├── site → Hospital
└── siteRole: 'INSTRUMENTIST' | ...

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

## 7. Variables d'environnement frontend

| Variable | Usage |
|---|---|
| `VITE_API_BASE_URL` | Base URL du backend (ex: `http://localhost`) |

Les URLs de fichiers uploadés sont construites comme `VITE_API_BASE_URL + profilePicturePath`.
