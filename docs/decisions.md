# Décisions d'architecture — SurgicalHub Backend

Ce document trace les décisions d'architecture structurantes prises pour le backend SurgicalHub afin d'assurer :

- cohérence métier,
- maintenabilité du code,
- traçabilité des choix techniques,
- alignement frontend ↔ backend.

---

## D-001 — Séparation mission vs encodage

Date : 18-01-2026

### Décision

Le détail d'encodage opératoire (interventions, firms, matériel) n'est pas inclus dans
`GET /api/missions/{id}`.

Un endpoint dédié est créé :

`GET /api/missions/{id}/encoding`

### Motivation

- Éviter l'alourdissement du payload mission standard.
- Séparer clairement le planning (mission) de l'exécution opératoire (encodage).
- Permettre l'évolution du modèle d'encodage sans impacter les listings, les écrans manager, le frontend instrumentiste Lot 3.

### Conséquences

- Deux appels frontend : mission (planning + allowedActions), encoding (interventions / matériel).
- Mapping dédié via `MissionEncodingService`.
- DTOs spécifiques et stables pour l'UI mobile.

---

## D-002 — Option B : encodage libre par interventions

Date : 18-01-2026

### Décision

Une mission peut contenir plusieurs interventions, créées librement par l'instrumentiste.

Aucune typologie d'intervention n'est imposée par la mission.

### Motivation

- Fidélité maximale à la réalité opératoire.
- Pas de rigidité côté backend.
- UI encodage simple et progressive.

---

## D-003 — Hiérarchie d'encodage

Date : 18-01-2026

### Structure retenue

```text
Mission
└─ MissionIntervention
   ├─ MaterialLine
   └─ MaterialItemRequest
```

### Règles métier

**MaterialLine :**
- matériel existant dans le catalogue,
- réellement utilisé.

**MaterialItemRequest :**
- matériel absent / inconnu,
- signalement à destination du manager.

---

## D-004 — Gestion du matériel implantable

Date : 18-01-2026

### Décision

Les items implantables (`MaterialItem.isImplant = true`) déclenchent automatiquement la création ou l'association à une `ImplantSubMission`.

### Motivation

Préparer les futures étapes : reporting, validation, facturation.

---

## D-005 — RBAC strict via Voters

Date : 17-01-2026

### Décision

- Toute logique d'autorisation passe exclusivement par des Voters.
- Aucun contrôle de rôle direct dans les controllers.
- Aucun droit inféré côté frontend.

---

## D-006 — allowedActions[] comme contrat frontend

Date : 20-01-2026

### Décision

Le backend calcule dynamiquement un tableau `allowedActions[]` pour chaque mission.

Le frontend :
- n'infère jamais un droit,
- n'anticipe jamais un statut,
- affiche uniquement ce qui est explicitement autorisé.

---

## D-007 — Missions de type CONSULTATION

Date : 18-01-2026

### Décision

Les missions de type `CONSULTATION` ne peuvent pas contenir de matériel.

---

## D-008 — Garde-fou temporel sur l'encodage

Date : 20-01-2026

### Décision

Un instrumentiste ne peut pas encoder avant le début réel de la mission.

---

## D-009 — Catalogue matériel en lecture libre

Date : 31-01-2026

### Décision

Le catalogue `MaterialItem` est accessible en lecture à tous les rôles.

---

## D-010 — Erreurs API normalisées

Date : 16-01-2026

### Décision

Toutes les erreurs API passent par `ApiExceptionSubscriber`.

---

## D-011 — Documentation vivante en Markdown

Date : 18-01-2026

### Décision

Trois documents de référence maintenus à jour :
- `docs/api.md`
- `docs/architecture.md`
- `docs/decisions.md`

---

## D-012 — Firms en référentiel (fabricants)

Date : 12-02-2026 — Mise à jour : 15-03-2026

### Décision

- `Firm` est une entité de référence, gérée en base par un admin.
- Un `MaterialItem` appartient toujours à exactement une `Firm` (1 matériel = 1 firme).
- L'instrumentiste ne peut jamais créer/éditer/supprimer une firm.
- `GET /api/firms` expose la liste des firmes actives pour les formulaires manager.
- La création/édition de firms est hors périmètre frontend V1 — gestion directe en base.

