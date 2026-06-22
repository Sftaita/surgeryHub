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

---

## D-021 — Module Planning — gabarits permanents

Date : 18-03-2026

### Décision

Le planning est généré à partir de **gabarits de semaine permanents** (`PlanningTemplate`), sans dates de validité.

**Modèle retenu :**
- Un `PlanningTemplate` définit une semaine type : `PAIR` / `IMPAIR` / `TOUTES`
- Il est obligatoirement rattaché à un site (`Hospital`)
- Il contient des `PlanningSlot` (créneau : jour, AM/PM, chirurgien, instrumentiste, type)
- La génération déroule les templates sur une plage de dates et crée des missions `DRAFT`
- Le déploiement publie les missions et envoie des PDFs par email

**Algorithme de sélection d'instrumentiste :**
1. Exclusion des instrumentistes absents (`Absence`)
2. Exclusion des instrumentistes déjà affectés (collision de créneau)
3. Scoring sur 100 pts : spécialité (0–40 pts) + historique chirurgien VALIDATED (0–35 pts) + expérience type BLOCK/CONSULTATION (0–25 pts)
4. Tri : historique + spécialité en premier, puis spécialité seule, puis score décroissant

**Types de semaine :**
- `PAIR` : s'applique aux semaines paires du calendrier ISO
- `IMPAIR` : semaines impaires
- `TOUTES` : toutes les semaines (priorité sur PAIR/IMPAIR)

### Motivation

- Éviter la saisie manuelle mission par mission
- Refléter les habitudes récurrentes des chirurgiens
- Permettre la gestion fine PAIR/IMPAIR courante en chirurgie orthopédique

### Impact technique

- Entités : `PlanningTemplate`, `PlanningSlot`, `Absence`
- Service : `PlanningGeneratorService` — calcul semaine ISO, filtrage absences, scoring
- Migrations : suppression `date_start`/`date_end`, `site_id NOT NULL`, `type VARCHAR(7)` (pour TOUTES)
- Controllers : `PlanningTemplateController`, `AbsenceController`, `PlanningGeneratorController`
- Frontend : feature `planning-manager`, pages dans `src/app/pages/manager/planning/`

---

## D-022 — Template naming — édition inline optimiste

Date : 18-03-2026

### Décision

Les `PlanningTemplate` peuvent avoir un nom personnalisé (`label`), éditable inline depuis l'éditeur de gabarit.

**Pattern d'édition optimiste :**
1. Clic sur l'icône crayon → `editingTitle = true`, affichage d'un `TextField`
2. Validation (Enter / blur) → `setEditingTitle(false)` **immédiatement** (fermeture optimiste)
3. `optimisticLabel` mis à jour immédiatement pour l'affichage
4. Mutation API `PATCH /api/planning/templates/{id}` lancée en arrière-plan
5. Succès → `optimisticLabel = undefined` (revenir à la valeur serveur après invalidation)
6. Erreur → `optimisticLabel = previousLabel` + toast d'erreur + réouverture de l'édition

### Motivation

- UX fluide : pas d'attente de la réponse API pour fermer le champ
- Cohérence avec les patterns optimistes du reste de l'application (affiliations de site)
- Rollback fiable en cas d'erreur

### Impact technique

