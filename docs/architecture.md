# SurgicalHub — Architecture système

_Last updated: 2026-03-15_

---

## 1. Vue d'ensemble

SurgicalHub est une plateforme de gestion des missions chirurgicales. Elle connecte trois rôles :

| Rôle | Périmètre |
|---|---|
| `MANAGER` / `ADMIN` | Création et gestion des missions, des instrumentistes, validation |
| `INSTRUMENTIST` | Prise en charge des missions, encodage des actes, déclarations |
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
- **FullCalendar** — affichage planning
- **Axios** — client HTTP avec intercepteur JWT + refresh

---

## 3. Architecture backend

### Structure des controllers

```
Api/
├── AuthController          — login / refresh token
├── MissionController       — CRUD missions + transitions de statut
├── InstrumentistController — gestion manager des instrumentistes
├── InvitationController    — flux complétion de compte (public)
├── MeController            — profil utilisateur connecté
└── ...
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
/app/m/*                    — Manager / Admin (desktop)
/app/i/*                    — Instrumentiste (mobile-first)
/app/s/*                    — Surgeon
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
│   │   ├── components/
│   │   ├── hooks/    — logique réutilisable (useInstrumentistDrawer)
│   │   └── utils/
│   ├── invitation/
│   └── sites/
├── pages/            — pages (orchestration uniquement)
│   ├── manager/
│   └── instrumentist/
├── layouts/          — DesktopLayout, MobileLayout
├── router/           — AppRouter, guards RequireAuth / RequireManager
└── ui/               — composants UI partagés (Toast...)
```

### Règles frontend

- **Pas de fallback métier** : le frontend reflète strictement l'état serveur
- **`allowedActions[]`** : les droits sur les missions sont calculés par le backend et consommés sans inférence côté client
- **React Query** : toutes les mutations invalident ou mettent à jour le cache via `setQueryData` / `invalidateQueries`
- **Optimistic updates** : utilisés pour les affiliations de site (avec rollback sur erreur)

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

---

## 7. Variables d'environnement frontend

| Variable | Usage |
|---|---|
| `VITE_API_BASE_URL` | Base URL du backend (ex: `http://localhost`) |

Les URLs de fichiers uploadés sont construites comme `VITE_API_BASE_URL + profilePicturePath`.