---

## D-013 — Missions déclarées par instrumentiste (unforeseen activity control)

Date : 20-02-2026

### Décision

Un instrumentiste peut déclarer une mission imprévue via un flux contrôlé.

Cette mission est créée avec le statut `DECLARED`. Elle doit obligatoirement être validée ou rejetée par un Manager/Admin.

Les chirurgiens ne peuvent jamais créer de mission.

### Motivation

- Refléter la réalité terrain (urgences, dépassements bloc).
- Permettre l'encodage sans briser la cohérence planning.
- Maintenir un contrôle manager-centric du système.
- Éviter la création sauvage de missions validées automatiquement.
- Préserver la robustesse juridique et financière.

### Règles métier

Une mission `DECLARED` :
- est créée uniquement par un `INSTRUMENTIST`,
- lie automatiquement `instrumentist_user_id = created_by_user_id`,
- n'est pas publiée,
- n'est pas claimable,
- n'est pas facturable,
- ne peut pas être `VALIDATED`,
- ne peut pas être `CLOSED`.

Transitions autorisées :

```
DECLARED → ASSIGNED (approve)
DECLARED → REJECTED
```

`REJECTED` est un statut terminal.

### Gouvernance

Seul un `MANAGER`/`ADMIN` peut approuver ou rejeter.

- Le chirurgien a uniquement un droit de consultation.
- Aucune suppression autorisée.
- Audit obligatoire.

### allowedActions impact

Si `mission.status = DECLARED` :

| Rôle | Actions |
|---|---|
| Instrumentiste (owner) | `view`, `encoding`, `submit`, `edit_hours` |
| Manager / Admin | `approve`, `reject`, `edit` |
| Surgeon | `view` |

### Sécurité & anti-abus

- Historique complet conservé.
- Rejection ratio mesurable.
- Aucun impact financier sans validation.
- Impossible de convertir une mission `DECLARED` en `OPEN`.

### Impact technique

- Ajout enum `MissionStatus::DECLARED`
- Ajout enum `MissionStatus::REJECTED`
- Nouvelles capacités Voter : `DECLARE`, `APPROVE_DECLARED`, `REJECT_DECLARED`
- Extension `MissionActionsService`
- Nouveaux endpoints dédiés
- Nouveaux événements d'audit : `MISSION_DECLARED`, `MISSION_DECLARED_APPROVED`, `MISSION_DECLARED_REJECTED`

---

## D-014 — Envoi d'emails transactionnels via Symfony Mailer + Messenger

Date : 12-03-2026

### Décision

Les emails transactionnels de SurgicalHub sont envoyés via Symfony Mailer et dispatchés de manière asynchrone via Symfony Messenger.

L'envoi d'email ne bloque jamais les requêtes API.

Les emails utilisent :
- Symfony Mailer pour l'envoi SMTP
- Twig pour le rendu HTML et texte
- Symfony Messenger pour la file d'envoi
- un transport Doctrine async

### Motivation

Garantir :
- des requêtes API rapides,
- une tolérance aux pannes SMTP,
- une architecture réutilisable pour tous les emails futurs.

Exemples d'emails futurs : invitation instrumentiste, reset password, mission assignée, mission publiée, validation manager, notifications système.

L'utilisation de Messenger permet des retries automatiques, une gestion des erreurs centralisée et une meilleure scalabilité.

### Architecture technique

Flux d'envoi :

```
Controller / Service métier
        │
        ▼
NotificationService
        │
        ▼
Dispatch Messenger Message
(App\Message\SendTemplatedEmailMessage)
        │
        ▼
Transport async (Doctrine queue)
        │
        ▼
Worker Messenger
        │
        ▼
SendTemplatedEmailMessageHandler
        │
        ▼
Symfony Mailer
        │
        ▼
SMTP
```

### Templates d'email

Les emails utilisent Twig. Structure :