- Endpoint `PATCH /api/planning/templates/{id}` avec body `{ label: string | null }`
- `null` pour effacer le nom (affiché "Sans nom" en italique dans l'UI)
- `renameTemplate()` dans `planning.api.ts`

---

## D-023 — Drag & drop de slots — positionnement optimiste

Date : 18-03-2026

### Décision

L'éditeur de gabarit supporte le glisser-déposer des slots entre cellules (jour × période) via l'API HTML5 DnD native (sans bibliothèque externe).

**Pattern de positionnement optimiste :**
1. `onDragStart` : mémorise `slotId`, `sourceDayOfWeek`, `sourcePeriod`
2. `onDrop` (sur une cellule cible) : appelle `setPosOverrides(map.set(slotId, { dayOfWeek, period }))` immédiatement
3. L'affichage recalcule `effectiveSlots` en appliquant les overrides → la carte est déjà dans sa nouvelle cellule
4. Mutation `PUT /api/planning/templates/{id}/slots/{slotId}` lancée
5. Succès → `posOverrides.delete(slotId)` + invalidation du cache
6. Erreur → `posOverrides.delete(slotId)` + toast + la carte revient à sa position originale

**Contraintes :**
- Un slot ne peut être déplacé que sur une cellule différente (même jour+période = no-op)
- La mutation `updateSlot` accepte `dayOfWeek`, `period`, `startTime`, `endTime`, `surgeonId`, `instrumentistId`

### Motivation

- Éviter le "clignotement" d'une carte qui revient à sa position avant d'aller à la nouvelle
- Feedback immédiat pour l'utilisateur
- Gestion propre du rollback sans état incohérent

### Impact technique

- `posOverrides: Map<number, { dayOfWeek: number; period: "AM" | "PM" }>` dans le state local de `PlanningTemplateEditorPage`
- `effectiveSlots` = `rawSlots.map(s => posOverrides.has(s.id) ? { ...s, ...posOverrides.get(s.id) } : s)`
- HTML5 `draggable`, `onDragStart`, `onDragOver`, `onDrop`, `onDragLeave` — pas de dépendance DnD externe

---

## D-024 — Vue tableau pour la génération du planning

Date : 27-04-2026

### Décision

La page `PlanningGeneratePage` affiche les lignes de prévisualisation sous forme de **tableau groupé par semaine**, calqué sur le format du planning Excel interne (Jour | Date | Chirurgien | Période | Instrumentiste | Site | État).

**Structure du tableau :**
- Une section par semaine ISO, avec barre d'en-tête colorée (bleu = paire, violet = impaire)
- Cellules Jour et Date fusionnées (`rowSpan`) pour les jours multi-créneaux
- Tri au sein de chaque jour : chirurgien A→Z, puis Matin avant Après-midi (dérivé de `startTime < 12:00`)
- Couleur de ligne pilotée par le statut : blanc (COVERED), jaune (UNCOVERED), bleu (MODIFIED), rouge (CONFLICT), gris (SKIPPED)

### Motivation

- Cohérence avec le document de référence PDF/Excel utilisé en production
- Lisibilité supérieure pour une semaine complète vs le layout deux-panneaux (calendrier + détail)
- Facilite la relecture et la validation avant génération

### Impact technique

- `groupIntoWeeks()` : regroupe les `PreviewLine[]` par numéro de semaine ISO, calcule PAIR/IMPAIR, trie les lignes de chaque jour
- `getPeriod(startTime)` : déduit Matin/Après-midi depuis l'heure de début
- `PlanningGeneratePage` : suppression du layout deux-panneaux, rendu `Table` MUI par section semaine

---

## D-025 — Détection de conflits intra-preview

Date : 27-04-2026

### Décision

`PlanningGeneratorService::preview()` détecte désormais deux types de conflits instrumentiste, pas seulement les conflits avec les missions en base.

**Conflit intra-preview :** si deux slots de templates différents assignent le même instrumentiste à des créneaux qui se chevauchent dans la même preview, le second slot reçoit le statut `CONFLICT`. La détection utilise une map en mémoire `$previewAssignments[instrumentistId] = [[dateStr, startMins, endMins], …]` accumulée au fil du traitement.

**Conflit avec DRAFT :** `hasInstrumentistConflict()` n'exclut plus les missions `DRAFT` (seules les `REJECTED` sont exclues). Ainsi, une re-preview après génération détecte les conflits avec les missions générées.

### Motivation

- Sans intra-preview : deux templates assignant le même instrumentiste deux fois le même matin passaient tous les deux `COVERED` — la génération créait des missions en double-booking silencieux
- Sans inclusion DRAFT : une re-preview après génération ne détectait pas les conflits inter-templates générés

### Impact technique

- `PlanningGeneratorService::preview()` : ajout de `$previewAssignments`, vérification avant `hasInstrumentistConflict()`
- `PlanningGeneratorService::hasInstrumentistConflict()` : `excluded` = `[REJECTED]` (DRAFT retiré)
- Test couvert : `test_intra_preview_conflict_detected_across_templates`

---

## D-026 — Attribution manuelle d'instrumentiste inline

Date : 27-04-2026

### Décision

La colonne Instrumentiste de `PlanningGeneratePage` contient un `<Select>` MUI directement dans chaque cellule (pas de dialog ou popover).

**Comportement :**
- La liste de tous les instrumentistes actifs est chargée une fois via `GET /api/instrumentists?active=true` au niveau de la page et passée en prop
- **Avant génération** (`existingMissionId = null`) : la sélection met à jour `previewLines` localement — permet de préparer l'attribution avant de générer
- **Après génération** (`existingMissionId` présent) : la sélection appelle `POST /api/missions/{id}/assign-instrumentist` puis met à jour `previewLines` localement (statut → `COVERED`)
- Désélectionner (valeur vide) remet le statut à `UNCOVERED` localement

### Motivation

- L'approche Popover + suggestions scorées était invisible (clic non évident) et bloquée avant génération
- Un `<Select>` inline est le pattern UX le plus direct pour "liste déroulante"
- Permettre l'attribution avant génération donne plus de flexibilité au manager (ajuster, puis générer)

### Impact technique

- `InstrumentistCell` : composant avec `<Select>` MUI, `canEdit = status !== "SKIPPED"`
- `instrumentistsQuery` : `GET /api/instrumentists?active=true` avec `staleTime: 5min`, chargé au niveau page
- `handleAssigned(lineKey, instrumentistId, name)` : identifie la ligne par `${date}-${slotId}`, met à jour `previewLines`

---

## D-027 — Autocomplete pour chirurgien/instrumentiste dans SlotDialog

Date : 27-04-2026

### Décision

Les champs Chirurgien et Instrumentiste dans `SlotDialog` (éditeur de gabarit) utilisent `<Autocomplete>` MUI au lieu de `<Select>`.

**Comportement :**
- L'utilisateur tape n'importe quelle partie du prénom, nom ou email → la liste filtre en temps réel (filtrage côté client sur la liste déjà chargée)
- Le champ Chirurgien est obligatoire (le bouton Ajouter/Enregistrer reste désactivé si vide)
- Le champ Instrumentiste est optionnel : bouton ✕ pour effacer la sélection
- `noOptionsText="Aucun résultat"` affiché quand la recherche ne matche rien

### Motivation

- Avec `<Select>`, les listes de 20+ chirurgiens/instrumentistes nécessitent un scroll manuel — peu ergonomique
- `<Autocomplete>` permet de trouver rapidement en tapant 2-3 lettres
- Cohérence avec les patterns de l'application (les autres formulaires de recherche utilisent déjà ce pattern)

### Impact technique

- Remplacement de `<FormControl><InputLabel><Select>` par `<Autocomplete renderInput={TextField}>` dans `SlotDialog`
- La valeur `surgeonId`/`instrumentistId` (string) est convertie en `UserOption | null` via `.find()` pour alimenter `value` de l'Autocomplete
- `onChange` reçoit `UserOption | null` et met à jour le form state avec `String(val.id)` ou `""`

---

## D-028 — Couleurs par chirurgien dans l'éditeur de gabarit

Date : 27-04-2026

### Décision

Dans `PlanningTemplateEditorPage`, chaque chirurgien reçoit une couleur visuelle déterministe appliquée à tous ses créneaux (`SlotBlock`), quel que soit le jour de la semaine.

**Implémentation :**
- Palette de 10 paires `{ bg: string; accent: string }` couvrant bleu, émeraude, violet, rose, ambre, teal, rouge, indigo, lime, purple
- Attribution via `getSurgeonColor(surgeonId: number)` → `SURGEON_COLORS[surgeonId % 10]`
- `accent` utilisé pour la bordure gauche et les textes colorés du slot
- `bg` utilisé pour le fond du bloc
- L'indicateur *"Sans instrumentiste"* reste affiché en texte orange pour préserver l'alerte de complétion, indépendamment de la couleur du chirurgien

### Motivation

- Identifier visuellement chaque chirurgien d'un coup d'œil sur la grille hebdomadaire, sans avoir à lire le texte
- Cohérence cross-day : même chirurgien = même couleur sur Lundi, Mercredi, Vendredi

### Impact technique

- `SURGEON_COLORS[]` et `getSurgeonColor()` ajoutés juste avant `SlotBlock` dans `PlanningTemplateEditorPage.tsx`
- Suppression de la logique `isBlock ? BLUE : "#7C3AED"` qui colorait selon le type de mission
- `isBlock` conservé uniquement pour le chip "Bloc/Consult." en bas du bloc

---

## D-029 — Résolution des créneaux non-attribués avant génération

Date : 27-04-2026

### Décision

Un bouton **"Résoudre les non-attribués (N)"** apparaît sur la page de génération dès qu'il y a des lignes `UNCOVERED` après preview. Il ouvre un modal qui propose une action par ligne, mutuellement exclusive :

**Option A — Instrumentiste libéré disponible :**
- Détecté depuis les lignes `SKIPPED` du même jour (chirurgien absent → instrumentiste assigné libéré)
- Validation : l'instrumentiste n'a pas de slot actif qui chevauche le créneau cible
- Action : `POST /api/missions` (DRAFT) + `POST /api/missions/{id}/publish { scope: TARGETED, targetUserId }`
- Résultat : mission ciblée vers l'instrumentiste ; ligne → COVERED dans le tableau

**Option B — Aucun libéré disponible :**
- Action : `POST /api/missions` (DRAFT) + `POST /api/missions/{id}/publish { scope: POOL }`
- Résultat : mission ouverte à tous les instrumentistes ; ligne → "Demande envoyée" (fond bleu)

### Cohérence avec "Générer"

`PlanningGeneratorService::generate()` est modifié pour ne PAS écraser les missions MODIFIED dont le statut est hors `DRAFT` (OPEN, ASSIGNED…). Les missions créées et publiées manuellement en étape ② sont donc préservées lors du batch "Générer".

### Calcul des libérés — frontend only

Aucun nouvel endpoint backend. La logique `getFreedInstrumentists(previewLines, target)` :
1. Filtre les `SKIPPED` du même jour avec un instrumentiste
2. Retire ceux qui ont un slot actif chevauche le créneau cible
3. Retourne la liste avec l'explication ("Libéré — Dr X est absent ce jour-là")

### Motivation

- Éviter que des créneaux UNCOVERED restent sans solution jusqu'au déploiement
- Valoriser les instrumentistes "libérés" par les absences de chirurgiens
- Offrir une alternative (POOL) quand aucun libéré n'est disponible
- Pas de nouvel endpoint backend — tout repose sur les endpoints mission existants

---

## D-030 — Doublon instrumentiste dans l'éditeur de gabarit

Date : 28-04-2026

### Décision

`DayTimeline` calcule en `useMemo` les instrumentistes qui apparaissent sur des créneaux qui se chevauchent dans le même jour. Les `SlotBlock` concernés reçoivent `isDuplicate=true` et affichent : fond orange, outline orange, badge "Doublon" en bas du time range.

**Algorithme** : regroupement des slots par `instrumentist.id` → test `overlaps()` sur chaque paire → `Set<number>` des IDs doublon.

### Motivation

La doc (D-023, UX clé éditeur) mentionne ce comportement mais il n'était pas implémenté. Un manager pouvait créer deux slots lundi matin avec le même instrumentiste sans aucun avertissement — la collision n'était détectée qu'au preview de génération.

---

## D-031 — Alerte déploiement manquant + AppErrorBoundary MUI

Date : 28-04-2026

### Décision

**Alerte déploiement :** après une génération réussie, un `<Alert severity="warning">` persiste tant que le manager n'a pas cliqué "Déployer". Il disparaît après un déploiement réussi (`deployed = true`). Réinitialisation lors d'un re-preview.

**AppErrorBoundary :** l'affichage de secours en cas de crash React est désormais stylé (HTML/CSS autonome, sans dépendance MUI pour être robuste à un crash du ThemeProvider) : carte centrée, icône warning, message, détail de l'erreur en mode dev, bouton "Recharger la page".

### Motivation

- Sans alerte déploiement : risque réel de laisser des missions en DRAFT (invisibles côté instrumentiste) après génération
- Sans AppErrorBoundary propre : un crash React affichait du HTML brut non stylé, confusant pour l'utilisateur

---

## D-032 — Attribution directe libéré + DeployPreCheckModal

Date : 28-04-2026

### Décision

**Attribution directe (Option B)** : quand un instrumentiste libéré est assigné via "Envoyer" dans le `ResolveModal`, la mission est créée en DRAFT avec `instrumentistUserId` directement défini (`POST /api/missions { instrumentistUserId }`). Plus de `publishMission TARGETED`. Le déploiement publie ensuite la mission → l'instrumentiste la voit comme la sienne (OPEN avec son nom). Le flux OPEN → ASSIGNED sera traité dans une itération future.

**Re-preview automatique après Générer** : après `generateMutation.onSuccess`, un appel `previewPlanning` est lancé automatiquement pour synchroniser les `existingMissionId` dans `previewLines`. Sans ça, le `DeployPreCheckModal` ne sait pas quelles lignes UNCOVERED ont déjà une mission créée par "Générer" et risquerait de créer des doublons.

**DeployPreCheckModal** : remplace le simple confirm dialog du déploiement. S'ouvre au clic "Déployer et envoyer les PDFs". Filtre les lignes `status === "UNCOVERED" && existingMissionId === null && !openRequestKeys` (vraiment sans mission). Propose :
- "Créer toutes les missions (N)" — batch
- "Créer une mission" par ligne
- "Ignorer et déployer" — skip les non-résolus
- "Déployer et envoyer les PDFs" — procède au déploiement

### Workflow complet avec ces changements

```
① Preview
② Résoudre les non-attribués (libérés → attributions directes DRAFT)
③ Générer (batch DRAFTs + re-preview auto)
④ Déployer → DeployPreCheckModal
     → créer missions restantes si besoin
     → confirmer déploiement (publie tous les DRAFTs + envoie PDFs)
```

### Flux à construire (itération future)

Après déploiement, les missions OPEN avec `instrumentist` pré-assigné (Option B) ont besoin d'un flux côté instrumentiste (claim automatique ou interface dédiée).

---

## D-033 — Page planning publié (`PlanningSchedulePage`)

Date : 28-04-2026

### Décision

Une nouvelle page `/app/m/planning/schedule` ("Planning" dans la sidebar) affiche les missions publiées sous le même format de tableau semaine par semaine que `PlanningGeneratePage`.

**Source de données :** `GET /api/missions?from=...&to=...&siteId=...` avec pagination limit=500. Les missions DRAFT et REJECTED sont exclues côté frontend.

**Structure du tableau :** identique à la page de génération — sections PAIR/IMPAIR, rowSpan Jour+Date, tri chirurgien A→Z puis Matin avant Après-midi.

**Colonne Statut :** chip coloré par statut mission :

| Statut | Chip |
|---|---|
| OPEN | bleu "À réserver" (outlined) |
| ASSIGNED | vert "Assigné" |
| SUBMITTED | primary "Soumis" |
| VALIDATED | secondary "Validé" |
| DECLARED | orange "Déclaré" |
| CLOSED | gris "Clôturé" |

**Instrumentiste modifiable inline :** `<Select>` MUI pour les missions `OPEN` et `ASSIGNED` — appelle `POST /api/missions/{id}/assign-instrumentist`. Read-only pour les autres statuts.

**Chargement manuel :** bouton "Charger le planning" (la query est `enabled: false` — pas de chargement automatique au montage).

### Motivation

- Donner au manager une vue de lecture/modification du planning déployé sans repasser par la génération
- Même format visuel que le tableau de génération → cohérence UX

### Impact technique

- `PlanningSchedulePage.tsx` : nouvelle page avec `ScheduleRow` (interface locale), `toRow(Mission)`, `groupRows()`, `ScheduleInstrumentistCell`
- Route ajoutée : `m/planning/schedule` dans `AppRouter.tsx`
- Item ajouté dans la section Planning de `DesktopLayout.tsx`
- `architecture.md` : route `/app/m/planning/deploy` (obsolète) remplacée par `/app/m/planning/schedule`

---

## D-034 — Auto-assignation backend des instrumentistes libérés dans preview()

Date : 28-04-2026

### Décision

`PlanningGeneratorService::preview()` effectue un **second passage** après la boucle principale pour réaffecter automatiquement les instrumentistes libérés aux créneaux sans instrumentiste du même jour.

### Motivation

Sans ce mécanisme, quand un chirurgien est absent, son instrumentiste apparaissait libéré (SKIPPED) mais les autres créneaux du même jour restaient UNCOVERED ou COVERED-sans-instrumentiste sans proposition automatique. Le manager devait manuellement ouvrir le modal "Résoudre" pour chaque créneau, ce qui était contre-intuitif.

### Règle "libéré"

Un instrumentiste est "libéré" si et seulement si **tous** ses slots du jour sont SKIPPED. Un instrumentiste qui a au moins un slot actif (non-SKIPPED) n'est pas libéré.

### Lignes concernées par le second passage

Le second passage traite **deux types** de lignes — c'est le point critique :

1. `status === 'UNCOVERED'` — slot sans instrumentiste, aucune mission existante
2. `status === 'COVERED' && instrumentistId === null` — **mission existante** sans instrumentiste

Le cas 2 est non-évident : quand une génération précédente a créé une mission DRAFT sans instrumentiste (parce qu'aucun n'était disponible à l'époque), la preview la marque COVERED (mission existante = slot couvert). Sans le traitement du cas 2, Françoise n'était pas proposée à Jérôme même si elle était libre, parce que la ligne Jérôme était déjà COVERED.

### Affectation multi-créneaux

Un instrumentiste libéré peut couvrir **plusieurs créneaux non-chevauchants** le même jour. L'instrumentiste **n'est pas retiré du pool** après la première affectation. Les affectations suivantes sont contrôlées par `$secondPassAssignments` (map en mémoire) pour éviter le double-booking entre les affectations du second passage lui-même.

### Impact sur `generate()`

Quand `generate()` voit une ligne `COVERED + freedFrom=true + existingMissionId` avec `instrumentistId != null`, il met à jour l'instrumentiste de la mission DRAFT existante au lieu de la sauter. Ainsi, après "Générer", les missions existantes reçoivent bien Françoise comme instrumentiste.

### Champs ajoutés à `PreviewLine`

| Champ | Usage |
|---|---|
| `freedFrom: bool` | `true` si auto-assigné depuis un libéré — badge vert "Libéré" en frontend |
| `existingInstrumentistId: int\|null` | Instrumentiste actuel de la mission existante (MODIFIED) |
| `existingInstrumentistName: string\|null` | Nom affiché dans le tooltip MODIFIED |

### Test de régression

`PlanningFreedInstrumentistTest::test_freed_instrumentist_assigned_to_covered_missions_without_instrumentist` — vérifie explicitement que des missions COVERED sans instrumentiste reçoivent l'instrumentiste libéré via le second passage.

---

## D-035 — Multi-salle : claim exclusif de mission par slot (claimMission)

Date : 28-04-2026

### Décision

Quand un chirurgien opère dans **deux salles simultanément** (même heure, deux instrumentistes différents), le système de preview doit associer chaque slot à sa propre mission existante — pas toujours la même.

### Problème initial

`findExistingMission()` cherchait par chirurgien+site+jour+heure et retournait la **première mission trouvée**. Pour deux slots PM d'Arnaud Deltour (Salve Decorte et Sophie Colette) :
- Slot 1 (Salve) → mission trouvée avec Salve → COVERED ✅
- Slot 2 (Sophie) → **même mission** trouvée, Salve ≠ Sophie → MODIFIED ❌

### Solution : pré-chargement + claim exclusif

`findExistingMission()` (requête DB par slot) est remplacée par :
1. **`loadExistingMissionsPool()`** — une seule requête DQL au début de `preview()`, groupe les missions par `"{surgeonId}_{siteId}_{YYYY-MM-DD}"`
2. **`claimMission()`** — pour chaque slot, cherche dans le pool la meilleure mission non encore réclamée :
   - Priorité 1 : match exact instrumentiste (slot.instrumentistId === mission.instrumentistId)
   - Priorité 2 : n'importe quelle mission non réclamée à la même heure (±30 min)
   - Une mission réclamée ne peut plus être attribuée à un autre slot (`$claimedMissionIds`)

### Résultat

- Deux missions en base (Salve + Sophie) → chaque slot claim la sienne → deux COVERED ✅
- Une seule mission en base (Salve) → slot 1 claim (exact match), slot 2 → UNCOVERED (crée une nouvelle mission à la génération) ✅

### Bonus : performance

Un seul `SELECT` pour charger toutes les missions de la période au lieu de N requêtes (une par slot). Le `hasInstrumentistConflict()` reste une requête séparée car il vérifie les chevauchements horaires.

### Test de régression

`PlanningGeneratorServiceTest::test_multi_room_two_slots_same_surgeon_same_time_each_claim_own_mission`

---

## D-036 — Optimisation preview() : 3 pré-chargements DB au lieu de N×M requêtes

Date : 29-04-2026

### Décision

`PlanningGeneratorService::preview()` ne fait **que 3 requêtes DB** pour toute période, quelle que soit sa longueur :

| Requête | Méthode | Quoi |
|---|---|---|
| 1 | `loadAllTemplates()` | Tous les templates + slots (QB, filtré par site) |
| 2 | `loadAbsencesMap()` | Toutes les absences de la période (DQL token `absencesFrom`) |
| 3 | `loadExistingMissionsPool()` | Toutes les missions existantes (DQL token `poolFrom`) |

Tout le reste (filtrage PAIR/IMPAIR, vérification absences, détection conflits) s'effectue **en mémoire**.

### Problème initial

Avant l'optimisation, la boucle `while ($current <= $end)` exécutait **par jour** :
- 1 QB pour charger les templates
- Par slot : 1 query `isAbsent(surgeon)` + 1 query `isAbsent(instrumentist)` + 1 query `hasInstrumentistConflict`

Résultat : ~1 830 queries pour 61 jours × 10 slots/jour. Doublement avec le re-preview automatique post-génération → **timeout HTTP**.

### Méthodes de remplacement

| Ancienne méthode | Nouvelle méthode | Changement |
|---|---|---|
| `isAbsent(User, day)` → 1 DB | `isAbsentFast(userId, dayStr, $absencesByUser)` | In-memory map lookup |
| `hasInstrumentistConflict(User, ...)` → 1 DB | `hasConflictFast(instId, start, end, $missionsByInstrumentist)` | In-memory map lookup |
| QB templates per day | `loadAllTemplates()` once + filtrage PAIR/IMPAIR in-memory | 1 QB au lieu de N |

### Structures de données

- `$absencesByUser` : `[userId => [[dateStart Y-m-d, dateEnd Y-m-d], ...]]`
- `$existingMissionsPool` : `["{surgeonId}_{siteId}_{YYYY-MM-DD}" => Mission[]]`
- `$missionsByInstrumentist` : `[instrumentistId => Mission[]]` (index secondaire du pool)

### Test de régression

`PlanningPreviewPerformanceTest::test_two_month_preview_uses_only_3_db_queries` vérifie que pour 61 jours le total de requêtes DB est exactement 3 (1 QB + 1 absencesFrom + 1 poolFrom).

---

## D-037 — Déploiement asynchrone : PDF et emails via PlanningDeployPdfsMessageHandler

Date : 29-04-2026

### Décision

`PlanningDeploymentService::deploy()` ne fait **que le travail DB** (rapide) et retourne immédiatement. La génération des PDFs et l'envoi des emails sont délégués à `PlanningDeployPdfsMessageHandler` via Messenger (asynchrone).

### Problème initial

`deploy()` générait tous les PDFs synchroniquement (DomPDF × N instrumentistes + N chirurgiens + 1 global) avant de répondre. Avec ~194 missions → timeout HTTP systématique.

### Architecture après

```
HTTP POST /api/planning/deploy
    │
    ▼
PlanningDeploymentService::deploy()
    ├── Archive version ACTIVE précédente (si applicable)
    ├── Passe version DRAFT → ACTIVE
    ├── Publie missions DRAFT → OPEN
    ├── Persist + flush
    └── bus.dispatch(PlanningDeployPdfsMessage)  ← async
            │
            ▼  (dans le worker Messenger)
    PlanningDeployPdfsMessageHandler
        ├── Charge les missions publiées
        ├── Génère PDF global (1 fois)
        ├── Pour chaque instrumentiste : génère PDF perso + envoie email
        ├── Pour chaque chirurgien : génère PDF perso + PDF global + envoie email
        └── Push notifications (ASSIGNED + OPEN pool + manager)
```

**Retour HTTP** : `{ deploymentId, missionCount, openPoolCount }` — retourné immédiatement après le flush.

### Règles

- Failure d'un PDF individuel → logged, autres PDFs continuent (try/catch par individu)
- Failure SMTP → ne bloque pas le worker (Messenger retry automatique)
- La réponse HTTP ne dépend pas du succès des PDFs

---

## D-038 — Résumé version par période+site (pas par FK) + sémantique "skipped" clarifiée

Date : 29-04-2026

### Décision

#### Résumé `GET /api/planning/versions/{id}`

**Avant :** `PlanningVersionController` comptait `$version->getMissions()` — missions liées à cette version par FK `planningVersion_id`. À la 2e génération d'une même période, si rien n'est créé (tout déjà couvert), la version a 0 missions liées → résumé à 0 partout → confusion.

**Après :** Le contrôleur requête **toutes les missions non-rejetées de la période+site** de la version, indépendamment de leur `planningVersion_id`. Cela donne la vraie image du planning actuel quelle que soit la version qui les a créées.

Nouveaux champs `summary` :

| Champ | Remplace | Description |
|---|---|---|
| `draft` | — | DRAFT — en attente de déploiement |
| `open` | — | OPEN — publiées, disponibles pool |
| `assigned` | `assigned` | ASSIGNED+ — avec instrumentiste confirmé |
| `withoutInstrumentist` | `unassigned` | DRAFT ou OPEN sans instrumentiste |

#### Sémantique "skipped" dans `POST /api/planning/generate`

`skipped` compte **deux cas** :
1. Chirurgien absent → slot SKIPPED
2. Slot avec mission existante déjà couverte → préservée sans modification

Le frontend affiche "X mission(s) existantes préservées" quand `created === 0 && updated === 0` pour éviter la confusion "205 ignorées" qui semblait indiquer un problème.

### Motivation

Quand un manager génère une 2e fois sur la même période (pour ajouter un slot ou corriger), le résumé montrait 0 partout même si 200 missions existaient déjà. Cela créait de l'inquiétude.

---

## D-039 — Modal déploiement 2 étapes : selectedUncoveredMissionIds + sendChangeSummary

Date : 30-04-2026

### Décision

Le déploiement du planning passe par un modal en 2 étapes côté frontend.

**Étape 1 — Postes sans instrumentiste**

Le manager voit la liste des missions DRAFT sans instrumentiste (avec `existingMissionId` non null). Checkboxes cochées par défaut. Il peut décocher celles qu'il ne veut pas publier en pool. Les IDs cochés sont envoyés dans `selectedUncoveredMissionIds`.

**Étape 2 — Récapitulatif des modifications**

Le frontend appelle `GET /api/planning/versions/{id}/diff` pour afficher le diff. Une checkbox `sendChangeSummary` (pré-cochée si diff non vide) déclenche l'envoi d'emails de récapitulatif par le worker.

**Règles de statut à la publication :**

```
DRAFT + instrumentist IS NOT NULL            → ASSIGNED  (2 bulk UPDATEs séparés)
DRAFT + instrumentist IS NULL + sélectionné  → OPEN
DRAFT + instrumentist IS NULL + non sélectionné → reste DRAFT
```

**Retour HTTP :** `{ deploymentId, missionCount, openPoolCount }`

### Motivation

Avant ce modal, toutes les missions DRAFT passaient OPEN en bloc, y compris les non-attribuées que le manager n'avait pas l'intention de publier en pool. La distinction ASSIGNED/OPEN n'existait pas.

---

## D-040 — PlanningDiffService : clé de matching missions entre versions

Date : 30-04-2026

### Décision

**Clé de matching :** `siteId_surgeonId_missionType_date_startAt(arrondi 15 min)`

- **Priorité 1 :** `templateSlotId` — non disponible sur l'entité Mission (pas de FK template → mission après génération).
- **Priorité 2 (implémentée) :** composite key `siteId + surgeonId + missionType + date + startAt` avec arrondi à 15 min.

**Pourquoi l'arrondi 15 min ?**
Absorbe les micro-décalages entre versions (08:00 ↔ 08:07 → même slot, pas de fausse modification). Les vrais changements d'horaire (08:00 ↔ 08:30 → clés distinctes) sont détectés. La comparaison exacte `startAt` dans `detectChanges()` signale quand même la différence de temps.

**Collision :** deux missions avec exactement la même clé (même chirurgien, même site, même type, même heure arrondie) reçoivent un suffixe `_1`, `_2`... Le matching cross-version en cas de collision est order-dépendant (limite V1 acceptée, cas très rare en pratique).

**Champs comparés :** `startAt`, `endAt`, `surgeon`, `site`, `instrumentist`.
**Exclus :** statut, notes, champs financiers, timestamps.

### Conséquences

- `PlanningDiffService::computeDiff(array $old, array $new)` est publique et testable sans EM.
- `PlanningDiffService::diff(PlanningVersion $draft)` orchestre : trouve version précédente (ACTIVE → ARCHIVED) + charge missions par FK + délègue à `computeDiff`.

---

## D-041 — Idempotence PlanningDeployment : status PENDING/PROCESSING/DONE/FAILED

Date : 30-04-2026

### Décision

`PlanningDeployment` porte un champ `status` enum :

| Valeur | Signification |
|---|---|
| `PENDING` | Créé par `deploy()`, worker pas encore démarré |
| `PROCESSING` | Worker a commencé le traitement |
| `DONE` | Worker a terminé avec succès |
| `FAILED` | Worker a échoué (errorLog rempli) |

**Idempotence :** le handler vérifie en entrée `status == DONE` → return immédiatement si vrai.

**Limite V1 :** si le worker crash entre PROCESSING et DONE (→ retry Messenger), il re-exécute intégralement (PDF et emails peuvent être dupliqués). Un log d'envoi par destinataire/canal éliminerait ce risque mais est différé.

**Retry :** en cas d'exception, le handler re-throw → Messenger planifie une nouvelle tentative (max 5, délai exponentiel). `status` est mis à `FAILED` + `errorLog` tronqué à 65 535 chars.

### Résultat pour les lignes existantes avant migration

La migration `Version20260429000001` ajoute `status DEFAULT 'DONE'` pour les déploiements antérieurs.

---

## D-042 — getReference() après em->clear() pour éviter cascade persist

Date : 30-04-2026

### Décision

Dans `PlanningDeploymentService::deploy()`, après les bulk DQL UPDATEs, `em->clear()` est appelé pour purger l'identity map. En Doctrine ORM 3.x, `clear(ClassName::class)` est ignoré — **toutes les entités sont détachées**, y compris `$deployedBy` (User).

Utiliser `$deployment->setDeployedBy($deployedBy)` directement après `em->clear()` → flush() throw :
```
A new entity was found through PlanningDeployment#deployedBy that was not configured
to cascade persist.
```

**Fix :** `$this->em->getReference(User::class, $deployedBy->getId())` retourne un proxy Doctrine **managé** sans requête SQL.

```php
$deployment->setDeployedBy(
    $this->em->getReference(User::class, $deployedBy->getId())
);
```

**Test de régression :** `test_deploy_calls_getReference_for_deployedBy_to_survive_em_clear` vérifie via `expects($this->once())->method('getReference')->with(User::class, 1)` que l'appel est présent.

---

## D-043 — Routing Messenger obligatoire pour tout message à handler IO-intensif

Date : 30-04-2026

### Décision

Tout message Symfony Messenger dont le handler fait du IO significatif (PDF, SMTP, DB writes) **doit** être explicitement routé vers le transport `async` dans `config/packages/messenger.yaml`.

```yaml
routing:
    App\Message\PlanningDeployPdfsMessage: async  # PDF Dompdf + SMTP
    App\Message\SendBillingEmailMessage:   async  # SMTP
    App\Message\SendTemplatedEmailMessage: async  # SMTP
```

### Problème initial (régression)

`PlanningDeployPdfsMessage` et `SendBillingEmailMessage` n'avaient pas de règle de routing. Symfony Messenger les traitait **synchroniquement dans la requête HTTP** par défaut. La génération PDF (Dompdf × N instrumentistes + N chirurgiens) excédait le timeout axios de 10 s → erreur frontend systématique au déploiement.

### Règle structurante

> Tout nouveau message avec un handler IO-intensif doit ajouter sa ligne dans `messenger.yaml` **avant** de merger.

**Test de régression :** `tests/Unit/Config/MessengerRoutingTest.php` parse le YAML et vérifie que `PlanningDeployPdfsMessage` et `SendBillingEmailMessage` sont bien routés vers `async`. Si le routing est retiré accidentellement, le test échoue immédiatement.

---

## D-044 — Observabilité : Sentry + channel Monolog push

Date : 2026-05-29

### Décision

Deux outils d'observabilité mis en place :

**Sentry** — capture des erreurs en production, côté backend et frontend.

- Backend : `sentry/sentry-symfony` — capture toutes les exceptions PHP non gérées ; le handler Monolog `sentry` (level `error`) remonte également les erreurs critiques du canal `push`.
- Frontend : `@sentry/react` — initialisé dans `main.tsx` avant le render. `AppErrorBoundary.componentDidCatch` appelle `Sentry.captureException()` pour remonter les crashes React.
- `SENTRY_DSN` configuré dans `backend/.env` et `VITE_SENTRY_DSN` dans `frontend/.env`.
- Sentry se désactive automatiquement si la variable d'environnement est absente (`enabled: !!dsn`).

**Channel Monolog `push`** — tous les événements liés aux push notifications passent par ce canal dédié, séparé du log applicatif général.

| Événement | Level | Déclencheur |
|---|---|---|
| `push.subscription_created` | INFO | Nouveau device enregistré |
| `push.subscription_updated` | INFO | Re-subscribe device existant |
| `push.subscription_removed` | INFO | Unsubscribe explicite |
| `push.send_failed` | WARNING | Notification refusée par le push service |
| `push.flush_failed` | ERROR | Push service injoignable — capturé par Sentry |
| `push.batch_done` | INFO | Récap batch (sent / failed / expired) |

### Motivation

- Sans observabilité, les échecs push (encoding incorrect, service down, subscription expirée) étaient silencieux — aucun moyen de savoir si les notifications arrivent.
- Sentry permet d'être alerté en temps réel sur les exceptions prod sans avoir à surveiller les logs manuellement.
- Un canal `push` dédié permet de filtrer et monitorer uniquement les événements push sans bruit.

### Variables d'environnement

```
# backend/.env
SENTRY_DSN="https://..."

# frontend/.env
VITE_SENTRY_DSN="https://..."
```

### Impact technique

- `WebPushService` : injecte `monolog.logger.push` via `#[Autowire]`, logs structurés avec contexte (`type`, `endpoint`, `sent`/`failed`/`expired`)
- `PushSubscriptionController` : idem, log sur subscribe/unsubscribe
- `config/packages/monolog.yaml` : canal `push` déclaré, handler `sentry` ajouté en `when@prod`
- `config/packages/sentry.yaml` : config Sentry bundle
- `config/bundles.php` : `SentryBundle` enregistré
- `frontend/src/main.tsx` : `Sentry.init()` avec `browserTracingIntegration`, `tracesSampleRate: 0.1`
- `frontend/src/app/errors/AppErrorBoundary.tsx` : `Sentry.captureException` dans `componentDidCatch`

---

## D-045 — Synchronisation missions instrumentiste par polling intelligent (V1, sans Mercure)

Date : 2026-06-12

### Décision

Synchronisation des missions instrumentiste (onglet Offres / Mes missions / Planning) via un
endpoint de polling dédié plutôt que Mercure/WebSocket :

- `GET /api/instrumentist/missions/sync?since=ISO_DATE` — retourne `{ serverTime, changed,
  missions[], removedMissionIds[] }` (voir `docs/api.md` §27).
- Index DB `IDX_MISSION_UPDATED_AT` sur `mission.updated_at` (migration
  `Version20260610000001`) pour permettre un filtrage `since` performant.
- `updatedAt` est déjà rafraîchi automatiquement sur toute transition de statut
  (`TimestampableTrait` + `#[ORM\PreUpdate]`) — aucune modification nécessaire dans
  `MissionService` pour publish/claim/submit/approve/reject.
- Frontend : hook `useInstrumentistMissionSync()` monté dans `MobileLayout` — polling 30s
  actif uniquement si connecté + `ROLE_INSTRUMENTIST` + onglet visible + réseau online ; pause
  si hidden/offline ; refresh immédiat au retour online/focus ou via `requestMissionSync()`
  (bus d'événements) après claim/submit/declare.
- `lastSyncAt` est dérivé de `serverTime` (jamais de l'heure locale), persisté en
  `localStorage`.
- Le cache React Query (`["missions", ...]`, toutes clés dynamiques incluses) est patché en
  place par `applyMissionSyncToCache` : update/suppression des missions existantes, ajout des
  nouvelles offres OPEN claimables, ajout des missions nouvellement assignées dans "Mes
  missions".
- Anti-spam : un seul toast groupé ("N nouvelles missions disponibles") même si plusieurs
  offres arrivent dans le même cycle.

### Motivation

- Hébergement mutualisé (Hostinger) — Mercure/WebSocket non disponibles.
- Les instrumentistes doivent voir apparaître les nouvelles missions OPEN sans refresh manuel,
  et voir disparaître les offres prises par d'autres (anti-collision sur le claim).

### Garde-fous (inchangés)

- Aucune donnée patient dans la réponse de sync (`MissionListDto` uniquement).
- `allowedActions[]` reste l'unique source de vérité côté frontend — aucune inférence de droit
  basée sur le statut.
- Le `claim` reste transactionnel côté `POST /api/missions/{id}/claim` (`409` si déjà prise) ;
  l'endpoint de sync ne fait que refléter l'état serveur.

---

---

## D-046 — Module Administration — ROLE_ADMIN

Date : 2026-06-16

### Décision

Ajout d'un rôle `ROLE_ADMIN` superposé à `ROLE_MANAGER`. Les admins ont accès à l'intégralité
du module manager **et** à un module Administration dédié exposant :

- **Gestion des utilisateurs** (`GET/POST /api/admin/users`, `GET/PATCH /api/admin/users/{id}`)
- **Transitions d'état utilisateur** (endpoints dédiés : `/suspend`, `/activate`, `/change-role`,
  `/resend-invitation`, `/site-memberships`)
- **Vue invitations** (`GET /api/admin/invitations`)
- **Journal d'audit** (`GET /api/admin/audit`) via l'entité `UserAuditEvent` (séparée de
  `AuditEvent` dont la FK mission est NOT NULL)

Contraintes retenues :

- `UserAdministrationVoter` — toutes les permissions exigent `ROLE_ADMIN` ; aucun contrôle de
  rôle direct dans les contrôleurs.
- `UserAdministrationService` — toute la logique métier centralisée (création, suspension,
  changement de rôle, renvoi d'invitation, gestion sites). Flush contrôlé par le service.
- `UserAuditService` — journalise chaque action critique ; ne flush pas (laisse le service
  appelant contrôler la transaction).
- L'invitation est désormais générique (`NotificationService::sendUserInvitation()`) — bug
  silencieux corrigé : `findInstrumentistByInvitationToken()` filtrait par rôle et empêchait les
  chirurgiens/managers de compléter leur compte.
- `invitationLastSentAt` (nouveau champ `User`) détermine le statut `email_not_sent` distinct de
  `expired`/`pending`.
- `SiteMembership.siteRole` VARCHAR(50) sans contrainte DB — la valeur `'MANAGER'` est acceptée
  sans migration supplémentaire.
- Frontend : section **Administration** visible uniquement si `role === 'ADMIN'` dans le sidebar ;
  garde `RequireAdmin` sur toutes les routes `/app/admin/*`.

### Motivation

- Un ROLE_ADMIN doit pouvoir gérer tous les utilisateurs sans passer par le manager d'un site.
- L'auditabilité des actions administratives requiert une trace séparée des missions.
- Le flux d'invitation n'était pas générique et cachait un bug silencieux sur les chirurgiens.

### Garde-fous

- Aucun impact sur les workflows manager existants.
- Aucune donnée patient dans le module admin.
- L'ADMIN ne peut pas changer son propre rôle (garde côté `UserAdministrationService`).
- Le frontend ne déduit aucun droit — `allowedActions[]` reste la règle générale, et les boutons
  d'action sont pilotés par l'état retourné par le backend.

### Contrainte FK `actor_id` (UserAuditEvent)

`actor_id` est NOT NULL avec `ON DELETE RESTRICT` (comportement par défaut MySQL). En conséquence,
un utilisateur ayant généré des événements d'audit en tant qu'acteur **ne peut pas être supprimé**
de la base tant que ces événements existent. C'est intentionnel : l'audit trail est une obligation
et la suppression physique d'admins n'est pas supportée (un admin peut être suspendu, pas supprimé).
`target_user_id` utilise `ON DELETE SET NULL` pour préserver les événements même si la cible est
supprimée.

---

## D-047 — Remember me / session persistante

Date : 2026-06-19

### Objectif

Permettre une session persistante optionnelle ("Se souvenir de moi") sur `/login`, sans toucher
au mécanisme d'authentification existant (Lexik JWT + Gesdinet refresh token + stockage
`localStorage` côté frontend).

### Décision

Ajout d'un champ optionnel `rememberMe` (boolean, défaut `false`) au payload de
`POST /api/auth/login` et `POST /api/auth/google`. Il pilote uniquement la **durée de vie du
refresh token** émis au login :

| Token | Durée |
|---|---|
| Access token (Lexik JWT) | 1 heure — fixe, indépendante de `rememberMe` |
| Refresh token, `rememberMe=false` (défaut) | 1 jour |
| Refresh token, `rememberMe=true` | 30 jours (durée historique du bundle, inchangée) |

Le champ `remember_me` (boolean) est ajouté à l'entité `RefreshToken` / table `refresh_tokens`
(migration `Version20260619145211`) pour tracer le mode de session associé à chaque token.

**Un seul refresh token est créé par login**, et **un appel à `/api/auth/refresh` n'en crée
jamais un second** : pas de rotation du refresh token en V1 — le même refresh token reste valable
jusqu'à son expiration. Ce choix s'appuie sur le comportement par défaut du bundle
`gesdinet/jwt-refresh-token-bundle` (`ttl_update: false`), déjà en place — l'introduire aurait
nécessité de la complexité supplémentaire (gestion de concurrence multi-onglets) pour un gain de
sécurité marginal vu que le token reste côté client en `localStorage` uniquement (jamais exposé à
un tiers via cookie). Voir aussi le correctif anti-orphelin ci-dessous : sans lui, le listener du
bundle Gesdinet créait un second refresh token à chaque login (avec un TTL fixe de 30 jours,
ignorant `rememberMe`).

Le logout (`POST /api/auth/logout`, nouveau) invalide/supprime le refresh token fourni, côté
serveur, en base. Implémenté en activant la configuration `logout: { path: /api/auth/logout }`
sur le firewall `api` : le listener `Gesdinet\JWTRefreshTokenBundle\EventListener\LogoutEventListener`
(déjà fourni par le bundle, jusqu'ici inutilisé car aucun firewall ne déclarait de `logout:`)
prend en charge l'invalidation sans code applicatif supplémentaire.

Le **frontend conserve le stockage `localStorage`** en V1 (pas de cookie). Le **CORS reste
inchangé** (`allow_credentials: false` dans `nelmio_cors.yaml`) — pas de cookie HttpOnly en V1.

**Raison de ce choix** : changement minimal et compatible avec l'existant — `rememberMe` ne fait
que faire varier un TTL et ajouter un appel de logout, sans casser l'intercepteur Axios (refresh +
retry + mutex déjà en place) ni `AuthContext` (bootstrap au chargement, état `anonymous` /
`loading` / `authenticated`). Passer à un cookie HttpOnly aurait nécessité une refonte CORS
(`allow_credentials: true`, origines explicites) et la réécriture de l'intercepteur Axios et de
`AuthContext` — hors périmètre de cette fonctionnalité.

### Évolution future recommandée

Si le risque d'exfiltration du refresh token via XSS devient une préoccupation prioritaire (le
`localStorage` est lisible par tout script exécuté dans la page), migrer le refresh token vers un
**cookie HttpOnly, `Secure`, `SameSite=Lax`**, avec :
- `allow_credentials: true` et origines CORS explicites (plus de regex large) ;
- l'intercepteur Axios adapté pour ne plus lire/écrire le refresh token en JS (le navigateur le
  gère via le cookie) ;
- `AuthContext` simplifié (plus besoin de stocker `refreshToken` côté JS, seul l'access token
  reste géré en mémoire/`localStorage`).

Cette évolution est volontairement reportée : elle change la surface CORS et le contrat
frontend/backend, alors que le besoin actuel (session courte vs longue) ne le justifie pas.

### Motivation

- Besoin produit : une session courte par défaut (sécurité), une session longue optionnelle
  (confort) sans changer le mécanisme de stockage front existant (`localStorage` + intercepteur
  Axios + mutex de refresh, déjà robustes face aux 401 concurrents).
- Le logout actuel ne faisait que vider le `localStorage` côté client : le refresh token restait
  valide en base jusqu'à expiration naturelle (30 jours), même après déconnexion explicite. Faille
  corrigée par cette décision.

### Garde-fous

- Pas de cookies HttpOnly en V1 (voir "Évolution future recommandée" ci-dessus) : le CORS du
  projet a `allow_credentials: false` (voir `nelmio_cors.yaml`) — introduire des cookies aurait
  nécessité une refonte CORS plus large que le périmètre de cette fonctionnalité. Le `localStorage`
  reste donc la stratégie de stockage frontend en V1.
- `rememberMe` absent du payload est traité comme `false` (rétrocompatibilité totale avec les
  clients existants).
- Correctif anti-orphelin : avant ce correctif, le listener `App\EventListener\AuthenticationSuccessListener`
  se déclenchait aussi bien au login qu'au refresh (les deux passent par l'événement
  `lexik_jwt_authentication.on_authentication_success`), et le listener `AttachRefreshTokenOnSuccessListener`
  du bundle Gesdinet (qui écoute le même événement, exécuté juste après) créait alors un **second**
  refresh token à chaque login, avec le TTL global du bundle (30 jours, ignorant `rememberMe`), et
  écrasait la réponse. Corrigé en : (1) scopant notre listener au path `/api/auth/login`
  uniquement (aucune création au refresh) et (2) en plaçant le refresh token créé dans les
  attributs de la requête (`$request->attributes->set('refresh_token', ...)`) pour que le listener
  Gesdinet le retrouve et le réutilise au lieu d'en créer un second. Preuve par test :
  `LoginRefreshTokenOrphanRegressionTest` (câble les deux vrais listeners) et
  `AuthRememberMeFlowTest` (vrai client HTTP + vraie base) vérifient explicitement qu'une seule
  ligne `refresh_tokens` existe par login.
- Frontend : message discret "Votre session a expiré" affiché sur `/login` après un échec de
  refresh (pas de boucle 401 — l'intercepteur Axios existant gérait déjà ce cas).

---

## D-048 — Planning V2 : bascule UI (cutover) et désactivation de la navigation V1

Date : 2026-06-22

### Objectif

Planning V2 (entités, moteur de génération, alertes, réassignation, notifications, API, puis
frontend complet — voir `docs/planning-v2-architecture-freeze.md`) est implémenté, redessiné et
validé. Le faire devenir l'interface planning officielle des managers, sans supprimer V1.

### Décision

- **Le menu latéral manager "Planning" pointe désormais vers `/app/m/planning/v2`.** L'entrée est
  simplement libellée "Planning" (pas "Planning V2", pas "Bêta") — c'est maintenant l'UI planning
  par défaut, pas un module expérimental parallèle.
- **Les entrées V1 historiques (Templates, Générer, Plannings/versions, Vue planning,
  Spécialités) sont retirées de la navigation manager.** Elles ne sont plus dans `DesktopLayout`.
  "Absences" reste visible (page partagée V1/V2, pas une vue V1 obsolète — V2 n'a pas encore
  d'écran de gestion des absences propre).
- **`/app/m/planning` (chemin nu) redirige vers `/app/m/planning/v2`** (`<Navigate replace>`).
- **Les routes V1 restent techniquement actives**, accessibles par URL directe uniquement
  (`/app/m/planning/templates`, `/generate`, `/versions`, `/versions/:id`, `/schedule`,
  `/specialties`) — filet de sécurité en cas de régression V2, pas une fonctionnalité visible.
- **Le code V1 n'est pas supprimé.** `PlanningGeneratorService`, `PlanningTemplateController`,
  `PlanningTemplate`/`PlanningSlot`/`PlanningTemplateType`, et les pages frontend associées
  restent en place. Leur suppression est explicitement **reportée**, pas annulée — voir le critère
  de sortie déjà posé dans `docs/planning-v2-architecture-freeze.md` §C (un cycle complet de
  facturation/encodage sans incident sur V2 avant suppression de V1).
- **Récurrences mensuelles ("1ère/2e/3e/4e semaine du mois") retirées du formulaire de création/
  édition de poste**, sans suppression de la capacité backend. Audit : la branche
  `MONTHLY`+`monthlyNthWeekday` de `PlanningGeneratorServiceV2::isOccurrenceActive()` porte le
  commentaire explicite *"Simplified nth-weekday-of-month match (not part of Batch 2's required
  test matrix)"* — aucun test ne couvre la correction de l'expansion de récurrence pour ce cas
  (seule la validation de saisie est testée). Les options restent dans le code
  (`recurrencePresets.ts` — `RECURRENCE_PRESET_OPTIONS` complet) pour ne pas casser l'édition d'un
  poste existant qui en utiliserait déjà une, mais seules les récurrences validées
  (`LAUNCH_RECURRENCE_PRESET_OPTIONS` : toutes les semaines, semaines paires, semaines impaires,
  une semaine sur deux, jours sélectionnés) sont proposées à la création/édition. Couverture de
  test pour le cas mensuel = travail futur documenté (voir
  `docs/planning-v2-architecture-freeze.md`), pas un blocage du lancement.
- **Les cartes "Fin de poste proche" déplacées de l'onglet Alertes vers l'onglet Postes.** Ce ne
  sont pas des `PlanningAlert` réelles (aucune entité backend, calcul de date 100% frontend depuis
  `SurgeonSchedulePost.endDate`) — les mélanger avec les vraies alertes nécessitant une action
  (acquitter/résoudre/réassigner/ouvrir) créait une ambiguïté sur ce qui est une alerte officielle.
  Dans l'onglet Postes, la carte porte le badge "Information" et le texte fixe "Ce poste arrive
  bientôt à échéance.", sans aucun bouton d'action.

### Raison de ce choix

Le frontend V2 a été entièrement reconstruit (4 onglets, design system dédié, validation manuelle
batch par batch) et testé (115 tests frontend, 416 tests backend, tous verts). V1 reste la seule
voie de repli pendant la période de rodage — d'où la décision de **masquer sans supprimer** :
risque de régression minimal (un manager perdu peut encore atteindre V1 par URL directe en
attendant un correctif), tout en forçant l'usage réel de V2 pour la validation en conditions
réelles avant la suppression définitive de V1.

### Travail restant (non bloquant pour ce lancement)

- **Batch 14 — Préférences de notification** : `GET`/`PATCH /api/notification-preferences`,
  UI de réglage par type d'alerte (Planning alert, Absence chirurgien, Absence instrumentiste,
  Réassignation, Mission ouverte, Rappel) × par canal (in-app, email, push). Nécessaire avant une
  généralisation large, mais ne bloque pas ce lancement initial (les canaux in-app/email
  fonctionnent déjà avec les valeurs par défaut du resolver).