```
templates/
└─ emails/
   ├─ instrumentist_invitation.html.twig
   └─ instrumentist_invitation.txt.twig
```

Deux formats sont envoyés : HTML (principal) et texte brut (fallback).

### Configuration Messenger

Transport utilisé : `async → doctrine://default`

Retry automatique configuré :
- `max_retries: 5`
- `delay: 1000 ms`
- `multiplier: 2`
- `max_delay: 60000 ms`

En cas d'échec final, les messages sont déplacés dans le transport `failed`.

### Comportement API

L'API ne dépend jamais du succès SMTP.

- Si le message Messenger est correctement dispatché → succès normal
- Si le dispatch échoue → la logique métier est conservée et un warning `INVITATION_EMAIL_NOT_SENT` est renvoyé

Ainsi : aucune création métier n'est rollbackée, les erreurs email n'impactent pas le système.

### Configuration environnement

Variables utilisées : `MAILER_DSN`, `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME`, `FRONTEND_URL`

---

## D-015 — Onboarding instrumentiste via invitation manager

Date : 11-03-2026

### Décision

Lorsqu'un Manager crée un instrumentiste dans le système, un flux d'invitation est utilisé afin que l'instrumentiste finalise lui-même son compte.

La création suit les règles suivantes :
- le `User` est créé immédiatement,
- `active = true`,
- `password = null`,
- un token d'invitation sécurisé est généré,
- ce token est envoyé par email à l'instrumentiste.

L'email contient un lien vers le frontend :

```
{FRONTEND_URL}/complete-account?token=XXXX
```

Ce lien permet à l'instrumentiste de compléter son profil, de définir son mot de passe et d'activer réellement son compte utilisateur.

### Stockage du token

Pour limiter la complexité de la V1, le token est stocké directement dans l'entité `User`.

Champs ajoutés :

```
User
 ├─ invitationToken
 └─ invitationExpiresAt
```

- Durée de validité : **48 heures**
- Après utilisation : `invitationToken = null`, `invitationExpiresAt = null`

### Complétion du profil

Lors de l'ouverture du lien d'invitation, l'instrumentiste doit compléter :
- `firstname`
- `lastname`
- `phone` (obligatoire)
- `password`
- `confirmPassword`
- `profilePicture` (optionnel)

Les champs `phone` et `profilePicture` sont ajoutés dans l'entité `User`.

### Gestion des cas particuliers

**Email déjà existant :** HTTP `409` — `Email already used` — la création est refusée.

**Token déjà utilisé :** `Account already activated` — le frontend redirige vers la page de connexion.

**Échec d'envoi d'email :** la création du compte est conservée, un warning est renvoyé à l'API, l'invitation pourra être renvoyée ultérieurement.

### Contraintes métier

Lors de la création par un manager :
- au moins un site doit être sélectionné,
- une `SiteMembership` est créée pour chaque site.

### Distinction avec l'auto-inscription

| Flux | Comportement |
|---|---|
| Création par Manager | invitation envoyée, activation via complétion du profil |
| Auto-inscription (future évolution) | validation email obligatoire, flux de création autonome |

### Impact technique

Ajouts dans `User` : `phone`, `profilePicture`, `invitationToken`, `invitationExpiresAt`

Nouveaux endpoints :
```
GET  /api/invitations/{token}
POST /api/invitations/complete
```

---

## D-016 — Module Catalogue Matériel — gestion manager

Date : 15-03-2026

### Décision

Le manager peut gérer le catalogue matériel directement depuis l'interface (sans accès base de données).

**Périmètre V1 :**
- Création et édition de `MaterialItem` via `POST /api/material-items` et `PATCH /api/material-items/{id}`
- Lecture des firmes via `GET /api/firms` pour les formulaires
- Gestion du workflow des demandes matériel (`MaterialItemRequest`) : PENDING → RESOLVED / IGNORED

**Modèle `MaterialItem` adapté à l'existant :**
- `label` (pas `name`) — cohérence avec l'encodage existant
- `referenceCode` (pas `reference`) — idem
- `isImplant: bool` (pas d'enum IMPLANT/INSTRUMENT/CONSOMMABLE) — le backend ne distingue que 2 états
- `unit` obligatoire — requis par l'entité existante

**Réconciliation demandes matériel :**
Lors de la résolution (`resolve`), le backend :
1. Lie la demande au `MaterialItem` choisi
2. Passe `status → RESOLVED`
3. Crée automatiquement une `MaterialLine` sur la mission concernée (quantity=1)

Ce mécanisme garantit que les lignes matériel de la mission reflètent toujours l'état catalogue.

### Motivation

- Éviter l'accès direct à la base de données pour les opérations courantes
- Fermer la boucle encodage → demande → catalogue → ligne mission
- Donner au manager visibilité et contrôle sur le catalogue sans surcharge technique

### Impact technique

- Ajout `status` + `materialItem` FK sur `MaterialItemRequest` (migration `Version20260315120000`)
- Nouveaux controllers : `FirmController`, `MaterialItemRequestManagerController`
- Extension `MaterialCatalogController` : POST + PATCH
- Feature frontend `manager-catalogue` + pages `CataloguePage` / `CatalogueRequestsPage`
- `DesktopLayout` refactorisé avec sidebar MUI permanente

---

## D-017 — Filtrage PENDING dans l'encoding instrumentiste

Date : 15-03-2026

### Décision

Le payload `GET /api/missions/{id}/encoding` ne renvoie que les `MaterialItemRequest` avec `status = PENDING` dans le tableau `materialItemRequests` de chaque intervention.

Les demandes `RESOLVED` et `IGNORED` sont exclues.

### Motivation

- Une demande `RESOLVED` génère automatiquement une `MaterialLine` sur la mission — elle est donc déjà représentée dans `materialLines`.
- Afficher aussi la demande résolue provoquerait un doublon visuel côté instrumentiste.
- Une demande `IGNORED` n'a plus d'action possible — l'afficher serait du bruit sans valeur.

### Conséquences

- L'instrumentiste voit uniquement les demandes en attente sous chaque intervention.
- Dès que le manager résout une demande, elle disparaît de l'encoding et la MaterialLine correspondante apparaît.
- Le manager voit toutes les demandes (PENDING/RESOLVED/IGNORED) via `GET /api/material-item-requests`.

---

## D-018 — Module Chirurgiens — gestion manager

Date : 15-03-2026

### Décision

Le manager peut créer et gérer des chirurgiens via l'interface, avec le même flux d'invitation que les instrumentistes.

**Périmètre V1 :**
- Création via `POST /api/surgeons` avec envoi d'email d'invitation
- Complétion du profil par le chirurgien via `/complete-account?token=XXX` (même flux)
- Gestion des affiliations site
- Planning : missions où le chirurgien est `mission.surgeon`

**Hors périmètre V1 :**
- Pas de tarifs (pas de `hourlyRate`, `consultationFee`)
- Pas de toggle actif/suspendu
- Pas de notation / rating manager (endpoint existant hors périmètre UI)

### Motivation

- Cohérence avec le module instrumentistes
- Permettre au manager de gérer l'ensemble des acteurs du système
- Réutilisation du flux d'invitation existant sans duplication de code

---

---

## D-019 — Module Facturation Firmes

Date : 18-03-2026

### Décision

Le manager peut générer des factures pour les firmes partenaires à partir des missions `VALIDATED`.

**Modèle de tarification :**
- `PricingRule` lie une `Firm` à une règle tarifaire de type `INTERVENTION_FEE` ou `IMPLANT_FEE`.
- `INTERVENTION_FEE` : matche sur `MissionIntervention.code` (ex: "LCA") → montant fixe par occurrence.
- `IMPLANT_FEE` : matche sur `MaterialItem` (implant) → montant par unité posée.
- Une mission peut générer des lignes pour **plusieurs firmes** (ex: forfait Conmed + implants S&N).

**Anti-doublon :**
- `FirmInvoiceLine` conserve une FK nullable vers `MissionIntervention` (INTERVENTION_FEE) ou `MaterialLine` (IMPLANT_FEE).
- Lors du preview/génération, toute intervention/materialLine déjà présente dans une facture `GENERATED/SENT/PAID` est exclue.

**Contact de facturation :**
- `Firm` porte `billingEmail` et `billingEmailCc` (JSON array), configurables par le manager.
- L'email et les CC sont snapshotés dans `FirmInvoice` au moment de l'envoi.

**Numérotation :** `FIRM-YYYY-NNN` (séquentiel par année).

**PDF :** généré via DomPDF à partir d'un template Twig.

**Email :** envoyé via `SendBillingEmailMessage` (Messenger async) avec PDF en pièce jointe et CC support.

### Entités

```
PricingRule, FirmInvoice, FirmInvoiceLine
```

### Endpoints

```
PATCH  /api/firms/{id}/billing-contact
GET    /api/firms/{id}/pricing-rules
POST   /api/firms/{id}/pricing-rules
PATCH  /api/firms/{id}/pricing-rules/{ruleId}
DELETE /api/firms/{id}/pricing-rules/{ruleId}
GET    /api/firm-invoices
POST   /api/firm-invoices/preview
POST   /api/firm-invoices
GET    /api/firm-invoices/{id}
GET    /api/firm-invoices/{id}/pdf
POST   /api/firm-invoices/{id}/send
POST   /api/firm-invoices/{id}/mark-paid
```

---

## D-020 — Module Décomptes Instrumentistes

Date : 18-03-2026

### Décision

Le manager peut générer des décomptes mensuels de prestations pour chaque instrumentiste.

**Source :** missions `VALIDATED` uniquement, filtrées par mois/année sur `mission.startAt`.

**Calcul BLOC :**
- Durée brute = `endAt - startAt` (minutes)
- Durée arrondie = `ceil(durationRaw / 15) * 15` minutes
- Montant = `(durationRounded / 60) × User.hourlyRate`

**Calcul CONSULTATION :** `1 × User.consultationFee`

**Snapshot :** tarifs (`hourlyRate`, `consultationFee`), nom instrumentiste, nom chirurgien, nom site — tous figés à la génération.

**Anti-doublon :** vérification service-side que la mission n'est pas déjà dans un décompte `GENERATED/SENT/PAID` pour le même mois.

**Un seul décompte `GENERATED+` par (instrumentiste, mois, année)** — refus en `409` si tentative de doublon.

### Entités

```
InstrumentistStatement, InstrumentistStatementLine
```

### Endpoints

```
GET  /api/instrumentist-statements
POST /api/instrumentist-statements/preview
POST /api/instrumentist-statements
GET  /api/instrumentist-statements/{id}
GET  /api/instrumentist-statements/{id}/pdf
POST /api/instrumentist-statements/{id}/send
POST /api/instrumentist-statements/{id}/mark-paid
```

### Impact historique

| Date | Décision |
|---|---|
| 18-03-2026 | D-019 — Module Facturation Firmes |
| 18-03-2026 | D-020 — Module Décomptes Instrumentistes |

---

## Historique

| Date | Décision |
|---|---|
| 18-01-2026 | D-001 à D-003 — Séparation mission/encodage, hiérarchie |
| 17-01-2026 | D-005 — RBAC via Voters |
| 20-01-2026 | D-006, D-008 — allowedActions, garde-fou temporel |
| 31-01-2026 | D-009 — Catalogue lecture libre |
| 16-01-2026 | D-010 — Erreurs normalisées |
| 12-02-2026 | D-012 — Firms référentiel |
| 20-02-2026 | D-013 — Missions DECLARED |
| 12-03-2026 | D-014 — Emails transactionnels via Symfony Mailer + Messenger |
| 11-03-2026 | D-015 — Onboarding instrumentiste par invitation manager |
| 15-03-2026 | D-016 — Module catalogue matériel + gestion demandes |
| 15-03-2026 | D-017 — Filtrage PENDING dans l'encoding instrumentiste |
| 15-03-2026 | D-018 — Module Chirurgiens — gestion manager |