- Couverture de test pour l'expansion de récurrence `MONTHLY`+`monthlyNthWeekday`, avant de
  rouvrir ces options dans le formulaire.
- Détection de conflits cross-site (`SURGEON_CONFLICT`/`INSTRUMENTIST_CONFLICT`) — toujours non
  déclenchée, cf. `planning-v2-architecture-freeze.md` §G.
- Critère de sortie pour la suppression effective de V1 : un cycle complet de
  facturation/encodage sans incident sur V2 (cf. §C du freeze doc), site par site.

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
| 18-03-2026 | D-019 — Module Facturation Firmes |
| 18-03-2026 | D-020 — Module Décomptes Instrumentistes |
| 18-03-2026 | D-021 — Module Planning — gabarits permanents |
| 18-03-2026 | D-022 — Template naming — édition inline optimiste |
| 18-03-2026 | D-023 — Drag & drop de slots — positionnement optimiste |
| 27-04-2026 | D-024 — Vue tableau pour la génération du planning |
| 27-04-2026 | D-025 — Détection de conflits intra-preview |
| 27-04-2026 | D-026 — Attribution manuelle d'instrumentiste inline |
| 27-04-2026 | D-027 — Autocomplete pour chirurgien/instrumentiste dans SlotDialog |
| 27-04-2026 | D-028 — Couleurs par chirurgien dans l'éditeur de gabarit |
| 27-04-2026 | D-029 — Résolution des créneaux non-attribués avant génération |
| 28-04-2026 | D-030 — Doublon instrumentiste dans l'éditeur de gabarit |
| 28-04-2026 | D-031 — Alerte déploiement manquant + AppErrorBoundary MUI |
| 28-04-2026 | D-032 — Attribution directe libéré + DeployPreCheckModal |
| 28-04-2026 | D-033 — Page planning publié (PlanningSchedulePage) |
| 28-04-2026 | D-034 — Auto-assignation backend des instrumentistes libérés dans preview() |
| 28-04-2026 | D-035 — Multi-salle : claim exclusif de mission par slot (claimMission) |
| 29-04-2026 | D-036 — Optimisation preview() : 3 pré-chargements DB au lieu de N×M requêtes |
| 29-04-2026 | D-037 — Déploiement asynchrone : PDF et emails via PlanningDeployPdfsMessageHandler |
| 29-04-2026 | D-038 — Résumé version par période+site (pas par FK) + sémantique "skipped" clarifiée |
| 30-04-2026 | D-039 — Modal déploiement 2 étapes : selectedUncoveredMissionIds + sendChangeSummary |
| 30-04-2026 | D-040 — PlanningDiffService : clé de matching missions entre versions |
| 30-04-2026 | D-041 — Idempotence PlanningDeployment : status PENDING/PROCESSING/DONE/FAILED |
| 30-04-2026 | D-042 — getReference() après em->clear() pour éviter cascade persist |
| 30-04-2026 | D-043 — Routing Messenger obligatoire pour tout message à handler IO-intensif |
| 29-05-2026 | D-044 — Observabilité : Sentry + channel Monolog push |
| 12-06-2026 | D-045 — Synchronisation missions instrumentiste par polling intelligent |
| 16-06-2026 | D-046 — Module Administration — ROLE_ADMIN |
| 19-06-2026 | D-047 — Remember me / session persistante |
| 22-06-2026 | D-048 — Planning V2 : bascule UI (cutover) et désactivation de la navigation V1 |
