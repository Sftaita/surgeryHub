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

> **Modèle de tarification partiellement remplacé par [D-067](#d-067--catalogue-financier-des-firmes--prestations-non-liantes-moteur-indépendant-lot-1)
> (16-07-2026) :** `IMPLANT_FEE` a été renommé `MATERIAL_FEE` et `PricingRule::interventionCode`
> (texte libre) remplacé par `PricingRule::interventionType` (référentiel fermé
> `InterventionType`). Le reste de cette décision (anti-doublon, numérotation, PDF,
> email) reste valable tel quel.

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

Date : 30-04-2026 — Note Batch 15A (2026-06-27) : voir ci-dessous.

> **⚠️ Amendée par D-058 (2026-07-04)** : `sendChangeSummary` a été retiré du backend
> (`PlanningDeploymentService::deploy()`, `PlanningDeployPdfsMessage`, les deux endpoints
> de déploiement) — l'"Étape 2" décrite ci-dessous n'a plus d'effet côté serveur. Le
> modal V1 (`DeployModal.tsx`) envoie encore ce champ ; il est désormais silencieusement
> ignoré. `selectedUncoveredMissionIds` (Étape 1) reste inchangé et fonctionnel.
>
> **Dette résolue par D-079 (2026-07-20)** : `DeployModal.tsx` et la page qui l'utilisait
> (`PlanningVersionDetailPage.tsx`) ont été supprimées avec le reste du moteur V1 — ce
> no-op n'existe donc plus.

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

### Note Batch 15A — Simplification pour le déploiement V2

Cette décision est **partiellement supersédée pour le chemin V2** à partir de Batch 15A.

Pour un déploiement V2 (avec `versionId` présent), la sélection manuelle via `selectedUncoveredMissionIds` est supprimée. Le comportement simplifié :

```
DRAFT + instrumentist IS NOT NULL  → ASSIGNED  (inchangé)
DRAFT + instrumentist IS NULL      → OPEN       (automatique, sans sélection)
```

Motivation : dans le flux V2, le manager résout les non-couverts avant de générer (instrumentistes libérés, suggestions, modal pré-génération). Au moment du déploiement, tout DRAFT restant sans instrumentiste doit aller au pool — la sélection manuelle ajoute de la friction sans valeur métier.

Le chemin **V1 legacy** (sans `versionId`) conserve le comportement `selectedUncoveredMissionIds` inchangé.

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

### Amendement (2026-08-03) — Réinvestigation "déconnexions fréquentes malgré Se souvenir de moi"

Une première investigation (session précédente) concluait qu'aucun bug applicatif n'était
visible et suspectait la purge `localStorage` de Safari/iOS ITP pour les PWA installées.
Cette conclusion a été invalidée : le symptôme a été rapporté également sur **Google
Chrome**, ce qui exclut ITP/Safari comme cause principale ou unique.

**Cause racine réelle, confirmée par relecture ligne à ligne puis reproduite par un test
exécutable** : dans l'intercepteur Axios (`frontend/src/app/api/apiClient.ts`), le bloc
`catch` qui suit une tentative de refresh échouée effaçait systématiquement les tokens et
déclenchait la déconnexion — **y compris quand l'appel réseau de refresh lui-même échouait
pour une raison transitoire** (coupure Wi-Fi, latence, le timeout de 10s configuré sur le
client Axios), sans aucun rapport avec la validité réelle du refresh token stocké. Un
utilisateur avec un refresh token parfaitement valide se retrouvait déconnecté à la moindre
requête réseau imparfaite pendant le refresh — reproductible sur n'importe quel navigateur,
pas un problème de stockage Safari.

**Correctif** : le `catch` ne déclenche désormais la déconnexion (nettoyage des tokens,
`markSessionExpired`, `dispatchSessionExpired`) que lorsque l'erreur de refresh porte
effectivement un statut HTTP `401` (refresh token réellement invalide/expiré, déjà détecté
en amont par la branche `/api/auth/refresh` de l'intercepteur). Toute autre erreur (pas de
`response`, timeout, 5xx) est désormais transmise à l'appelant sans purger la session — une
prochaine requête pourra retenter un refresh normalement.

**Autres hypothèses explicitement testées et écartées** (voir
`frontend/src/app/api/apiClient.test.ts`) :
- 401 prématuré avant toute tentative de refresh au bootstrap (`AuthContext.tsx`) — réfuté,
  `/api/me` passe par l'intercepteur qui tente déjà un refresh avant tout échec définitif.
- Race condition entre deux requêtes recevant un 401 en parallèle — réfuté, le mutex
  (`refreshMutex.ts`) est un check-and-set synchrone sans fenêtre d'interleaving ; preuve
  ajoutée par un test simulant deux requêtes 401 concurrentes ne déclenchant qu'un seul
  appel de refresh réel.
- Stockage token en `sessionStorage` (purgé à la fermeture d'onglet) — vérifié absent, seul
  un indicateur UI sans lien avec les tokens utilise `sessionStorage`.
- Cache du service worker sur les routes `/api/*` — vérifié absent (`sw.js` ne cache que les
  assets statiques `/icons/` et `manifest.json`).
- Dérive de configuration Lexik/Gesdinet par rapport à ce qui est documenté ci-dessus —
  vérifiée absente (TTL access 1h fixe, refresh 1j/30j selon `rememberMe`, pas de rotation).

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
  >
  > **Errata (2026-08-01, audit de reprise)** : ce paragraphe décrit l'état au lancement (cutover),
  > désormais dépassé. La récurrence mensuelle (`MONTHLY_NTH_WEEKDAY`) est couverte par
  > `PlanningGeneratorServiceV2MonthlyTest.php` (612 lignes, matrice de cas réels incluant les bords
  > de calendrier) et de nouveau exposée dans le picker de création/édition
  > (`LAUNCH_RECURRENCE_PRESET_OPTIONS` est désormais identique à `RECURRENCE_PRESET_OPTIONS`) —
  > livré par le commit `11bbc0e` ("Batch 14A/B/C"), qui n'a cependant **jamais eu sa propre entrée
  > ADR** ; ce commit reste donc non documenté au-delà de cette note.
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

- ~~**Batch 14 — Préférences de notification**~~ **Livré (D-086, 2026-07-29)** —
  `GET`/`PATCH /api/me/notification-preferences[/{type}]` (chemin final, différent du plan
  initial), UI de réglage par type × par canal.
- ~~Couverture de test pour l'expansion de récurrence `MONTHLY`+`monthlyNthWeekday`~~ **Livré**
  (commit `11bbc0e`, "Batch 14A/B/C") — voir errata ci-dessus.
- Détection de conflits cross-site (`SURGEON_CONFLICT`/`INSTRUMENTIST_CONFLICT`) — toujours non
  déclenchée, cf. `planning-v2-architecture-freeze.md` §G.
- Critère de sortie pour la suppression effective de V1 : un cycle complet de
  facturation/encodage sans incident sur V2 (cf. §C du freeze doc), site par site.

### Errata (2026-07-19, D-079 — réorganisation de l'espace manager)

Deux inexactitudes de ce décision record, corrigées à l'occasion de l'audit legacy mené pour D-079 :

1. **"Les routes V1 restent techniquement actives, accessibles par URL directe"** — faux depuis
   `26d66ef` (26/06/2026, 4 jours après cette ADR) : ce commit a retiré les `<Route>` de
   `PlanningTemplatesPage`, `PlanningTemplateEditorPage`, `PlanningGeneratePage`,
   `PlanningVersionsListPage`, `SpecialtiesPage` d'`AppRouter.tsx` sans que cette doc soit mise à
   jour. Ces 5 pages étaient donc déjà du code 100% mort (ni routé, ni importé, ni navigable) avant
   même le début de D-079.
2. **Critère de sortie ("un cycle complet de facturation/encodage sans incident sur V2")** — jamais
   formellement constaté dans le dépôt. V2 sert cependant 100% du trafic planning depuis son
   cutover, sans régression rapportée dans les mémoires/commits depuis.

**Décision D-079** : au vu du point 1 (code déjà mort, pas une suppression anticipée d'un filet de
sécurité actif) et du point 2 (V2 en production stable depuis près d'un mois sans incident), les 5
pages frontend ci-dessus, leurs tests, et le contrôleur backend `PlanningTemplateController.php`
(CRUD `/api/planning/templates`, lui aussi sans appelant restant une fois les 5 pages supprimées)
sont supprimés.

**Ce qui n'est PAS supprimé, contrairement à l'intention initiale de D-079** : l'audit de
suppression a révélé que `PlanningVersionDetailPage.tsx` (conservée, `/app/m/planning/versions/:id`,
déjà signalée comme risque préexistant hors périmètre) appelle toujours `previewPlanning`/
`deployPlanning` (`POST /api/planning/preview`, `POST /api/planning/deploy`,
`PlanningGenerationController`), qui dépendent directement de `PlanningGeneratorService` — lequel
interroge les entités `PlanningTemplate`/`PlanningSlot` (`PlanningGeneratorService.php:8-9,79-80,
375,452-458`). Supprimer le service ou les entités aurait cassé le bouton "Déployer" de cette page
encore active : régression réelle, exclue par la contrainte "aucune régression fonctionnelle" du
brief D-079. Restent donc en place : `PlanningGeneratorService.php` (+ ses tests
`PlanningGeneratorServiceTest.php`, `PlanningGeneratorServiceGenerateTest.php`), les entités
`PlanningTemplate`/`PlanningSlot`/`PlanningTemplateType`, `PlanningGenerationController.php`, et les
exports non-CRUD de `planning.api.ts` (`previewPlanning`, `getPlanningVersion`, `deployPlanning`,
`getVersionDiff`, `listPlanningVersions`, `deletePlanningVersion`, `downloadPlanningVersionPdf`,
`triggerVersionPdfDownload`). Seuls les exports exclusifs aux 5 pages supprimées sont retirés
(CRUD gabarits/slots : `getTemplates`, `createTemplate`, `addSlot`, etc. ; `generatePlanning`,
`getSuggestedInstrumentists`, `assignInstrumentist` — 0 appelant restant) — ainsi que
`createMission`/`publishMission`, dupliqués de `missions.api.ts` et déjà sans appelant avant D-079.

Le nouveau critère de sortie, si une suppression complète de ce reliquat V1 est souhaitée un jour :
retirer le bouton "Déployer" (et son flux preview/diff) de `PlanningVersionDetailPage.tsx`, ou
migrer cette page vers les endpoints `/api/planning/v2/*`. Décision produit à prendre séparément,
hors périmètre de D-079.

### Errata 2 (2026-07-20, D-079 — audit complet de PlanningVersionDetailPage → suppression)

L'errata ci-dessus concluait à tort à la conservation. Un audit de réachabilité complet (grep
exhaustif de tout `frontend/src` pour tout `NavLink`/`Link`/`navigate()`/template email pointant
vers `/app/m/planning/versions/:id`) a établi que :

- **Zéro lien UI** ne mène à cette page — ni sidebar (retirée dès D-048), ni `PlanningVersionsListPage`
  (supprimée dans la première passe D-079, qui était le seul moyen de découvrir un `versionId`), ni
  notification, ni template email (`backend/templates`, `MessageHandler` audités : aucune référence).
  Seule une URL tapée à la main ou un favori antérieur au cutover V2 (22/06/2026) peut y mener.
- **Le "Déployer" de cette page est fonctionnellement redondant et potentiellement incorrect** :
  `PlanningV2GenerationController::deploy()` réutilise déjà `PlanningDeploymentService` — le même
  service que le "Déployer" V1 (`deploy() reuses PlanningDeploymentService unchanged`, commentaire du
  fichier) — donc aucune divergence de logique de déploiement entre V1 et V2. En revanche, le bouton
  "Déployer" de cette page recalcule un **aperçu indépendant** via `previewPlanning()` →
  `PlanningGeneratorService::preview()`, qui lit les gabarits `PlanningTemplate`/`PlanningSlot` — une
  source sans aucun rapport avec le contenu réel d'une `PlanningVersion` générée par V2 (V2 utilise
  `SurgeonSchedulePost`/`ShiftPeriodConfig`, jamais `PlanningTemplate`/`PlanningSlot`). Si un manager
  ouvrait cette page sur une version V2 et cliquait "Déployer", `selectedUncoveredMissionIds` serait
  calculé à partir d'un aperçu sans rapport avec les missions réelles de la version — un risque de
  correction de données, pas seulement un risque théorique de confusion UI.
- **Aucune fonctionnalité n'est perdue** : Planning V2 a son propre flux complet et déjà testé
  (aperçu/génération/déploiement via `GeneratePlanningTab`, `/api/planning/v2/*`), qui couvre 100% du
  même besoin métier sans les défauts ci-dessus.

**Décision finale** : `PlanningVersionDetailPage.tsx`, `DeployModal.tsx` (composant exclusif à cette
page) et la route `m/planning/versions/:id` sont supprimés. Côté backend, suppression en cascade de
tout ce qui n'était plus utile qu'à cette page :
- `PlanningGenerationController.php` (POST /api/planning/preview, /generate — et
  /api/missions/{id}/suggested-instrumentists, déjà sans appelant frontend avant cette passe) —
  fichier entier, 0 appelant restant.
- `PlanningDeployController.php` (POST /api/planning/deploy) — fichier entier, 0 appelant restant.
- `PlanningVersionController.php` : 4 actions retirées (`show`, `diff`, `delete`, `pdf` — toutes
  sans appelant restant, vérifié par grep sur les tests backend : aucun test fonctionnel ne les
  couvrait déjà). Conservé intégralement : `list`, `apply-modifications`, `cancel-all`,
  `coverage-summary`, `history` — actifs, utilisés par Planning V2. `PlanningDiffService` et
  `PdfService` (services injectés uniquement pour `diff`/`pdf`) restent en place : tous deux
  partagés avec d'autres flux actifs (`PlanningModificationService`, factures/décomptes/PDFs de
  déploiement), seule l'injection dans ce controller est retirée.
- `PlanningGeneratorService.php` (moteur V1) — devenu sans appelant une fois `preview()` retiré ;
  `PlanningScoreService` (utilisé aussi par `PlanningAlertActionService`, V2) n'est pas touché.
- Entités `PlanningTemplate`, `PlanningSlot`, enum `PlanningTemplateType` — plus aucune référence
  dans `src/` après suppression du service ci-dessus (vérifié par grep exhaustif).
- 6 fichiers de tests unitaires exclusifs à `PlanningGeneratorService`
  (`PlanningGeneratorServiceTest`, `PlanningGeneratorServiceGenerateTest`,
  `PlanningFreedInstrumentistTest`, `PlanningMultiRoomTest`, `PlanningPoolFilteringTest`,
  `PlanningPreviewPerformanceTest`) — chacun instancie `new PlanningGeneratorService(...)`
  directement, aucun ne teste autre chose.
- `BusinessDateTimeColumnConventionTest.php` : 3 entrées `SAFE_ALLOWLIST` retirées
  (`PlanningSlot::startTime`, `PlanningSlot::endTime`, `PlanningTemplate::createdAt`) — ce test
  échoue explicitement sur toute entrée d'allowlist dont la classe n'existe plus plus
  (`staleAllowlistEntries`), donc laisser ces lignes aurait cassé la suite après suppression des
  entités.

**Ce qui reste en place, sans lien avec ce nettoyage** : `PlanningDeploymentService` (partagé V1
et V2 dès l'origine, mais V1 n'a plus de point d'entrée HTTP pour l'atteindre — seul V2 l'utilise
désormais), `PlanningVersion`/`PlanningDeployment` (entités actives, coeur de V2),
`PlanningVersionAllowedActions`/`PlanningVersionSummary`/`listPlanningVersions` côté frontend
(contrat de données du `list`, consommé par `GeneratePlanningTab`).

**Non traité ici, action distincte à décider séparément** : les tables SQL `planning_template` et
`planning_slot` ne sont pas droppées par cette passe — supprimer une entité Doctrine ne supprime
pas la table ; une migration de suppression de table est une action destructive distincte qui n'a
pas été demandée et n'a pas été exécutée. Les tables restent en base, orphelines de tout mapping
ORM, jusqu'à une décision explicite ultérieure.

Suite complète validée après cette suppression : backend 1251/1251 (contre 1292/1292 avant, delta
= -41 tests supprimés avec le code qu'ils testaient), frontend 431/431 (61 fichiers).

---

## D-049 — Règles d'affiliation aux sites par rôle métier

Date : 2026-06-23

### Décision

`SiteMembership` reste une propriété générique de `User` (tout utilisateur peut avoir 0 à N
sites), mais le **nombre de sites obligatoire dépend désormais du rôle métier**, appliqué de
façon symétrique à la création, à la suppression d'une affiliation et au changement de rôle :

| Rôle | Sites autorisés | Site obligatoire |
|---|---|---|
| INSTRUMENTIST | 1..N | Oui |
| SURGEON | 1..N | Oui |
| MANAGER | 0..N | Non |
| ADMIN | 0..N | Non |

Un chirurgien est une entité globale unique : un même `User` peut être affilié à plusieurs sites
(`SiteMembership` ×N) sans jamais être dupliqué par hôpital — ce modèle (une ligne `User` +
plusieurs `SiteMembership`) était déjà correctement implémenté pour `SurgeonServiceManager` et
`InstrumentistServiceManager` ; cette décision en fait une règle explicite et l'étend à
`UserAdministrationService` (création générique manager/admin/instrumentiste/chirurgien via
`POST /api/admin/users`).

### Implémentation

- **`AdminCreateUserRequest::$siteIds`** : retrait de la contrainte statique
  `Assert\Count(min: 1)` — un constraint par champ ne peut pas dépendre d'un autre champ (ici le
  rôle). Le contrôle devient conditionnel, posé dans le service.
- **`UserAdministrationService`** — nouvelle constante `ROLES_REQUIRING_SITE =
  ['ROLE_INSTRUMENTIST', 'ROLE_SURGEON']`, vérifiée à 3 endroits :
  - `createUser()` : 400 si rôle requérant un site et `siteIds` vide.
  - `removeSiteMembership()` : 409 si le rôle requiert un site et qu'il ne reste qu'une seule
    affiliation (empêche de vider le dernier site d'un chirurgien/instrumentiste).
  - `changeRole()` : 400 si le nouveau rôle requiert un site et que l'utilisateur cible n'en a
    aucun (évite de faire passer un manager à 0 site directement en chirurgien/instrumentiste).
- **`SurgeonServiceManager::deleteSiteMembership()` / `InstrumentistServiceManager::deleteSiteMembership()`**
  — même garde de suppression du dernier site (409), ajoutée à ces deux services qui n'avaient
  aucune protection contre la suppression jusqu'ici (seule la création vérifiait déjà `count(siteIds) === 0`).
- **`CreateSurgeonRequest`/`CreateInstrumentistRequest`** : déjà corrects (`Assert\Count(min: 1)`
  statique, car ces DTOs sont mono-rôle) — aucun changement nécessaire.
- **Frontend** : `AdminCreateUserModal` rend la validation du nombre de sites conditionnelle au
  rôle sélectionné (`ROLES_REQUIRING_SITE`). `CreateSurgeonDialog`/`CreateInstrumentistDialog`
  avaient un bug où le frontend ne validait pas le site obligatoire avant l'appel API (rejet
  silencieux côté serveur uniquement) — validation ajoutée, et le texte trompeur "vous pouvez
  créer [...] sans site" (faux, car le backend rejette déjà ce cas) a été retiré. `AdminUserDrawer`
  expose désormais l'ajout de site (`addAdminSiteMembership`, jusqu'ici défini mais jamais appelé
  par aucune UI) en plus du retrait déjà existant — les écrans `SurgeonDrawer`/`InstrumentistDrawer`
  avaient déjà les deux actions.

### Motivation

Aligner le modèle technique sur la réalité métier : un manager ou un admin n'opère pas
nécessairement depuis un site physique (rôle transverse), alors qu'un instrumentiste ou un
chirurgien doit toujours être rattaché à au moins un site pour être planifiable. L'invariant
n'était auparavant vérifié qu'à la création — un instrumentiste pouvait être vidé de tous ses
sites via l'endpoint de suppression sans qu'aucune garde n'existe.

### Garde-fous

- Aucune logique de fallback côté frontend : la règle est strictement appliquée côté backend
  (service), le frontend ne fait que refléter la contrainte pour l'UX (message d'erreur avant
  l'appel réseau).
- L'API reste inchangée (`POST /api/admin/users`, `POST/DELETE .../site-memberships`) — création
  de compte et gestion d'affiliation restent deux opérations indépendantes.

---

## D-050 — Absences "jours isolés" : N lignes `Absence` d'un jour, pas de nouveau champ

Date : 2026-06-24

### Décision

Pour permettre de déclarer des jours isolés (ex. 04/07, 09/07, 18/07) en plus des périodes
continues existantes, **le modèle `Absence` (`dateStart`/`dateEnd`) n'est pas modifié**. Le
frontend crée une ligne `Absence` par jour isolé sélectionné, avec `dateStart === dateEnd` —
un cas déjà accepté par l'API et déjà traité correctement par tous les services métier.

Options écartées : ajouter un champ `dates: json`, ou un `absence_type` — rejetées car une
revue complète montre que `PlanningGeneratorService` (V1), `PlanningGeneratorServiceV2`,
`PlanningScoreService` et `AbsenceImpactService` chargent déjà les absences comme des **listes
d'intervalles par utilisateur** (`loadAbsencesMap()` → `[userId => [[start,end], ...]]`), sans
jamais supposer une seule ligne par personne. Le besoin "jours isolés" est donc un cas
particulier déjà supporté, pas un besoin de modèle.

### Implémentation

- **Frontend** : `planning.api.ts` expose `createIsolatedDayAbsences({userId, dates, reason})`
  qui boucle `createAbsence()` une fois par date (séquentiel, par cohérence avec le pattern déjà
  utilisé pour la génération multi-mois de Planning V2). `AbsencesPage.tsx` ajoute un toggle
  **Période / Jours isolés** : le premier mode garde le comportement `Du/Au` existant inchangé ;
  le second affiche des chips de dates ajoutables/retirables.
- **Backend correctif ciblé** : `PlanningAlertService::findActiveAlert()` déduplique
  désormais sur `(mission, type)` au lieu de `(mission, type, absence)`. Défaut préexistant
  trouvé lors de l'analyse : deux lignes `Absence` différentes qui se recouvrent pour la même
  personne sur la même `Mission` (ex. une période + un jour isolé déjà inclus dedans) créaient
  une alerte en double, car l'ancienne clé incluait la ligne `Absence` exacte. `absence` reste
  stocké sur l'alerte à titre d'attribution/traçabilité, mais ne fait plus partie de la clé
  d'unicité logique.
- **Aucune migration**, aucune entité modifiée, aucun générateur V1/V2 modifié.

### Limite connue — traitée (suivi du 2026-06-24)

La limite initialement documentée ici (suppression d'une absence qui résolvait l'alerte même
si une autre absence chevauchante existait encore pour la même personne) est corrigée :
`AbsenceImpactService::onAbsenceDeleted()` n'appelle plus
`PlanningAlertService::resolveAllForAbsence()` sans condition. Pour chaque alerte liée à
l'absence supprimée, une requête `findOtherOverlappingAbsence(user, mission, excluding)`
vérifie si une **autre** ligne `Absence` du même utilisateur couvre encore la mission de
l'alerte :
- si oui → l'alerte reste `OPEN`, simplement **re-pointée** (`PlanningAlert.absence`) vers
  l'absence survivante, pour ne jamais référencer une ligne supprimée ;
- si non → comportement inchangé : l'alerte est résolue (jamais supprimée).

Toujours **zéro migration, zéro modification d'entité** — `findOtherOverlappingAbsence()` est
une requête DQL supplémentaire dans `AbsenceImpactService`, au même endroit que
`findOverlappingMissions()`. `PlanningAlertService::resolveAllForAbsence()` reste disponible
(testé en isolation) mais n'est plus appelée par ce chemin.

Test fonctionnel dédié (réel, DB) : `AbsenceControllerTest::test_deleting_one_of_two_overlapping_absences_keeps_the_alert_open_until_the_last_one_is_gone`
— mission → absence période (alerte créée) → absence jour isolé chevauchante (pas de
doublon) → suppression de la période (alerte reste `OPEN`, re-pointée vers le jour isolé) →
suppression du jour isolé (alerte enfin résolue).

### Garde-fous

- Comportement des absences déjà en production strictement inchangé (une période = toujours
  une seule ligne, comme avant).
- Tous les tests existants (`AbsenceControllerTest`, `AbsenceImpactServiceTest`,
  `PlanningAlertServiceTest`, suites V1/V2) restent verts ; l'unique test modifié
  (`test_absence_deleted_resolves_all_its_active_alerts` → renommé
  `test_absence_deleted_resolves_alerts_with_no_surviving_overlapping_absence`) l'est pour
  refléter le nouveau chemin d'appel, pas pour affaiblir l'assertion.

---

## D-051 — Relances congés manager : preview backend, destinataires différenciés, audit sans cible unique

Date : 2026-06-24 (amendé deux fois le 2026-06-24 — voir historique des corrections en fin de section)

### Décision (état final)

Les **deux** actions manager envoient désormais **un email individuel par personne
sélectionnée, à sa propre adresse** — jamais à une adresse fixe. `boost.conge@gmail.com`
n'apparaît plus *nulle part* comme destinataire réel ; c'est uniquement un bout de texte dans
le message de "Demander les congés", invitant son destinataire à y répondre directement.

- **"Demander les congés"** : sélection = instrumentistes/chirurgiens actifs **sans aucune
  absence chevauchant aujourd'hui → +3 mois**. Le message dit qu'aucun congé n'est encodé,
  demande de répondre à `boost.conge@gmail.com`, et annonce la future fonctionnalité in-app.
- **"Confirmer les congés encodés"** : sélection = instrumentistes/chirurgiens actifs **avec au
  moins une absence future**. L'email contient **tous** les congés futurs de la personne
  (`dateEnd >= aujourd'hui`, **sans plafond de 3 mois** — différent de la fenêtre de
  sélection de "Demander les congés", volontairement).

Les deux actions acceptent un `userIds[]` optionnel dans le body pour restreindre la sélection
(coché par défaut = tout le monde côté frontend, décocher = exclu de l'envoi — personne décochée
ne reçoit rien).

Chaque période est calculée **à un seul endroit** : `AbsenceReminderService::defaultPeriod()`
(3 mois, "missing") et `AbsenceReminderService::findAllFutureEncodedAbsencesGrouped()` (uncapped,
"encoded") — partagées entre les endpoints de prévisualisation (GET) et d'envoi (POST), jamais
recalculées côté frontend, pour qu'il soit structurellement impossible que l'aperçu affiché
diverge de ce qui est réellement envoyé.

### Alternative écartée

Calculer l'aperçu côté client à partir des données déjà chargées dans la table des absences
(zéro endpoint supplémentaire). Écartée : la table principale est paginée/filtrée pour
l'affichage, et un calcul client dupliquerait la définition de la période — exactement le
type de double-source-de-vérité qui a causé l'incident D-050 (la base plus à jour que le
code). Le coût de 2 endpoints GET supplémentaires est jugé inférieur à ce risque.

### Implémentation

- **`AbsenceReminderService`** : `findUsersWithoutAbsenceInPeriod($from, $to)` (3 mois,
  "missing") et `findAllFutureEncodedAbsencesGrouped($from)` (uncapped, "encoded" — prend
  volontairement un seul paramètre, pas de `$to`). Aucune entité modifiée, aucune migration.
- **`AbsenceReminderController`** (`/api/planning/absences/{missing-preview,encoded-preview,
  request-missing,confirm-encoded}`) sous `PlanningVoter::PLANNING_MANAGE` (même gate que
  `AbsenceController` — MANAGER ou ADMIN). Body JSON simple (`{message?: string, userIds?:
  number[]}`) lu directement comme `AbsenceController::create()` le fait déjà — pas de
  DTO/Serializer.
- **Envoi** : `NotificationService::sendAbsenceRequestMissingEmailToUser()` et
  `::sendAbsenceConfirmEncodedEmailToUser()` dispatchent chacune `SendTemplatedEmailMessage`
  via le bus (même mécanisme déjà async que les emails de facturation — D-043 respecté sans
  rien ajouter à `messenger.yaml`), **une fois par personne** (boucle dans le contrôleur) —
  `count` retourné est donc le nombre d'emails individuels effectivement envoyés, pas le nombre
  de personnes vues en preview (qui peut différer si certaines sont décochées). Aucune des deux
  réponses n'a de champ `recipient`.
- **Templates** : `absences_request_missing.html.twig` simplifié à `{user, greeting, message}`
  (plus de table de plusieurs personnes — un seul destinataire désormais) ;
  `absences_confirm_encoded.html.twig` perd son encadré `.note` qui répétait presque mot pour
  mot la phrase "à terme..." déjà présente dans `{{ message }}` — un seul bloc de texte. Les
  deux templates rendent `{{ greeting }},` juste avant `{{ message }}`.
- **Audit** : `UserAuditEvent` avec `targetUser = null` — ces actions concernent N personnes à
  la fois, pas une cible unique ; `payload.count` porte le nombre concerné. Deux cas d'enum
  (`ABSENCES_REQUEST_SENT`, `ABSENCES_CONFIRMATION_SENT`), pas de nouveau mécanisme d'audit.
- **`AbsenceController::serialize()`** étendu (champ additif `role` + `firstname`/`lastname`
  séparés) pour permettre au frontend d'afficher une identité lisible et de trier
  Instrumentistes→Chirurgiens dans la liste principale — aucun consommateur existant cassé.

### Garde-fous

- Aucune donnée patient dans les emails (liste de personnes + dates uniquement) — vérifié par
  test fonctionnel (`assertStringNotContainsStringIgnoringCase('patient', ...)`).
- Test fonctionnel dédié vérifie que `missing-preview` et `request-missing` retournent
  exactement le même `count`.
- Test dédié confirme qu'aucun email n'est jamais envoyé à `boost.conge@gmail.com` pour les
  deux actions, et qu'une absence à 8 mois est bien incluse dans "confirm-encoded" (pas de
  plafond de 3 mois).
- Test de rendu Twig dédié au non-régression de la duplication de texte (`substr_count($html,
  'À terme') === 1`).
- Découverte pendant l'implémentation : `KernelBrowser` réinitialise le conteneur (donc le
  transport en mémoire de test) entre deux requêtes par défaut — `$client->disableReboot()`
  est nécessaire dans tout test fonctionnel qui authentifie puis vérifie un message Messenger
  dispatché sur plusieurs requêtes du même test. Documenté ici pour la prochaine fois.

### Historique des corrections (toutes le 2026-06-24, avant toute mise en prod)

1. Version initiale : les deux actions envoyaient un seul email groupé à
   `boost.conge@gmail.com`.
2. 1er amendement : "Confirmer les congés encodés" corrigé en envoi individuel par personne —
   l'implémentation initiale était une incompréhension du besoin, jamais déployée.
3. 2e amendement : "Demander les congés" corrigé en envoi individuel par personne également ;
   "Confirmer les congés encodés" passe de "3 prochains mois" à "tous les congés futurs sans
   plafond" ; suppression de la répétition de texte dans le template de confirmation.
4. 3e amendement (celui-ci) : salutation personnalisée par destinataire — "Bonjour Dr
   {nom}" pour un chirurgien, "Bonjour {prénom}" pour un instrumentiste (repli sur "Bonjour"
   seul si le champ pertinent est vide). Calculée par `NotificationService::greetingFor()` et
   passée au contexte Twig (`greeting`), rendue par le template, **jamais** par le texte
   éditable du manager — qui ne contient donc plus "Bonjour," en dur (évite toute duplication
   si le manager personnalise le message).

Aucune de ces quatre versions n'a été déployée en production — corrigées en cours de
développement, avant tout déploiement réel.

---

## D-052 — Le planning publié est un objet vivant

Date : 2026-06-27

### Décision

Un planning déployé n'est pas un instantané figé. C'est un objet vivant qui évolue jusqu'à la clôture de la période.

**La génération sert uniquement à créer la première version.** Le déploiement la rend visible. Tout ce qui suit — réassignations, prises de missions, ajouts, annulations, changements d'horaire — constitue la vie du planning et s'exprime exclusivement sur les entités `Mission` existantes.

### Flux complet

```
SurgeonSchedulePost
       ↓
  preview()         — sandbox, rien écrit en DB
       ↓
  generate()        — crée les Missions DRAFT + PlanningVersion
       ↓
  deploy()          — DRAFT → ASSIGNED ou OPEN, notifications initiales
       ↓
═══════════════════════════════════════════════════
  LE PLANNING EST VIVANT
  Toute modification opère directement sur les Missions
═══════════════════════════════════════════════════
       ↓
  Prise de mission  — OPEN → ASSIGNED (instrumentiste)
       ↓
  Réassignation     — ASSIGNED → OPEN → ASSIGNED (manager)
       ↓
  Ouverture pool    — ASSIGNED → OPEN (manager)
       ↓
  Annulation        — OPEN → CANCELLED (manager)
       ↓
  Ajout             — nouvelle Mission post-deploy (manager)
       ↓
  Chaque action :   — AuditEvent + Notification(s)
```

### Règle structurante

> Toute modification d'un planning publié s'appuie sur les endpoints Mission dédiés, jamais sur un nouveau cycle generate/deploy.

### Invariant "never regenerate"

> Une Mission publiée (statut ≠ DRAFT) ne peut jamais être écrasée par un appel ultérieur à `generate()`.
>
> Le générateur crée et modifie des missions **DRAFT uniquement**. Les statuts OPEN, ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS, CANCELLED sont des états terminaux pour `generate()` — il les ignore silencieusement.

Ce comportement est implémenté depuis D-029/D-034 (V1) et préservé en V2 : `preview()` marque MODIFIED toute mission existante hors DRAFT, et `generate()` ne touche que les MODIFIED dont le statut est encore DRAFT. Mais cet invariant est ici élevé au rang de contrat architectural : aucune implémentation future du générateur ne doit le rompre.

**Cas concret :** si un manager régénère pour le même mois après déploiement (pour ajouter un poste oublié), les missions déjà OPEN ou ASSIGNED survivent intactes. Seules les nouvelles missions DRAFT créées par ce second `generate()` seront publiées au prochain `deploy()`.

### Conséquences

Chaque nouvelle feature post-publication doit :
1. Opérer sur une `Mission` existante via un endpoint dédié
2. Créer un `AuditEvent` (acteur, type, payload diff — snapshot des noms au moment de l'action)
3. Déclencher les `NotificationEvent` appropriés via `NotificationPreferenceResolver`
4. Ne jamais régénérer de `PlanningVersion`

### Invariant Post/Mission

> Après déploiement, toute modification opérationnelle s'effectue **sur les Missions uniquement**.
>
> Un `SurgeonSchedulePost` n'est **jamais modifié** pour résoudre un problème opérationnel sur un planning publié.
>
> **Les Posts décrivent le planning futur. Les Missions décrivent la réalité opérationnelle.**

Conséquence : un manager qui veut "retirer" un créneau d'un planning publié annule la Mission (`CANCELLED`). Il ne désactive pas le Post correspondant. Les deux restent indépendants : le Post continue à générer des missions pour les mois suivants.

### Pattern de dispatch pour les changements post-deploy

Les changements post-déploiement (release, cancel, réassignation, etc.) suivent ce pattern, cohérent avec D-014 (emails async) et D-043 (IO async) :

```
Endpoint dédié (release / cancel / assign-instrumentist)
  → MissionApplicationService::action()   ← service d'application obligatoire (voir D-056)
     → mission.status = nouveau statut  (synchrone)
     → AuditEvent créé + flush          (synchrone — ne doit pas échouer)
     → bus.dispatch(MissionLifecycleChangedMessage(missionId, changeType, actorId, payload))
               ↓  (async — worker Messenger)
     MissionLifecycleChangedMessageHandler
       → détermine les audiences selon changeType
       → NotificationPreferenceResolver par audience
       → NotificationEvent(s) créés + email dispatché si emailEnabled
```

`MissionLifecycleChangedMessage` est un message générique : un seul type de message, un seul handler, un seul routing dans `messenger.yaml`. Le `changeType` est un PHP enum (`MissionChangeType`). Tous les futurs changements post-deploy utilisent ce même pattern.

### Motivation

- La génération est coûteuse et destructive (elle réécrit les missions DRAFT). La réutiliser pour chaque ajustement casserait l'audit, l'historique et les missions déjà acceptées.
- Les Missions sont l'unité atomique du planning. Leur cycle de vie complet (DRAFT → OPEN → ASSIGNED → SUBMITTED → VALIDATED → CLOSED) est déjà modélisé.
- `AuditEvent` permet d'enregistrer chaque changement avec son acteur, son horodatage et un contexte lisible durablement.

---

## D-053 — Notification chirurgien : par poste, pas par statistique

Date : 2026-06-27

> **⚠️ Amendée par D-058 (2026-07-04)** : l'**email** de déploiement chirurgien contient
> désormais des compteurs agrégés (total/couvertes/non couvertes) — voir D-058 pour le
> rationale. Le détail poste-par-poste décrit ci-dessous reste valable pour la
> **notification in-app** (`NotificationEvent.payload.posts[]`), inchangée.

### Décision

La notification de déploiement adressée au chirurgien (`PLANNING_DEPLOYED_SURGEON`) présente chaque poste individuellement, dans l'ordre chronologique. Elle ne contient jamais de compteurs agrégés du type "22 couverts / 3 non couverts".

**Payload `posts[]` — une entrée par poste :**

```json
{
  "periodLabel": "Juillet 2026",
  "posts": [
    {
      "missionId": 42,
      "date": "2026-07-14",
      "dayLabel": "Mardi 14 juillet",
      "siteName": "Delta",
      "periodLabel": "Matin",
      "covered": false,
      "instrumentistName": null,
      "uncoveredReasonLabel": "Aucune instrumentiste disponible"
    },
    {
      "missionId": 43,
      "dayLabel": "Jeudi 16 juillet",
      "siteName": "Delta",
      "periodLabel": "Après-midi",
      "covered": true,
      "instrumentistName": "Sophie Martin",
      "uncoveredReasonLabel": null
    }
  ]
}
```

**Email (`planning_surgeon.html.twig`)** : une carte par poste dans l'ordre chronologique — date + site + période + statut couvert/non couvert + instrumentiste ou motif.

### Motivation

Un chirurgien raisonne par journée opératoire, pas par quota. L'agrégation masque l'information actionnable (quel poste, quel jour) et oblige le chirurgien à consulter l'application pour comprendre ce qui se passe.

### Règles

- Le chirurgien ne voit que ses propres postes.
- `uncoveredReasonLabel` est le libellé lisible de l'enum `UncoveredReason` (Batch 15A).
- Les statistiques agrégées sont réservées au résumé manager (`PLANNING_DEPLOYED_MANAGER`).

---

## D-054 — Deux familles de notifications instrumentiste

Date : 2026-06-27

### Décision

Les notifications de planning adressées à un instrumentiste sont séparées en deux familles distinctes, avec des types, des préférences et des contenus différents.

**Famille 1 — Publication initiale (`PLANNING_DEPLOYED_INSTRUMENTIST`) :**
- Déclencheur : déploiement initial, une seule fois par déploiement
- Contenu : résumé de la période + nombre de missions + PDF en pièce jointe par email
- Message : "Votre planning de juillet 2026 a été publié. Vous êtes affecté(e) à N missions."

**Famille 2 — Mise à jour post-déploiement (`PLANNING_MISSION_REASSIGNED`, `PLANNING_MISSION_CANCELLED`, `PLANNING_MISSION_ADDED`, `PLANNING_MISSION_UPDATED`) :**
- Déclencheur : toute modification d'une mission assignée à cet instrumentiste
- Contenu : la mission spécifique + nature du changement + before/after
- Message : "Votre planning a été modifié. La mission du mardi 14 juillet (Delta — Matin) vous a été retirée."
- Pas de PDF (le PDF reste réservé à la publication initiale)

### Invariant

> Une notification de Famille 1 n'est jamais renvoyée lors d'une mise à jour. Une notification de Famille 2 n'est jamais envoyée lors du déploiement initial.

### Catalogue de types (`NotificationType`) — état cible

| Type | Famille | Audience | inApp | email |
|---|---|---|---|---|
| `PLANNING_DEPLOYED_INSTRUMENTIST` | Initiale | Instrumentiste assigné | true | true |
| `PLANNING_DEPLOYED_SURGEON` | Initiale | Chirurgien | true | true |
| `PLANNING_DEPLOYED_MANAGER` | Initiale | Manager/Admin | true | true |
| `OPEN_MISSION_AVAILABLE` | Initiale | Instrumentiste éligible | true | false |
| `SURGEON_POST_COVERED` | Suivi | Chirurgien | true | false |
| `SURGEON_POST_UNCOVERED` | Suivi | Chirurgien | true | false |
| `PLANNING_MISSION_REASSIGNED` | Mise à jour | Ancien + nouvel instrumentiste | true | false |
| `PLANNING_MISSION_CANCELLED` | Mise à jour | Instrumentiste + Chirurgien | true | true |
| `PLANNING_MISSION_ADDED` | Mise à jour | Instrumentiste (si assigné) | true | false |
| `PLANNING_MISSION_UPDATED` | Mise à jour | Instrumentiste + Chirurgien | true | false |

Les types "Mise à jour" sont conçus aujourd'hui, implémentés dans les batches futurs (Batch 15+).

`NotificationEvent.eventType` étant VARCHAR(100) (pas une colonne enum en base), l'ajout de nouveaux types ne nécessite aucune migration — seule l'enum PHP `NotificationType` est à étendre.

### Motivation

Sans cette séparation, une mise à jour de planning pourrait déclencher une re-notification initiale complète (avec PDF) — comportement de spam. La séparation en familles garantit que le contenu, le canal et le déclencheur sont toujours cohérents.

---

## D-055 — AuditEvent comme historique des changements post-déploiement

Date : 2026-06-27

### Décision

Toute modification d'un planning publié est historisée via l'entité `AuditEvent` existante (actor FK + mission FK NOT NULL + eventType + payload JSON).

**Nouveaux `AuditEventType` post-déploiement :**

| Valeur | Déclencheur | Payload |
|---|---|---|
| `MISSION_RELEASED_TO_POOL` | ASSIGNED → OPEN (manager relâche) | `{ fromInstrumentistId, fromInstrumentistName }` |
| `MISSION_CANCELLED_POST_DEPLOY` | OPEN → CANCELLED | `{ reason? }` |
| `MISSION_REASSIGNED_POST_DEPLOY` | Manager réassigne directement | `{ fromInstrumentistId, fromInstrumentistName, toInstrumentistId, toInstrumentistName }` |
| `MISSION_TIME_CHANGED_POST_DEPLOY` | Modification des horaires | `{ fromStartAt, fromEndAt, toStartAt, toEndAt }` |
| `MISSION_ADDED_POST_DEPLOY` | Mission créée post-deploy | `{ surgeonId, surgeonName, instrumentistId?, instrumentistName? }` |
| `MISSION_CLAIMED_FROM_POOL` | OPEN → ASSIGNED (instrumentiste claim) | `{ instrumentistId, instrumentistName }` |

**Convention de payload :** tout `AuditEvent` post-déploiement inclut un snapshot before/after avec les noms des personnes au moment de l'action. Ce snapshot garantit la lisibilité de l'historique même si un utilisateur change de nom ou est supprimé ultérieurement.

```json
{
  "occurredAt": "2026-07-02T09:15:00+02:00",
  "fromInstrumentistId": 5,
  "fromInstrumentistName": "Françoise Dubois",
  "toInstrumentistId": 7,
  "toInstrumentistName": "Sophie Martin"
}
```

**Endpoint exposition :** `GET /api/missions/{id}/audit` — retourne les AuditEvent de la mission, triés par date DESC.

### Motivation

- `AuditEvent` a `mission` FK NOT NULL — compatible avec tous les cas post-déploiement (toujours sur une mission existante).
- Réutiliser l'infrastructure d'audit existante évite une nouvelle entité et un nouveau système de journalisation.
- Le snapshot payload est la seule garantie de lisibilité durable : une entité `User` peut être renommée ou désactivée ; l'événement d'audit reste lisible.

### Historique d'une PlanningVersion

L'endpoint `GET /api/planning/versions/{id}/history` reconstruit la timeline de la version sans entité supplémentaire, en agrégeant deux sources :

1. `PlanningDeployment` — événement racine : horodatage du déploiement, auteur, `missionCount`, `openPoolCount`
2. `AuditEvent` sur les missions de la version — via la jointure `audit_event.mission_id → mission.planning_version_id = :versionId`

Timeline résultante :

```
09:12  Publié — 25 missions (20 assignées, 5 au pool)   ← PlanningDeployment
10:18  Mission couverte — Mar 14/07 · Delta · Sophie Martin  ← MISSION_CLAIMED_FROM_POOL
11:02  Mission relâchée — Jeu 16/07 · Delta              ← MISSION_RELEASED_TO_POOL
11:04  Mission réassignée — Jeu 16/07 · Delta            ← MISSION_REASSIGNED_POST_DEPLOY
```

Le statut "planning entièrement couvert" **n'est pas un événement historisé** — c'est un état dérivé du `coverage-summary` calculé en temps réel. Il ne doit pas être injecté dans le timeline comme un événement fictif.

### Contraste avec `UserAuditEvent`

`UserAuditEvent` (D-046) trace les actions d'administration (suspension, changement de rôle) avec `targetUser` nullable et sans FK mission. `AuditEvent` trace les actions opérationnelles sur les missions — les deux coexistent, distincts par conception.

---

## D-056 — Règle d'or : toute mutation de Mission passe par un service d'application

Date : 2026-06-28

### Décision

Aucune feature n'est autorisée à modifier une entité `Mission` directement depuis un contrôleur ou un handler.

Toute mutation de `Mission` — création post-deploy incluse — doit passer par un **service d'application** (`MissionPostDeployService` ou son successeur). Ce service est seul responsable de :

1. **La validation métier** (transition de statut légale, permissions d'audience, cohérence des données)
2. **La création de l'`AuditEvent`** (actor, mission FK, eventType, payload snapshot before/after)
3. **Le flush synchrone** (AuditEvent en base avant tout dispatch)
4. **Le dispatch de `MissionLifecycleChangedMessage`** (async, pour les notifications)

### Règle

> **Toute mutation de Mission passe par le service d'application. Jamais directement depuis un contrôleur. Jamais depuis un handler Messenger.**

### Pourquoi

Cette règle empêche les code paths futurs de bypasser l'audit ou les notifications.

Sans cette contrainte, une feature ajoutée rapidement (e.g. "assigner instrumentiste depuis l'alert modal") peut muter une Mission et oublier de dispatcher `MissionLifecycleChangedMessage` — le chirurgien n'est pas notifié, l'historique a un trou.

### Conséquences

- Le contrôleur est un orchestrateur : il lit la requête, appelle le service, sérialise la réponse. Pas de `$em->persist` dans un contrôleur pour une Mission.
- Le handler `MissionLifecycleChangedMessageHandler` ne mute **jamais** une Mission — il émet seulement des `NotificationEvent`. La séparation est stricte.
- Un test sur un endpoint Mission **doit** vérifier la présence d'un `AuditEvent` en base et d'un `MissionLifecycleChangedMessage` dans le transport de test. C'est le critère minimum de la Définition of Done de chaque batch.
- L'existant (`claimMission`, `assignInstrumentist`) doit être migré vers ce service dans Batch 15B.

### Lien avec D-052

D-052 définit le cycle de vie du planning vivant et le pattern de dispatch. D-056 rend ce pattern **non-optionnel** — c'est une règle de gouvernance du code, pas une recommandation.

---

## D-057 — MissionEligibilityService : source de vérité unique pour l'éligibilité

Date : 2026-06-29

> **Précisée par D-059 (2026-07-06)** : le tableau ci-dessous documente `findEligible()` comme
> retournant `array<int, User[]>` agrégé par site. D-059 fige la cible mission-centrique
> (`array<missionId, User[]>`) pour cette méthode — voir D-059 pour le rationale et la garantie
> que cette évolution ne coûte aucune requête DB supplémentaire (D-036 préservé).

### Décision

Un service dédié `MissionEligibilityService` est la **seule** source de vérité pour décider si un instrumentiste peut revendiquer (claim) une mission OPEN.

### Trois méthodes publiques

| Méthode | Usage | Cardinalité DB |
|---|---|---|
| `evaluate(Mission, User): EligibilityResult` | Gate pré-lock dans `MissionPostDeployService::claim()` | ≤ 3 requêtes |
| `evaluateAllCandidates(Mission): EligibilityResult[]` | Endpoint `GET /eligible-instrumentists` | ≤ 3 requêtes |
| `findEligible(Mission[]): array<int, User[]>` | Notifications pool dans `PlanningDeployPdfsMessageHandler` | ≤ 3 requêtes |

### EligibilityResult (DTO immutable)

```php
final readonly class EligibilityResult {
    public bool $eligible;  // true si reasons est vide
    public function __construct(public User $candidate, public array $reasons) {}
}
```

### EligibilityReason (enum)

Six raisons typées : `INACTIVE`, `NO_SITE_MEMBERSHIP`, `ABSENT`, `SCHEDULE_CONFLICT`, `ALREADY_ASSIGNED`, `INCOMPATIBLE_STATUS`.

### Règles de performance (D-036)

Chaque méthode effectue exactement 3 requêtes DB, indépendamment du nombre de missions ou de candidats. Les filtres fins (absence qui couvre exactement la mission, conflit horaire exact) sont appliqués en PHP après chargement batch.

### Pourquoi un service dédié

Sans ce service, la logique d'éligibilité était dupliquée : dans `MissionVoter`, dans `PlanningPreviewService`, dans `sendPoolNotifications()`. Chaque copie avait des règles légèrement différentes.

Ce service devient le point d'entrée unique — le voter délègue, le handler délègue, le contrôleur délègue.

### Fix MissionVoter (V2 OPEN)

Les missions OPEN Planning V2 n'ont pas de `MissionPublication`. L'ancienne logique de `canClaim()` appelait `isEligibleInstrumentistForOpenMission()` qui itérait les publications → retournait `false` pour toutes les missions V2. Fix : guard `if ($mission->getPublications()->isEmpty()) { return true; }` — l'éligibilité réelle est déléguée à `MissionEligibilityService::evaluate()` appelé dans `claim()`.

---

## D-058 — Email policy redesign : un seul email de déploiement par destinataire

Date : 2026-07-04

### Contexte

Un audit des emails de déploiement (surgeons/instrumentistes recevant des emails en double, contenu qui se recoupe, PDF partiellement en anglais, libellé `Laissé ouvert manuellement` peu clair) a révélé la cause racine : `PlanningV2GenerationController::deploy()` transmettait `sendPdf` (défaut `true`) directement au paramètre `sendChangeSummary` de `PlanningDeploymentService::deploy()` — **chaque déploiement V2 envoyait donc systématiquement l'email de récapitulatif de changements**, en plus de l'email "Planning" standard. Voir l'audit complet pour le détail des chemins de code.

### Décision

**Exactement UN email de déploiement par destinataire.** Cette décision **amende D-053** (qui interdisait les compteurs agrégés dans l'email chirurgien) et **précise D-054** (dont la Famille 1 instrumentiste, elle, était déjà correcte et reste inchangée).

**Chirurgien** — un seul email `Planning du {from} au {to}` :
- Salutation, période, compteurs agrégés (`totalCount`/`coveredCount`/`uncoveredCount`).
- Si `uncoveredCount > 0` : paragraphe explicatif non technique ("déjà proposées aux instrumentistes disponibles, vous serez informé dès qu'une est acceptée").
- Aucune mention du mécanisme interne (`UncoveredReasonResolver`, noms d'enum).
- PDF personnel joint uniquement — **le PDF global n'est plus joint** (contenu réservé au manager).

**Instrumentiste** — un seul email `Planning du {from} au {to}` (Famille 1 de D-054, inchangée) :
- Salutation, période, nombre de missions assignées, PDF personnel joint.

**Manager (déployeur)** — nouvel email `Déploiement confirmé — planning {from} au {to}` :
- Confirmation, missions/assignées/ouvertes, PDF global joint.
- S'ajoute à la notification in-app `PLANNING_DEPLOYED_MANAGER` existante (même type, canal email désormais également actif).

**Email "récapitulatif de changements" (`planning_change_summary_*`)** : n'est **plus jamais envoyé pendant le déploiement initial**. La capacité n'est pas supprimée — elle est extraite dans `PlanningChangeSummaryService`, un service autonome non invoqué par `PlanningDeployPdfsMessageHandler`. Elle est réservée à un futur déclencheur sur un planning déjà publié qui change (réassignation, annulation, etc.) — ce déclencheur n'existe pas encore.

> **✅ Déclencheur câblé depuis Batch 15K (2026-07-11)** : le mode Modification de
> l'éditeur unifié Planning V2 (`POST /api/planning/versions/{id}/apply-modifications`)
> est ce futur déclencheur — `PlanningModificationService` calcule un diff avant/après
> sur le lot d'édits et appelle `PlanningChangeSummaryService::sendChangeSummaryEmails()`
> une seule fois, un email ciblé par personne réellement affectée. Les deux templates
> (`planning_change_summary_instrumentist.html.twig`/`_surgeon.html.twig`) ont aussi été
> refondus visuellement à cette occasion (liste unifiée "Modifications (N)" par carte,
> au lieu de sections ✅/🔄/❌ séparées). Voir `docs/api.md` §26.6c.

### Pourquoi l'amendement de D-053

D-053 interdisait les compteurs agrégés au motif qu'"un chirurgien raisonne par journée opératoire, pas par quota". En pratique, cette contrainte forçait un second email (le récapitulatif "postes non couverts") pour transmettre exactement l'information — total/couvert/non couvert — qu'un compteur agrégé aurait donné directement, créant la duplication à l'origine de ce ticket. Le compromis retenu : les compteurs suffisent pour l'email (le chirurgien sait qu'il faut vérifier), le détail poste-par-poste avec raison reste disponible **in-app** (`NotificationEvent.payload.posts[]`, inchangé) pour qui veut le détail.

### Libellé `Laissé ouvert manuellement`

Provenait de `UncoveredReason::MANUALLY_LEFT_OPEN` — en réalité le cas *fallback* du resolver (aucune des trois autres raisons détectées ne s'applique), pas une action manuelle avérée. Renommé `Recherche en cours` (`src/Enum/UncoveredReason.php`) — plus neutre, ne présuppose pas une décision spécifique, cohérent avec le message "déjà proposées aux instrumentistes".

### Traductions PDF centralisées

- `MissionStatus::label()` ajouté (`src/Enum/MissionStatus.php`) — remplace `mission.status.value` (anglais brut : `OPEN`, `ASSIGNED`, …) dans les 3 templates PDF (`pdf/planning_surgeon.html.twig`, `pdf/planning_instrumentist.html.twig`, `pdf/planning_global.html.twig`).
- Filtre Twig `french_day` (`src/Twig/DateExtension.php`) — remplace `day|date("l")` (anglais brut : `Tuesday`) dans les mêmes 3 templates. PHP's `date("l")` est indépendant de la locale ; ce filtre centralise la traduction plutôt que de la dupliquer.

### API

`sendChangeSummary` retiré de `PlanningDeploymentService::deploy()`, `PlanningDeployPdfsMessage`, `POST /api/planning/deploy`. `sendPdf` retiré de `POST /api/planning/v2/deploy` (n'avait plus d'effet distinct une fois `sendChangeSummary` retiré du service partagé). Voir `docs/api.md` §26.6/§26.6 V2.

> **Dette connue, hors scope de ce ticket** : le modal V1 `DeployModal.tsx` (frontend, `planning-manager`) envoie encore un champ `sendChangeSummary` dans sa requête — désormais silencieusement ignoré par le backend (pas d'erreur HTTP, juste sans effet). La case à cocher correspondante devient un no-op côté UI ; un ticket frontend de suivi est nécessaire pour la retirer/relabelliser.
> **Résolu par D-079 (2026-07-20)** : `DeployModal.tsx` a été supprimé avec tout le reste du moteur V1 — plus de no-op à corriger.

---

## D-059 — MissionClaim historique seule + MissionEligibilityService mission-centrique

Date : 2026-07-06

### Contexte

Suite au P0 découvert en validation (« une mission relâchée ne peut plus jamais être reprise ») et au P1 associé (« la notification `OPEN_MISSION_AVAILABLE` liste des missions que le destinataire ne peut pas réellement revendiquer »), une revue d'architecture complète a été menée sur `MissionClaim` et sur la forme de retour de `MissionEligibilityService::findEligible()`. Cette ADR fige les deux décisions qui en résultent. **Aucun code n'est modifié par cette ADR** — elle documente l'architecture cible ; l'implémentation des correctifs P0/P1 la suit.

---

### Décision 1 — MissionClaim devient une entité historique, jamais consultée pour une décision métier

`MissionClaim` est désormais officiellement une entité **append-only** : elle enregistre qu'une revendication a eu lieu, à quel instant, par qui — et ne participe **plus jamais** à la détermination de l'état courant d'une mission.

**L'état courant d'une mission est déterminé exclusivement par `Mission.status` et `Mission.instrumentist`.** Aucune autre source n'est consultée pour répondre à « cette mission est-elle actuellement revendicable / assignée ? ».

#### Pourquoi l'usage de MissionClaim comme garde d'état a créé le P0

`MissionPostDeployService::claim()` vérifiait trois conditions avant d'accepter une revendication : `mission.status === OPEN`, `mission.instrumentist === null`, et l'absence d'une ligne `MissionClaim` existante pour la mission. Les deux premières conditions sont **strictement suffisantes** — elles reflètent exactement l'état courant. La troisième condition interroge une table dont le cycle de vie n'est **pas synchronisé** avec celui de la mission : `release()` remet `mission.status` à `OPEN` et `mission.instrumentist` à `null`, mais ne supprime jamais la ligne `MissionClaim` créée par la revendication précédente. Résultat : une mission redevenue `OPEN` de manière parfaitement valide reste bloquée pour toujours par une ligne historique que plus rien ne rattache à son état réel. Le bug n'est pas un oubli de nettoyage isolé — c'est la conséquence directe d'avoir laissé une entité pensée comme un historique jouer aussi le rôle d'un verrou d'état.

#### Investigation ayant motivé la décision

- **Lecture** : un seul site de lecture dans tout le code (`MissionPostDeployService::claim()`, le garde-fou incriminé). `Mission::getClaims()` existe mais n'a aucun appelant en dehors de l'entité elle-même. Aucun DTO ne sérialise de données de claim dans une réponse API.
- **Écriture** : un seul site de création (`claim()`), aucune mise à jour, aucune suppression explicite nulle part (la cascade `orphanRemoval: true` sur `Mission::$claims` existe mais n'est exercée par aucun code actuel).
- **Contrainte DB** : aucune contrainte unique sur `mission_claim.mission_id` (vérifié via `information_schema.STATISTICS` — seuls des index de clé étrangère non-uniques existent). Le `catch (UniqueConstraintViolationException)` du service protège contre une contrainte qui n'existe pas.
- Conclusion : rien dans la base de code ne dépend aujourd'hui de `MissionClaim` comme représentation de l'état courant. `Mission.status` + `Mission.instrumentist` suffisent déjà entièrement.

#### Cas d'usage futurs rendus possibles

Une fois `MissionClaim` traitée comme pur historique : historique des revendications par mission (« revendiquée par X, relâchée, revendiquée par Y »), statistiques de charge par instrumentiste (« N missions revendiquées ce mois-ci » — alimente directement l'idée de charge de travail déjà identifiée dans la roadmap UX), reporting/analytics (délai moyen avant revendication d'une mission ouverte, patterns par site/période), timelines historiques dédiées.

#### Responsabilités respectives — Mission, MissionClaim, AuditEvent

| Entité | Responsabilité | Ce qu'elle N'EST PAS |
|---|---|---|
| **Mission** | Source de vérité unique et exclusive de l'état courant (`status`, `instrumentist`, horaires, site). Toute décision métier (« peut-on revendiquer, réassigner, annuler ? ») se base uniquement sur ces champs. | Un journal — Mission ne conserve pas l'historique de ses propres transitions. |
| **MissionClaim** | Enregistrement append-only, spécifique et typé, du moment où une mission a été revendiquée par un instrumentiste (`mission`, `instrumentist`, `claimedAt`). Sert exclusivement des besoins d'historique/reporting/statistiques ciblés sur l'événement « claim ». | Un garde d'état — plus jamais consultée par `claim()` ou tout autre service pour une décision métier. |
| **AuditEvent** | Le journal général et transverse de **tous** les changements post-déploiement d'une Mission (claim, release, reassign, cancel — D-055), avec acteur, horodatage et payload snapshot lisible durablement. C'est la source utilisée par `GET /api/missions/{id}/audit` et par la timeline `GET /api/planning/versions/{id}/history`. | Un remplaçant de MissionClaim pour des requêtes structurées/typées sur les claims spécifiquement — AuditEvent stocke un payload JSON générique, pas des colonnes typées interrogeables efficacement pour des statistiques de charge par exemple. |

**Chevauchement assumé, pas une redondance à supprimer :** `AuditEvent` enregistre déjà `MISSION_CLAIMED_FROM_POOL` pour chaque revendication — `MissionClaim` et `AuditEvent` racontent donc partiellement le même fait, à deux niveaux de granularité différents (log générique horodaté vs. table dédiée et typée). Les deux coexistent : `AuditEvent` reste le journal transverse de référence ; `MissionClaim` devient la vue spécialisée, requêtable efficacement, pour tout ce qui concerne spécifiquement les revendications (statistiques, charge, historique dédié).

---

### Décision 2 — MissionEligibilityService devient progressivement mission-centrique

Le modèle métier canonique de l'éligibilité est **« qui est éligible pour CETTE mission ? »**, pas « qui est éligible pour CE site ? ». `findEligible(Mission[]): array<siteId, User[]>` doit évoluer vers une forme **mission-centrique** : `array<missionId, User[]>` (ou équivalent), exposée progressivement — sans réécriture brutale, au fil des correctifs qui la consomment (à commencer par le P1).

#### Pourquoi

- **Correctness** : la forme actuelle (agrégée par site) a produit un bug reproductible et confirmé — un utilisateur éligible pour au moins une mission d'un site reçoit la liste complète des missions ouvertes du site, y compris celles pour lesquelles il n'est individuellement pas éligible (absence, conflit). La forme mission-centrique élimine cette classe de bug par construction : chaque mission porte sa propre liste d'éligibles, sans reconstruction approximative côté appelant.
- **Simplicité** : les deux appelants actuels de `findEligible()` (`PlanningDeployPdfsMessageHandler`, `MissionLifecycleChangedMessageHandler`) veulent tous les deux, in fine, la réponse mission-centrique — l'un la reconstruit (mal) depuis l'agrégat par site ; l'autre s'en sort uniquement parce qu'il n'appelle jamais la méthode avec plus d'une mission à la fois. Une forme mission-centrique est directement consommable par les deux, sans reconstruction.
- **Living Planning** : le besoin de réassignation (« qui peut reprendre CETTE mission ») est déjà servi par `evaluateAllCandidates(Mission)`, déjà mission-centrique et déjà correct — cette décision aligne `findEligible()` sur le même principe directeur, pour toute future fonctionnalité Living Planning qui aurait besoin de « qui pourrait revendiquer cette mission ouverte précise ».
- **Notifications** : élimine directement le P1 — chaque notification `OPEN_MISSION_AVAILABLE` peut porter la liste exacte des missions que SON destinataire peut réellement revendiquer.
- **Réassignation** : aucun changement requis — `evaluateAllCandidates()` est un chemin séparé, déjà correct, non affecté par cette décision.
- **Futur dashboard / analytics** : toute vue agrégée future (« combien de missions ouvertes ont au moins un candidat éligible », « temps moyen avant premier candidat éligible ») se construit plus naturellement à partir d'une carte mission→candidats que depuis un agrégat par site qui a déjà perdu l'information.

#### Préservation de la garantie de performance D-036

Cette évolution ne coûte **aucune requête supplémentaire**. L'inspection du code de `findEligible()` montre que le calcul d'éligibilité par candidat **par mission** (`isEligibleForMission($candidate, $mission, ...)`) est déjà effectué à l'intérieur de la boucle existante, pour chaque paire candidat × mission du site — la méthode s'arrête simplement (`break`) dès qu'une première mission correspond, et ne conserve que le fait agrégé « éligible pour au moins une ». Passer à une forme mission-centrique ne change ni Q1, ni Q2, ni Q3 (toujours exactement 3 requêtes, indépendamment du nombre de missions/candidats, conformément à D-036) — seule la dernière étape d'agrégation en PHP est concernée : conserver le résultat pour **chaque** mission qui correspond au lieu de s'arrêter à la première.

#### Statut

Architecture validée et gelée pour ces deux décisions. L'implémentation (fix P0 : suppression du garde `MissionClaim` dans `claim()` ; fix P1 : `findEligible()` mission-centrique et `sendPoolNotifications()`/`sendOpenMissionAvailableNotifications()` adaptés) suit dans un ticket séparé, une fois cette ADR revue.

---

## D-060 — Photo de profil : optionnelle à l'onboarding, rappel proactif après connexion

Date : 2026-07-06

### Décision

La photo de profil reste **techniquement optionnelle** à la complétion de compte (`/complete-account`) — aucune validation ne bloque un compte sans photo. En complément, tout utilisateur **actif** (compte déjà complété, donc authentifié avec succès) qui n'a pas encore de photo de profil se voit proposer, après connexion, un modal de rappel non bloquant l'invitant à en ajouter une. Le modal est fermable sans conséquence et n'empêche jamais la navigation.

### Pourquoi ne pas rendre la photo obligatoire

Une contrainte obligatoire à l'onboarding aurait un coût réel (friction à l'inscription, blocage possible si l'utilisateur n'a pas de photo sous la main au moment de l'invitation) pour un bénéfice purement organisationnel (identification visuelle dans les plannings) — pas une exigence métier ou réglementaire. Le compromis retenu : demander explicitement au bon moment (onboarding), puis rappeler une fois le compte actif, sans jamais bloquer.

### Pourquoi un rappel après connexion plutôt qu'un blocage

- Le rappel cible précisément les comptes qui ont sauté l'étape (`profilePictureUrl` vide) — pas un rappel systématique à chaque connexion pour tout le monde.
- Dismiss stocké en **session** (`surgicalhub.profilePhotoPrompt.dismissed.<userId>`), pas en permanent : un « Plus tard » ne supprime pas définitivement le rappel (il peut réapparaître à une session future), mais ne harcèle pas non plus l'utilisateur à chaque navigation dans la même session.
- Un seul point de montage (`ProfilePhotoPromptGate` dans `RequireAppAccess`) couvre tous les rôles/layouts (`MobileLayout` : instrumentiste/chirurgien ; `DesktopLayout` : manager/admin) sans dupliquer la logique dans chaque shell.

### Réutilisation de l'infrastructure existante

Aucune nouvelle infrastructure de stockage : `ProfilePictureStorage` (déjà utilisé par `POST /api/invitations/complete`) est réutilisé tel quel par le nouvel endpoint `POST /api/me/profile-picture`, avec exactement la même validation (`Assert\Image`, jpeg/png/webp, 5 Mo max — cf. `docs/api.md` §21). Le remplacement d'une photo existante supprime l'ancien fichier (déjà géré par le service, non modifié).

### Portée

- Pas de données patient, pas de données financières impliquées.
- RBAC inchangé : l'endpoint n'a pas de Voter dédié — un utilisateur ne modifie jamais que sa propre photo, aucune autorisation supplémentaire n'a de sens ici.
- L'écran "Mon profil" (seul écran de profil existant à ce jour, côté instrumentiste) permet aussi le changement direct de la photo, indépendamment du modal.

---

## D-061 — MAIL_SAFE_MODE : garde-fou centralisé contre l'envoi accidentel d'emails réels

Date : 2026-07-12

### Contexte — incident du 2026-07-12

Pendant la validation post-déploiement de `v2026.07.11-prod-3`, le tout premier test manuel
(déploiement initial de planning) a été exécuté directement en production avec de
**vraies données** (chirurgiens/instrumentistes réels d'un site réel) au lieu de comptes
jetables — parce que le MAILER_DSN de production pointe vers un vrai relais SMTP
(Hostinger), contrairement au MAILER_DSN local qui pointe vers un catcher (Mailpit).
**16 emails réels** ont été envoyés à de vraies personnes, avec le sujet "Planning du
01/02/2027 au 28/02/2027" — un planning fictif de test. Le code testé était correct ; la
cause était strictement procédurale (voir l'entrée d'incident dans `docs/production.md`).

### Décision

Ajout de `App\EventListener\MailSafeModeListener`, écouteur sur
`Symfony\Component\Mailer\Event\MessageEvent` — le point bas-niveau par lequel **tout**
email sortant de l'application transite, quel que soit le flux métier qui l'a déclenché
(invitations, reset, déploiement planning, modification planning, facturation, relances
absences, alertes). Audit exhaustif (2026-07-12) : seuls deux handlers appellent
`MailerInterface::send()` dans tout le repo (`SendTemplatedEmailMessageHandler`,
`SendBillingEmailMessageHandler`) — `MessageEvent` les couvre tous les deux sans
exception, et toute future voie d'envoi les couvrira aussi automatiquement (le point
d'interception est le composant Mailer lui-même, pas un des appelants).

**Comportement** : quand le mode sûr est actif, tout destinataire (`To`/`Cc`/`Bcc`) dont
l'adresse n'est ni explicitement autorisée (`MAIL_SAFE_ALLOWED_RECIPIENTS`) ni sur un
domaine autorisé (`MAIL_SAFE_ALLOWED_DOMAINS`, défaut `surgicalhub.internal`) est retiré
du message **et** de l'`Envelope` SMTP réellement utilisé à l'envoi (voir "Pourquoi
l'Envelope" ci-dessous). Si plus aucun destinataire ne reste, l'envoi est purement et
simplement annulé (`MessageEvent::reject()` — mécanisme officiel Symfony, jamais un
`throw` qui casserait le flux appelant).

**Activation** (`MAIL_SAFE_MODE`, défaut `auto`) :
- `auto` — actif partout sauf si `kernel.environment === 'prod'`. Aucune configuration
  requise pour le cas courant : chaque environnement non-prod est protégé par défaut.
- `on` — forcé actif, y compris en prod — c'est le mécanisme qui aurait empêché
  l'incident du 2026-07-12 : une session de test manuel contre la vraie prod peut
  activer ceci temporairement (voir `docs/mail-safe-mode.md`), sans toucher au
  `MAILER_DSN` ni changer quoi que ce soit d'autre.
- `off` — forcé inactif, y compris hors prod (réservé à un futur environnement staging
  qui devrait légitimement envoyer de vrais emails — jamais activé à la légère).

### Pourquoi l'Envelope, pas seulement les en-têtes du message

Une première version ne modifiait que `Email::to()/cc()/bcc()`. Le composant Mailer de
Symfony envoie en réalité via un `Envelope` (liste RCPT TO SMTP), qui peut être un
`DelayedEnvelope` recalculé paresseusement depuis les en-têtes du message — donc
correcte dans ce repo puisque personne ne construit d'`Envelope` explicite — mais rien
ne garantit que ça reste vrai indéfiniment. Le listener reconstruit désormais aussi
explicitement l'`Envelope` avec la liste filtrée, pour que la garantie ne dépende jamais
d'un détail d'implémentation interne de Symfony. Couvert par un test dédié
(`test_mixed_recipients_strips_only_the_non_allow_listed_ones_from_message_and_envelope`)
qui aurait échoué avec la première version.

### Pourquoi un `EventListener` bas-niveau plutôt qu'une vérification dans chaque handler

Une vérification dupliquée dans les deux handlers existants aurait été concrètement
suffisante aujourd'hui, mais silencieusement contournable par tout futur appelant qui
injecterait `MailerInterface` ailleurs (un nouveau handler, une commande console, un
script ponctuel) sans savoir qu'il doit répliquer la vérification. Un listener sur
l'événement du composant Mailer lui-même ferme cette classe de bug par construction :
impossible d'envoyer un email dans cette application sans passer par lui.

### Documentation

`docs/mail-safe-mode.md` (nouveau) — fonctionnement complet, activation/désactivation,
test avec Mailpit, variables d'environnement. `docs/deployment-versioning.md` mis à jour
avec une étape obligatoire (§5, tests ciblés) : toute vérification manuelle d'un flux
email en production doit d'abord activer `MAIL_SAFE_MODE=on`.

### Statut

Implémenté et testé (11 tests unitaires `MailSafeModeListenerTest` couvrant la matrice
de décision complète + 2 tests d'intégration `MailSafeModeIntegrationTest` prouvant le
câblage réel dans le conteneur — un test unitaire seul ne peut pas détecter une erreur
de câblage `services.yaml`/attribut `#[AsEventListener]`).

### Amendement 2026-07-12 (même jour) — mode `capture` pour Mailpit

Le tout premier usage réel du garde-fou en développement local (déploiement testé
contre une copie de données de production) a révélé un second problème : le mode
d'origine n'avait qu'un seul comportement, filtrer/rejeter tout destinataire non
autorisé — ce qui bloquait **aussi** les emails en local, où `MAILER_DSN` pointe déjà
vers Mailpit, un simple capteur SMTP incapable de livrer quoi que ce soit vers
Internet. Résultat : plus aucun email visible dans Mailpit dès qu'un test utilisait de
vraies adresses, y compris pour un usage parfaitement légitime (vérifier le rendu réel
d'un template avec des données réalistes).

**Décision** : ajout d'un second mode de délivrance, `capture`, choisi via la nouvelle
variable `MAIL_SAFE_DELIVERY_MODE` (défaut `auto`). En mode `capture` — actif
automatiquement quand `MAILER_DSN` correspond à un sink local vérifié
(`MAIL_SAFE_LOCAL_SINKS`, ex. `mailer:1025`) — les destinataires sont laissés intacts
(visibles dans Mailpit) et un en-tête `X-SurgicalHub-Mail-Safe-Mode: captured-locally`
est ajouté ; la garantie de sécurité vient du **transport vérifié incapable de livrer
en dehors de la machine**, pas du filtrage des destinataires. Le mode `allowlist`
(comportement d'origine, décrit ci-dessus) reste utilisé partout où le transport
configuré peut réellement atteindre Internet (staging avec relais SMTP, production).

**Garde-fou obligatoire** : `MAIL_SAFE_DELIVERY_MODE=capture` explicite est **refusé**
(repli automatique sur `allowlist`, log `critical`) si `MAILER_DSN` ne correspond à
aucun sink local reconnu — une variable mal positionnée ne peut donc jamais désactiver
le filtrage par accident contre un vrai relais. La décision ne repose jamais sur
`APP_ENV`/`kernel.environment` seul, uniquement sur ces deux faits vérifiables
(garde-fou actif + transport vérifié).

Vérifié en conditions réelles : redéploiement local du même scénario qui avait échoué
(données de copie de production, période réelle) — les 16 emails initialement
silencieusement bloqués apparaissent désormais dans Mailpit avec leurs vrais
destinataires et l'en-tête de diagnostic, confirmé via l'API Mailpit
(`http://localhost:8026`) et la source brute du message.

`docs/mail-safe-mode.md` mis à jour en conséquence (§1, §2, §3.1, §6). 8 nouveaux tests
ajoutés à `MailSafeModeListenerTest` (capture/allowlist/auto, refus de capture contre
un relais externe, transport `null://`), `MailSafeModeIntegrationTest` mis à jour pour
refléter le nouveau comportement attendu en environnement `test` (capture, pas rejet,
puisque le DSN committé y est aussi Mailpit).

---

## D-062 — Réaction automatique aux absences sur les missions déjà déployées

Date : 2026-07-12

### Contexte

Jusqu'ici, `AbsenceImpactService` était la seule réaction du système à une absence
créée/modifiée/supprimée sur une mission déjà générée — et son contrat, documenté et
testé explicitement, était de **ne jamais muter une Mission** ("Hard rule: this service
NEVER mutates a Mission"), se limitant à créer/résoudre des `PlanningAlert` pour qu'un
manager traite chaque cas à la main. Le besoin métier : un planning publié doit réagir
automatiquement à une absence déclarée après coup, sans attendre une action manuelle
pour les cas non ambigus.

### Décision

Ajout d'un nouveau collaborateur, `App\Service\AbsenceMissionReactionService`, appelé par
`AbsenceController` **en plus de** (jamais à la place de) `AbsenceImpactService` — dont le
contrat "jamais de mutation" reste intégralement vrai et inchangé. Portée :

- **Absence instrumentiste** — missions `ASSIGNED` dont l'instrumentiste est la personne
  absente → `MissionPostDeployService::release()` (`ASSIGNED` → `OPEN`, instrumentiste
  retiré).
- **Absence chirurgien** — missions `OPEN`/`ASSIGNED` dont le chirurgien est la personne
  absente → `MissionPostDeployService::cancel()` (→ `CANCELLED`, instrumentiste retiré le
  cas échéant).

**Règle par statut (audit exhaustif, `MissionStatus` complet) :**

| Statut | Absence instrumentiste | Absence chirurgien |
|---|---|---|
| `DRAFT` | Jamais touché — pas encore déployé, hors périmètre de cette fonctionnalité | Idem |
| `OPEN` | Sans objet (un `OPEN` n'a jamais d'instrumentiste) | **`CANCELLED`** |
| `ASSIGNED` | **`OPEN`, instrumentiste retiré** | **`CANCELLED`, instrumentiste retiré** |
| `SUBMITTED` | Jamais touché — déclaration déjà en cours, alerte existante conservée | Idem |
| `VALIDATED` | Jamais touché — déjà validée par le manager, alerte existante conservée | Idem |
| `IN_PROGRESS` | Jamais touché — intervention en cours, alerte existante conservée | Idem |
| `DECLARED` | Jamais touché (déjà hors périmètre de `AbsenceImpactService` aujourd'hui — comportement préexistant non modifié) | Idem |
| `CLOSED`, `REJECTED`, `CANCELLED` | Terminaux, jamais retraités | Idem |

`SurgeonSchedulePost` (définition récurrente) n'est **jamais** touché — seules les
occurrences `Mission` déjà matérialisées le sont, conformément à la distinction du
cahier des charges. Prouvé par test fonctionnel dédié comparant un snapshot complet du
post avant/après traitement d'une absence sur une de ses missions.

### Ordonnancement — pourquoi `AbsenceImpactService` n'a nécessité aucune modification

`AbsenceController` appelle `AbsenceMissionReactionService` **avant**
`AbsenceImpactService`. La requête de chevauchement de `AbsenceImpactService`
(`(m.surgeon = :user OR m.instrumentist = :user) AND m.status IN (alertable)`) exclut
naturellement toute mission déjà mutée : l'instrumentiste est désormais `null` (ne
correspond plus à `m.instrumentist = :user`), ou le statut est `CANCELLED` (jamais dans
la liste des statuts alertables). Résultat : aucune alerte `REASSIGNMENT_REQUIRED` ou
`SURGEON_ABSENCE` obsolète n'est jamais créée pour une mission déjà auto-traitée, sans
avoir touché une seule ligne d'`AbsenceImpactService`. Les deux services composent
correctement du seul fait de l'ordre d'appel.

### Idempotence

Aucune table de suivi "déjà traité" — la requête de chevauchement elle-même exclut une
mission une fois mutée (son FK/statut ne correspond plus aux critères), donc rejouer le
traitement (mise à jour de l'absence sans changement de période, ou changement qui ne
couvre plus une mission déjà traitée) ne retraite jamais rien. Prouvé par test
fonctionnel avec re-`PATCH` répété sur les mêmes dates : un seul `AuditEvent`, jamais de
doublon.

### Concurrence

Chaque mutation acquiert un verrou pessimiste (`LockMode::PESSIMISTIC_WRITE`) dans une
transaction, avec re-vérification du statut sous verrou avant mutation — même schéma que
`MissionPostDeployService::claim()`, seul autre point de forte contention préexistant
dans le code. `MissionLifecycleChangedMessage` est dispatché **après** le commit de la
transaction, jamais depuis l'intérieur (dispatcher avant commit exposerait un worker à
un état pas encore visible sur une autre connexion).

### Notifications — pourquoi 3 nouveaux `NotificationType`

Le pipeline existant (`MissionLifecycleChangedMessageHandler`, déclenché par
`MissionPostDeployService::release()`/`cancel()`) est **exclusivement in-app/push** — il
n'envoie d'email pour aucun type de changement aujourd'hui. C'est le vrai manque que
cette fonctionnalité comble. `AbsenceMissionReactionService` dispatche un second message,
`AbsenceMissionsReactedMessage` (un seul par traitement d'absence, jamais un par
mission), traité par `AbsenceMissionsReactedMessageHandler`, qui ajoute :

- `ABSENCE_INSTRUMENTIST_RELEASED` — à l'instrumentiste retiré.
- `ABSENCE_SURGEON_MISSION_OPENED` — à chaque chirurgien concerné.
- `ABSENCE_MISSION_CANCELLED` — à chaque instrumentiste dont la mission est annulée.

Les trois sont ajoutés à `DefaultNotificationPreferenceResolver::EMAIL_ON_BY_DEFAULT`
(même urgence que `PLANNING_MISSION_CANCELLED`). Aucun des types existants ne convenait :
tous manquaient soit le cadrage "à cause d'une absence", soit tout simplement le canal
email. Le pipeline existant continue de fonctionner sans changement en parallèle
(`SURGEON_POST_UNCOVERED`, `OPEN_MISSION_AVAILABLE`, `PLANNING_MISSION_CANCELLED` — tous
in-app/push, jamais dupliqués avec les nouveaux emails).

**Anti-doublon** : l'email est groupé par destinataire (un seul email récapitulatif par
personne et par traitement d'absence, listant toutes les missions concernées) ; les
notifications in-app restent unitaires (une par mission), explicitement autorisé par le
cahier des charges. Une mission `OPEN` déjà sans instrumentiste au moment de son
annulation (absence chirurgien) n'a simplement aucun destinataire instrumentiste — pas
un email vide.

### Extension de `MissionPostDeployService::cancel()`

`cancel()` n'acceptait que `OPEN → CANCELLED`. Étendu à `OPEN|ASSIGNED → CANCELLED`,
avec retrait de l'instrumentiste dans le second cas. Effet de bord assumé et documenté :
l'endpoint manager générique `POST /api/missions/{id}/cancel` (qui appelle la même
méthode) peut désormais aussi annuler une mission `ASSIGNED` — capacité jugée saine en
soi (aucune règle métier existante ne l'interdisait, c'était une limitation initiale non
délibérée), test fonctionnel `MissionLifecycleControllerTest` mis à jour en conséquence.

### Suppression d'une absence — jamais de restauration automatique

`onAbsenceDeleted()` ne mute jamais une mission (une mission libérée a pu être reprise
par quelqu'un d'autre entre-temps ; une mission annulée a déjà généré ses propres
notifications — reconstruire l'état antérieur écraserait silencieusement ce qui s'est
passé depuis). À la place : une notification in-app (`PLANNING_ALERT`, réutilisé) à
chaque manager/admin, invitant à réévaluer manuellement. Délibérément générique — aucun
lien durable n'existe entre une `Absence` et les missions qu'elle a un jour mutées, et en
construire un pour ce seul cas d'usage aurait été disproportionné.

### Statut

Implémenté et testé : `MissionPostDeployServiceTest` (+3 tests pour l'extension de
`cancel()`), `AbsenceMissionReactionServiceTest` (13 tests unitaires),
`AbsenceMissionsReactedMessageHandlerTest` (8 tests unitaires — groupement par
destinataire, gating des préférences), `AbsenceMissionReactionFunctionalTest` (7 tests
fonctionnels réels — DB réelle, idempotence, `SurgeonSchedulePost` inchangé),
`AbsenceControllerTest` (2 tests réécrits pour le nouveau comportement + non-régression
de la composition avec `AbsenceImpactService`), `PlanningEmailTemplatesTest` (+7 tests
pour les 3 nouveaux templates). 831/831 tests backend verts. Vérifié en conditions
réelles contre Mailpit local (comptes jetables `@surgicalhub.internal`) : les deux
scénarios (absence instrumentiste, absence chirurgien) produisent exactement les emails
attendus, aucun doublon, `MAIL_SAFE_MODE` toujours actif (mode `capture`, aucune
livraison externe possible).

---

## D-063 — Modification sécurisée de l'adresse email par un manager/admin + double notification

Date : 2026-07-13

### Contexte

Un manager n'avait aucun moyen de corriger l'adresse email d'un instrumentiste ou d'un
chirurgien depuis les fiches `/app/m/instrumentists` et `/app/m/surgeons` — l'email
n'était modifiable nulle part après création du compte (`AdminUserController::patch()`,
ADMIN seul, gère explicitement `firstname`/`lastname`/`phone`, jamais `email`).

### Décision

Nouvel endpoint générique `PATCH /api/users/{id}/email` sur `UserController`
(collaborateur déjà existant, jusqu'ici limité à `PATCH /{id}/specialties`) — volontairement
**pas** dupliqué dans `InstrumentistController`/`SurgeonController`, l'email appartenant au
même agrégat `User` quel que soit le rôle. Toute la logique métier vit dans
`App\Service\UserEmailChangeService` (jamais dans le contrôleur, conformément au RBAC
strict du projet) :

`validation (vide / format Assert\Email / identique / doublon casse-insensible) →
mutation User → UserAuditService::userEmailChanged() (nouveau
UserAuditEventType::USER_EMAIL_CHANGED) → flush() → dispatch de 2×
SendTemplatedEmailMessage (ancienne adresse, puis nouvelle adresse)`.

**Autorisation** : nouvel attribut `UserAdministrationVoter::UPDATE_EMAIL`, ouvert à
`ROLE_MANAGER` **ou** `ROLE_ADMIN` — distinct de `UserAdministrationVoter::UPDATE`
(réservé `ROLE_ADMIN` seul, utilisé par `/api/admin/users/{id}`), pour ne jamais élargir
silencieusement le périmètre de cette dernière surface admin-only.

**Double notification, jamais couplée à `NotificationPreference`** : contrairement au
pipeline `NotificationType`/`DefaultNotificationPreferenceResolver` (préférences
utilisateur, désactivables), les deux emails de changement d'adresse sont **toujours**
envoyés — y compris à un compte suspendu — puisque leur unique objet est la sécurité du
compte, jamais une préférence de confort. Ancienne adresse capturée **avant** toute
mutation (jamais reconstruite après `flush()`). Chaque dispatch (`EmailService::
sendTemplatedEmail()`, qui encapsule `SendTemplatedEmailMessage`) est catché
indépendamment par le service : un échec de mise en file vers une adresse ne bloque
jamais l'autre, ni la mutation déjà flushée — remonté comme `warnings[]` dans la réponse
(`{code: "EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED", recipient: "old"|"new", message}`),
jamais comme un échec de la requête elle-même.

**Templates** : `user_email_changed_{old,new}_address.{html,twig}` — design final appliqué
le 2026-07-13 depuis le handoff `design_handoff_email_recap_planning` (même système
visuel que les emails de planning : table-based/CSS inline, wordmark + eyebrow "SÉCURITÉ"/
"COMPTE", carte `ancienne → nouvelle adresse` pour l'email ancienne adresse, bannière verte
de confirmation pour l'email nouvelle adresse). Variables de contexte : `displayName`,
`oldEmail`/`newEmail`. **La date/heure du changement n'apparaît volontairement pas dans le
corps de l'email** — absente du design fourni (fidélité totale retenue plutôt qu'un ajout
hors design), déjà tracée indépendamment dans `UserAuditEvent.createdAt` (accessible à un
admin via `/api/admin/audit`). Versions `.txt.twig` dérivées du même contenu (non fournies
par le design, celui-ci ne livrant que du HTML final).

**Erreurs** : réutilise les exceptions HTTP génériques déjà auto-mappées par
`ApiExceptionSubscriber` vers le format normalisé (`BadRequestHttpException`→400,
`NotFoundHttpException`→404, `ConflictHttpException`→409,
`UnprocessableEntityHttpException`→422) — aucune nouvelle classe d'exception nécessaire.

### Sessions JWT / refresh tokens — risque documenté, assumé, non contourné

`security.yaml` : le provider Doctrine charge l'utilisateur par `email`
(`property: email`). Le firewall `api` (`jwt: ~`) **recharge l'utilisateur à chaque
requête** via ce provider avec le claim `username` du JWT — capturé au moment de
l'émission, donc figé à l'ancienne adresse. `gesdinet_jwt_refresh_token` utilise le même
provider ; `RefreshToken.username` (colonne string, pas de FK `user_id`) souffre du même
figement. **Conséquence factuelle, pas un choix de code** : après un changement d'email,
la personne concernée perd sa session au prochain appel authentifié (rechargement par
l'ancien email → introuvable → 401) et son refresh échoue pour la même raison — une
reconnexion avec la nouvelle adresse est nécessaire. Exactement le même mécanisme que la
suspension d'un compte (`UserChecker`) : ce n'est jamais une invalidation codée en dur ici,
c'est une conséquence structurelle du provider. **Stratégie retenue : accepter et
documenter ce comportement, ne rien coder pour le contourner** — le préserver artificiellement
irait à l'encontre de l'objectif sécurité de la fonctionnalité (l'email est l'identifiant de
connexion).

### Google OAuth — risque identifié, non corrigé (hors périmètre)

`AuthGoogleController::__invoke()` retrouve l'utilisateur par
`findOneBy(['email' => $googleEmail])`. `User::$googleId` existe en colonne mais n'est
**jamais réellement renseigné** (ligne de code laissée commentée). Si la nouvelle adresse
saisie par le manager diverge de l'adresse réelle du compte Google de la personne, la
prochaine tentative de connexion Google ne retrouvera plus l'utilisateur et **créera un
second compte** au lieu d'échouer proprement. Risque réel, documenté ici et dans l'audit
de ce lot — corriger nécessiterait de réellement lier `googleId`, chantier distinct non
entrepris.

### DTO photos de profil — gap documentaire, pas de code

Audit du contrat de `GET /api/instrumentists` : `profilePicturePath` était déjà renvoyé
par `InstrumentistListItemResponse` (et déjà consommé côté `GET /api/surgeons`/
`{id}`) — seul `docs/api.md` §16.1 ne le montrait pas dans son exemple JSON. Corrigé
(ajout au JSON + note frontend), sans aucun changement de sérialiseur backend.
`InstrumentistListItemDTO` (frontend) complété à l'identique (additif).

### Frontend

`UserEmailEditor` (nouveau, partagé) — vue lecture (`email + bouton Modifier`) / vue
édition (champ + confirmation listant explicitement les deux destinataires notifiés) —
intégré dans `InstrumentistDrawer` et `SurgeonDrawer` à la place de la ligne email statique
de la section "Informations générales", jamais dupliqué. `buildProfilePictureUrl` (déjà
existant, déjà réutilisé par les deux drawers) étendu — pas recréé — pour préserver une URL
déjà absolue et éviter les doubles slashs. `PersonAvatar` (déjà générique) réutilisé tel
quel dans les deux `DataGrid` (`InstrumentistsTable`/`SurgeonsTable`) : la colonne "Nom"
combine désormais avatar + nom + email empilés dans une seule cellule, la colonne "Email"
séparée est retirée (redondante).

### Statut

Implémenté et testé : `UserEmailChangeServiceTest` (12 tests unitaires), 4 tests voter
(`UserAdministrationVoterTest`), `UserEmailControllerTest` (9 tests fonctionnels HTTP réels,
DB réelle), `PlanningEmailTemplatesTest` (+4, les 2 nouveaux templates html+txt).
860/860 tests backend verts. Frontend : `UserEmailEditor` (8 tests), tables avatar (3+3
tests), drawers avatar (2+2 tests), `buildProfilePictureUrl` (7 tests) — voir
`docs/api.md`/`docs/architecture.md` pour le détail du contrat. Design final des 2
templates appliqué le 2026-07-13, `PlanningEmailTemplatesTest` mis à jour en conséquence
(831/831 → toujours 860/860, contenu revu, aucun test supplémentaire).

---

## D-064 — Démarrage automatique des missions (ASSIGNED → IN_PROGRESS) sur startAt

Date : 2026-07-15

### Contexte

`MissionStatus::IN_PROGRESS` existe dans l'enum et est déjà référencé par
`MissionVoter`, `PlanningCoverageService`, `MissionActionsService`, etc. — mais rien
dans le code n'a jamais transitionné une mission vers ce statut. Conséquence directe :
le `StatusPill « En cours »` du `MissionHero` (`docs/design/components/mission-hero.md`)
n'apparaissait jamais sur des données réelles, alors que le composant frontend est
correct et conforme au design (repéré en recomparant l'écran Aujourd'hui contre la
maquette — le code n'avait pas de bug, la transition manquait côté backend).

### Décision

Nouvelle commande planifiée `app:missions:start-due` (`MissionStartDueCommand`),
recommandée en cron toutes les ~5 minutes — pas d'endpoint HTTP, ce n'est pas une
action utilisateur. Trouve les missions `ASSIGNED` dont `startAt <= now` et
`endAt > now`, et appelle `MissionPostDeployService::start()` (nouvelle méthode, même
patron que `release()`/`cancel()`/`reassign()` — garde de statut, `AuditEvent`
(`MISSION_STARTED`), `flush()` avant dispatch, R-05/D-056).

**Acteur système** : toute mutation de `Mission` exige un `actor` `User` non-nul
(`AuditEvent.actor_id` est `NOT NULL`, D-056) — mais cette transition n'a **aucun**
humain derrière elle, seulement l'horloge. Plutôt que d'affaiblir la contrainte du
schéma (utilisée par tout le reste du système), migration `Version20260715064809` sème
une ligne `User` technique dédiée (`system@surgicalhub.internal`) : `password` `NULL`
(authentification structurellement impossible), `roles: []` (invisible de tout listing
filtré par rôle), `active: false`. `MissionStartDueCommand` la résout par email au
démarrage et échoue explicitement (`Command::FAILURE`, message clair) si elle est
absente plutôt que de créer un acteur ad-hoc à la volée.

**`notify` par défaut `false`** : contrairement aux autres transitions
`MissionPostDeployService`, celle-ci ne dispatche pas `MissionLifecycleChangedMessage`
par défaut — passer d'`ASSIGNED` à `IN_PROGRESS` au moment prévu n'est pas une
information qui justifie un email à quiconque ; c'est un simple changement d'affichage
(le badge "En cours"). Le paramètre existe et un `MissionChangeType::STARTED` est bien
défini si un besoin de notification apparaît plus tard, mais rien ne l'exerce
aujourd'hui côté handler.

**Bornage `endAt > now`** : volontairement absent la logique inverse
(IN_PROGRESS → autre chose à `endAt`). Une mission `ASSIGNED` dont la fenêtre est déjà
entièrement passée sans jamais avoir été soumise reste `ASSIGNED` — c'est un souci
distinct (encodage en retard/oublié), pas traité ici. La commande ne fait qu'entrer
dans `IN_PROGRESS`, jamais en sortir automatiquement ; la sortie normale reste l'action
humaine `submit`.

### Piège découvert : timezone du stockage de `startAt`/`endAt`

Vérifié empiriquement (mission déclarée via l'API réelle avec un `startAt` explicite
`+02:00`) : Doctrine persiste `DateTimeImmutable` sans normalisation — la valeur stockée
est `format('Y-m-d H:i:s')` de l'objet PHP tel quel, donc le cadran (wall-clock) de
l'offset fourni, jamais converti en UTC. C'est exactement l'hypothèse déjà faite
ailleurs (`MissionService::DEFAULT_TIMEZONE = 'Europe/Brussels'` pour ses propres calculs
de bornes "aujourd'hui"), mais **jusqu'ici aucun code ne comparait `startAt`/`endAt` à un
"maintenant" calculé côté serveur** — `MissionStartDueCommand` est la première requête
DQL à le faire. Le conteneur PHP tourne en UTC
(`date_default_timezone_get()`) ; un `new \DateTimeImmutable()` nu y aurait comparé un
"maintenant" UTC contre des valeurs stockées en cadran Bruxelles — décalage systématique
de l'offset DST (+1h/+2h), les missions auraient semblé démarrer 1 à 2h en retard. La
commande construit donc explicitement son "maintenant" en `Europe/Brussels`
(`MISSION_TIMEZONE` const). Couvert par un test d'intégration réel-DB dédié
(`MissionStartDueCommandIntegrationTest`) qui aurait échoué sans ce fix — un test avec
`EntityManager` mocké ne l'aurait jamais détecté.

### Statut

Implémenté et testé : `MissionPostDeployServiceTest` (+6, méthode `start()`),
`MissionStartDueCommandTest` (+3, unitaire, collaborateurs mockés),
`MissionStartDueCommandIntegrationTest` (+5, DB réelle, coïncidences timezone
explicitement couvertes). 883/883 tests backend verts. Vérifié en conditions réelles :
missions réelles de la copie locale de prod transitionnées correctement (`08:00` →
`IN_PROGRESS` une fois l'heure de Bruxelles dépassée, mission de l'après-midi restée
`ASSIGNED`), pastille "En cours" confirmée à l'écran sur `/app/i/today`.

**Amendement 2026-07-15 (voir D-065 ci-dessous)** : la pastille "En cours" a finalement
été rendue indépendante du cron côté frontend — elle se calcule maintenant à l'affichage
(`statut ∈ {ASSIGNED, IN_PROGRESS}` et `now ∈ [startAt, endAt[`), sans attendre le
passage de `app:missions:start-due`. Le statut `IN_PROGRESS` persisté reste la source de
vérité pour les vrais comportements métier (classification d'alerte absence, garde-fou de
remise au pool) — voir §"Quelle partie a réellement besoin du statut persisté" ci-dessous.
Le cron continue de tourner pour ces usages-là ; il ne conditionne plus l'affichage.

**Quelle partie a réellement besoin du statut persisté vs un calcul à l'affichage** —
audit fait le 2026-07-15 en répondant à cette question précise :
- **A besoin du statut persisté** (comportement différent selon `ASSIGNED` vs
  `IN_PROGRESS`) : `AbsenceImpactService::classify()` (type d'alerte —
  `REASSIGNMENT_REQUIRED` si `ASSIGNED`, sinon informatif) et
  `AbsenceMissionReactionService` (n'auto-libère vers le pool que les missions
  `ASSIGNED`, jamais `IN_PROGRESS` — garde-fou de sécurité).
- **N'en a pas besoin** (`IN_PROGRESS` traité à l'identique d'`ASSIGNED`, la persistance
  ne change aucun comportement) : `MissionVoter` (le vrai verrou d'accès encodage est
  `hasMissionStarted()`, une comparaison horaire indépendante — voir piège timezone
  ci-dessous), `MissionActionsService::allowedActions()` (même liste
  `[ASSIGNED, IN_PROGRESS]`), `PlanningCoverageService` (compté à l'identique dans
  `total` ET `covered` — pourcentage mathématiquement inchangé).

**Découverte annexe** : `MissionVoter::hasMissionStarted()` (garde-fou d'accès à
l'encodage) compare `startAt` à un `new \DateTimeImmutable('now', new
DateTimeZone('UTC'))` — exactement le même piège que celui décrit dans D-064, mais
préexistant et non corrigé ici (hors-scope de cette session, signalé mais pas touché).

---

## D-065 — Timezone : l'API renvoyait un offset faux pour startAt/endAt

Date : 2026-07-15

### Contexte

Découvert en vérifiant en navigateur réel que la pastille "En cours" fonctionnait sans
le cron (D-064, amendement) : une mission insérée avec une heure de début connue
("10h11" heure de Bruxelles) s'affichait "12h11" côté instrumentiste — décalage de +2h.

### Cause racine

Deux mécanismes indépendants se combinent :
1. **Stockage** : Doctrine persiste `Mission.startAt`/`endAt` sans conversion — la
   valeur stockée est le cadran (wall-clock) tel que soumis par le client, jamais
   normalisée en UTC (déjà établi en D-064).
2. **Lecture** : le conteneur PHP tourne avec `date.timezone = UTC`. Quand Doctrine
   relit la chaîne brute stockée (sans info de fuseau) pour reconstruire l'objet
   `DateTimeImmutable`, il l'étiquette avec le fuseau par défaut de PHP — UTC — alors que
   les chiffres représentent en réalité l'heure de Bruxelles. `format(ATOM)` expose donc
   un `+00:00` mensonger au lieu du vrai `+01:00`/`+02:00`.

Le navigateur du testeur étant lui-même en `Europe/Brussels`, l'affichage appliquait
*une deuxième* conversion (correcte, mais sur une valeur déjà fausse), doublant l'écart
perçu (+2h au lieu de +0h) — c'est ce qui a rendu le bug visible aussi nettement.

**Ampleur réelle auditée** : 21 entités backend utilisent `DateTimeImmutable`, mais la
plupart (`created_at`/`updated_at` via `TimestampableTrait`) ne sont jamais formatées en
`ATOM`/`c` — elles ne sont donc pas affectées (`format('H:i')`/`format('Y-m-d')` lisent
le cadran brut correctement, peu importe l'étiquette de fuseau). Les endroits qui
appellent réellement `format(\DateTimeInterface::ATOM)` sur `Mission::getStartAt()/
getEndAt()` et exposent donc l'offset faux : `MissionMapper` (corrigé ici — c'est ce qui
pilote `/api/missions`, donc Aujourd'hui/Planning/Détail côté instrumentiste),
`ExportService`, `NotificationService` (emails), `PlanningAlertService`,
`InstrumentistServiceManager`, `SurgeonServiceManager`, `MissionPostDeployService`
(payload d'audit `updateSchedule`) — **non corrigés dans cette session**, signalés à
l'utilisateur qui a explicitement choisi de ne traiter que le cas prouvé/testé
aujourd'hui plutôt que d'étendre sans validation individuelle (risque particulier sur
`NotificationService`, qui alimente des emails réels — voir D-061 MAIL_SAFE_MODE).

### Décision

Nouveau `App\Service\AppTimezone::relabel(?DateTimeImmutable): ?DateTimeImmutable` —
reconstruit l'objet à partir de son propre `format('Y-m-d H:i:s')` sous
`Europe/Brussels` explicite. Ne **shift** jamais le cadran, corrige seulement
l'étiquette. Appliqué dans `MissionMapper::toListDto()`/`toDetailDto()` avant le
`format(ATOM)`.

**Délibérément une correction à la couche de sérialisation, pas au stockage** (option
retenue parmi 3 proposées — cf. discussion) : ne touche ni `date.timezone` du conteneur
(qui aurait changé la sémantique de "now"/logs/audit dans tout le système), ni un type
Doctrine sur mesure par colonne. Rayon d'impact minimal, un seul point de correction,
corrige tous les écrans consommant `/api/missions` d'un coup — y compris ceux qui
n'appellent pas `.tz()` côté frontend (incohérence déjà présente : `MissionCardMobile.tsx`/
`MissionCreateWizard.tsx` forcent `.tz("Europe/Brussels")`, `TodayPage.tsx`/`OffersPage.tsx`/
`MissionEncodingPage.tsx` non — les deux étaient faux avant ce fix, pour des raisons
différentes).

### Statut

Implémenté et testé : `AppTimezoneTest` (5 tests unitaires — préservation du cadran, DST
été/hiver, idempotence, passthrough `null`), `MissionMapperTest` (+4 : offset DST réel
été/hiver, `null` passthrough). 892/892 tests backend verts. Vérifié en conditions
réelles : mission avec heure connue insérée en base, `curl` direct sur `/api/missions/{id}`
confirme `+02:00` (au lieu du `+00:00` faux avant fix), navigateur réel confirme
l'affichage correct ("10h11" au lieu de "12h11") et la pastille "En cours" fonctionnelle
en combinaison avec l'amendement D-064.

**Reste non corrigé, documenté ci-dessus** : `ExportService`, `NotificationService`,
`PlanningAlertService`, `InstrumentistServiceManager`, `SurgeonServiceManager`,
`MissionPostDeployService` (payload d'audit). `MissionVoter::hasMissionStarted()` a le
même piège timezone mais dans l'autre sens (compare à `now()` en UTC nu) — signalé, pas
corrigé.

**⚠️ Superseded 2026-07-15 — voir D-066.** La correction applicative décrite ci-dessus
(`AppTimezone::relabel()`, appliqué uniquement dans `MissionMapper`) a été remplacée par
une correction structurelle à l'hydratation Doctrine (`business_datetime_immutable`,
D-066), à la demande explicite de l'utilisateur après un audit complet ayant confirmé 9
endroits non corrigés par cette approche applicative. `App\Service\AppTimezone` a été
**supprimée** (plus aucun appelant). Les 6 endroits listés ci-dessus comme "non corrigés"
sont désormais corrigés automatiquement par D-066, sans modification individuelle — voir
`MissionBusinessTimezoneIntegrationTest` pour la preuve par test de chacun. Cette section
D-065 reste comme trace historique du diagnostic (toujours exact) et de la première
tentative de correction (remplacée, pas fausse).

---

## D-066 — Correction structurelle du timezone à l'hydratation Doctrine

Date : 2026-07-15

### Contexte

D-065 corrigeait le mensonge de fuseau (`+00:00` au lieu du vrai offset Bruxelles) au
niveau applicatif, un seul appel `AppTimezone::relabel()` dans `MissionMapper`. Un audit
exhaustif de toutes les sorties `DateTime` du backend (demandé explicitement après D-065)
a trouvé **9 autres endroits** exposant le même offset faux, chacun nécessitant sa propre
correction si l'approche restait applicative — exports, emails, alertes manager, deux API
de planning déjà en production. L'utilisateur a validé la stratégie alternative proposée
dans ce rapport d'audit : corriger une seule fois, à la source (hydratation Doctrine),
plutôt que neuf fois en aval.

### Décision

Nouveau type DBAL `App\Doctrine\Type\BusinessDateTimeImmutableType`
(`business_datetime_immutable`), appliqué uniquement à `Mission.startAt`/`endAt` :

- **Lecture** (`convertToPHPValue`) : parse la chaîne brute stockée avec
  `Europe/Brussels` explicite au lieu du fuseau par défaut du conteneur (UTC) — les
  chiffres ne bougent jamais, seule l'étiquette change.
- **Écriture** (`convertToDatabaseValue`) : convertit la valeur reçue (quel que soit son
  offset d'origine — `+02:00` client, `+00:00` UTC explicite, etc.) vers son équivalent
  réel en `Europe/Brussels`, puis stocke le cadran local sans offset.
- **Même déclaration SQL que le type intégré** (`DATETIME`) — vérifié directement
  (`getSQLDeclaration()` comparé programmatiquement, identique), donc **aucune
  migration**. Seul le comportement PHP change.
- Enregistré dans `config/packages/doctrine.yaml` (`dbal.types`), appliqué via
  `#[ORM\Column(type: 'business_datetime_immutable')]` sur les deux propriétés.

**Conséquence directe** : les deux appels `AppTimezone::relabel()` dans `MissionMapper`
sont devenus redondants et ont été retirés — l'entité hydratée porte déjà le bon
étiquetage. `App\Service\AppTimezone` n'avait plus aucun appelant ailleurs dans le
backend (vérifié) ; supprimée entièrement plutôt que laissée comme code mort, cohérent
avec le principe validé "un seul point de correction" plutôt que deux mécanismes
parallèles invitant à la confusion future.

### Piège découvert pendant l'implémentation : les constructions "naïves" existantes

La suite complète de tests a révélé une régression réelle avant d'être corrigée :
`PlanningGeneratorService`/`PlanningGeneratorServiceV2` (les générateurs de planning en
production, à partir des gabarits) construisaient `Mission.startAt`/`endAt` via
`(new \DateTimeImmutable($dateString))->setTime($h, $m)` — une construction **naïve**,
sans fuseau explicite, donc implicitement étiquetée UTC par le conteneur. Avant D-066,
cette approche fonctionnait "par accident" : l'ancien type ne faisait aucune conversion
à l'écriture, se contentant de stocker le cadran tel quel. Avec le nouveau type,
`convertToDatabaseValue()` convertit **réellement** vers `Europe/Brussels` — appliqué à
une valeur naïvement UTC, ça décale le cadran de l'offset DST (+1h l'hiver testé,
`PlanningV2CrudControllerTest` a détecté l'écart : `08:00:00` stocké devenait `09:00:00`).

**Corrigé** : les deux générateurs construisent désormais leur `$day` avec
`new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)` explicite, rendant
leur intention (cadran Bruxelles) conforme à ce que le type attend réellement. Un test
d'intégration dédié (`test_naive_construction_without_explicit_timezone_would_have_drifted_regression_guard`)
documente délibérément le comportement inverse (décalage) pour qu'un futur lecteur
comprenne pourquoi l'explicite est obligatoire — ce n'est pas un bug du type, c'est le
type qui convertit fidèlement ce qu'on lui donne : à l'appelant de dire la vérité sur le
fuseau de ce qu'il construit.

**Règle établie pour tout code futur construisant `Mission.startAt`/`endAt`** : ne
jamais construire un `\DateTimeImmutable` "métier" sans fuseau explicite. Soit il vient
déjà d'un client HTTP (désérialisé par Symfony avec son offset réel, correct tel quel),
soit il est construit en interne — dans ce cas, toujours
`new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)` explicite.

### Garde-fou : test d'architecture

Aucun PHPStan/Psalm n'est configuré dans ce projet (vérifié : absent de `composer.json`)
— `tests/Architecture/BusinessDateTimeColumnConventionTest.php` remplit ce rôle en
PHPUnit pur : réflexion sur toutes les entités sous `src/Entity`, toute colonne
`DateTimeImmutable` (hors `createdAt`/`updatedAt` via `TimestampableTrait`, exemptées par
convention) doit être soit sur `business_datetime_immutable`, soit listée explicitement
dans une allowlist commentée (raison obligatoire, vérifiée pendant l'audit du
2026-07-15). Toute nouvelle colonne métier ajoutée sans décision consciente fait échouer
ce test avec un message explicite. Vérifié empiriquement : suppression temporaire d'une
entrée de l'allowlist → le test échoue avec le message attendu ; restauration → repasse.

### Statut

Implémenté et testé :
- `BusinessDateTimeImmutableTypeTest` (18 tests unitaires — lecture/écriture été/hiver,
  round-trip, idempotence sur 5 cycles, cas DST documentés empiriquement : le passage
  heure d'hiver→été avec une heure locale inexistante "roule" en avant de la durée du
  saut ; le passage été→hiver avec une heure ambiguë résout vers l'heure standard —
  comportement PHP natif déterministe, verrouillé en test de non-régression).
- `MissionMapperTest` (fixtures ajustées pour construire avec un fuseau explicite,
  simulant une vraie hydratation — le mapper lui-même ne fait plus rien de spécial).
- `MissionBusinessTimezoneIntegrationTest` (15 tests, DB réelle) : lecture été/hiver,
  écriture offset et UTC-équivalent, round-trip sans dérive sur plusieurs cycles,
  `MissionMapper` via vraie hydratation, **les deux API de planning**
  (`SurgeonServiceManager`, `InstrumentistServiceManager`), payload d'audit
  manuellement formaté (`MissionPostDeployService::updateSchedule`),
  `PlanningAlertService::serializeMission()`, `ExportService::exportSurgeonActivity()`,
  snapshot `AbsenceImpactService` (`PlanningAlert.snapshotJson`),
  `NotificationService` (payload in-app) — **les 9 endroits de l'audit D-065 confirmés
  corrigés automatiquement, aucun modifié individuellement** — et le motif exact
  générateur-de-planning (jour + heure) avec son garde-fou de régression.
- `BusinessDateTimeColumnConventionTest` (2 tests — scan général + verrou spécifique
  Mission.startAt/endAt).

**922/922 tests backend verts** (869 avant D-064, +53 cumulées D-064/D-065/D-066).
Aucune migration générée (`getSQLDeclaration()` identique au type intégré, vérifié
programmatiquement). Vérifié en conditions réelles sur la base de dev : mission créée via
l'API réelle avec offset `+02:00`, comparaison à trois — valeur brute MySQL
(`2026-07-15 10:11:00`), hydratation PHP réelle via `doctrine:query:dql`
(`Europe/Brussels`, `+02:00`), sortie JSON de `GET /api/missions/{id}`
(`2026-07-15T10:11:00+02:00`) — les trois concordent exactement.

---

## D-067 — Catalogue financier des firmes : prestations non liantes, moteur indépendant (Lot 1)

Date : 2026-07-16

### Contexte

Six audits successifs (interventions/matériel/firmes/tarification, modèle « profil
opératoire », architecture V1, UX de configuration manager, clarification firmes vs
règles de facturation, décision finale) ont précédé ce lot. Point de départ vérifié
avant toute implémentation : `firm`=3, `material_item`=16, et **zéro** ligne dans
`material_line`, `pricing_rule`, `firm_invoice`, `firm_invoice_line` — seule
`mission_intervention` avait une ligne, manifestement une donnée de test (mission #529,
`label="csd"`), volontairement non touchée par ce lot.

### Décision

Introduction de `InterventionType` (référentiel médical fermé, `code` unique et
immuable), `FirmServiceOffering` (« Prestation » à l'écran — `firm` + `interventionType`,
`UNIQUE(firm_id, intervention_type_id)`) et `SuggestedMaterial` (liste ordonnée,
suppression toujours physique).

**Invariant central, non négociable :** le moteur financier (`PricingRuleResolver`) ne
lit jamais `FirmServiceOffering` ni `SuggestedMaterial`. Il ne lit que `PricingRule`,
elle-même indexée directement sur `(firmId, interventionTypeId)` ou `(firmId,
materialItemId)` — jamais au travers d'une prestation. Critère de vérification retenu :
supprimer entièrement `firm_service_offering`/`suggested_material` ne doit changer aucun
montant déjà calculable. Un test d'intégration dédié (`PricingRuleResolverTest`) exerce
littéralement ce scénario (création d'une prestation + suggestions, suppression
complète, nouvelle résolution identique).

> **Amendement D-092 (2026-08-02) — exception scopée, jamais monétaire :**
> `PricingRuleResolver` reste inchangé et garde cet invariant à la lettre — vérifié
> structurellement par `PricingRuleResolverArchitectureTest` (aucun import de
> `FirmServiceOffering`, aucune dépendance constructeur). `FinancialCalculationService`
> (jamais `PricingRuleResolver`) est désormais le seul consommateur autorisé à lire, via
> `RepresentativePolicyResolver`, quatre indicateurs **non-monétaires** de
> `FirmServiceOffering` (`representativePresenceRelevant`,
> `representativeSuppressesInterventionFee`, `representativeSuppressesOwnMaterialFees`,
> `feeApplicable`) — et uniquement **après** résolution normale du tarif par
> `PricingRuleResolver`, jamais avant, jamais à sa place. Le montant brut provient
> toujours et exclusivement d'une `PricingRule` ; la politique délégué ne peut
> qu'appliquer un ajustement post-résolution (voir D-092). `SuggestedMaterial` reste
> totalement hors-jeu, y compris dans cette exception.

`isImplant` (`MaterialItem`) redevient une information médicale pure — la seule source
de vérité du caractère facturable d'un matériel est désormais l'existence d'une
`PricingRule` active (`MATERIAL_FEE`, renommé depuis `IMPLANT_FEE`), quel que soit ce
flag. `PricingRule` gagne `currency` (défaut EUR), `validFrom`/`validTo` (nullables,
`null` = borne ouverte) ; un chevauchement de périodes actives sur la même cible est un
refus bloquant à l'écriture, jamais un choix silencieux à la lecture.

`MaterialItem.firm` devient obligatoire et immuable dès qu'une `MaterialLine` réelle
existe (contrôle applicatif dans `MaterialCatalogController`, pas seulement une
convention) ; unicité `(firm_id, reference_code)` ajoutée. Un matériel suggéré doit
appartenir à la même firme que sa prestation — garanti en base par une clé étrangère
composée `(firm_id, material_item_id) → material_item(firm_id, id)`, pas seulement par
un contrôle applicatif contournable par un futur chemin de code oublié.

Aucune donnée réelle n'a nécessité de migration conservatrice au-delà d'un
resserrement de contraintes déjà vraies (0 conflit constaté) sur `firm` (3 lignes) et
`material_item` (16 lignes) — voir `Version20260716120000`.

### Contrôle final (2026-07-16) — FirmInvoiceService adapté, Stratégie A

Un contrôle final avant commit a identifié que `FirmInvoiceService` référençait encore
`IMPLANT_FEE` et `PricingRule::interventionCode`, tous deux supprimés par ce lot — code
mort avec les données réelles actuelles (0 `pricing_rule`), mais un code mort qui
compile en apparence et casserait au premier `pricing_rule` réel. Choix retenu :
**Stratégie A**, adaptation minimale plutôt que neutralisation (`BILLING_ENGINE_NOT_READY`).
Le rapprochement `INTERVENTION_FEE` passe déjà par `InterventionType.code` comparé au
champ texte libre `MissionIntervention.code` (aucune nouvelle colonne nécessaire) ;
`findImplantRule`/`buildImplantPreviewLine` sont devenus `findMaterialRule`/
`buildMaterialPreviewLine` (`MATERIAL_FEE`) ; les deux méthodes de rapprochement
respectent désormais `PricingRule::coversDate()` (`validFrom`/`validTo`), ce que
l'ancien code ne faisait pas du tout — un vrai correctif, pas seulement un renommage.
Caractérisé par deux tests qui appellent réellement `preview()`/`generate()` contre une
vraie base (`FirmInvoiceServiceLot1AdaptationTest`,
`FirmInvoiceControllerLot1AdaptationTest`, HTTP inclus) plutôt que par un simple grep.

**Limite réellement restante (Lot 5) :** `MissionIntervention.code` demeure un champ
texte libre non contraint par une clé étrangère vers `InterventionType` — le
rapprochement fonctionne par convention de code partagée, pas par intégrité
référentielle. Le déblocage du statut `VALIDATED` reste également hors périmètre
(constat du tout premier audit de cette série).

### Correctif final (2026-07-17) — atomicité des règles tarifaires

Le risque de concurrence identifié au contrôle du 2026-07-16 (`hasOverlap()` en
check-then-act sans verrou, prouvé contournable par `PricingRuleConcurrencyTest`) est
désormais corrigé.

**Centralisation :** toute écriture de `PricingRule` (création, modification,
suppression) passe exclusivement par `PricingRuleWriteService` — `FirmBillingController`
ne fait plus lui-même ni `hasOverlap()`, ni `persist()`, ni `flush()`. Le contrôleur se
limite à lire/valider le payload HTTP et sérialiser le résultat.

**Verrouillage pessimiste déterministe :** `create()`/`update()` s'exécutent dans une
transaction (`EntityManager::wrapInTransaction()`) qui verrouille d'abord la *cible*
tarifaire — `Firm`, puis `InterventionType` (INTERVENTION_FEE) ou `MaterialItem`
(MATERIAL_FEE), toujours dans cet ordre, jamais l'inverse — **avant** de relire les
`PricingRule` existantes et vérifier le chevauchement. Verrouiller la cible (pas les
`PricingRule` elles-mêmes) est ce qui protège aussi la toute première règle d'un couple
firme + type d'intervention : `Firm` et `InterventionType`/`MaterialItem` existent
toujours avant qu'une `PricingRule` ne puisse être créée (contrainte FK), donc deux
créations concurrentes sur la même cible se sérialisent réellement même quand 0
`PricingRule` n'existe encore pour cette cible.

**Ordre de verrouillage et interblocages :** l'ordre `Firm` puis `InterventionType`/
`MaterialItem` est fixe dans tout le code — aucun chemin de ce service ni d'ailleurs
dans l'application ne verrouille l'un de ces types après l'autre dans l'ordre inverse
(vérifié : les autres usages de verrous pessimistes du code, `AbsenceMissionReactionService`,
`MissionPostDeployService`, `MissionService`, verrouillent uniquement `Mission`, jamais
`Firm`/`InterventionType`/`MaterialItem`). Un interblocage classique (A verrouille X puis
attend Y pendant que B verrouille Y puis attend X) est donc structurellement impossible
ici. Contrepartie assumée : deux écritures concurrentes sur la **même firme** mais des
cibles différentes (ex. LCA et PTE pour Smith & Nephew) se sérialisent l'une après
l'autre au niveau du verrou `Firm`, même si elles ne se chevauchent pas — un coût de
performance jugé acceptable (écritures manager, rares, jamais un chemin chaud) et prouvé
ne PAS s'étendre à des firmes différentes (cas D, aucun blocage inter-firmes).

**Erreur métier :** un chevauchement détecté lève `PricingRulePeriodOverlapException`
(`ConflictHttpException`), normalisée par `ApiExceptionSubscriber` en `HTTP 409` /
`code: PRICING_RULE_PERIOD_OVERLAP` / message « Une règle tarifaire existe déjà pour
cette période. ». Le frontend l'affiche sans changement de code : `BillingConfigPage`
utilise déjà un extracteur générique (`error.message`) branché sur tous ses `onError`.
La `\LogicException` défensive de `PricingRuleResolver::resolveInterventionFee()`/
`resolveMaterialFee()` reste en place comme garde ultime si des données incohérentes
existaient déjà (ambiguïté détectée en lecture), mais le chemin normal d'écriture
produit désormais systématiquement cette erreur métier contrôlée, jamais une exception
technique brute.

**Preuve, pas supposition :** `PricingRuleConcurrencyTest` — qui prouvait la
vulnérabilité avant ce correctif — a été transformé en suite de non-régression à 6 cas
(A : deux créations identiques concurrentes, une seule réussit ; B : périodes
chevauchantes, une seule réussit ; C : périodes adjacentes non chevauchantes, les deux
réussissent ; D : cibles différentes, aucun blocage inter-cibles ; E : création et
modification concurrentes ne peuvent pas produire de chevauchement ; F : même protection
sur une cible `MATERIAL_FEE`). Chaque cas prouve le blocage réel (pas supposé) en tenant
un verrou sur une connexion DBAL indépendante pendant qu'une seconde connexion tente son
écriture sous un `innodb_lock_wait_timeout` volontairement court — un succès immédiat
aurait démontré l'absence de verrou ; un timeout MySQL déterministe démontre l'inverse.

---

## D-068 — Rattachement de l'encodage au référentiel InterventionType (Lot 5)

Date : 2026-07-17

### Clarification de numérotation des lots

Le découpage technique initial du catalogue financier prévoyait 7 lots distincts
(1 — MaterialItem, 2 — InterventionType, 3 — PricingRule, 4 — FirmServiceOffering +
SuggestedMaterial, 5 — MissionIntervention, 6 — écran manager + encodage instrumentiste,
7 — moteur de facturation). **Les lots techniques 1 à 4 ont été regroupés dans le
déploiement historiquement nommé « Lot 1 »** (D-067, `v2026.07.17-prod`). Le présent
D-068 est donc la première évolution du **modèle persistant** de l'encodage (Lot 5) ;
le Lot 6 sera la première évolution **UX** complète visible par l'instrumentiste
(parcours guidé, suggestions, nouvelle interface de catalogue fermé) — non commencé ici.

### Audit préalable (vérifié sur le code vivant, pas sur les noms de commits)

Confirmé avant toute implémentation : les 5 éléments du Lot 1 (`MaterialItem`,
`InterventionType`, `PricingRule`, `FirmServiceOffering`, `SuggestedMaterial`) sont
présents et complets (entités, migrations, contrôleurs manager, tests). `MissionIntervention`
n'avait aucune relation vers `InterventionType` ni de notion de firme principale — seul
un couple `code`/`label` texte libre, rapproché du référentiel `PricingRule` par
convention de code partagée (`FirmInvoiceService::findInterventionRule()`), jamais par
intégrité référentielle. Une seule ligne historique : mission #529, `code="LCA"`,
`label="csd"` — donnée de test manifeste, déjà signalée par `Version20260716120000`,
non mappable (`intervention_type` totalement vide au moment de l'audit, aucun
candidat de rapprochement).

### Décision

`MissionIntervention` gagne `interventionType` (FK, **obligatoire pour tout nouvel
encodage** — imposé par `InterventionService::create()`, pas par une contrainte NOT
NULL en base, pour ne pas casser la ligne historique non mappée) et `primaryFirm` (FK,
toujours facultative). `code`/`label` ne sont plus fournis par le client : ils
deviennent un **instantané figé à la création**, copié depuis
`interventionType.code`/`.label` — jamais recalculé ensuite, même si le manager renomme
ou désactive le type ailleurs dans la configuration. C'est ce qui garantit que
l'affichage d'une intervention déjà encodée ne change jamais rétroactivement (même
invariant d'esprit que le "firm snapshot" de `FirmInvoiceLine`).

**Politique retenue pour un catalogue incomplet** (le référentiel `intervention_type`
était vide en production au moment de ce lot) : miroir exact de `MaterialItemRequest`
(D-016), pas un fallback texte ni un blocage sec. Nouvelle entité `InterventionTypeRequest`
(`PENDING`/`RESOLVED`/`IGNORED`) : l'instrumentiste soumet une demande quand aucun type
actif ne correspond ; le manager la résout en choisissant/créant un `InterventionType`,
ce qui crée la `MissionIntervention` réelle (impossible de l'avoir créée avant, faute de
type valide) via `InterventionService::create()` — mêmes validations, même instantané.
**Différence avec `MaterialItemRequest`** : pas de `missionInterventionId` sur la
demande, puisqu'aucune intervention ne peut exister avant sa résolution.

**Choix explicitement écarté** : ne pas contraindre `primaryFirm` par l'existence d'une
`FirmServiceOffering` pour le type choisi — cela aurait transformé une "prestation"
(accélérateur de saisie non liant, invariant central de D-067) en une contrainte de
validation bloquante par la bande. Le code `PRIMARY_FIRM_NOT_AVAILABLE_FOR_INTERVENTION_TYPE`
n'est donc pas implémenté.

**Erreurs métier stables** (`ApiExceptionSubscriber`) : `INTERVENTION_TYPE_NOT_FOUND`,
`INTERVENTION_TYPE_INACTIVE`, `PRIMARY_FIRM_NOT_FOUND`, `PRIMARY_FIRM_INACTIVE`.
`MISSION_ENCODING_NOT_ALLOWED` n'a pas de classe d'exception dédiée : déjà couvert en
amont par `MissionEncodingGuard::assertEncodingAllowed()`, appelé avant toute validation
Lot 5 dans les contrôleurs concernés — une classe séparée aurait dupliqué une protection
déjà exhaustive.

**Migration** (`Version20260717210000`) : colonnes nullables (voir ci-dessus), aucun
backfill automatique — la seule ligne historique (mission #529) reste non mappée,
signalée, jamais mutée. `up→down→up` vérifié sur base fraîche.

**Aucun verrou de concurrence supplémentaire** : la création d'une `MissionIntervention`
n'a pas de cible mutable partagée en situation de check-then-act (contrairement à
`PricingRule` ou `claim()`) — chaque création est indépendante, `orderIndex` n'est qu'un
ordre d'affichage cosmétique, jamais une contrainte d'unicité. Pas de risque réel
démontré, donc pas de verrou ajouté (cohérent avec le principe déjà appliqué au Lot 1).

**Portée non traitée ici (Lots 6/7)** : refonte UX de l'écran d'encodage (parcours
guidé, suggestions automatiques pilotées par `FirmServiceOffering`), rebranchement du
moteur de facturation sur la relation directe `MissionIntervention.interventionType`
(le rapprochement reste par convention de code partagée dans ce lot, inchangé).

---

## D-070 — Workflow métier de l'encodage : cycle de vie explicite, verrouillage backend, commentaires historisés (Lot 7)

Date : 2026-07-17

### Problème

Avant ce lot, une `Mission` restait modifiable tant qu'elle n'était pas dans un statut
"fermé" au sens large — suffisant pour un formulaire, pas pour la facturation. Le futur
moteur financier (Lot 8+) a besoin d'un état **définitif et sans ambiguïté** : une
facture ne doit jamais être générée à partir d'une mission simplement "encodée", elle
doit l'être à partir d'une mission **contrôlée par un manager et validée**.

### Décision — cycle de vie retenu

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

L'exemple fourni en spécification proposait un statut linéaire strict. Écarté
délibérément : `start()` (`ENCODING_IN_PROGRESS`) est une **invite optionnelle**, pas un
préalable obligatoire — `complete()` (`SUBMITTED`) reste atteignable directement depuis
`ASSIGNED`/`IN_PROGRESS`/`DECLARED`, préservant à l'identique un comportement déjà établi
avant ce lot (liberté instrumentiste). `POST /api/missions/{id}/submit` (legacy) et
`POST /api/missions/{id}/encoding/complete` (nouveau) délèguent donc au **même**
`MissionEncodingWorkflowService::complete()` — jamais deux implémentations métier pour
la même transition.

**Verrouillage** — champ existant réutilisé, pas de nouveau concept : `encodingLockedAt`
existait déjà en base (jamais réellement renseigné avant ce lot). Seul
`POST .../encoding/validate` le renseigne désormais (`SUBMITTED → VALIDATED`) ; seul
`POST .../encoding/reopen` le remet à `null`. `MissionEncodingGuard` (partagé avec les
endpoints d'écriture Lot 5/6) applique ce verrou à toute mutation, quel que soit
l'acteur — **le backend reste l'unique garant**, jamais le frontend. `CLOSED` est un
statut terminal explicite : `reopen()` le rejette avec un message dédié
(*"A CLOSED mission can never be reopened"*), jamais confondu avec un simple mauvais
état transitoire.

**Commentaires manager** — nouvelle entité `MissionEncodingComment` (mission, author,
comment, createdAt) plutôt qu'un champ unique sur `Mission` : chaque reject/reopen avec
commentaire crée une **nouvelle ligne**, jamais une mise à jour qui écraserait
l'historique. Distincte de l'`AuditEvent` de la même transition par construction : l'un
trace le fait technique (payload générique, `MissionLifecycleChangedMessage`), l'autre
porte le contenu métier pensé pour être lu par un manager (matériel manquant, quantité
incorrecte, mauvaise firme, intervention incomplète…). Obligatoire au reject
(`NotBlank`), optionnel au reopen — cohérent avec le fait qu'un reject est toujours une
critique à motiver, un reopen peut être une simple réouverture administrative.

**Contrôles métier (`coherenceSummary`)** — agrégation mission-level, en mémoire, des
signaux déjà calculés par intervention (Lot 6, D-069) : aucune interventions,
interventions sans matériel, suggestions ignorées, firme absente, matériel d'une autre
firme. Délibérément **informationnel, jamais bloquant** — la spec demande de préparer
ces indicateurs pour le manager, pas d'empêcher une transition sur leur base ; un
manager reste libre de valider une mission imparfaite s'il juge que c'est correct.

**Notifications** — chaque transition dispatche `MissionLifecycleChangedMessage` avec un
nouveau `MissionChangeType` dédié (`ENCODING_STARTED`/`_COMPLETED`/`_VALIDATED`/
`_REJECTED`/`_REOPENED`, D-056). Le handler existant les reçoit tous via sa branche par
défaut déjà documentée "unhandled changeType — forward-compatible skip" : le mécanisme
est prêt (en particulier pour notifier l'instrumentiste au reopen, demandé
explicitement), aucune notification réelle n'est câblée dans ce lot — le contenu/l'UX
des notifications sera décidé avec les maquettes, pas avant.

**Permissions (`MissionVoter`)** — `ENCODING_START` est strictement instrumentiste
(spec : manager "consulte / valide / refuse / rouvre", jamais "démarre"). Pour chaque
transition, le Voter impose **le même jeu de statuts autorisés** que le service
(défense en profondeur) : un appel HTTP hors statut est donc toujours refusé au niveau
du Voter (`403`), le `ConflictHttpException` (`409`) du service ne restant atteignable
qu'en cas d'appel direct (tests unitaires, futur appelant interne) — comportement
délibéré, pas une lacune de test.

**Point d'entrée facturation (Lot 8+, non implémenté ici)** :
`MissionEncodingWorkflowService::isBillable(Mission): bool` (vrai ⇔ `VALIDATED`) et
`findMissionsReadyForBilling(): Mission[]`. Aucune autre logique financière dans ce
service — le découpage garde la validation opérationnelle et le calcul financier
strictement séparés, le futur moteur n'aura qu'une seule question à se poser.

**Migration** (`Version20260717220000`) : une seule colonne ajoutée,
`mission.encoding_started_at` (nullable, server-generated — voir
`BusinessDateTimeColumnConventionTest`/D-066) — `submittedAt`/`encodingLockedAt`
existaient déjà en base sans jamais être renseignés. Plus la table
`mission_encoding_comment`. `ENCODING_IN_PROGRESS` (nouveau cas de `MissionStatus`) ne
nécessite aucune migration : `mission.status` est un `VARCHAR(255)` libre, la validité
des valeurs est garantie côté PHP par le type d'enum, jamais par une contrainte SQL.

**Aucun verrou de concurrence supplémentaire** : ces transitions sont des actions
humaines explicites (clic manager/instrumentiste), pas une tâche planifiée qui se
chevauche (contrairement à `claim()`/`start()` automatique, D-064) — la vérification de
l'état de départ protège déjà contre un double traitement silencieux, une seconde
tentative concurrente trouve un statut qui ne correspond plus et échoue proprement
en `409`.

**Portée non traitée ici (Lot 8+)** : moteur de facturation réel, contenu/branchement
des notifications (email/push), UX de l'écran manager de contrôle (maquettes Claude
Design à venir).

---

## D-071 — Modèle de domaine Mission / MissionExecution / FinancialCalculation ; InstrumentistService devient MissionExecution (EPIC Exécution & Valorisation, Lot 1)

Date : 2026-07-18

### Le modèle de domaine (figé après deux tours de discussion)

Trois réalités distinctes, jamais fusionnées :

```
Mission              — le PLANIFIÉ + le cycle de vie du processus (statut, encodage Lot 7)
MissionExecution     — le RÉALISÉ (heures réelles, source, contestations)
FinancialCalculation — la VALORISATION FINANCIÈRE (Lot 2+, non implémentée ici)
```

**Pourquoi pas "Mission absorbe tout" (option écartée) :** Mission accumule déjà un
champ plat par préoccupation (`declaredAt`, `submittedAt`, `encodingLockedAt`,
`encodingStartedAt`, `invoiceGeneratedAt`...). Y ajouter les faits d'exécution
(déclarés par l'instrumentiste, contestés par le chirurgien, résolus par le manager —
trois acteurs sans rapport avec les acteurs qui mutent le planning) aurait reproduit,
au moment même où on corrige le premier symptôme (`InstrumentistService` orphelin), le
mécanisme exact qui l'a produit. Le triptyque retenu correspond au découpage standard
d'un ERP de gestion de missions/temps : ressource planifiée → feuille de temps réelle →
pièce de coût, jamais fusionnés.

**Nuance assumée :** `Mission.status` n'est pas *purement* planifié — le cycle Lot 7
(`ENCODING_IN_PROGRESS`/`SUBMITTED`/`VALIDATED`) est un état de *processus*, pas un fait
de planning. Ça ne remet pas en cause la séparation : le statut reste légitimement sur
Mission (chaque entité porte son propre cycle de vie), `MissionExecution` porte le
*contenu* factuel de ce qui s'est passé pendant ce cycle.

**Nommage** — jamais `startAt`/`endAt`/`hours` sur `MissionExecution` (ambiguïté avec
le planifié) : `actualStartAt`, `actualEndAt`, `actualDurationMinutes` (en MINUTES,
cohérent avec `InstrumentistStatementLine.durationMinutesRaw/Rounded` existant — l'ancien
`InstrumentistService.hours` était en heures décimales, converti à la migration).

### InstrumentistService → MissionExecution

Pas une nouvelle table : renommage (`instrumentist_service` → `mission_execution`,
`service_hours_dispute` → `mission_execution_dispute`, migration `Version20260718065425`,
vérifié réversible up→down→up avec conservation exacte des données via un test manuel
en base). Ses deux responsabilités réellement vivantes migrent :
- `hours`/`hoursSource` → `actualDurationMinutes`/`hoursSource` sur `MissionExecution`
- `ServiceHoursDispute` → `MissionExecutionDispute`, workflow et permissions inchangés
  (le chirurgien concerné ouvre, le manager résout)

Ses responsabilités mortes sont supprimées (vérifié avant suppression : aucun chemin de
production, backend ou frontend, n'en dépendait) :
- `serviceType` (dupliquait `Mission.type`), `employmentTypeSnapshot` (jamais lu)
- `consultationFeeApplied` (jamais lu — `InstrumentistStatementService` relit
  `User.consultationFee` en direct au moment de la génération du décompte)
- `computedAmount` (jamais écrit par aucun code — absent même du DTO de mise à jour) et
  le statut financier `CALCULATED`/`APPROVED`/`PAID` : un vestige d'une tentative
  antérieure de construire exactement ce que `FinancialCalculation` construira
  proprement — confirmation que la direction retenue était la bonne, pas une régression.

**mission_id devient UNIQUE** (contrainte ajoutée) : la relation `Mission` 1—0..1
`MissionExecution` n'était qu'implicite avant (simple index), jamais garantie par le
schéma.

### `resolveEffectiveDuration()` — le seul point d'extension pour le futur moteur

`MissionExecutionService::resolveEffectiveDuration(Mission): EffectiveDuration` —
horaires réels si connus (`ACTUAL_TIMES`), sinon durée explicite déclarée
(`ACTUAL_EXPLICIT`), sinon repli sur `Mission.startAt/endAt` (`PLANNED`). Centralisé,
déterministe, ne calcule jamais de montant — un simple calcul de durée. Une
`MissionExecution` peut exister sans aucune donnée (créée mais tout `null`) : dans ce
cas aussi, repli sur `PLANNED`.

**Cohérence actualStartAt/actualEndAt/actualDurationMinutes** — une seule règle,
appliquée par `updateActuals()`, jamais côté frontend : si les deux horaires sont
connus (état résultant, pas seulement la requête courante), la durée est **toujours**
dérivée d'eux — jamais deux sources de vérité en parallèle. Une durée explicite fournie
dans la même requête qui contredirait cette dérivation est un `422`, jamais une
acceptation silencieuse. Un seul horaire sans l'autre est également un `422`.

### Settlement — resté un concept d'architecture, pas une nouvelle table

Challengé et confirmé dans ce même tour : Firm-side et Instrumentist-side (destinataire
Firme vs User, cadence période-libre vs mois calendaire, numérotation séquentielle
uniquement côté firme) sont deux réalités différentes. Les fusionner dans une table
`Settlement` générique aurait reproduit, à l'identique, l'anti-pattern qu'on vient
d'écarter pour Mission — sans compter le coût et le risque de migrer deux tables déjà
porteuses d'historique légal externe (factures envoyées, décomptes payés).
`FirmInvoice`/`InstrumentistStatement` restent les deux implémentations concrètes ; le
futur `RemunerationLine`/`FinancialCalculation` (Lot 2+) sera la couche qui les
alimentera, pas une troisième table qui les remplace.

### Correction de sécurité trouvée en migrant

L'ancien flux (`ServiceController::findOrCreateService()` appelé avant
`denyAccessUnlessGranted()`) persistait une `InstrumentistService` vide **avant** de
refuser l'accès en cas d'appel non autorisé. `MissionExecutionVoter::VIEW`/`UPDATE`
sont désormais évalués sur `Mission` (jamais sur l'entité pas-encore-créée) — la
permission est vérifiée avant toute création paresseuse. Testé explicitement
(`ServiceControllerTest::test_legacy_patch_service_denied_does_not_create_a_row`).

### Compatibilité API

Endpoints legacy (`PATCH /api/missions/{id}/service`, `POST /api/services/{id}/disputes`,
`GET`/`PATCH /api/disputes`) conservés à l'identique — même URL, même forme de payload
— délégués au nouveau modèle. Vérifié qu'aucun consommateur frontend ne lit les champs
financiers morts dans la réponse (`EditServiceHoursDialog.tsx` n'envoie que `hours`/
`hoursSource` et ignore la réponse). Nouveaux endpoints additifs
`GET`/`PATCH /api/missions/{id}/execution`.

### Portée non traitée ici (Lot 2+)

`FinancialCalculation`/`RemunerationLine` elles-mêmes, tout montant, tout tarif,
l'historisation des tarifs instrumentistes, la bascule de `InstrumentistStatementService`/
`FirmInvoiceService` vers le nouveau modèle.

---

## D-072 — Les tarifs sont versionnés par périodes de validité. Aucun tarif applicable ou passé n'est modifié en place (EPIC Exécution & Valorisation, Lot 2)

Date : 2026-07-18

### Décision

Garantir qu'une modification future d'un tarif ne puisse jamais modifier
rétroactivement la valeur financière d'une mission déjà calculée — fondation tarifaire
nécessaire avant `FinancialCalculation` (Lot 3+, non implémentée ici). Le système
répond désormais de façon déterministe à "quel tarif était applicable à cette date,
pour cette firme/instrumentiste/intervention/matériel ?".

**Append-only, sans exception pour une règle "jamais utilisée" :** dès que
`validFrom <= aujourd'hui`, la règle appartient à l'historique — le critère est
purement temporel, jamais "a-t-elle déjà servi à une facture". Seule
`replaceCurrentRuleFrom()`/`replaceCurrentRateFrom()` peut faire évoluer un tarif actif :
ferme l'ancienne période + ouvre la nouvelle, **dans une seule transaction** (Doctrine
DBAL imbrique nativement les `wrapInTransaction()` de `PricingRuleWriteService::update()`
+ `::create()` via son compteur de nesting — le commit réel n'a lieu qu'à la sortie de
la méthode orchestratrice, jamais d'état intermédiaire avec deux règles ouvertes,
aucune règle active, ou un chevauchement).

### Convention temporelle — changement assumé par rapport à Lot 1/D-067

`validFrom` **inclusif**, `validTo` **exclusif** — le jour `validTo` lui-même
n'appartient déjà plus à cette règle. Avant ce lot, `PricingRule::coversDate()`/
`overlapsWith()` traitaient `validTo` comme inclusif (un ancien tarif se terminant le
2026-07-01 ET un nouveau démarrant le 2026-07-01 se seraient chevauchés, et le
2026-07-01 aurait été couvert par les deux). **Trois tests existants encodaient cette
ancienne sémantique** (`PricingRuleTest::testCoversDateRespectsValidToBoundary`,
`testCoversDateExactBoundaries`, `testAdjacentPeriodsSameDayOverlap`,
`FirmBillingControllerPricingRuleTest::test_exact_boundary_date_is_covered_by_the_rule`)
— mis à jour pour refléter la nouvelle convention, jamais laissés à décrire un
comportement révolu. Centralisée une seule fois dans `PricingRule`/`InstrumentistRate`,
identique dans les deux entités.

### Modèle retenu — évolution de PricingRule plutôt qu'une refonte

`PricingRule` garde son schéma actuel (Lot 1/D-067) — la seule vraie évolution est la
discipline d'écriture, pas la forme. `PricingRuleWriteService::create()`/`update()`
restent inchangés dans leur signature exacte : ce sont des primitifs bas niveau
(verrouillage pessimiste + anti-chevauchement) déjà prouvés par
`PricingRuleConcurrencyTest` (7 tests, dont un cas `update()` sous verrou concurrent) —
les casser aurait été un coût sans bénéfice. `PricingRuleVersioningService` (nouveau)
orchestre par-dessus les opérations métier (`createInitialRule`/`scheduleRule`/
`replaceCurrentRuleFrom`/`updateFutureRule`/`cancelFutureRule`/`resolveAt`) — seul point
d'écriture autorisé pour les contrôleurs désormais. `PricingRuleWriteService::delete()`
gagne un garde-fou défensif (`validFrom <= aujourd'hui` → refus) directement au niveau
bas niveau, pas seulement dans le service métier — en cas de futur appelant direct.

`validFrom` reste **nullable** sur `PricingRule` (contrairement à la recommandation
littérale du lot) : c'est la sémantique délibérée de D-067 ("valide depuis toujours"),
pas une donnée ambiguë ou incomplète — la changer aurait silencieusement modifié le
résultat de résolution de règles existantes, exactement ce que ce lot interdit. Le
nouveau `PricingRuleVersioningService` exige néanmoins un `validFrom` explicite pour
toute règle future (`scheduleRule()`), seul l'ancien chemin d'écriture legacy
(`POST /pricing-rules`, inchangé) permet encore `null`.

### InstrumentistRate — nouveau modèle, `validFrom` obligatoire

Contrairement à `PricingRule`, `InstrumentistRate.validFrom` est **NOT NULL** : table
neuve créée par ce lot, aucune donnée historique préexistante à cette contrainte, donc
aucune raison de reproduire l'ambiguïté "null = toujours". Types couverts :
`HOURLY_RATE`/`CONSULTATION_FEE` — les deux seuls réellement utilisés par
`InstrumentistStatementService::buildPreviewLine()` (BLOC/CONSULTATION), aucun type
inventé prématurément. Ne stocke ni durée ni montant calculé — uniquement la règle.
`InstrumentistRateResolver`/`InstrumentistRateWriteService`/`InstrumentistRateService`
sont des miroirs exacts de leurs équivalents `PricingRule` — même verrouillage
(cible = l'instrumentiste lui-même, seule entité garantie présente avant qu'une
`InstrumentistRate` ne puisse exister), même garde-fous, même découpage d'opérations.

**`User.hourlyRate`/`consultationFee` non supprimés** (compatibilité legacy explicite) :
l'endpoint `PATCH /api/instrumentists/{id}/rates` (autosave manager, préexistant, non
touché par ce lot) continue de les écrire directement. Aucun nouveau code financier ne
s'y branche — la nouvelle source de vérité pour tout futur calcul est
`InstrumentistRate`. **Risque connu, documenté, non traité ici** : un manager utilisant
l'ancien endpoint après ce lot fait diverger `User.hourlyRate` du modèle historisé sans
qu'aucune `InstrumentistRate` ne soit créée — accepté délibérément (le lot autorise
explicitement à laisser le legacy fonctionner tel quel), à trancher dans un lot futur
(rediriger l'endpoint legacy vers `InstrumentistRateService::replaceCurrentRateFrom()`,
ou le déprécier une fois le frontend basculé sur les nouveaux endpoints). Chevauchement
d'URL assumé également : `PATCH /instrumentists/{id}/rates` (legacy) et
`GET`/`POST /instrumentists/{id}/rates` (nouveaux) partagent un préfixe — aucune
collision de routage réelle (Symfony résout sans ambiguïté par méthode+forme exacte),
mais une confusion de nommage à résoudre plus tard.

### Backfill

**`InstrumentistRate`** : une première ligne par utilisateur ayant actuellement un
`hourlyRate`/`consultationFee`, valeur préservée telle quelle, `currency = User.
defaultCurrency`, `validFrom = DATE(User.createdAt)` — priorité documentée : (1) date
historique fiable si disponible (aucune n'existe dans le schéma actuel, option jamais
atteignable) ; (2) date de création de l'utilisateur (retenue — la meilleure option
métier-compatible réellement disponible) ; (3) date de migration en dernier recours
(non utilisée, (2) toujours disponible). Assumé comme approximation : le tarif réel
aurait pu changer entre la création du compte et aujourd'hui sans laisser de trace —
c'est un cas de "donnée passée ambiguë conservée sans deviner une relation incorrecte",
pas une reconstruction d'historique réel.

**`PricingRule`** : aucun backfill appliqué. Vérifié avant d'écrire la migration : 0
ligne en production au moment de ce lot. Si une ligne `validFrom = null` existe un
jour, ce n'est **pas** une donnée ambiguë — c'est la sémantique D-067 délibérée, jamais
touchée par ce lot.

### Audit sans Mission à rattacher

`AuditEvent.mission` devient **nullable** (élargissement de contrainte, migration
`ALTER TABLE ... MODIFY mission_id INT DEFAULT NULL`, aucune donnée existante
affectée) — les événements catalogue/tarifaires n'ont structurellement aucune Mission à
rattacher. Nouveau `AuditService::recordGlobal(User $actor, AuditEventType $type,
array $payload)`, `AuditService::record()` (Mission-scopé) reste inchangé. Choix
délibéré de réutiliser `AuditEvent` plutôt qu'un troisième mécanisme d'audit à côté des
deux déjà existants (`AuditEvent` mission-centrique, `UserAuditEvent` administration
utilisateurs) — c'était l'entité déjà la plus générale (payload JSON libre, déjà
utilisée pour de nombreux types d'événements au-delà du cycle de vie mission).

### Contraintes base de données ajoutées

`instrumentist_rate` : `CHECK (amount >= 0)`, `CHECK (valid_to IS NULL OR
valid_to > valid_from)`, FK `instrumentist_id` obligatoire, index composite
`(instrumentist_id, rate_type, valid_from, valid_to)` pour la résolution. Vérifiées
manuellement à l'exécution de la migration (insertion rejetée dans les deux cas).

### Permissions

Réutilise `BillingVoter::MANAGE` (manager/admin) pour les tarifs instrumentistes plutôt
qu'un nouveau Voter redondant — même périmètre déjà appliqué à `/pricing-rules`,
cohérent avec "les tarifs relèvent uniquement du manager/admin" (aucune notion de
propriété instrumentiste sur son propre tarif).

### Portée non traitée ici (Lot 3+)

`FinancialCalculation`, `RemunerationLine`, toute bascule de `FirmInvoiceService`/
`InstrumentistStatementService` vers `PricingRuleVersioningService`/
`InstrumentistRateService`, tout paiement, toute correction financière, la réconciliation
de l'endpoint legacy `PATCH /instrumentists/{id}/rates` avec `InstrumentistRate`.

### Amendement (2026-08-05) — le risque de double source de vérité a été réexaminé, pas supprimé

Diagnostic production (2026-08-05) : sur 10 instrumentistes actifs réels, un seul avait un
tarif configuré (compte de démo) — les 7 instrumentistes réels n'avaient de tarif ni dans
`User.hourlyRate`/`consultationFee` (legacy), ni dans `InstrumentistRate` (source de
vérité) — donc pas un cas de divergence entre les deux systèmes, mais une absence totale
des deux côtés. Aucune migration nécessaire (rien à backfiller).

Le vrai défaut identifié : le manager ne découvrait un tarif horaire manquant qu'en
tentant de calculer une mission (422 `MISSING_INSTRUMENTIST_RATE`), sans aucun endroit
pour le voir à l'avance. Lot correctif : badge « Tarif configuré »/« Tarif manquant »
dans `InstrumentistsTable` (calculé côté backend via `InstrumentistRateResolver`, même
règle que `FinancialCalculationService` — voir `docs/api.md` §16.1), avertissement dans
`InstrumentistVersionedRates` quand aucune version `HOURLY_RATE` ne couvre aujourd'hui, et
filtre `validatedWithoutCalculation` sur `GET /api/missions` relié à la tuile dashboard
« Missions validées sans calcul » (même règle que
`FinancialStatisticsQueryService::pipeline()`, voir le commentaire croisé dans les deux
implémentations).

**Le risque de divergence documenté ci-dessus reste réel et non traité** : l'endpoint
legacy `PATCH /instrumentists/{id}/rates` est toujours accessible et continue d'écrire
uniquement `User.hourlyRate`/`consultationFee`, jamais `InstrumentistRate`. Option
envisagée pour ce lot — rediriger cet endpoint pour qu'il écrive aussi dans
`InstrumentistRate` — **rejetée** : `InstrumentistRateService::replaceCurrentRateFrom()`
interdit un remplacement le même jour que le `validFrom` du tarif courant
(`InstrumentistRateImmutableException`), alors que le formulaire legacy peut être
enregistré plusieurs fois le même jour (pas d'autosave, mais rien n'empêche un second
clic sur "Enregistrer") — rediriger l'endpoint aurait donc introduit une nouvelle erreur
utilisateur là où il n'y en avait pas, pour un chemin déjà legacy et non couvert par
`InstrumentistStatementService` en pratique aujourd'hui (aucun instrumentiste réel n'a de
valeur dans ce champ). Correctif retenu à la place, strictement en affichage : un
avertissement explicite dans la section « Ancien flux (décompte classique) » du drawer
précisant que ces champs n'alimentent pas le calcul financier — rend la divergence
visible plutôt que de la corriger structurellement. La redirection ou dépréciation de
l'endpoint legacy reste à trancher dans un lot futur, non traité ici.

---

## D-073 — FinancialCalculation est append-only. Chaque calcul fige les données tarifaires et métier utilisées. Toute nouvelle valorisation crée une nouvelle version (EPIC Exécution & Valorisation, Lot 3)

Date : 2026-07-18

### Décision

Construire le cœur déterministe de la valorisation financière : `FinancialCalculation`
fige la valeur économique d'une mission à une date donnée (`effectiveAt`), à partir de
la mission, son exécution réelle (`MissionExecution`, D-071), ses interventions/lignes
de matériel, les règles tarifaires firmes et instrumentistes historisées (`PricingRule`/
`InstrumentistRate`, D-072). Le résultat est déterministe (deux appels avec les mêmes
données produisent le même montant), historisé (jamais réécrit en place), reproductible
(indépendant de toute modification tarifaire future — tout est snapshoté), append-only,
auditable, et exploitable plus tard par `FirmInvoice`/`InstrumentistStatement` — sans
encore basculer ni l'un ni l'autre dans ce lot.

### Modèle retenu — pas de statut DRAFT

`FinancialCalculationStatus` : `CALCULATED → APPROVED → LOCKED`, plus `SUPERSEDED`
(ancienne version remplacée par un recalcul) et `CANCELLED` (annulation explicite).
**Délibérément aucun statut `DRAFT`** : un calcul est construit entièrement en mémoire
puis persisté en un seul bloc ou pas du tout (voir anomalies ci-dessous) — un `DRAFT`
n'aurait jamais eu de transition observable, un statut sans comportement réel que le lot
interdit explicitement d'introduire.

Relation `Mission` 1 — 0..n `FinancialCalculation` (plusieurs versions successives
possibles), unicité `(mission_id, version)` en base, un seul calcul actif
(`CALCULATED`/`APPROVED`/`LOCKED`, jamais `SUPERSEDED`/`CANCELLED`) à la fois par
mission. `FinancialCalculationLine` porte le détail (jamais un total opaque) : firme
**et** instrumentiste via deux FK nullables explicites (`beneficiaryFirm`/
`beneficiaryInstrumentist`) plutôt qu'un discriminateur générique — même pattern déjà
en place sur `FirmInvoiceLine`. Quatre types de ligne minimaux, pas de système universel
abstrait prématuré : `FIRM_INTERVENTION_FEE`, `FIRM_MATERIAL_FEE`,
`INSTRUMENTIST_HOURLY`, `INSTRUMENTIST_CONSULTATION_FEE`.

### Date effective — jamais now() implicitement

`FinancialCalculationService::resolveEffectiveAt(Mission)` centralise l'unique règle :
`MissionExecution.actualStartAt` si disponible (réalisé, D-071), sinon `Mission.startAt`
(planifié). Le calcul stocke la valeur retenue (`FinancialCalculation.effectiveAt`) —
jamais recalculée après coup. Durée instrumentiste exclusivement via
`MissionExecutionService::resolveEffectiveDuration()` (D-071, Lot 1) — jamais dupliquée,
seule source de vérité pour la durée réelle/déclarée/planifiée.

### Politique d'arrondi — decimal-string, jamais float

Cohérente avec la convention déjà en place dans tout le projet (`FirmInvoiceService`/
`InstrumentistStatementService`, aucun usage de `bcmath` nulle part dans le code
existant) : colonnes `decimal` mappées en chaînes PHP, arithmétique interne en `float`
puis `round()`/`number_format()`. Heures arrondies à 4 décimales
(`round($minutes / 60, 4)`), montant total arrondi à 2 décimales — **une seule fois**,
jamais en cascade (`roundMoney()`, point d'arrondi unique documenté). Testé
explicitement sur 1 min, 30 min, 90 min et un tarif non divisible par 60.

### Tarifs manquants — jamais inventés, jamais un échec au premier élément

`buildAndPersist()` résout **toutes** les lignes possibles et collecte **toutes** les
anomalies avant de décider — jamais un échec dès la première règle manquante. Codes
stables : `MISSING_PRIMARY_FIRM`, `MISSING_INTERVENTION_TYPE` (legacy pré-Lot 5),
`MISSING_FIRM_INTERVENTION_RATE`, `MISSING_FIRM_MATERIAL_RATE`,
`MISSING_INSTRUMENTIST_RATE`, `INVALID_EFFECTIVE_DURATION`. En cas d'anomalie : **aucun**
`FinancialCalculation` n'est persisté (zéro persistance partielle), seul un audit
`FINANCIAL_CALCULATION_FAILED` structuré (liste complète des anomalies) est conservé.

**Piège découvert et corrigé pendant le lot** : auditer l'échec puis lever l'exception
*à l'intérieur* du même `wrapInTransaction()` provoque le rollback de l'audit lui-même —
`EntityManager::wrapInTransaction()` appelle `close()` sur l'EntityManager dès qu'une
exception le traverse (voir son code source), rendant tout audit ultérieur sur ce même
EntityManager impossible. Corrigé : `buildAndPersist()` ne lève plus jamais d'exception
elle-même (retourne `null` + anomalies par référence) ; `calculate()`/`recalculate()`
auditent l'échec **à l'intérieur** de la transaction (qui se termine alors normalement,
committant l'audit) puis lèvent `FinancialCalculationAnomaliesException` **après** la fin
de `wrapInTransaction()`. Découvert par un test d'intégration réel (pas un mock) qui
vérifiait la présence de l'AuditEvent après l'échec — jamais visible sans exécuter le
code contre une vraie base.

### Recalcul et verrouillage

Recalcul explicite uniquement (jamais déclenché implicitement par une lecture) tant que
le calcul actif n'est pas `LOCKED` : l'ancien passe `SUPERSEDED` (`supersededAt` +
`supersededByCalculation` pointant vers le nouveau), jamais muté ni supprimé ; le nouveau
devient `CALCULATED` avec `version + 1`. Deux faits distincts, deux audits
(`FINANCIAL_CALCULATION_SUPERSEDED` sur l'ancien, `FINANCIAL_CALCULATION_RECALCULATED`
sur le nouveau) — jamais un double audit du même fait. `LOCKED` : callable seulement
depuis `APPROVED`, un calcul `LOCKED` ne peut plus être superseded ni cancelled.

### Intégration avec le Lot 7 — garde de réouverture

Seule exception sanctionnée à "ne pas toucher les lots précédents sans nécessité
démontrée" : `MissionEncodingWorkflowService::reopen()` (D-070) gagne une garde
supplémentaire — une mission avec un `FinancialCalculation` `LOCKED` ne peut plus être
réouverte (409). Volontairement **pas** de dépendance au `FinancialCalculationService`
complet (`final`, dépendances profondes elles-mêmes `final` — `PricingRuleResolver`/
`InstrumentistRateResolver`/`MissionExecutionService` — aurait compliqué inutilement le
test unitaire existant) : une requête directe via l'`EntityManager` déjà injecté suffit
pour ce seul contrôle booléen. `FinancialCalculationService::hasLockedCalculation()`
reste exposée publiquement pour les futurs consommateurs API/UI ; les deux requêtes
doivent rester synchronisées si la logique évolue (documenté dans les deux docblocks).
Aucun mécanisme de correction développé dans ce lot (D-073 §20) — seulement le blocage.

### Concurrence

Verrou pessimiste sur la `Mission` (`LockMode::PESSIMISTIC_WRITE`), même mécanisme que
`PricingRuleWriteService`/`InstrumentistRateService` (D-072) — relecture de l'état sous
verrou, jamais de fenêtre de course entre la vérification d'éligibilité et la
persistance. Prouvé par un test de concurrence utilisant deux connexions DBAL réellement
distinctes (pas de mock, pas de simulation) : un `calculate()` concurrent est réellement
bloqué (timeout MySQL déterministe), jamais une réussite silencieuse ni un doublon.

### Permissions et API

Réutilise `BillingVoter::MANAGE` (manager/admin), même périmètre que
`/pricing-rules`/`/instrumentists/{id}/rates` — pas de nouveau Voter redondant, pas
d'exposition aux instrumentistes/chirurgiens. Six endpoints (`POST`
`/api/missions/{id}/financial-calculations`, `POST .../recalculate`, `.../approve`,
`.../lock`, `GET /api/missions/{id}/financial-calculations`,
`GET /api/financial-calculations/{id}`) — `cancel()` existe au niveau service mais
n'est volontairement pas exposé en HTTP dans ce lot (non listé dans la spec API du lot).

### Contraintes base de données ajoutées

`financial_calculation` : unicité `(mission_id, version)`, `CHECK (version > 0)`.
`financial_calculation_line` : `CHECK (quantity > 0)`, `CHECK (unit_amount >= 0)`,
`CHECK (total_amount >= 0)`, `CHECK (duration_minutes IS NULL OR duration_minutes > 0)`.
Toutes vérifiées manuellement à l'exécution de la migration (insertion rejetée dans
chaque cas) — MySQL 8.3 les applique réellement (contrairement à MySQL < 8.0.16).

### Portée non traitée ici (Lot 4+)

Bascule de `FirmInvoiceService`/`InstrumentistStatementService` vers les lignes figées de
`FinancialCalculation`, tout paiement, toute correction financière additive après
verrouillage, table `Settlement`, conversion de devises entre lignes de devises
différentes (`totalsByCurrency()` les regroupe séparément, ne les additionne jamais),
refonte UX.

---

## D-074 — Les documents financiers ne calculent aucun montant. Ils consomment exclusivement les lignes figées de FinancialCalculation. Une ligne financière ne peut être intégrée qu'une seule fois dans son flux documentaire (EPIC Exécution & Valorisation, Lot 4)

Date : 2026-07-18

### Décision

`FirmInvoice`/`InstrumentistStatement` deviennent des documents construits à partir de
`FinancialCalculationLine` déjà valorisées (Lot 3) — plus jamais de résolution de
`PricingRule`/`InstrumentistRate`, de relecture de `User.hourlyRate`/`consultationFee`,
ni de recalcul de durée/quantité/prix unitaire/total côté document.
`FinancialCalculationLine` = vérité monétaire ; `FirmInvoiceLine`/
`InstrumentistStatementLine` = présentation documentaire et affectation.

### Deux chemins coexistent — pas de bascule forcée du frontend

`FirmInvoiceService::preview()`/`generate()`/`InstrumentistStatementService::preview()`/
`generate()` (chemin LEGACY, seul chemin utilisé par le frontend actuel — voir
`FirmInvoiceServiceLot1AdaptationTest`, jamais retouché) recalculent toujours eux-mêmes
depuis `PricingRule`. Deux nouvelles méthodes par service —
`previewEligibleLines()`/`createFromEligibleLines()` — consomment exclusivement des
`FinancialCalculationLine`. `FirmInvoiceLine.financialCalculationLine`/
`InstrumentistStatementLine.financialCalculationLine` (FK nullable, UNIQUE) distinguent
les deux : `null` = ligne legacy, non-null = ligne nouvelle
(`FirmInvoiceLine::isLegacy()`/`InstrumentistStatementLine::isLegacy()`). Un même
document ne mélange jamais les deux. `FirmInvoice.legacySource`/
`InstrumentistStatement.legacySource` marquent le document entier (`true` pour tout
document existant avant ce lot, backfillé par la migration ; `false` uniquement pour un
document créé via le nouveau chemin).

### Statuts — pas de nouvelle machine à états

`InvoiceStatus` (`DRAFT`/`GENERATED`/`SENT`/`PAID`) gagne un seul nouveau cas,
`CANCELLED` — les autres restent inchangés dans leur sémantique produit existante.
**`GENERATED` joue le rôle du "DRAFT/document définitif non encore engagé" du lot** : les
deux chemins de création (legacy et nouveau) produisent déjà un document `GENERATED`
en un seul appel atomique, jamais un `DRAFT` visible/transitoire — exactement le même
raisonnement que "pas de statut DRAFT" pour `FinancialCalculation` (D-073) : un document
est construit entièrement en mémoire puis persisté en un bloc, ou pas du tout. `SENT`
(transition existante `markSent()`, inchangée) est le vrai point de bascule "engagé vis-
à-vis du tiers" dans ce produit — c'est là qu'est auditée `FIRM_INVOICE_ISSUED`/
`INSTRUMENTIST_STATEMENT_ISSUED` (§27 du lot), pas à la création. Aucun endpoint
`/issue` séparé n'a donc été ajouté : il n'existerait aucun `DRAFT` observable sur lequel
l'appeler.

### Éligibilité d'une ligne (identique firme/instrumentiste, symétrique)

Une `FinancialCalculationLine` n'est sélectionnable que si : son `beneficiaryType`/
bénéficiaire correspond exactement à la cible du document (firme ou instrumentiste) ;
son calcul est `APPROVED` **ou** `LOCKED` (jamais `CALCULATED`/`SUPERSEDED`/`CANCELLED`)
— `LOCKED` est accepté car un calcul verrouillé par l'affectation d'une première ligne
doit continuer à pouvoir fournir ses autres lignes (§30 du lot, voir plus bas) ; sa
devise correspond exactement à celle demandée ; elle n'est pas déjà rattachée à un
document (`isLineAlreadyAssigned()`, requête SQL fraîche — jamais l'association inverse
potentiellement périmée en mémoire) ; sa date effective (`FinancialCalculationLine.
effectiveAt`, jamais `createdAt`/la date de génération/`now()`) tombe dans la période
demandée. Filtrage identique et documenté une seule fois pour les deux services.

### Regroupement — 1 document = 1 bénéficiaire + 1 devise

Une mission peut produire des lignes pour plusieurs firmes (intervention vs matériel) —
la facture se base exclusivement sur `FinancialCalculationLine.beneficiaryFirm`, jamais
sur l'hypothèse que toute la mission appartient à la firme principale. Une firme/un
instrumentiste avec des lignes dans deux devises reçoit deux documents distincts (aucune
conversion, aucune addition inter-devises) — le manager fournit explicitement la devise
cible à `previewEligibleLines()`/`createFromEligibleLines()`, qui ne renvoient/ne
consomment que les lignes de cette devise.

### Rattachement — atomique, jamais de confiance dans la prévisualisation

`createFromEligibleLines()` ne fait jamais confiance à `previewEligibleLines()`
(lecture seule, ne réserve rien) : elle reverrouille (`PESSIMISTIC_WRITE`, ordre
croissant d'id — §22, évite les deadlocks entre générations concurrentes dont les
ensembles de lignes se recoupent) chaque `FinancialCalculation` DISTINCT référencé par
les lignes sélectionnées, puis revérifie individuellement chacune sous ce verrou. Une
seule ligne devenue inéligible (déjà assignée, mauvaise devise, mauvais bénéficiaire,
calcul non `APPROVED`/`LOCKED`, ou introuvable) fait échouer toute la création — aucun
document partiel, aucune ligne rattachée, aucun calcul verrouillé
(`DocumentLineSelectionException`, miroir exact de
`FinancialCalculationAnomaliesException` du Lot 3 : toutes les anomalies collectées en
un seul rapport, jamais un échec sur la première ligne).

**Piège de transaction reproduit et corrigé comme au Lot 3** : `$invoice`/`$statement`
doivent être `persist()`-és **avant** la boucle qui appelle
`FinancialCalculationService::lock()` (celui-ci `flush()` en interne à chaque itération)
— sinon Doctrine refuse la cascade de persistance des lignes qui référencent un document
encore inconnu de l'UnitOfWork. Trouvé par un test d'intégration réel, pas par
inspection.

### Verrouillage du calcul — dès la première ligne intégrée, jamais un déverrouillage automatique

Dès qu'une ligne d'un calcul `APPROVED` est intégrée à un document, le calcul passe
`LOCKED` dans la **même transaction** (réutilise `FinancialCalculationService::lock()`,
idempotent si déjà `LOCKED`). `hasUnassignedFirmLines()`/
`hasUnassignedInstrumentistLines()`/`isFullyDocumented()` (méthodes dérivées sur
`FinancialCalculation`, jamais un booléen stocké en doublon) permettent de représenter un
calcul **partiellement** documenté : une mission produit typiquement une ligne firme
(intervention), une ligne firme (matériel) et une ligne instrumentiste sur le **même**
calcul — verrouillé dès que la première est facturée/décomptée, les deux autres restent
sélectionnables tant qu'elles ne sont pas elles-mêmes affectées. Annuler un document
(voir plus bas) **ne déverrouille jamais** le calcul — politique explicite, jamais
automatique.

### Annulation — DRAFT/GENERATED libère, SENT/PAID jamais

`cancel()` (nouveau sur les deux services, symétrique) : `GENERATED → CANCELLED`
autorisé — supprime physiquement les `FirmInvoiceLine`/`InstrumentistStatementLine`
(libère la contrainte `UNIQUE(financial_calculation_line_id)`, la ligne redevient
sélectionnable dans un nouveau document) mais conserve le document lui-même comme trace
historique (`CANCELLED`, jamais une suppression physique du document). `SENT`/`PAID` →
refusé (`DocumentAlreadyIssuedException`, 409) : un document déjà engagé vis-à-vis du
tiers ne peut plus être annulé dans ce lot — une correction future passera par une note
de crédit / un document compensatoire, explicitement hors périmètre ici (§12/§37).

### Anti-double facturation — trois niveaux

Applicatif (revérification sous verrou avant rattachement, ci-dessus) ; base de données
(`UNIQUE(financial_calculation_line_id)` sur les deux tables de lignes — dernier
rempart, prouvé par insertion directe rejetée) ; transaction (sélection + création +
rattachement + verrouillage du calcul, atomique). Concurrence prouvée par
`FirmInvoiceConcurrencyTest` — deux connexions DBAL réellement distinctes, un
`createFromEligibleLines()` concurrent sur la même ligne est réellement bloqué (timeout
MySQL déterministe), jamais une réussite silencieuse ni un doublon.

### PDF — aucun changement nécessaire

Les templates `firm_invoice.html.twig`/`instrumentist_statement.html.twig` ne lisaient
déjà que des champs snapshot (`descriptionSnapshot`, `unitPrice`/`rateSnapshot`,
`totalAmount`, `quantity`, `missionDateSnapshot`/`mission.startAt`) — `hydrateFromFinancialLine()`
peuple exactement les mêmes champs pour les nouvelles lignes. Confirmé sans modification
de template (§21/§33 du lot).

### Migration — conservatrice, aucune reconstruction rétroactive

Colonnes nullables uniquement (`financial_calculation_line_id`, `currency`,
`unit_snapshot`, `source_snapshot`, `created_at` sur les deux tables de lignes ;
`currency`/`legacy_source` sur les deux documents). Aucun rapprochement approximatif par
montant/date/description tenté pour les documents existants — `financial_calculation_line_id`
reste `NULL` pour toute ligne créée avant ce lot, `legacy_source = true` par défaut.
Aucune perte de facture/décompte existant, testé par round-trip up→down→up.

### Portée non traitée ici (Lot 5+)

Gestion des documents `SENT`/`PAID`, paiements, rapprochement bancaire, corrections
financières additives après règlement externe, notes de crédit, table `Settlement`,
conversion de devises, refonte UX.

---

## D-075 — Les paiements sont des événements append-only. Ils ne modifient jamais les montants historiques. Le solde est toujours dérivé des paiements enregistrés (EPIC Exécution & Valorisation, Lot 5)

Date : 2026-07-18

### Décision

Cycle de vie financier complet après génération : émission (`GENERATED → SENT`),
paiement (unique, multiple, partiel), solde, historique — sans jamais modifier
`FinancialCalculation`/`FinancialCalculationLine` ni les montants documentaires
(`FirmInvoice.totalAmount`/`InstrumentistStatement.totalAmount` restent le montant BRUT,
gelé depuis la création du document, Lot 4). Un `Payment` est un événement, jamais
modifié ni supprimé une fois créé — le solde (`paidAmount`/`remainingAmount`/
`PaymentStatus`) est systématiquement recalculé en sommant les `Payment` existants,
jamais stocké en doublon (§7 du lot).

### Deux dimensions distinctes — sans explosion d'enum

`InvoiceStatus` (documentaire : `DRAFT`/`GENERATED`/`SENT`/`PAID`/`CANCELLED`) reste
inchangé pour compatibilité legacy (`markPaid()`, Lot 1, continue d'écrire `PAID`
directement — chemin non touché). Pour le **nouveau** flux de paiement, `PAID` n'est
plus jamais une valeur de `InvoiceStatus` : la dimension financière (`UNPAID`/
`PARTIALLY_PAID`/`PAID`) est portée par un nouvel enum `PaymentStatus`, **jamais
persisté** — uniquement retourné par `DocumentPaymentService::computeBalance()`. Une
facture payée intégralement via le nouveau chemin reste `InvoiceStatus::SENT` :
documentairement, elle a été émise et n'a jamais été ré-émise ; financièrement, elle
est soldée. Les deux dimensions ne se contaminent jamais.

### Compatibilité legacy — un document PAID sans aucun Payment

Un document marqué `PAID` avant ce lot via l'ancien `markPaid()` (Lot 1) n'a par
définition aucune ligne `Payment` (le modèle n'existait pas). `computeBalance()` le
traite comme intégralement soldé pour l'affichage (`paidAmount = grossAmount`) **sans**
reconstruire de `Payment` rétroactif — aucun rapprochement approximatif par montant/date
n'est tenté (cohérent avec la politique déjà appliquée aux lignes documentaires au
Lot 4, D-074 §19).

### Modèle Payment — table unique, polymorphe

Une seule entité `Payment` sert `FirmInvoice` **et** `InstrumentistStatement` (§3/§4 du
lot : "il ne doit pas être spécifique aux factures", "sans duplication"). Discriminant
explicite `documentType`/`documentId` (pas de FK Doctrine directe — impossible
nativement vers deux tables ; validé au niveau applicatif par `DocumentPaymentService`,
jamais deviné). Chaque entité déclare son propre `PaymentDocumentType` via l'interface
partagée `PayableDocument` (`getId()`/`getStatus()`/`setStatus()`/`getCurrency()`/
`getTotalAmount()`/`getPaymentDocumentType()`), implémentée par `FirmInvoice` et
`InstrumentistStatement` — même principe que la coexistence à deux agrégats distincts
du Lot 4, jamais fusionnés en un modèle générique.

Champs minimaux du lot tous présents : `documentType`, `documentId`, `amount`,
`currency`, `paidAt` (date réelle du paiement, date-only — un manager peut saisir une
date passée, mais aucune heure n'a de sens métier ici, donc aucun risque d'offset à
mal étiqueter, D-066 non applicable), `recordedAt` (horodatage serveur de la saisie,
toujours `new \DateTimeImmutable()`), `recordedBy`, `reference`, `method`
(`BANK_TRANSFER`/`CASH`/`OTHER` — pas de passerelle Stripe/SEPA inventée), `comment`,
`createdAt`.

### Solde et paiement partiel/complet

`DocumentBalance` (`grossAmount`/`paidAmount`/`remainingAmount`/`status`) — toujours
calculé, jamais stocké (§7). `paidAmount` = somme des `Payment` (ou `grossAmount` si
legacy `PAID` sans `Payment`, voir plus haut). `PaymentStatus::PAID` dès que
`remainingAmount <= 0` — transition automatique par construction (aucun champ à muter,
c'est une conséquence directe du calcul, §9 du lot). Paiement partiel testé avec
plusieurs paiements successifs qui s'accumulent correctement.

### Protection contre le surpaiement

`recordPayment()` recalcule le solde **sous verrou** juste avant d'accepter le nouveau
paiement (`(float) $amount > (float) $before->remainingAmount` → 422
`PAYMENT_EXCEEDS_REMAINING`) — jamais un paiement partiellement accepté. Devise
strictement égale à celle du document, jamais de conversion (422
`PAYMENT_CURRENCY_MISMATCH`). Montant strictement positif exigé.

### Concurrence

Même mécanisme que les Lots 2-4 : verrou pessimiste sur le **document** (`FirmInvoice`/
`InstrumentistStatement`, l'aggregate root — pas de verrou possible sur `Payment`
lui-même côté agrégat, il n'existe pas encore au moment de la validation), `refresh()`
sous verrou avant de relire le solde. Deux enregistrements concurrents sur le même
document se sérialisent — prouvé par `DocumentPaymentConcurrencyTest` (connexions DBAL
réellement distinctes, timeout MySQL déterministe, jamais un pari sur l'ordonnancement).

### Émission (issue())

`FirmInvoiceService::issue()`/`InstrumentistStatementService::issue()` (nouveau) :
`GENERATED → SENT`, attribue un numéro si absent (défensif — déjà assigné à la création
dans ce système), date l'émission, audite, actor obligatoire.
`markSent()` (existant, `POST /{id}/send`) délègue désormais à `issue()` pour la
transition elle-même, en conservant sa responsabilité propre (envoi de l'email, gérée
par le contrôleur) — **aucune rupture du contrat existant**, uniquement l'ajout d'un
paramètre `$actor` obligatoire (tous les appelants mis à jour : les deux contrôleurs et
le seul test qui appelait `markSent()` directement).

Pas de `DOCUMENT_SENT` séparé : réutilise `FIRM_INVOICE_ISSUED`/
`INSTRUMENTIST_STATEMENT_ISSUED` (créés au Lot 4 pour cette transition précise mais
jamais câblés jusqu'ici) plutôt que de dupliquer un événement pour le même fait.

### Numérotation

Stratégie inchangée (D-074) : `generateNumber()` reste `COUNT(...) + 1`, la contrainte
`UNIQUE` en base reste le filet de sécurité contre une collision concurrente
(`UniqueConstraintViolationException` → 409, déjà mappé). `issue()` n'attribue un
numéro que s'il manque encore — jamais de réservation anticipée sur une simple
prévisualisation.

### Annulation

Politique inchangée du Lot 4 (D-074 §12/§14) : `GENERATED → CANCELLED` autorisé (libère
les lignes), `SENT`/`PAID` refusé (`DocumentAlreadyIssuedException`). Un paiement ne
change jamais cette politique — un document `SENT` avec des paiements enregistrés reste
aussi peu annulable qu'un document `SENT` sans paiement.

### Endpoints, permissions, audit

`POST /{id}/issue`, `POST/GET /{id}/payments` — factures et décomptes, symétriques.
`BillingVoter::MANAGE` uniquement (aucun accès instrumentiste à la saisie ni à la
lecture des paiements — aucun droit de consultation personnelle préexistant à
préserver, vérifié). `DOCUMENT_PAYMENT_RECORDED` (systématique) +
`DOCUMENT_PARTIALLY_PAID`/`DOCUMENT_FULLY_PAID` (selon le solde résultant) —
`recordGlobal()`, document multi-mission.

### Portée non traitée ici (Lot 6+)

Notes de crédit, corrections financières additives après paiement, recalcul de montants
existants, rapprochement bancaire automatisé, conversion de devises, refonte UX.

---

## D-076 — Toute correction financière postérieure à l'émission est additive. Aucun document, calcul, ligne financière ou paiement historique n'est modifié ou supprimé. Les notes de crédit, notes de débit et remboursements sont des mouvements distincts, traçables et append-only (EPIC Exécution & Valorisation, Lot 6)

Date : 2026-07-18

### Décision

Corriger une erreur financière détectée après émission (`SENT`/`PAID`) ne modifie
jamais : le document d'origine, ses lignes, le `FinancialCalculation`/
`FinancialCalculationLine` d'origine, ni un `Payment` existant. Toute correction est un
nouveau mouvement financier — document correctif (note de crédit/débit) ou paiement
append-only (remboursement) — jamais une réécriture du passé.

### Modèle retenu — extension, pas de nouvelle entité

Stratégie recommandée du lot appliquée telle quelle : `FirmInvoice`/
`InstrumentistStatement` gagnent un `documentType` (`STANDARD`/`CREDIT_NOTE`/
`DEBIT_NOTE`, défaut `STANDARD`) et un lien `correctsDocument` (self-FK, nullable). Les
lignes documentaires (`FirmInvoiceLine`/`InstrumentistStatementLine`) gagnent
`reasonCode` et `originalDocumentLine` (self-FK, nullable, **sans** contrainte
d'unicité — contrairement à `financialCalculationLine`, une même ligne d'origine peut
être référencée par plusieurs corrections successives). Aucune entité
`FirmInvoiceCorrection`/`Settlement` séparée : l'extension reste cohérente, la
duplication de modèle aurait été gratuite (§3 du lot).

### Convention de signe

Montants stockés **toujours positifs** dans les lignes. Le signe économique est porté
exclusivement par `documentType`, centralisé dans
`FinancialDocumentType::signCoefficient()` (`CREDIT_NOTE` = -1, `STANDARD`/
`DEBIT_NOTE` = +1) — une seule source de vérité, jamais de montant négatif stocké.

### Lien avec le document d'origine — toujours la racine STANDARD

Une correction référence toujours directement le document `STANDARD` racine, jamais une
autre correction (`assertRootEligible()` refuse explicitement `documentType !==
STANDARD` avec `CorrectionNotEligibleException`, 409 `CORRECTION_NOT_ELIGIBLE`) — simplifie
l'audit et le calcul net (§6 du lot), pas de chaîne de corrections à parcourir.

### Lignes correctives

Champs minimaux du lot tous présents : `originalDocumentLine` (nullable — `null` pour
une ligne oubliée, §10), `reasonCode`, `descriptionSnapshot`, `quantity`, `unitAmount`
(`unitPrice`/`rateSnapshot` selon l'entité), `totalAmount`, `currency`, `createdAt`, et
`financialCalculationLine` optionnel (traçabilité uniquement — **ne signifie jamais**
que la ligne financière est consommée une deuxième fois ; toujours vérifié
`!isAssigned()` avant rattachement, jamais de réutilisation, §7/§34 du lot).
`CorrectionReasonCode` limité aux besoins réels (8 cas) ; `OTHER` exige un commentaire
(`MISSING_OTHER_COMMENT` sinon).

### Source des montants correctifs — jamais de recalcul

`FinancialCorrectionService` n'invoque jamais `PricingRuleResolver`/
`InstrumentistRateResolver`, ne recalcule aucune durée, n'accède jamais à
`User.hourlyRate` (§11/§34 du lot). Un montant correctif est soit explicite (saisi et
validé par le manager, avec ligne d'origine + motif obligatoires), soit repris tel quel
d'une `FinancialCalculationLine` déjà valorisée par le Lot 3.

### Politique avant/après émission

`GENERATED` → correction refusée (`CorrectionNotEligibleException`), le chemin correct
reste `cancel()` (§2/§21 du lot, inchangé du Lot 4). `SENT`/`PAID` → correction
additive uniquement. Une correction elle-même suit `GENERATED → SENT` — réutilise
`FirmInvoiceService::issue()`/`InstrumentistStatementService::issue()` (Lot 5) plutôt
que de dupliquer la transition, la numérotation et l'audit `*_ISSUED`.

### Notes de crédit — limites cumulées

Deux garde-fous dans `assertCreditLimits()` : (1) par ligne d'origine, la somme des
crédits déjà liés (statuts `GENERATED`/`SENT`/`PAID`, jamais `CANCELLED` — un crédit
encore brouillon compte déjà contre la limite pour empêcher un double-crédit créé sous
le même verrou avant émission) ne peut dépasser le montant original de cette ligne
(`CREDIT_EXCEEDS_ORIGINAL_LINE`) ; (2) le montant net projeté du document ne peut
jamais devenir négatif (`NET_AMOUNT_NEGATIVE`, recommandation §22 :
`netDocumentAmount >= 0`). Toute anomalie de ligne est collectée (jamais un échec sur
la première ligne trouvée) puis levée en un seul `CorrectionValidationException` (422
`CORRECTION_VALIDATION_FAILED`) — aucun document partiel, aucune ligne persistée, aucun
numéro attribué (§30 du lot).

### Notes de débit — augmentation justifiée

Aucune limite liée à une ligne d'origine (une note de débit peut porter sur une
prestation entièrement oubliée, `originalDocumentLine = null` + `missionId`
obligatoire, §10/§23 du lot) — uniquement les mêmes contraintes générales (motif,
montant strictement positif, devise/bénéficiaire du document racine).

### Montant net — toujours dérivé, jamais stocké

`DocumentBalance` (Lot 5) étendu à 9 champs dérivés :
`originalGrossAmount`/`creditNotesAmount`/`debitNotesAmount`/`netDocumentAmount`/
`paidAmount`/`refundedAmount`/`remainingAmount`/`overpaidAmount`/`status`. Formules :
`netDocumentAmount = originalGrossAmount - creditNotesAmount + debitNotesAmount` ;
`remainingAmount = max(0, netDocumentAmount - paidAmount + refundedAmount)` ;
`overpaidAmount = max(0, paidAmount - refundedAmount - netDocumentAmount)`. **Seules
les corrections `ISSUED` (`SENT`/`PAID`) comptent** dans `creditNotesAmount`/
`debitNotesAmount` (`sumIssuedCorrections()`) — une correction encore `GENERATED` reste
modifiable/annulable et ne doit jamais influencer silencieusement ce qui est dû (§17 du
lot).

### Paiements et remboursements — toujours rattachés à la racine

Politique retenue explicitement parmi les deux proposées par le lot (§17/§18) :
paiements **et** remboursements sont toujours rattachés au document `STANDARD` racine,
jamais à un document correctif — un seul compte financier par famille documentaire,
les corrections restent de purs mouvements documentaires.
`DocumentPaymentService::resolveRoot()` (`$document->getCorrectsDocument() ??
$document`) garantit cette règle même si l'appelant passe une note de crédit/débit par
erreur. `Payment` (Lot 5, append-only, inchangé) étend uniquement avec `direction`
(`PaymentDirection::INBOUND`/`OUTBOUND`) — un remboursement est un nouveau `Payment`
`OUTBOUND`, jamais une modification/suppression d'un `Payment` existant.

### Protection contre le remboursement excessif

`recordRefund()` recalcule le solde **sous verrou** juste avant d'accepter
(`(float) $amount > (float) $before->overpaidAmount` → 422
`REFUND_EXCEEDS_OVERPAID`) — même mécanisme que `PAYMENT_EXCEEDS_REMAINING` (Lot 5).
Deux remboursements concurrents ne peuvent jamais dépasser le trop-perçu réel (prouvé
par `FinancialCorrectionConcurrencyTest`, connexions DBAL distinctes).

### Legacy — aucune reconstruction

Une correction sur un document `legacySource = true` est autorisée dès lors que
bénéficiaire/devise/montant/statut émis sont exploitables — aucune
`FinancialCalculationLine` n'est jamais reconstruite rétroactivement, la ligne
corrective référence uniquement la ligne documentaire legacy (§24 du lot, même
politique que D-074 §19).

### Numérotation

Préfixe distinct par type documentaire, même stratégie `COUNT(...) + 1` +
`UNIQUE` en base (D-074/D-075 inchangés) : `FIRM-YYYY-NNN` (STANDARD, inchangé),
`FIRM-CN-YYYY-NNN`/`FIRM-DN-YYYY-NNN` pour les factures ; `STMT-CN-YYYY-NNN`/
`STMT-DN-YYYY-NNN` pour les décomptes (`InstrumentistStatement.number` est un champ
**nouveau** — un décompte STANDARD n'a jamais eu de numéro et n'en reçoit toujours pas,
seules ses corrections en reçoivent un, à l'émission uniquement, jamais sur un
brouillon).

### Endpoints, permissions

`POST /{id}/credit-notes`, `POST /{id}/debit-notes`, `GET /{id}/corrections`,
`POST /{id}/refunds` sous les préfixes existants (`/api/firm-invoices`,
`/api/instrumentist-statements`) ; `POST /api/firm-invoice-corrections/{id}/issue` et
`POST /api/instrumentist-statement-corrections/{id}/issue` dans deux contrôleurs dédiés
(la famille de ressource "correction" n'est pas adressable sous le préfixe de classe
existant). `BillingVoter::MANAGE` uniquement pour toutes les opérations d'écriture —
aucun accès instrumentiste préservé au-delà de ce qui existait déjà (aucun droit de
lecture personnelle sur son propre décompte n'existait avant ce lot, rien à préserver).

### Audit — sans doublon

`CREDIT_NOTE_CREATED`/`DEBIT_NOTE_CREATED` (création, `createCorrection()`),
`FINANCIAL_CORRECTION_ISSUED` (émission, en plus de `FIRM_INVOICE_ISSUED`/
`INSTRUMENTIST_STATEMENT_ISSUED` déjà émis par `issue()` — contexte propre à la
correction : document racine, type, lignes d'origine), `DOCUMENT_NET_BALANCE_CHANGED`
(uniquement si le solde net du document racine change réellement entre avant/après
émission), `REFUND_RECORDED`. Pas de `DOCUMENT_SENT` séparé — même principe de
non-duplication que D-075.

### Concurrence et atomicité

Même mécanisme que les Lots 2-5 : verrou pessimiste sur le document **racine** (jamais
sur la correction elle-même, qui n'existe pas encore au moment de la validation),
`refresh()` sous verrou avant de revalider les limites. Toute la construction d'une
correction (validation de toutes les lignes, calcul des limites, persistance,
numérotation, audit) est enveloppée dans une seule transaction
(`wrapInTransaction()`) : une seule ligne invalide annule tout — aucun document
partiel, aucune ligne persistée, aucun numéro attribué, aucun audit mensonger, aucun
changement de solde observable (§30 du lot, prouvé par
`test_invalid_correction_line_creates_no_partial_document`).

### Portée non traitée ici

Rapprochement bancaire automatisé, conversion de devises, refonte UX, modification/
suppression d'un paiement existant (toujours interdite, §34 du lot).

---

## D-077 — Les statistiques financières utilisent les événements financiers immuables comme sources de vérité. L'activité provient des missions et exécutions. La valeur générée provient des FinancialCalculationLine actives. La valeur documentée provient des documents émis et de leurs corrections. Les flux monétaires proviennent exclusivement des Payment append-only (EPIC Pilotage financier, Lot 7)

Date : 2026-07-19

### Décision

Chaque indicateur statistique déclare une source métier explicite et unique — jamais
un mélange, jamais une déduction depuis un statut seul (sauf compatibilité legacy déjà
formalisée au Lot 5). Aucun recalcul tarifaire : `FinancialStatisticsQueryService`/
`FinancialStatisticsRankingService` n'invoquent jamais `PricingRuleResolver`/
`InstrumentistRateResolver`/`User.hourlyRate` — les montants proviennent exclusivement
des lignes déjà valorisées (Lot 3) et des documents déjà émis (Lots 4-6).

### Sources de vérité par catégorie

| Catégorie | Source | Champs typiques |
|---|---|---|
| Activité opérationnelle | `Mission`/`MissionExecution` | missionCount, executedMissionCount, validatedMissionCount |
| Valeur générée | `FinancialCalculationLine` (calcul ACTIF) | generatedFirmRevenue, generatedInstrumentistCompensation |
| Valeur documentée | `FirmInvoice`/`InstrumentistStatement` + corrections ISSUED | invoicedNetAmount, statementNetAmount |
| Flux monétaires | `Payment` (append-only) | paymentsIn, paymentsOut, netCashFlow |

### Convention temporelle (§3/§4 du lot)

`from` inclusif, `to` exclusif. Jamais `now()` comme borne fonctionnelle — un filtre
`from`/`to` absent est résolu vers un sentinel fixe (1970-01-01 / 9999-12-31), jamais la
date du jour. Date de rattachement par catégorie :

- Missions : `COALESCE(MissionExecution.actualStartAt, Mission.startAt)`.
- `FinancialCalculationLine` : `effectiveAt`.
- Documents : `sentAt` — **un document `GENERATED` n'est jamais compté comme émis**
  (seul `SENT`/`PAID` compte), sa date de rattachement pour le pipeline
  (`generatedInvoicesNotIssued`) est `createdAt`.
- Paiements/remboursements : `Payment.paidAt`.

Timezone métier (D-066, Europe/Brussels) : `Mission.startAt`/`MissionExecution.
actualStartAt` sont stockées en digits Europe/Brussels littéraux
(`BusinessDateTimeImmutableType`) — comparées directement, aucune conversion SQL. `
FinancialCalculationLine.effectiveAt`/`Payment.paidAt` sont des `DATE` pures (aucune
heure, D-066 non applicable). `FirmInvoice`/`InstrumentistStatement.sentAt` sont de
simples `datetime_immutable` (digits UTC nus). **Limite documentée** : le
regroupement par bucket de série temporelle sur `sentAt` n'applique pas de conversion
Europe/Brussels — `CONVERT_TZ` avec zones nommées est indisponible sur cette instance
MySQL (zoneinfo non chargée, vérifié). Un document émis très tôt/tard dans la journée
peut apparaître dans le bucket UTC plutôt que le bucket Bruxelles adjacent ; impact
mineur, sans rapport avec la règle D-066 (qui ne couvre que `Mission.startAt/endAt`).

### Convention de devise (§5)

Jamais de conversion. Tout agrégat monétaire est regroupé par devise
(`currency` explicite sur chaque ligne de résultat) ; s'il existe plusieurs devises sur
une période, plusieurs entrées sont retournées — jamais un total artificiel qui les
mélangerait. `missionCount`/`averageExecutionDurationMinutes` (non-monétaires) ne sont
jamais dupliqués par devise (voir `FinancialOverviewActivityDto`).

### Chiffre d'affaires généré et rémunération instrumentiste (§8/§9)

`generatedFirmRevenue` = somme des lignes `FIRM_INTERVENTION_FEE`/`FIRM_MATERIAL_FEE`
d'un `FinancialCalculation` **actif** (`CALCULATED`/`APPROVED`/`LOCKED` — le modèle
garantit qu'au plus une version est active par mission à tout instant, voir
`FinancialCalculationService::calculate()`, donc aucune déduplication de version
supplémentaire n'est nécessaire). `generatedInstrumentistCompensation` = somme des
lignes `INSTRUMENTIST_HOURLY`/`INSTRUMENTIST_CONSULTATION_FEE`. `SUPERSEDED`/
`CANCELLED` toujours exclus.

### Contribution margin (§10)

`generatedContributionMargin = generatedFirmRevenue - generatedInstrumentistCompensation`.
Nommée explicitement "contribution margin", jamais "bénéfice net" — elle n'intègre ni
charges sociales, ni TVA, ni coûts administratifs, ni matériel non valorisé, ni impôts,
ni frais généraux.

### Paiements et flux de trésorerie réels (§20)

`Payment.direction` seul ne suffit pas : `INBOUND` signifie "règle le document", pas
"argent entrant en caisse". Sur un `FirmInvoice`, `INBOUND` est un encaissement réel.
Sur un `InstrumentistStatement`, `INBOUND` est un **décaissement** réel (l'entreprise
règle l'instrumentiste) — la même valeur de colonne porte un sens économique inversé
selon le type de document. `paymentsIn`/`paymentsOut` dérivent donc de
`(documentType, direction)` combinés, jamais de `direction` seule :

```
paymentsIn  = FirmInvoice.INBOUND + InstrumentistStatement.OUTBOUND
paymentsOut = InstrumentistStatement.INBOUND + FirmInvoice.OUTBOUND
netCashFlow = paymentsIn - paymentsOut
```

Prouvé par `test_instrumentist_statement_inbound_payment_counts_as_cash_out`.

### Corrections dans les statistiques (§19)

Convention identique à D-076 : `STANDARD`/`DEBIT_NOTE` = +montant, `CREDIT_NOTE` =
-montant, uniquement pour les corrections **ISSUED** (`SENT`/`PAID`) — une correction
`GENERATED` ne modifie jamais un agrégat. `invoicedNetAmount`/`statementNetAmount`
répliquent en SQL la même formule que `DocumentPaymentService::computeBalance()` (Lot
5/6), jamais un appel PHP par document (§22 — voir `documentBalanceDerivedTable()`).

### Legacy (§21)

Un document `legacySource = true` reste visible dans les statistiques documentaires et
de paiement si ses données sont exploitables — aucune reconstruction de
`FinancialCalculation`/snapshot/ligne financière historique. Les métriques générées
(issues de `FinancialCalculationLine`, qui n'existe pas pour ces documents) peuvent
donc différer des métriques documentaires legacy — différence normale et attendue.

### Pipeline financier (§17)

Neuf compteurs disjoints (jamais de double comptage) : missions `VALIDATED` sans
calcul, calculs `CALCULATED` en attente d'approbation, calculs `APPROVED`/`LOCKED`
totalement non documentés, calculs partiellement documentés (au moins une ligne
assignée ET au moins une libre — exclusif du précédent par construction NOT
EXISTS/EXISTS opposés), factures/décomptes `GENERATED` non émis, factures/décomptes
émis avec solde ouvert, documents en trop-perçu en attente de remboursement.

### Performance (§22)

Toute agrégation lourde (missions/lignes financières/documents/paiements, potentiellement
des milliers de lignes) est faite en SQL (DBAL natif, jamais DQL avec hydratation
complète). Le tri/pagination des classements (by-firm/by-instrumentist/by-surgeon/
by-intervention/top-materials) se fait en PHP **après** l'agrégation SQL — la
population résultante est bornée par le nombre de firmes/instrumentistes/chirurgiens/
types d'intervention/matériels distincts, jamais par le nombre de missions ou de
lignes financières brutes (ce n'est donc pas l'"agrégation PHP sur des milliers de
lignes" interdite par le lot). Index ajoutés : voir migration
`Version20260719065527`.

### Limites documentées (autres qu'exposées ci-dessus)

`FinancialCalculationLine` ne porte aucun snapshot du chirurgien — `surgeonNameSnapshot`
est lu en direct via `Mission.surgeon` (FK vivante), jamais un vrai instantané
historique (aucune alternative n'existe dans le modèle actuel). `materialReferenceSnapshot`
n'est pas non plus dans `FinancialCalculationLine.snapshot` — lu en direct via
`MaterialItem.referenceCode` (donnée d'identification, jamais tarifaire). Le
regroupement `by-intervention` attribue `instrumentistCompensation` (qui n'a aucun lien
direct à une intervention précise) à l'intervention **primaire** de la mission
(`order_index` minimal) — un choix explicite documenté plutôt qu'une répartition
proportionnelle non demandée par le lot.

### Portée non traitée ici

Export comptable, prévisionnel financier, rapprochement bancaire, graphiques frontend
complexes, refonte UX complète (§34 du lot).

---

## D-078 — Autorisation catalogue financier alignée sur BillingVoter::MANAGE (ROLE_ADMIN = ROLE_MANAGER) ; système d'aide contextuelle par écran

Date : 2026-07-19

### Contexte

Audit post-déploiement (tests manuels manager/admin sur le catalogue financier livré
en D-072 à D-077) : un compte `ROLE_ADMIN` seul recevait un 403 sur la création/mise à
jour/suppression de firmes (`POST/PATCH/DELETE /api/firms`), alors que tout le reste du
catalogue financier (matériel, types d'intervention, prestations, règles tarifaires,
tarifs instrumentiste, factures, décomptes, statistiques) fonctionnait déjà correctement
pour un admin. Cause : `FirmController` était le seul contrôleur du périmètre à vérifier
littéralement `denyAccessUnlessGranted('ROLE_MANAGER')` au lieu du Voter dédié.

### Décision — autorisation

Le projet n'a **pas** de `role_hierarchy` Symfony configuré (vérifié dans
`config/packages/security.yaml`) : `ROLE_ADMIN` n'implique jamais `ROLE_MANAGER`
automatiquement. L'équivalence entre les deux rôles pour la gestion du catalogue
financier est donc portée exclusivement par `BillingVoter::MANAGE` (`ROLE_MANAGER` OU
`ROLE_ADMIN`), déjà la convention établie et utilisée sans exception par
`MaterialCatalogController`, `FirmServiceOfferingController`, `InterventionTypeController`,
`InterventionTypeRequestManagerController`, `FirmBillingController`,
`InstrumentistRateController`, `FirmInvoiceController`, `FirmInvoiceCorrectionController`,
`InstrumentistStatementController`, `InstrumentistStatementCorrectionController`,
`FinancialCalculationController`, `FinancialStatisticsController`. `FirmController` a été
aligné sur cette convention (import `BillingVoter`, remplacement des trois
`denyAccessUnlessGranted('ROLE_MANAGER')` par `denyAccessUnlessGranted(BillingVoter::MANAGE)`)
— aucune nouvelle classe Voter créée, la bonne abstraction existait déjà.

**Règle projet reconfirmée** : toute nouvelle route mutant une ressource du catalogue
financier doit utiliser `BillingVoter::MANAGE`, jamais un rôle littéral — un test
`test_admin_can_...` doit accompagner tout nouveau contrôleur pour empêcher la
régression de revenir silencieusement (voir `FirmControllerTest.php` et les tests
ajoutés à `MaterialCatalogControllerTest`, `FirmServiceOfferingControllerTest`,
`FirmBillingControllerPricingRuleTest`, `InstrumentistRateControllerTest` —
`InterventionTypeControllerTest` avait déjà cette couverture). Suite complète validée :
1292/1292 tests verts.

`SiteController` utilise le même check littéral `ROLE_MANAGER` mais est hors périmètre
« catalogue financier » (gestion des établissements/sites) — non touché ici, signalé
pour un audit ultérieur si le même besoin se présente pour les sites.

### Décision — aide contextuelle

Système d'aide par écran (drawer déclenché par un bouton « ? » discret dans le header),
jamais une FAQ générique. Architecture à trois couches, aucune couche ne connaît le
contenu métier des autres :

1. **Contenu** — `frontend/src/app/features/help/content/topics/*.ts`, un fichier par
   écran, exportant un objet `HelpTopic` typé (`types.ts` : titre, intro en une phrase,
   sections `{heading, paragraphs?, bullets?}`). Contenu rédigé du point de vue métier
   (workflow, impacts, erreurs fréquentes, bonnes pratiques) — jamais "Cette page permet
   de...".
2. **Registre** — `content/registry.ts` : `Record<topicId, HelpTopic>`. Ajouter une aide
   = créer un fichier de contenu + une ligne dans ce registre. Aucun composant à modifier.
3. **UI** — `HelpButton.tsx` (le seul composant à poser dans un header :
   `<HelpButton topicId="..." />`) et `HelpDrawer.tsx` (panneau MUI `Drawer`, largeur
   `100%` sous le breakpoint `sm`, 420px au-dessus — même composant desktop/mobile,
   aucune branche de code séparée). `HelpButton` accepte un `sx` optionnel pour
   s'intégrer à un header non standard (voir `EncodeHeader.tsx`, header dégradé de
   l'écran d'encodage instrumentiste, bouton stylé en miroir du bouton retour).

Cinq écrans couverts au lancement : Planning V2 (manager), Firmes, Catalogue matériel,
Règles de facturation firmes, Encodage d'une mission (instrumentiste). Voir
`docs/architecture.md` §4 « Aide contextuelle » pour le détail architecture et la
procédure d'ajout.

### Portée non traitée ici

Aide contextuelle sur les écrans hors périmètre financier/planning (missions, absences,
administration...) — le système est prêt à les recevoir, contenu non rédigé faute de
demande explicite. Pas de tracking d'usage/analytics sur l'ouverture de l'aide.

---

## D-079 — Réorganisation de la navigation manager (sidebar groupée, Dashboard, fusion Prestations/Demandes)

Date : 2026-07-19

### Contexte

L'espace manager avait grossi au fil des lots (D-067 à D-078) jusqu'à 23 pages : 8 items de
sidebar à plat + 2 groupes + une page (`InterventionTypesPage`) jamais liée au menu. Demande
explicite : regrouper par domaine, ajouter une page d'accueil, consolider les pages qui se
recoupaient (règles tarifaires / types d'intervention / matériel facturable dispersés ; demandes
matériel et demandes de type d'intervention jamais réunies bien que le backend `Intervention
TypeRequestManagerController` existât depuis D-068 sans jamais avoir de vue manager). Contraintes
dures : aucune nouvelle fonctionnalité métier, aucune régression, réutilisation maximale du code
et des composants, suppression du legacy uniquement après audit, aucune modification des règles
d'autorisation.

### Décision — nouvelle architecture de navigation

```
Dashboard · Missions · Instrumentistes · Chirurgiens · Établissements
Catalogue    → Firmes / Prestations / Demandes [badge]
Planning     → Construire / Absences
Facturation  → Factures firmes / Décomptes / Statistiques
Administration (ADMIN, inchangée) → Utilisateurs / Sites / Invitations / Audit
```

- **Dashboard** (`DashboardPage.tsx`, nouvelle route `/app/m/dashboard`, nouvelle page
  d'atterrissage post-login pour MANAGER/ADMIN) — agrège uniquement des requêtes déjà existantes
  (`fetchMissions`, `getAlerts`, `getInstrumentists`/`getSurgeons` filtrés actifs, `fetchSites`,
  `getMaterialRequests`/`getInterventionTypeRequests` PENDING, `getPipeline`, `getOverview`,
  `getByFirm`/`getBySurgeon`/`getTopMaterials`). Aucun nouvel endpoint backend créé — audit
  préalable du lot 7 statistiques (D-077) confirmé suffisant.
- **Missions** : `MissionsListPage.tsx` bascule sa distinction Toutes/À valider (jusqu'ici un
  `location.pathname.endsWith("/to-validate")` piloté par deux boutons) vers de vraies `Tabs` MUI
  pilotées par la route — la logique de filtre (`effectiveFilters`, déjà correcte) est inchangée.
  `/app/m/missions/to-validate` reste une route valide (compat liens/favoris).
- **Catalogue** reste à exactement 3 entrées :
  - **Firmes** (`FirmsPage.tsx`, inchangée) — fiche administrative uniquement, jamais mélangée aux
    tarifs (règle déjà établie, reconfirmée ici).
  - **Prestations** (`PrestationsPage.tsx`, nouvelle, remplace `BillingConfigPage.tsx` /
    `/app/m/billing/config`) — centre de configuration métier : prestations (offerings) + forfaits
    + tarifs matériel + types d'intervention + création d'article matériel, tous réunis. Réutilise
    **sans modification** les 5 dialogs existants de `BillingConfigPage.tsx`
    (`AddOfferingDialog`, `ForfaitDialog`, `MaterialRateDialog`, `SuggestedMaterialsDialog`,
    `AddMaterialRuleDialog`) et 100% de `firmBilling.api.ts`. Seul le conteneur change : sélecteur
    de firme `<Select>` → `SideList` (recherche + liste) ; deux `<Paper>` empilés (Prestations /
    Matériel facturable) → vraies `<Tabs>` MUI. Types d'intervention intégrés via un bouton
    "Gérer les types d'intervention" ouvrant un `Dialog` — décision explicite du produit (bouton
    plutôt que sous-onglet, option la plus simple) — qui embarque `InterventionTypesManager.tsx`,
    la logique CRUD **extraite** de `InterventionTypesPage.tsx` (devenu un wrapper fin autour de
    ce composant partagé, pas une duplication). Création d'article matériel intégrée via un bouton
    "Créer un article" réutilisant tel quel `MaterialItemFormDialog` (déjà utilisé par
    `CataloguePage.tsx`) — décision explicite : `/app/m/catalogue` (Matériel) sort du menu, sa
    fonction de création est désormais accessible aussi depuis Prestations.
  - **Demandes** (`CatalogueRequestsPage.tsx`, étendue) — fusionne demandes matériel (déjà
    branché) et demandes de type d'intervention (nouveau client frontend
    `interventionTypeRequests.api.ts` vers l'endpoint backend `InterventionTypeRequestManager
    Controller`, livré en D-068 mais jamais consommé côté manager) dans un même tableau avec un
    tag de type par ligne, Tabs En attente/Résolues/Ignorées inchangées, dialog de résolution
    dédié par type. Badge de sidebar = somme des deux compteurs PENDING
    (`ui/hooks/useNavBadgeCount`, généralisé depuis le `useQuery`+`Badge` auparavant câblé en dur
    pour les seules demandes matériel).
- **Planning** : "Construire" = `PlanningV2Page.tsx` inchangée (renommage de menu uniquement).
  "Planning publié" (`PlanningSchedulePage.tsx`) retirée du menu mais route conservée
  (`/app/m/planning/living`) et reste accessible via un bouton ajouté dans Construire.
- **Facturation** : entrée "Configuration" supprimée (absorbée par Catalogue > Prestations) ;
  Factures firmes / Décomptes / Statistiques inchangées. `/app/m/billing/config` redirige
  désormais vers `/app/m/catalogue/prestations` (compat liens existants).
- **Administration** : inchangée.

### Décision — composants extraits (`frontend/src/app/ui/`)

`EmptyState`, `PageHeader`, `StatusBadge`/`ActiveBadge`, `StatCard`, `SearchBox` (+
`hooks/useDebouncedValue`), `EntityHeader`, `SideList` (généralisé depuis le pattern sidebar
recherche+liste déjà le plus abouti du projet, `SurgeonPostsTab.tsx`), `hooks/useNavBadgeCount`.
Chaque extraction a remplacé ses doublons déjà identifiés dans le code (pas seulement posée sur
les nouvelles pages) : `EmptyState` remplace les implémentations locales de
`InstrumentistsTable.tsx`/`SurgeonsTable.tsx`/`FirmsPage.tsx`/`HospitalsPage.tsx`/
`InterventionTypesPage.tsx` ; `PageHeader` remplace le bloc copié-collé de ces mêmes pages ;
`ActiveBadge` remplace le ternaire actif/inactif dupliqué (`FirmsPage`, `InterventionTypesPage`,
`BillingConfigPage`, `SurgeonDrawer`) ; `EntityHeader` remplace la composition `Paper > Stack >
[avatar + nom + chip]` dupliquée dans `InstrumentistDrawer.tsx`/`SurgeonDrawer.tsx` ; `SearchBox`
remplace le debounce manuel dupliqué (`AdminUsersPage.tsx`, `CataloguePage.tsx`). Deux exceptions
délibérées, documentées au moment de l'implémentation : `SurgeonPostsTab.tsx` (Planning V2) n'a
pas été migré vers `SideList` (source d'inspiration seulement, migration jugée hors périmètre/
risque pour une fonctionnalité de planification en production) ; les champs de recherche non-
debouncés avec libellé d'`InstrumentistsTable.tsx`/`SurgeonsTable.tsx` n'ont pas été remplacés par
`SearchBox` (style visuel différent, aurait violé la contrainte "aucun changement visuel majeur").

### Décision — legacy Planning V1 (errata D-048, mise à jour 2026-07-20)

Voir les deux errata ajoutées directement dans D-048 ci-dessus. Résumé final (après audit complet
demandé sur `PlanningVersionDetailPage.tsx`, "Errata 2" du 2026-07-20) : la totalité du legacy V1
est supprimée, pas seulement les 5 pages initiales. `PlanningTemplatesPage`,
`PlanningTemplateEditorPage`, `PlanningGeneratePage`, `PlanningVersionsListPage`, `SpecialtiesPage`,
`PlanningVersionDetailPage.tsx`, `DeployModal.tsx`, `PlanningTemplateController.php`,
`PlanningGenerationController.php`, `PlanningDeployController.php`, `PlanningGeneratorService.php`,
les entités `PlanningTemplate`/`PlanningSlot`/`PlanningTemplateType`, et 4 actions orphelines de
`PlanningVersionController.php` (`show`/`diff`/`delete`/`pdf`) sont tous supprimés — plus aucune
route, endpoint, ni page ne dépend du moteur V1. Planning V2 est désormais l'unique implémentation
active de la génération/déploiement de planning. Suite backend validée : 1251/1251 tests verts.

### Portée non traitée ici

Les tables SQL `planning_template`/`planning_slot` ne sont pas droppées (suppression d'entité
Doctrine ≠ suppression de table ; décision distincte, non demandée). Le risque documenté en D-048
sur `PlanningVersionDetailPage.tsx` (bouton "Déployer" agissant via les endpoints V1 sur une
`PlanningVersion` potentiellement créée par V2) est désormais **résolu par suppression de la page
elle-même**, plus seulement documenté — voir "Errata 2" ci-dessus. Aucune modification des règles
d'autorisation (Voters inchangés). Aucun changement de logique métier backend au-delà des
suppressions listées ci-dessus (aucune règle métier réécrite, aucun endpoint dupliqué).

---

## D-080 — Justification obligatoire si aucun matériel encodé, deux colonnes distinctes commentaire/flag (précise D-070, Lot 7)

Date : 2026-07-20

### Contexte

`MissionSubmitRequest::$comment` existait côté DTO depuis D-070 mais n'était jamais
persisté (`MissionService::submit()` l'ignorait) — un test manuel en direct sur la
mission #690 a montré qu'aucun garde-fou n'empêchait de clôturer un encodage sans la
moindre ligne de matériel, sans qu'aucune trace ne permette au manager de savoir si
c'était normal (mission de consultation sans matériel) ou un oubli. Revue pré-déploiement
en 7 points demandée avant tout commit/déploiement (sémantique du champ, resoumission
avec matériel, comptage défensif, affichage manager, cycle de vie, documentation,
rapport final).

### Décision — règle métier

> Lors de la clôture de l'encodage, si aucune ligne de matériel active avec une quantité
> strictement positive n'est enregistrée, l'instrumentiste doit décrire les interventions
> réellement réalisées. La vérification est effectuée côté serveur.

`MissionEncodingWorkflowService::complete()` recalcule le compte
(`countActiveMaterialLines()`, filtre `quantity > 0`) à chaque appel — jamais un flag
envoyé par le client. `MaterialLine` n'a ni suppression logique (DELETE fait un vrai
`remove()`), ni flag `active` propre, ni notion de version/superseded : seul le filtre
quantité correspondait à une lacune réelle (aucune contrainte `Assert\Positive` sur
`MaterialLineCreateRequest`/`MaterialLineUpdateRequest` aujourd'hui — lacune signalée,
non corrigée dans ce lot, hors périmètre de la revue demandée).

### Décision — deux colonnes distinctes, pas un seul champ générique

- **`no_material_comment`** (texte) : le contenu du commentaire. Nommé explicitement
  (jamais un `submit_comment` générique) parce qu'il n'a qu'un seul usage possible.
  Conservé indéfiniment pour traçabilité, y compris après une resoumission ultérieure
  avec du matériel — jamais effacé automatiquement.
- **`submitted_without_material`** (booléen) : figé à **chaque** `complete()`, reflète si
  **cette soumission précise** avait 0 matériel actif. Nécessaire car
  `MissionEncodingGuard` bloque toute lecture d'encodage une fois la mission verrouillée
  (`encodingLockedAt`) — un recomptage live après coup n'est pas toujours possible, donc
  ce fait doit être capturé au moment de la transition, pas dérivé après coup.

Sans ce second champ, un manager consultant une mission resoumise avec du matériel après
une première justification "sans matériel" verrait un commentaire orphelin sans pouvoir
distinguer "cette soumission n'a pas de matériel" de "il y a du matériel mais le
commentaire d'une soumission antérieure traîne encore" — l'affichage manager
(`MissionDetailPage`, libellé **"Aucun matériel encodé — justification de
l'instrumentiste"**) est donc conditionné sur `submittedWithoutMaterial === true` de la
soumission courante, jamais sur la seule présence du commentaire.

### Décision — resoumission après correction

Scénario couvert par test (unitaire et fonctionnel HTTP) : soumission sans matériel avec
commentaire → correction (`reject()`, seule transition `SUBMITTED → ENCODING_IN_PROGRESS`)
→ ajout d'une ligne de matériel → nouvelle soumission. Le commentaire précédent reste en
base (traçabilité) ; `submittedWithoutMaterial` repasse à `false` ; la nouvelle
soumission n'exige pas de nouveau commentaire puisque du matériel est désormais présent.

### Cycle de vie et droits (vérifié par tests)

Le champ n'est exposé que dans `MissionDetailDto` (le récapitulatif de mission, GET
`/api/missions/{id}`) — jamais dans le DTO d'encodage (GET `.../encoding`, dédié au
formulaire de saisie). Obligatoire seulement quand le serveur constate 0 matériel actif ;
n'empêche jamais la sauvegarde progressive des lignes de matériel pendant la journée
(le contrôle ne vit que dans `complete()`, jamais dans les endpoints CRUD de
`MaterialLine`). Plus aucune mutation possible une fois la mission verrouillée — même
garde (`MissionEncodingGuard`) que toute autre transition d'encodage, pas de mécanisme
dédié. Le bouton de validation manager reste gouverné par `allowedActions`
(`MissionActionsService`), non affecté par ce lot.

### Portée non traitée ici

Contrainte `Assert\Positive` manquante sur les DTOs de création/édition de ligne de
matériel (quantité 0/négative reste persistable via l'API) — lacune réelle, signalée,
correction laissée à un lot ultérieur si jugée prioritaire. Aucune règle financière
modifiée (isBillable()/FinancialCalculation hors périmètre). Migration
`Version20260720150000` : ajoute `mission.no_material_comment` (LONGTEXT nullable) et
`mission.submitted_without_material` (TINYINT(1) nullable) ; aucune autre colonne
touchée.

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
| 23-06-2026 | D-049 — Règles d'affiliation aux sites par rôle métier |
| 24-06-2026 | D-050 — Absences "jours isolés" : N lignes `Absence` d'un jour, pas de nouveau champ |
| 24-06-2026 | D-051 — Relances congés manager : preview backend, destinataire fixe, audit sans cible unique |
| 27-06-2026 | D-052 — Le planning publié est un objet vivant (invariant Post/Mission + MissionLifecycleChangedMessage) |
| 27-06-2026 | D-053 — Notification chirurgien : par poste, pas par statistique |
| 27-06-2026 | D-054 — Deux familles de notifications instrumentiste |
| 27-06-2026 | D-055 — AuditEvent comme historique des changements post-déploiement |
| 28-06-2026 | D-056 — Règle d'or : toute mutation de Mission passe par un service d'application |
| 29-06-2026 | D-057 — MissionEligibilityService : source de vérité unique pour l'éligibilité |
| 04-07-2026 | D-058 — Email policy redesign : un seul email de déploiement par destinataire (amende D-053) |
| 06-07-2026 | D-059 — MissionClaim historique seule + MissionEligibilityService mission-centrique (précise D-057) |
| 06-07-2026 | D-060 — Photo de profil : optionnelle à l'onboarding, rappel proactif après connexion |
| 12-07-2026 | D-061 — MAIL_SAFE_MODE : garde-fou centralisé contre l'envoi accidentel d'emails réels |
| 12-07-2026 | D-062 — Réaction automatique aux absences sur les missions déjà déployées (libération/annulation) |
| 13-07-2026 | D-063 — Modification sécurisée de l'adresse email par un manager/admin + double notification |
| 15-07-2026 | D-064 — Démarrage automatique des missions (ASSIGNED → IN_PROGRESS) sur startAt |
| 15-07-2026 | D-065 — Timezone : l'API renvoyait un offset faux pour startAt/endAt (superseded par D-066) |
| 15-07-2026 | D-066 — Correction structurelle du timezone à l'hydratation Doctrine (business_datetime_immutable) |
| 16-07-2026 | D-067 — Catalogue financier des firmes : prestations non liantes, moteur indépendant (Lot 1) |
| 17-07-2026 | D-068 — Rattachement de l'encodage au référentiel InterventionType (Lot 5) + InterventionTypeRequest |
| 17-07-2026 | D-070 — Workflow métier de l'encodage : cycle de vie explicite, verrouillage backend, commentaires historisés (Lot 7) |
| 18-07-2026 | D-071 — Modèle Mission/MissionExecution/FinancialCalculation ; InstrumentistService → MissionExecution (Exécution & Valorisation, Lot 1) |
| 18-07-2026 | D-072 — Historisation des tarifs : PricingRule append-only, validTo exclusif, InstrumentistRate (Exécution & Valorisation, Lot 2) |
| 18-07-2026 | D-073 — FinancialCalculation : valorisation figée, append-only, versionnée par mission (Exécution & Valorisation, Lot 3) |
| 18-07-2026 | D-074 — Bascule des documents financiers vers FinancialCalculationLine, anti-double facturation, legacy coexistant (Exécution & Valorisation, Lot 4) |
| 18-07-2026 | D-075 — Paiements append-only, solde toujours dérivé, émission explicite, Payment polymorphe (Exécution & Valorisation, Lot 5) |
| 19-07-2026 | D-076 — Corrections financières additives : notes de crédit/débit, remboursements append-only, jamais de réécriture (Exécution & Valorisation, Lot 6) |
| 19-07-2026 | D-077 — Statistiques financières : sources de vérité par catégorie, convention temporelle/devise, cash flow réel dérivé de (documentType, direction) (Pilotage financier, Lot 7) |
| 19-07-2026 | D-078 — FirmController aligné sur BillingVoter::MANAGE (ROLE_ADMIN = ROLE_MANAGER partout) ; système d'aide contextuelle par écran (HelpButton/HelpDrawer/registry) |
| 19-07-2026 | D-079 — Réorganisation navigation manager : sidebar groupée, Dashboard, fusion Prestations/Demandes, errata D-048 (suppression Planning V1 mort) |
| 20-07-2026 | D-080 — Justification obligatoire si aucun matériel encodé, deux colonnes distinctes commentaire/flag (précise D-070, Lot 7) |
| 25-07-2026 | D-081 — Fiabilisation du socle Web Push : endpoint unique par abonnement, rattachement mono-utilisateur, nettoyage au logout, activation volontaire pour tous les rôles, erreurs isolées mais observables (Lot 1) |
| 25-07-2026 | D-082 — Installation PWA Android/iOS : bannière + `beforeinstallprompt`, guide manuel iOS (animation recréée en React, jamais copiée), source de vérité standalone, politique de report, séparation stricte avec le Push (Lot 2) |
| 26-07-2026 | D-083 — Rappel unique d'encodage D+1 à 08 h Europe/Brussels : remplace le rappel 19 h jamais implémenté, Push prioritaire avec repli email, idempotence persistante (`encodingReminderSentAt`), aucune relance quotidienne |
| 26-07-2026 | D-084 — Historique centralisé des notifications sortantes : `OutboundNotification`/`OutboundNotificationAttempt`, snapshot du contenu, statuts honnêtes (`SENT` ≠ lu), fallback Push→email tracé, écran ADMIN, rétention 12 mois documentée |

---

## TODO — Suivi de la revue fonctionnelle « Espace instrumentiste »

*(Ajouté le 24-07-2026 — suivi de tâches, distinct des ADR numérotées ci-dessus.)*

Revue fonctionnelle menée en 4 catégories (écran d'encodage, onglet Offres, navigation,
planning instrumentiste) : **tous les points sont clôturés**, à l'exception de deux
fonctionnalités de notification push, volontairement différées au chantier général sur
les notifications push (traité dans une autre conversation).

### Terminé

- **Écran d'encodage** : résumé intermédiaire supprimé ; bouton « Démarrer l'encodage »
  absent ; heures prestées optimistes et persistantes (source unique
  `mission-execution`) ; firme sélectionnée avant l'intervention ; recherche dynamique
  des firmes ; type d'intervention en `select` ; intervention et matériel affichés de
  façon optimiste ; bouton « Terminer l'encodage » sticky ; intervention provisoire
  visible et utilisable ; matériel hors catalogue visible immédiatement ; photo du
  chirurgien (encodage et onglet Offres) ; heures correctes dans le récapitulatif final
  et dans la fiche instrumentiste.
- **Navigation** (commit `1cf1c90`) : un nouvel onglet démarre toujours en haut ; chaque
  route conserve sa propre position de scroll pendant la session ; revenir sur un onglet
  restaure sa dernière position ; les positions sont réinitialisées au montage initial de
  `MobileLayout` (nouvelle entrée dans l'espace instrumentiste) ; l'ouverture d'un
  encodage mémorise la route et le scroll d'origine ; la flèche retour y ramène avec
  restauration du scroll ; un accès direct à l'encodage utilise un repli stable
  (`/app/i/today`) ; la position restaurée est bornée à la hauteur réellement disponible.
  Logique centralisée dans `frontend/src/app/layouts/scrollRestoration.ts`.
- **Planning instrumentiste** (commit `60db90f`) : missions passées non soumises dans
  « À encoder », missions futures dans « À venir » ; missions déjà soumises absentes des
  deux sections ; aucun doublon entre sections ; états vides corrects ; clic sur une
  mission « À encoder » ouvre directement `/app/i/missions/{id}/encoding` (aucune modale
  intermédiaire) ; flèche retour vers le planning d'origine avec scroll restauré ;
  comportement des missions futures inchangé. Classification via
  `classifyPlanningMission()` dans `PlanningPage.tsx`.

### Ouvert — dépend du chantier général sur les notifications push

Ces deux fonctionnalités sont les seules tâches fonctionnelles encore ouvertes de la
revue instrumentiste. Elles seront traitées dans une autre conversation, après le
travail général sur les notifications push. **L'implémentation devra réutiliser
l'infrastructure push existante ou celle validée dans ce chantier général — aucun
second système parallèle ne doit être créé.**

#### TODO — Rappel d'encodage à 19 h — SUPERSEDED par D-083 (26-07-2026)

**Ce TODO n'a jamais été implémenté et ne le sera pas tel quel.** La décision produit a
changé : plus de rappel le jour même à 19 h, remplacé par un rappel unique le lendemain
matin à 08 h (D+1), avec repli email si le Push n'est pas livrable. Voir D-083 pour la
règle actuelle. Conservé ci-dessous pour l'historique uniquement.

À 19 h, heure métier du projet, envoyer une notification push à l'instrumentiste
lorsqu'une mission du jour qui lui est assignée n'a pas encore été soumise.

Contraintes à conserver pour l'implémentation future :

- uniquement les missions assignées à l'instrumentiste ;
- encodage encore modifiable ou en attente de soumission ;
- mission correspondant à la journée métier concernée ;
- exclusion des missions futures, annulées, rejetées, soumises ou validées ;
- réutilisation de `MissionEncodingGuard` ou des `allowedActions` ;
- timezone métier, jamais UTC brut ni heure du navigateur ;
- au maximum un rappel par mission, destinataire et journée ;
- traitement idempotent ;
- lien profond vers `/app/i/missions/{id}/encoding`.

Contenu envisagé :

- Titre : *Encodage à terminer*
- Message : *L'encodage de votre mission du jour n'a pas encore été soumis.*
- Inclure si possible : le nom de l'établissement, l'heure de la mission.

#### TODO — Notification au chirurgien après soumission

Après la soumission définitive de l'encodage par l'instrumentiste, envoyer une
notification push récapitulative au chirurgien concerné.

Contraintes à conserver :

- déclenchement uniquement après une soumission réellement persistée ;
- destinataire = chirurgien de la mission ;
- contenu récapitulatif utile ;
- lien profond vers la mission ou son récapitulatif ;
- déduplication ;
- aucune notification sur une tentative échouée ou annulée.

---

## D-081 — Fiabilisation du socle Web Push : endpoint unique, rattachement mono-utilisateur, nettoyage au logout, activation volontaire pour tous les rôles, erreurs isolées mais observables (Lot 1)

Date : 25-07-2026

### Contexte

L'audit PWA/push du 24-07-2026 a confirmé que le Web Push fonctionne sur le chemin
nominal mais a identifié huit défauts structurels du socle : aucun nettoyage au logout,
réattribution silencieuse d'un endpoint entre utilisateurs sur un navigateur partagé,
socle push absent de `DesktopLayout` (chirurgiens/managers/admins ne pouvaient jamais
s'abonner), erreurs frontend/backend pratiquement invisibles, contrainte
`UNIQUE(endpoint)` déclarée côté entité mais absente de la migration réelle, aucun test
fonctionnel du contrôleur d'abonnement, aucun test du vrai `WebPushService`, et un envoi
push synchrone dans `MissionController::publish()`. Ce lot fiabilise l'infrastructure
existante avant d'implémenter l'installation PWA, les préférences sélectives, le rappel
de 19 h et les mises à jour applicatives (tous explicitement hors périmètre ici).

### Décision — un abonnement = un endpoint, rattaché à un seul utilisateur à la fois

Un `PushSubscription` modélise *navigateur/profil × service worker registration ×
utilisateur SurgicalHub actuellement rattaché*. `endpoint` est désormais réellement
`UNIQUE` en base (voir migration ci-dessous, pas seulement déclaré côté entité).
`PushSubscriptionController::subscribe()` (idempotent, upsert par `endpoint`) distingue
trois cas :

- **endpoint inconnu** → création ;
- **endpoint déjà rattaché au même utilisateur** → mise à jour des clés, aucun doublon
  (resoumission silencieuse du navigateur au reload, cas courant) ;
- **endpoint rattaché à un autre utilisateur** → réattribution explicite et **toujours
  journalisée** (`push.subscription_reassigned`, `warning`, avec l'ancien et le nouvel
  `user_id` — jamais l'endpoint complet). Ce cas devient rare une fois le nettoyage au
  logout en place (ci-dessous) ; il reste la voie d'auto-guérison pour les sessions
  expirées sans logout explicite, plutôt qu'un `409` qui bloquerait un appareil partagé
  sans bénéfice réel (personne d'autre que l'utilisateur courant ne peut légitimement
  vouloir cet endpoint au moment du `subscribe()`).

### Décision — nettoyage au logout, best-effort, jamais bloquant

`AuthContext::logout()` appelle `detachCurrentPushSubscription(accessToken)`
(`pushSubscriptionClient.ts`) **avant** de nettoyer le stockage local, avec le token
capturé de façon synchrone (il peut avoir disparu du storage au moment où la résolution
de la subscription navigateur aboutit). L'appel est fire-and-forget : une erreur réseau,
un timeout ou une session déjà expirée ne doivent jamais retarder la révocation du
refresh token, le nettoyage local, ni la redirection vers `/login`. Un détachement raté
s'auto-corrige au prochain `subscribe()` via la voie de réattribution ci-dessus — aucune
complexité multi-compte par appareil n'a été introduite dans ce lot.

Scénario bout en bout (couvert par test, réparti backend/frontend, cf. §9/§10) :
utilisateur A connecté → endpoint X rattaché à A → logout A (détachement serveur
best-effort) → utilisateur B se connecte sur le même navigateur → `subscribe()` rattache
explicitement X à B → A ne reçoit plus aucune notification sur X.

### Décision — le socle réagit à l'identité de session, pas seulement à `isAuthenticated`

`PushProvider` est monté une seule fois, à la racine authentifiée de l'app, **au-dessus
du `Router`** (`main.tsx` : `<BrowserRouter><AppProviders>...`) — il ne démonte donc
jamais lors d'une navigation `/login ↔ /app/...`, contrairement à l'ancien hook qui vivait
dans `MobileLayout` (un composant de route, qui démonte réellement à cette navigation et
réinitialisait donc son état par construction). Une revue pré-commit a révélé qu'un garde
basé uniquement sur `isAuthenticated` (booléen) ne peut pas distinguer une **session**
d'une autre : sur un navigateur partagé, `A connecté → logout → B connecté` dans le même
onglet, sans rechargement complet, laisse `isAuthenticated` à `true` tout du long — un
garde "une seule fois par montage du provider" bloquerait alors le rattachement de B.

**Correctif :** le provider suit désormais l'identité réelle de la session
(`state.status === "authenticated" ? state.user.id : null`, pas seulement le booléen), et
compare cette identité à celle déjà traitée (`processedUserId`, `useRef`) à chaque rendu.
Toute transition d'identité (`null → A`, `A → B` directement même si `isAuthenticated`
reste vrai tout du long, `A → null`) déclenche :

1. **`→ null` (logout ou jamais connecté)** — recalcule le statut à partir de l'état
   réel du navigateur (`refreshStatus()`), sans jamais conserver artificiellement le
   `status` de la session précédente, sans révoquer la subscription navigateur (déjà
   gérée par `AuthContext::logout()` ci-dessus) et sans jamais demander la permission.
2. **`→ un utilisateur (A ou B)`** — relit `Notification.permission` en direct :
   `denied` → `permission-denied` (jamais de Sentry, refus normal) ; `default` →
   `permission-default` (le point d'entrée d'activation réapparaît, aucune demande
   automatique) ; `granted` → réabonnement idempotent (`subscribeToPush()`), qui
   réutilise la subscription navigateur existante si elle est déjà là (elle n'a jamais
   été révoquée par le logout précédent) et la rattache explicitement au nouvel
   utilisateur côté serveur (upsert par `endpoint`, cf. réattribution ci-dessus).

Une même identité de session ne déclenche jamais un second appel (le `ref` empêche tout
double traitement, y compris sous double-invoke React StrictMode — même mécanisme que
`swRegistered` pour l'enregistrement du service worker).

### Décision — activation volontaire, disponible pour tous les rôles

Deux choses distinctes, à ne pas confondre :

- **Le socle `PushProvider`** (état, réabonnement, service worker) est monté une seule
  fois à la racine authentifiée de l'app (`AppProviders.tsx`) et disponible, en tant que
  *capacité technique*, pour n'importe quel composant de l'arbre — `MobileLayout` et
  `DesktopLayout` y ont accès de façon strictement équivalente. C'est ce que ce lot livre
  et que `PushProvider.test.tsx` couvre génériquement ("disponible dans n'importe quel
  shell").
- **Le point d'entrée visuel** qui expose réellement ce socle à l'utilisateur diffère
  selon le fichier : `MobileLayout` affiche un bandeau discret (« Activer ») quand
  `status === "permission-default"` — ce point d'entrée est isolable du reste du fichier
  (revue pré-commit, patch de 3 lignes contre HEAD) et fait partie de ce commit. Le
  point d'entrée `DesktopLayout` (item de menu « Activer les notifications ») existe
  dans le code actuel mais est **imbriqué dans une refonte de navigation manager plus
  large et hors périmètre** (nouveau menu compte `Menu`/`MenuItem` qui n'existe pas dans
  HEAD) — il n'est **pas isolable proprement** de cette refonte et **ne fait donc pas
  partie du commit Lot 1**. Il sera livré avec le commit de la refonte de navigation qui
  le rend possible. Avant ce commit de suivi, les rôles chirurgien/manager/admin ont
  accès au socle technique (enregistrement SW, réabonnement automatique si la permission
  est déjà accordée) mais pas encore à un point d'entrée visuel pour l'activer
  volontairement une première fois depuis `DesktopLayout`.

L'enregistrement du service worker est centralisé et protégé contre les enregistrements
concurrents (`swRegistered` ref, idempotence native de `serviceWorker.register()`). La
permission n'est **jamais** demandée automatiquement au montage — la demande ne part que
d'une action utilisateur explicite (`subscribe()`), quel que soit le point d'entrée qui
l'invoque.

### Décision — erreurs isolées mais observables

`WebPushService::sendToSubscriptions()` ne journaliait auparavant qu'un `warning`
générique pour tout échec, expiré ou non. Désormais : abonnement expiré (404/410) →
suppression automatique inchangée, `info` (`push.subscription_expired`) ; tout autre
échec (VAPID invalide, quota, erreur transitoire du service push) → `error`
(`push.send_failed`), routé vers Sentry via le handler `sentry` (type `service`,
`Sentry\Monolog\LogToSentryIssueHandler`, seuil `ERROR`, ajouté à
`config/packages/monolog.yaml`/`services.yaml`, no-op si `SENTRY_DSN` est vide). Un
échec d'envoi individuel n'interrompt jamais le traitement des abonnements suivants
(isolation déjà en place, désormais couverte par test — voir
`WebPushServiceTest`). Aucun endpoint complet ni clé (`p256dh`, `auth`) n'apparaît jamais
dans un log : `WebPushService::hintForLog()` ne conserve que les premiers/derniers
caractères (`https://push.example/…xyz`), et `PushSubscriptionController` ne journalise
que des `user_id`.

### Décision — migration `UNIQUE(endpoint)`, additive

Audit préalable (dev + test, `surgicalhub`/`surgicalhub_test`, `dbal:run-sql`) : `0`
ligne dans `push_subscription` dans les deux environnements. Aucun doublon à nettoyer →
migration strictement additive (`Version20260724222527`, `ALTER TABLE push_subscription
ADD CONSTRAINT uniq_push_subscription_endpoint UNIQUE (endpoint)`). Non appliquée en
production dans le cadre de ce lot.

### Décision — envoi push synchrone dans `MissionController::publish()` : dette documentée, non traitée dans ce lot

`MissionController::publish()` appelle `WebPushService::sendToSiteInstrumentists()` de
façon synchrone, dans la requête HTTP du manager qui publie une mission — contrairement
au reste des envois push post-déploiement, tous asynchrones via
`MissionLifecycleChangedMessage`/`MissionLifecycleChangedMessageHandler` (D-056, D-043 :
routage Messenger obligatoire pour tout traitement IO-intensif). `publish()` agit sur une
mission encore `DRAFT` (pré-déploiement) — un domaine distinct de celui couvert par
`MissionChangeType` (`CLAIMED`/`RELEASED`/`REASSIGNED`/`CANCELLED`/`TIME_CHANGED`), qui ne
connaît que les transitions post-déploiement. Réutiliser tel quel ce message pour
`publish()` aurait exigé d'étendre l'enum et le handler à un domaine qu'ils ne couvrent
pas aujourd'hui — au-delà de la fiabilisation du socle visée par ce lot. Décision : **ne
pas migrer à moitié**. L'appel synchrone reste en l'état, documenté ici comme dette
technique connue ; `WebPushService::sendToSubscriptions()` reste néanmoins désormais
protégé par le même isolement d'erreurs que le reste du socle (une notification en échec
n'empêche pas la réponse HTTP de `publish()` de réussir). Sous-lot proposé, hors
périmètre de ce lot : introduire un message dédié (p. ex. `MissionPublishedMessage`) routé
async, en cohérence avec D-043/D-056, plutôt que d'étendre `MissionChangeType`.

### Tests

- `PushSubscriptionControllerTest` (fonctionnel, 17 tests) : AuthZ, `vapid-public-key`,
  validation stricte du payload, création/idempotence/rafraîchissement de clés,
  réattribution explicite entre deux utilisateurs, unsubscribe idempotent et scopé au
  propriétaire, les 4 rôles (instrumentiste/chirurgien/manager/admin) gèrent leur propre
  abonnement.
- `WebPushServiceTest` (unitaire, 9 tests) : envoi réussi, échec isolé sans blocage des
  abonnements suivants, suppression sur 404/410, absence de suppression sur erreur
  temporaire (503/500), log d'erreur avec raison/type, aucune exception ne remonte au
  code appelant, aucun endpoint complet ni clé dans les logs — via un vrai
  `GuzzleHttp\Handler\MockHandler` injecté dans `WebPush` (seam `httpClientOptions`),
  pas un double de `WebPushServiceInterface`.
- `PushProvider.test.tsx`, `pushSubscriptionClient.test.ts`, `AuthContext.test.tsx`,
  `MobileLayout.push.test.tsx` (frontend) : support/permission/état, enregistrement SW
  unique, auto-réabonnement silencieux par identité de session (§ ci-dessus),
  `subscribe()`/`unsubscribe()`, détachement au logout avec token capturé et non
  bloquant, bannière mobile pilotée par `status`, aucune exception Sentry sur un refus
  normal de permission. `MobileLayout.push.test.tsx` est délibérément séparé de
  `MobileLayout.test.tsx` (refonte de navigation, hors périmètre) afin que les deux
  puissent être committés indépendamment.

### Addendum (validation réelle sur appareil, 25-07-2026) — `VAPID_SUBJECT` ne doit jamais utiliser un domaine `.local`

Test réel sur iPhone (Safari, PWA installée) : Apple (`web.push.apple.com`) rejetait
systématiquement l'envoi avec `403 BadJwtToken`, alors que FCM (Android) acceptait le
même envoi sans erreur. Audience JWT, format de clé, algorithme ES256 et fenêtre
d'expiration étaient tous conformes (vérifiés dans `minishlink/web-push` v10.0.3, qui
dérive l'audience automatiquement par endpoint) — seule variable en cause :
`VAPID_SUBJECT=mailto:admin@surgicalhub.local`. Apple valide strictement le claim `sub`
du JWT et rejette le TLD `.local` (réservé, non résolvable) ; FCM ne le valide pas, d'où
un échec silencieux propre à iOS. Corrigé en `mailto:notifications@surgicalhub.be` (même
boîte que `MAILER_FROM_ADDRESS`, réellement surveillée) dans `backend/.env` et
`backend/.env.prod.local.example` — confirmé par bascule `403` → `201` puis réception
réelle sur l'appareil. `WebPushService` refuse désormais tout `VAPID_SUBJECT` invalide
(`.local` ou format inattendu) dès la construction du service, avec un message
explicite ; couvert par 3 tests dans `WebPushServiceTest`.

---

## D-082 — Installation PWA Android/iOS : bannière + `beforeinstallprompt`, guide manuel iOS, source de vérité standalone, politique de report (Lot 2)

Date : 25-07-2026

### Contexte

Le manifest (`frontend/public/manifest.json`) et le service worker (`frontend/public/sw.js`,
déjà enregistré globalement par `PushProvider`, D-081) rendaient SurgicalHub techniquement
installable depuis longtemps, mais rien ne le proposait jamais à l'utilisateur — aucune
bannière, aucun guide, aucun point d'entrée. Un design complet (bannière + guide iOS 4
temps « Partager → Sur l'écran d'accueil → Ajouter → Installé », variante Android native)
avait été produit séparément (`handoff-install-guide/`, Claude Design) ; ce lot l'intègre
au frontend de production sans le redessiner ni recréer les assets d'un autre format que
celui déjà designé.

### Décision — socle unique, même principe que Push (D-081)

`PwaInstallProvider` est monté une seule fois à la racine authentifiée
(`AppProviders.tsx`, à côté de `PushProvider`), jamais dupliqué par layout. Il capture
`beforeinstallprompt` (avec `preventDefault()`, jamais de mini-infobar Chrome par défaut)
et `appinstalled` sans jamais déclencher de prompt automatiquement — seule une action
utilisateur explicite (bannière ou point d'entrée manuel) appelle `deferredPrompt.prompt()`.
L'événement est à usage unique : la référence est supprimée après le premier `prompt()`,
qu'il soit accepté, refusé, ou en erreur (capturée par Sentry, tag `feature: "pwa-install"`
— jamais silencieuse).

### Décision — la détection standalone est la seule source de vérité sur l'installation

`window.matchMedia('(display-mode: standalone)')` (Android/desktop) et
`navigator.standalone` (iOS, jamais reconnu par `matchMedia` sur WebKit) — jamais dérivée
d'un état local ("J'ai compris" ne signifie pas installé, voir plus bas). `appinstalled`
recalcule cet état et efface toute la politique de report d'un coup
(`clearDismissalState()`) : une fois installée, l'application ne repropose plus jamais
rien automatiquement, et le point d'entrée manuel (§ ci-dessous) affiche
« Application installée ».

### Décision — deux parcours par plateforme, jamais un troisième inventé

- **Android/Chromium (bannière + `beforeinstallprompt`)** : bouton "Installer" déclenche
  directement le prompt natif du navigateur — SurgicalHub ne recrée jamais son interface
  (non personnalisable), conformément à la recommandation du handoff design.
- **iOS (bannière + guide manuel)** : aucune API ne permet de déclencher l'installation par
  script sur iOS — jamais de bouton "Installer" sur ce parcours. Le guide reprend la
  Variante B du handoff (bottom sheet, recommandée — texte, copie et timings d'animation
  exacts du handoff), qui bascule en dialogue centré ≥ 900px (même mécanisme responsive
  que `SheetModal.tsx`, réutilisé en styles mais pas en composant — voir plus bas).
- Détection iOS : UA `iPad|iPhone|iPod`, plus le cas iPadOS 13+ qui usurpe l'UA "Mac"
  (distingué via `navigator.maxTouchPoints > 1`, seul signal fiable pour un vrai Mac non
  tactile).

### Décision — animation recréée en React, jamais copiée depuis le handoff

Le fichier de référence (`handoff-install-guide/iOS Install Guide.dc.html`) est une
maquette exécutable de conception (son propre README le précise explicitement : « Design
uniquement — pas de logique d'affichage/détection réelle. À recréer en React dans le
codebase de production, pas à copier tel quel »). `IosStepAnimation.tsx` recrée les 4
étapes (Partager/Ajouter à l'écran d'accueil/Confirmer/Installé) en composants MUI `Box`,
en reprenant à l'identique : les noms et timings des animations CSS du handoff
(`iigFade`, `iigHalo` 1.6s, `iigTap` 1.2–1.4s, `iigGlow` 1.4–1.8s, `iigPop` 0.5s, cycle de
2.4s par étape), la copie exacte des textes, et la palette de couleurs déjà utilisée
ailleurs dans le projet (`MobileLayout.tsx`/`DesktopLayout.tsx`) — aucune couleur inventée
hors charte. Les fichiers `ios-frame.jsx`/`android-frame.jsx` du handoff (coques de
téléphone décoratives, marquées "pas à réutiliser en prod" dans leur propre en-tête) ne
sont pas repris.

`prefers-reduced-motion: reduce` : le cycle automatique s'arrête entièrement (reste figé
sur la première étape), sans qu'aucune information ne devienne inaccessible — les 3
étapes textuelles du guide (`IosInstallGuideModal`) sont un `<ol>` statique, **jamais**
conditionné par l'état de l'animation, donc toujours lisibles avec ou sans elle
(exigence accessibilité du lot).

### Décision — `IosInstallGuideModal` n'est pas une réutilisation de `SheetModal`

`SheetModal.tsx` (composant partagé par tout l'écran d'encodage) suit déjà le même motif
responsive (bottom sheet mobile / dialogue centré desktop) mais n'implémente ni focus
trap ni fermeture au clavier (`Échap`) — un gap préexistant, sur un composant partagé par
le reste de l'application. Le corriger pour ce seul lot aurait élargi son périmètre bien
au-delà de l'installation PWA ; `IosInstallGuideModal` est donc un composant dédié,
reprenant les mêmes valeurs d'animation/rayon/ombre que `SheetModal` pour la cohérence
visuelle, mais avec son propre conteneur et son propre `useFocusTrap` (module
`pwa-install/`, non partagé) : focus posé sur le premier élément focusable à l'ouverture,
`Tab`/`Maj+Tab` bouclent à l'intérieur, `Échap` ferme (`outcome: "later"`), le focus
revient au déclencheur à la fermeture. `role="dialog"`, `aria-modal`, `aria-labelledby`,
`aria-describedby` sur le conteneur.

### Décision — politique de report (localStorage)

```
surgicalhub.pwaInstall.dismissedAt
surgicalhub.pwaInstall.dismissCount
surgicalhub.pwaInstall.completedGuide
```

1er report ("Plus tard" ou "J'ai compris") → pas de nouvelle proposition automatique avant
7 jours ; 2e → 14 jours ; 3e et suivants → plus jamais automatiquement. **« J'ai compris »
applique la même règle que « Plus tard »** — ce n'est pas une confirmation d'installation,
seulement un accusé de lecture qui referme et reporte ; la détection standalone reste seule
source de vérité, jamais court-circuitée par ce champ. Stockage par navigateur
(`localStorage`, nativement scopé par origine) ; dégrade silencieusement en "toujours
proposer" si le stockage est indisponible (navigation privée stricte), jamais une erreur
bloquante. Le point d'entrée permanent (ci-dessous) n'est **jamais** soumis à cette
politique — disponible quel que soit le nombre de reports.

### Décision — point d'entrée permanent, page profil et menu compte

`usePwaInstallMenuState()` centralise la logique état→libellé→action, consommée par
`PwaInstallMenuItem` (page profil instrumentiste, `ProfilePage.tsx`) et par un `MenuItem`
dédié dans le menu compte de `DesktopLayout` (même état, deux rendus différents — jamais
un composant partagé forcé dans deux systèmes de menu hétérogènes). Bannière automatique
réservée à `MobileLayout` (montée une fois, jamais dupliquée) : le design fourni est
pensé téléphone, et une bannière d'installation dans le tableau de bord manager desktop
n'aurait pas de sens produit — le point d'entrée manuel du menu compte reste, lui,
disponible pour tous les rôles, y compris desktop (prompt natif si disponible).

### Décision — séparation stricte avec Web Push (D-081)

`PwaInstallProvider` n'importe rien de `features/push/` et ne demande jamais la
permission de notification depuis la bannière ou le guide — les deux parcours (installer
l'application, autoriser les notifications) restent déclenchés indépendamment, par des
actions utilisateur distinctes. `PushProvider` n'a pas été modifié par ce lot.

### Manifest et service worker — état déjà conforme

Audité, non modifié : `manifest.json` a déjà `name`, `short_name`, `start_url`,
`display: "standalone"`, `theme_color`, `background_color`, et des icônes `192`/`512`
déclinées en `purpose: "any"` et `"maskable"` — tous les critères d'installabilité Chrome
sont déjà réunis, tous les fichiers icônes référencés existent sur disque. `sw.js` est
déjà enregistré globalement (via `PushProvider`, D-081) avant même l'authentification —
aucun changement n'était nécessaire pour ce lot. Aucun cache offline, aucune logique
`install`/`activate`/`fetch` n'a été ajoutée (explicitement hors périmètre).

### Portée non traitée ici

Préférences sélectives de notifications, rappel 19 h, escalade J+1, cache offline,
versioning applicatif, mise à jour forcée du service worker, refonte de
`NotificationsPage` — aucun n'a été commencé.

---

## D-083 — Rappel unique d'encodage D+1 à 08 h Europe/Brussels, Push prioritaire avec repli email

Date : 26-07-2026

### Contexte

Remplace le TODO "rappel d'encodage à 19 h" (jamais implémenté, voir plus haut) par une
décision produit différente : plus de relance le jour même, un seul rappel le lendemain
matin. Le socle Web Push (D-081) et l'installation PWA (D-082) sont désormais validés en
conditions réelles (Android et iOS), ce lot construit dessus sans y toucher.

### Décision — règle métier

Une mission est éligible au rappel uniquement si, au moment de l'exécution :

- un instrumentiste lui est assigné ;
- son `endAt` planifié tombe dans le jour civil précédent, en Europe/Brussels
  (`business_datetime_immutable`, jamais UTC brut) ;
- elle n'est pas soumise (`submittedAt IS NULL`) ;
- elle n'est pas verrouillée (`encodingLockedAt`/`invoiceGeneratedAt` tous deux `NULL`) ;
- son statut fait encore partie des statuts où l'encodage est soumissible
  (`ASSIGNED`, `IN_PROGRESS`, `ENCODING_IN_PROGRESS`, `DECLARED` — liste blanche, exclut
  implicitement `REJECTED`/`CANCELLED`/`SUBMITTED`/`VALIDATED`/`CLOSED`/`OPEN`/`DRAFT`) ;
- aucun rappel ne lui a déjà été envoyé (`encodingReminderSentAt IS NULL`).

Le rappel n'est jamais envoyé avant 08 h Europe/Brussels — `SendEncodingRemindersCommand`
refuse d'agir (aucun appel au service) si l'heure locale au moment de l'exécution est
antérieure à 08 h, indépendamment de la fréquence réelle du cron serveur. Cette garde
interne, plutôt qu'une confiance aveugle dans l'heure du cron, absorbe tout décalage
été/hiver si le serveur est planifié en UTC fixe (voir docs/production.md).

### Décision — canal : Push prioritaire, email uniquement si Push n'est pas livrable

`WebPushService::sendToUser()` restait `void` et avalait silencieusement tout résultat
(aucune subscription trouvée, échec de transport, etc.) — insuffisant pour décider d'un
repli. Ajout de `sendToUserAndReportSuccess()` (nouvelle méthode publique, pas dans
`WebPushServiceInterface` — même précédent que `sendToSiteInstrumentists()`) qui retourne
`true` seulement si au moins un rapport de transport final est positif. `sendToUser()`
délègue désormais à cette méthode et ignore simplement son retour — comportement
inchangé pour tous les appelants existants.

`EncodingReminderService::processMission()` : Push tenté en premier ; s'il n'est pas
livrable (aucune subscription, toutes expirées, tous les envois échouent), repli email
via `NotificationService::missionEncodingReminderNotifyInstrumentist()` (nouveau
template `emails/mission_encoding_reminder.html.twig`, même mécanisme asynchrone
Messenger que le reste du projet). Jamais les deux canaux à la fois.

### Décision — idempotence : réservation atomique, pas de dépendance aux logs

Nouveau champ `Mission.encodingReminderSentAt` (nullable, migration additive
`Version20260726090000`) — au plus un rappel par mission, par construction. La
réservation utilise une `UPDATE ... WHERE encoding_reminder_sent_at IS NULL` en DQL
(bulk update, atomique côté moteur SQL) plutôt qu'un verrou applicatif Symfony Lock :
si `0` ligne est affectée, une exécution concurrente a déjà réclamé cette mission, celle-ci
est ignorée (`skipped`) sans effet de bord. Choix délibéré plutôt que `NotificationEvent`
seul : cette table n'a aucune contrainte unique aujourd'hui (limite déjà documentée dans
D-056/`MissionLifecycleChangedMessageHandler`, "accepted V1 limit") — insuffisant pour
l'exigence stricte "au plus un rappel, jamais deux" de ce lot.

**Compromis assumé** : la réservation a lieu *avant* la tentative d'envoi, pas après. Si
l'envoi échoue après la réservation (exception inattendue), le rappel de cette mission
est perdu plutôt que retenté le lendemain — préféré à l'alternative (envoyer puis
marquer), qui risquerait un double envoi si le processus meurt entre les deux étapes. Le
repli email n'étant qu'un dépôt en file Messenger (échoue seulement en cas de panne
d'infrastructure), ce risque de perte reste faible en pratique. Documenté ici plutôt que
« résolu » : accepté comme limite connue, pas un bug.

### Décision — orchestration : commande + service, aucun message Messenger dédié

`SendEncodingRemindersCommand` (`app:notifications:send-encoding-reminders`) orchestre
uniquement (garde horaire, boucle, isolation par mission, résumé) ; toute la décision
vit dans `EncodingReminderService`. Contrairement à
`MissionLifecycleChangedMessage`/Handler (D-043/D-056, transitions de statut
post-déploiement), ce lot est un balayage temporel, pas une mutation déclenchée par une
action utilisateur — étendre `MissionChangeType` pour ce cas aurait été le même genre de
"migration à moitié" déjà rejeté pour `MissionController::publish()`. Un appel direct
`WebPushService`/`NotificationService` depuis la commande suffit, à l'image de
`AbsenceReminderController`.

Pas de service `Clock` injecté : aucune abstraction de ce type n'existe encore dans ce
projet. La commande expose une méthode `now()` `protected`, surchargeable dans les tests
(seul point d'injection ajouté), plutôt que d'introduire une nouvelle dépendance
transverse pour un seul appelant.

### Tests

- `EncodingReminderServiceEligibilityTest` (intégration, base réelle, 10 tests) : chaque
  critère de sélection isolé (terminée hier/aujourd'hui, soumise, annulée, rejetée, sans
  instrumentiste, déjà rappelée), plus heure d'été/hiver Bruxelles et frontière exacte à
  minuit — un mock de `QueryBuilder` ne peut pas vérifier une clause `WHERE`, seule une
  exécution réelle le peut (même rationale que `MissionEligibilityServiceFindEligibleTest`,
  RC1-D/E). Piège rencontré et documenté dans le test lui-même : construire les dates de
  test sans fuseau explicite déplace silencieusement l'instant d'1-2 h au moment de
  l'écriture (`BusinessDateTimeImmutableType::convertToDatabaseValue()` appelle
  `setTimezone(Brussels)`, une vraie conversion, pas un simple réétiquetage).
- `EncodingReminderServiceTest` (unitaire, 10 tests) : canal (Push seul, repli email,
  jamais les deux), idempotence (réservation atomique, deux appels sur la même mission,
  deux missions indépendantes), contenu (aucune donnée patient, texte non culpabilisant).
- `SendEncodingRemindersCommandTest` (unitaire, 8 tests) : résumé exact, isolation d'une
  erreur par mission, logs sans donnée sensible, garde 08 h (avant/pile/été/hiver).
- `NotificationServiceEncodingReminderTest` (unitaire, 2 tests) : email envoyé avec
  uniquement prénom + URL mission dans le contexte, no-op si mission sans instrumentiste.
- `WebPushServiceTest` (+3 tests) : `sendToUserAndReportSuccess()` — vrai si au moins un
  envoi réussit, faux si aucune subscription, faux si tous les envois échouent.

### Portée non traitée ici

Écran de préférences détaillées, cache offline, résilience `pushsubscriptionchange`
(gap réel identifié pendant la validation Push, non corrigé — voir le rapport de
diagnostic Android/iOS du 25-07-2026), notifications manager, escalade répétée J+2/J+3,
modification de l'encodage ou des règles financières — aucun n'a été commencé.

---

## D-084 — Historique centralisé des communications sortantes (Push + email), ADMIN uniquement

Date : 26-07-2026

### Contexte

Le socle Push (D-081), l'installation PWA (D-082) et le rappel D+1 (D-083) sont
committés et validés en conditions réelles. Aucune trace persistante des envois n'existe
jusqu'ici — `WebPushService` et `SendTemplatedEmailMessageHandler` journalisent (Monolog)
mais rien n'est requêtable ni consultable par un admin. Ce lot ajoute cette traçabilité,
sans toucher aux règles métier des lots précédents.

### Décision — deux entités, jamais une ligne partagée entre canaux

`OutboundNotification` (une ligne par communication sortante — Push OU email) +
`OutboundNotificationAttempt` (append-only, une ligne par tentative réelle de transport).
Un repli Push → email crée une **seconde** `OutboundNotification`, liée à la première via
`fallbackOf`/`fallbackReason` — jamais un statut composite écrasant les deux canaux sur
une même ligne. `payload` est toujours nettoyé (liste blanche stricte
`missionId`/`planningVersionId`/`url`/`notificationType`) avant persistance —
`OutboundNotificationService::cleanPayload()` ; n'importe quelle autre clé fournie par un
appelant (donnée patient incluse) est silencieusement rejetée, jamais persistée.

**N'écrit jamais** : endpoint Push complet, `p256dh`, `auth`, JWT, clé VAPID, secret
SMTP, mot de passe, donnée patient. `OutboundNotificationAttempt.provider` (`FCM` /
`APPLE` / `OTHER` / `SMTP`) est dérivé du host de l'endpoint, jamais de l'endpoint
lui-même. `reason` est toujours normalisée : `WebPushService::normalizeReason()` extrait
un code court depuis la réponse du fournisseur — minishlink/web-push embarque l'endpoint
complet dans son propre message d'erreur (`Client error: POST https://...`), donc le
logger `push.send_failed` existant loggait déjà cette fuite avant ce lot ; corrigé au
passage dans le même fichier, même log. Idem côté email :
`OutboundNotificationEmailFailureListener::normalizeThrowableMessage()` retire toute
sous-chaîne ressemblant à une URI (susceptible de contenir le DSN SMTP avec mot de
passe) avant persistance.

### Décision — statuts honnêtes, `SENT` ne veut jamais dire « lu »

`QUEUED` / `SENT` / `FAILED` / `SKIPPED` uniquement. Pas de `DELIVERED`/`OPENED`/
`READ`/`CLICKED` — aucune preuve technique de lecture n'existe pour Push ou email dans
ce projet (pas de pixel de tracking, explicitement hors périmètre). L'interface ADMIN
affiche systématiquement l'avertissement « accepté par le fournisseur ne garantit pas
que le message a été lu » à côté de tout statut `SENT`.

### Décision — Push : agrégation depuis le détail par abonnement

`WebPushService::sendToUser()` restait `void`, `sendToUserAndReportSuccess()` (D-083)
ne retournait qu'un bool agrégé — insuffisant pour tracer une ligne par tentative
réelle. Nouvelle méthode `sendToUserWithAttempts()` (concrète uniquement, pas dans
`WebPushServiceInterface` — même précédent que `sendToSiteInstrumentists()`) qui
retourne le détail complet (`provider`, `success`, `statusCode`, `reason` normalisée)
par abonnement, en réutilisant `sendToSubscriptions()` en interne (son type de retour
change de `int` à `array{sent, attempts}`, mais reste `private` : aucune API publique
existante n'est modifiée). `OutboundNotificationService::recordPushSend()` agrège :
`SENT` si au moins un envoi réussit, `FAILED` si tous échouent, `SKIPPED` si aucun
abonnement — même logique que D-083, désormais tracée.

### Décision — email : `QUEUED` avant dispatch, jamais un nouvel enregistrement par retry

`SendTemplatedEmailMessage` transporte un `outboundNotificationId` optionnel (nullable —
rétrocompatible avec tous les appelants antérieurs à D-084 : invitations, absences,
factures). La ligne `OutboundNotification` est créée `QUEUED` **avant** le dispatch
Messenger (`OutboundNotificationService::recordEmailQueued()`), pour que son id circule
dans le message et que le handler/listener mettent à jour la **même** ligne à chaque
tentative plutôt que d'en créer une nouvelle. `bodyText`/`bodyHtml` restent `null` à la
mise en file : ce projet rend Twig dans le handler asynchrone, pas au moment du dispatch
— le contenu réellement envoyé n'est donc connu qu'après coup et rétro-rempli par
`recordEmailAttempt()` au succès.

Le passage `FAILED` n'a lieu que quand Messenger a épuisé ses tentatives : aucun état de
retry n'est lisible depuis l'intérieur d'un handler dans ce projet (confirmé par
recherche — pas de lecture de `RedeliveryStamp` existante), donc `FAILED` prématuré
serait dishonnête pour un échec encore en attente de retry. Solution :
`OutboundNotificationEmailFailureListener`, sur `WorkerMessageFailedEvent`
(`#[AsEventListener]`, même pattern que `MailSafeModeListener`) — `!$event->willRetry()`
seul déclenche `FAILED` ; sinon la ligne reste `QUEUED` et une nouvelle
`OutboundNotificationAttempt` est simplement ajoutée.

### Décision — autorisation : `OutboundNotificationVoter`, ROLE_ADMIN strict

Nouveau Voter dédié (`OUTBOUND_NOTIFICATION_LIST`/`_VIEW`), pas de palier MANAGER
(contrairement à `UserAdministrationVoter::UPDATE_EMAIL`) — l'historique complet des
communications, y compris leur contenu texte/HTML, est jugé plus sensible qu'une
opération de gestion ponctuelle. `AdminOutboundNotificationController` suit exactement
le style déjà établi par `AdminAuditController` (filtres manuels, `AdminResponseTrait`
pour le nom d'affichage du destinataire) — avec une différence délibérée : `total` est
un vrai `COUNT()` (`OutboundNotificationRepository::findForAdmin()`, deux requêtes),
pas `count($page)` comme le fait `AdminAuditController` aujourd'hui (limite déjà
documentée sur cette ligne, non corrigée ici — hors périmètre).

### Décision — interface ADMIN : aperçu HTML dans une iframe sandboxée, pas de nouvelle dépendance

Aucune librairie de sanitization HTML n'existe dans ce projet (vérifié avant
d'implémenter). Plutôt que d'ajouter `dompurify` pour un seul écran, l'aperçu HTML d'un
email s'affiche dans une `<iframe sandbox="">` — sandbox vide : aucun script, aucun accès
same-origin, aucun formulaire, aucune popup. Le texte brut (`bodyText`) reste l'onglet
par défaut. Page `AdminOutboundNotificationsPage` (tableau + filtres, convention
`AdminAuditPage`) et `AdminOutboundNotificationDrawer` (détail en panneau latéral,
convention `AdminUserDrawer` — MUI `Drawer`, pas `SheetModal.tsx` qui est réservé aux
parcours mobiles d'encodage).

**Entrée de menu non branchée** : `DesktopLayout.tsx` reste mêlé à la refonte de
navigation en cours (même constat que pour les lots Push/PWA précédents) — route et
page créées et fonctionnelles (`/app/admin/outbound-notifications`), entrée de menu
volontairement non ajoutée pour ne pas contaminer ce commit avec des hunks de navigation
non liés. À brancher avec le lot navigation.

### Décision — rétention : documentée, pas implémentée

Politique initiale : contenu complet conservé 12 mois, métadonnées minimales au-delà
(durée à définir dans un lot ultérieur). Aucune purge automatique dans ce lot — ni tâche
planifiée, ni commande, ni logique de suppression. Documenté ici comme dette assumée,
pas comme un oubli.

### Tests

- `OutboundNotificationServiceTest` (unitaire, 15 tests) : agrégation Push (un succès
  suffit, aucun abonnement → `SKIPPED`, tout échoue → `FAILED`), jamais d'endpoint dans
  une tentative, `cleanPayload()` (liste blanche stricte), cycle email `QUEUED` →
  `SENT`/`FAILED`, échec transitoire ≠ `FAILED`, tentatives cumulées sans écrasement,
  `fallbackReasonFor()` (NO_SUBSCRIPTION/EXPIRED/ALL_FAILED). A révélé deux bugs réels
  pendant l'écriture (voir ci-dessous).
- `WebPushServiceTest` (+4 tests) : `sendToUserWithAttempts()` — provider correct par
  host, raison normalisée sans fuite d'endpoint, subscription expirée marquée `expired`.
- `SendTemplatedEmailMessageHandlerTest` (unitaire, 2 tests) : succès enregistré avec le
  contenu réellement rendu ; aucun appel au service si `outboundNotificationId` est nul
  (rétrocompatibilité des appelants antérieurs).
- `OutboundNotificationEmailFailureListenerTest` (unitaire, 5 tests) : retry en attente
  ≠ `FAILED`, épuisement des tentatives → `FAILED`, message ignoré si pas de
  `SendTemplatedEmailMessage`/pas d'id, jamais de DSN/mot de passe dans la raison
  persistée.
- `AdminOutboundNotificationControllerTest` (fonctionnel, base réelle, 10 tests) : RBAC
  (ADMIN 200, MANAGER/INSTRUMENTIST 403, non authentifié 401), pagination avec vrai
  total, filtres canal/statut, détail complet avec tentatives, 404 sur id inconnu,
  aucun endpoint/clé/secret dans la réponse HTTP brute.
- `EncodingReminderNotificationHistoryTest` (intégration, base réelle, 4 tests) : preuve
  de bout en bout que le rappel D+1 (D-083) produit exactement la trace attendue — Push
  réussi = une seule ligne, aucun email ; Push impossible/échoué = ligne Push +
  ligne email liée (`fallbackOf`) avec la bonne raison ; deuxième exécution = aucune
  trace supplémentaire (idempotence déjà garantie par D-083, revérifiée au niveau
  historique).
- Frontend : `AdminOutboundNotificationsPage.test.tsx` (8 tests — chargement, vide,
  erreur, filtre, recherche debouncée, ouverture du détail, avertissement lecture,
  pagination/total réel) et `AdminOutboundNotificationDrawer.test.tsx` (7 tests —
  contenu texte, détail de tentative Push, contenu email + aperçu HTML sandboxé, repli
  affiché avec sa raison, avertissement lecture, aucune donnée sensible, id nul = aucun
  appel réseau).

**Deux bugs réels trouvés par les tests, corrigés avant commit** :
1. `recordPushSend()`/`recordEmailAttempt()` construisaient chaque
   `OutboundNotificationAttempt` avec `setNotification()` mais sans jamais appeler
   `OutboundNotification::addAttempt()` — la collection en mémoire restait vide dans la
   même requête (la ligne SQL était correcte après un `flush()` réel, mais
   `fallbackReasonFor()`, appelée dans le même appel que `recordPushSend()`, aurait
   toujours vu une collection vide et renvoyé `NO_SUBSCRIPTION` même pour un vrai échec
   `ALL_FAILED`/`EXPIRED`). Corrigé : les deux méthodes utilisent désormais
   `addAttempt()`, et la relation `OneToMany` porte `cascade: ['persist']`.
2. Les deux entités avaient leurs callbacks `#[ORM\PrePersist]` sans l'attribut de
   classe `#[ORM\HasLifecycleCallbacks]` requis pour que Doctrine les enregistre —
   `created_at` (et `started_at`) seraient restés `NULL` sur **toute** ligne en
   production. Invisible dans les tests unitaires (EntityManager mocké, aucun `flush()`
   réel n'y déclenche jamais un vrai `PrePersist`) ; découvert uniquement par le premier
   test fonctionnel en base réelle.

### Portée non traitée ici

Statistiques avancées, export CSV, relance manuelle d'un envoi, renvoi d'une
notification, modification des préférences utilisateur, accusé de lecture artificiel,
tracking d'ouverture email par pixel, purge automatique, entrée de menu (voir ci-dessus)
— aucun n'a été commencé.

---

## D-085 — Rotation des clés VAPID compromises, séparation stricte dev/test/prod

**Constat** : `backend/.env` (versionné depuis la mise en place du Web Push, commit
`a91ce6b`) contenait une vraie paire de clés VAPID de développement en clair.
`backend/tests/Unit/Service/WebPushServiceTest.php` (commit `dd5cad8`) réutilisait
exactement la même paire comme constante de test, avec un commentaire ambigu
("Reused as-is from .env (dev-only, non-secret)") laissant entendre à tort qu'une
clé d'environnement réel était acceptable dans un test. La clé étant versionnée,
elle est considérée compromise même après rotation — elle reste lisible dans
l'historique Git (`git log -S` sur les deux fichiers ci-dessus).

**Correctif** :
- `backend/.env` : `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` remplacés par `CHANGE_ME`
  (`backend/.env.prod.local.example` l'était déjà). `VAPID_SUBJECT` corrigé au passage
  vers le domaine réel (`mailto:notifications@surgicalhub.be`) — l'ancienne valeur
  `.local` aurait reproduit le rejet Apple `BadJwtToken` documenté en D-081.
- Nouvelle paire de développement générée (`Minishlink\WebPush\VAPID::createVapidKeys()`),
  stockée uniquement dans `backend/.env.local` (déjà ignoré par `backend/.gitignore` —
  `/.env.local`, `/.env.*.local` — aucun nouveau mécanisme introduit). Toute subscription
  Push existante sur un appareil de dev est invalidée par cette rotation ; les
  notifications doivent être réactivées sur ces appareils.
- `backend/.env.test` reçoit désormais sa propre paire dédiée, générée une seule fois et
  volontairement commise (jamais réutilisée en dev/prod, sujet `mailto:test@surgicalhub.invalid`,
  domaine `.invalid` — RFC 2606 — pour qu'elle ne puisse jamais ressembler à un domaine
  réel). Avant ce lot, les tests s'appuyaient sur le repli implicite de `.env` (aucune
  variable VAPID dans `.env.test`) — c'est ce repli qui avait permis à la vraie clé de dev
  d'être copiée dans un test au lieu qu'une paire dédiée soit générée.
- `WebPushServiceTest.php` : constantes remplacées par la paire de test dédiée,
  commentaire corrigé ("Test-only VAPID key pair. Never use in dev or production."),
  et nouveau test `test_vapid_constants_are_not_the_leaked_dev_keypair()` comparant par
  empreinte SHA-256 (jamais la valeur brute) pour empêcher toute réintroduction future de
  l'ancienne paire compromise.
- Nouveau garde-fou d'architecture `NoRealVapidKeyCommittedTest.php` : vérifie que
  `backend/.env`, `backend/.env.dev` et `backend/.env.prod.local.example` ne contiennent
  jamais autre chose que `CHANGE_ME` pour ces deux variables — volontairement restreint à
  ces trois fichiers pour ne jamais bloquer `.env.test` (paire dédiée légitime) ni un
  fichier local ignoré.

**Rotation production** : préparée (procédure documentée, jamais exécutée dans ce lot) —
nouvelle paire à générer séparément, à écrire uniquement dans
`/opt/stack/apps/surgicalhub/.env` sur le serveur, distincte des paires dev et test.
Toute rotation invalide les subscriptions Push existantes des utilisateurs réels ; prévoir
qu'ils devront réactiver les notifications après le déploiement de la nouvelle paire.

**Historique Git** : la clé de dev compromise reste lisible dans l'historique
(`a91ce6b`, `dd5cad8`) — non réécrit dans ce lot (`git filter-repo`/`filter-branch`
affecteraient tous les clones et tags existants, décision hors périmètre local). La
rotation seule (ci-dessus) suffit à rendre l'ancienne clé inutilisable : une purge
d'historique reste une option séparée, à planifier explicitement si l'exposition
historique doit être supprimée du dépôt lui-même.

---

## D-086 — Les notifications internes deviennent lisibles côté serveur (GET /api/notifications, seenAt enfin écrit), avec parité manager/admin ; le cache localStorage instrumentiste est retiré, GET /api/notifications devient l'unique source de vérité pour les deux rôles (audit PWA/mobile/admin, Lot 3 — révisé lors de la revue post-rapport du 29-07-2026)

Date : 29-07-2026

### Contexte

`NotificationEvent` (D-... Batch 7) était persistée à chaque événement métier pertinent
mais n'était lue par aucune API — `seenAt` existait en base, avec un groupe de
sérialisation `notification:read` prêt, mais `setSeenAt()` n'était appelé nulle part
dans le code (grep confirmé). L'unique affichage utilisateur (cloche + badge + écran
"Notifications") était un cache `localStorage` alimenté uniquement par les messages
`postMessage` du service worker au moment d'un push — perdu au changement
d'appareil/navigateur, jamais synchronisé avec le backend, et réservé à l'instrumentiste
(`MobileLayout.tsx`) : manager/admin n'avaient ni cloche, ni badge, ni historique.

### Décision — GET/POST /api/notifications, scopé `#[CurrentUser]`, sans Voter dédié

Même convention que `PushSubscriptionController` (D-081) : une notification
n'appartient qu'à son destinataire, la portée `#[CurrentUser]` suffit à l'autoriser.
`GET /api/notifications` (liste + `unreadCount`), `GET /api/notifications/unread-count`
(badge seul), `POST /api/notifications/{id}/seen` et `POST
/api/notifications/mark-all-seen` (idempotents). Voir §41.1 de `docs/api.md`.

### Décision — parité manager/admin, pas une nouvelle architecture parallèle

`frontend/src/app/pages/manager/NotificationsPage.tsx` (nouvelle route
`/app/m/notifications`) et l'entrée badge dans `DesktopLayout.tsx` (sidebar, pas de
topbar dans ce layout) sont entièrement backés par le nouvel endpoint serveur — pas de
cache local côté manager, contrairement à l'instrumentiste. Un même hook
(`useNotificationsFeed`) et un même formatter (`notificationFormat.ts`, qui dérive un
titre/corps lisible depuis `eventType`+`payload` car `NotificationEvent` ne stocke aucun
texte préformaté) servent les deux écrans.

### Décision révisée — le cache local instrumentiste est retiré, pas seulement augmenté

Choix initial (Lot 3) : garder `useNotifications`/`notifications.store.ts`
(localStorage, alimenté par le service worker) pour l'affichage instantané côté
instrumentiste, en synchronisant seulement `seenAt` côté serveur en plus — jugé plus
prudent qu'une réécriture, avec plusieurs fichiers de tests déjà verts dessus. Limite
explicitement actée à l'époque : deux systèmes coexistants, jamais unifiés.

**Révision (revue post-rapport, 2026-07-29) :** ce compromis maintenait deux sources
de vérité pour la même donnée (l'historique affiché restait local, potentiellement
différent du serveur), contraire à l'exigence "le backend doit être la source de
vérité finale, jamais `localStorage`". `NotificationsPage.tsx` (instrumentiste) est
réécrite pour utiliser `useNotificationsFeed()` — exactement le même hook que la page
manager — et `features/push/notifications.store.ts` + `useNotifications.ts` sont
**supprimés** (plus aucun consommateur, vérifié par recherche exhaustive avant
suppression). Le nudge temps réel (perdu avec le retrait du cache) est reconstruit
autrement, sans réintroduire d'état local persistant : `PushProvider` écoute toujours
les messages `PUSH_NOTIFICATION` du service worker, mais réagit désormais par un toast
éphémère (`useToast().info(...)`) + une invalidation immédiate de la query
`["notifications"]`, forçant un refetch serveur au lieu d'un affichage depuis un cache
local. `OffersPage.tsx` perd son appel `addNotification(...)` (créait une entrée
locale de confirmation à la prise de mission) — le `toast.success()` déjà présent
couvre le même besoin de feedback immédiat, sans persistance nécessaire (le backend ne
crée d'ailleurs aucun `NotificationEvent` pour l'auto-confirmation d'une action de
l'utilisateur sur sa propre mission).

### Décision — préférences par catégorie enfin lisibles/modifiables

`NotificationPreference` (Batch 15A) avait un resolver de défauts
(`DefaultNotificationPreferenceResolver`) déjà utilisé par l'envoi, mais aucune UI.
`GET`/`PATCH /api/me/notification-preferences[/{type}]` (MeController, même convention
self-scope que `POST /api/me/offers-seen`) exposent les 14 `NotificationType` avec leurs
canaux résolus ; un `PATCH` partiel ne matérialise une ligne que pour le canal
explicitement modifié, amorcée depuis les valeurs déjà résolues (jamais de régression
silencieuse des canaux non touchés). Le canal `push` n'y figure pas : il dépend d'un
abonnement d'appareil réel (D-081), pas d'un booléen par catégorie.

### Décision — les 4 états de permission push, désormais partagés

`PushPermissionCard` factorise les 4 branches (`permission-default` / `subscribed` /
`permission-denied` / `unsupported`) auparavant dupliquées uniquement dans
`manager/ProfilePage.tsx` (l'instrumentiste n'en avait aucune). Le cas
`permission-denied` explique la marche à suivre par plateforme (iOS : Réglages système ;
Android : paramètres navigateur/app installée ; desktop : paramètres du site) — jamais
de bouton prétendant réactiver seul une permission bloquée, un site web ne peut pas
rouvrir la demande native après un refus permanent.

---

## D-087 — Le badge "offres non lues" devient un compteur serveur filtré sur `User.offersLastSeenAt`, jamais une valeur locale (audit PWA/mobile/admin, Lot 6)

Date : 29-07-2026

### Contexte

Le badge de la nav instrumentiste (`MobileLayout.tsx`) affichait
`offersData?.items?.length` — le nombre total de missions `OPEN` actuellement éligibles.
Visiter l'écran Offres ne le faisait jamais redescendre : ce n'était pas un cumulatif au
sens "ne fait qu'augmenter" (une offre claim/annulée sortait bien du compte au prochain
`refetchInterval`), mais un simple inventaire, sans aucune notion de lecture.

### Décision — checkpoint serveur, réutilise la logique d'éligibilité existante

`User.offersLastSeenAt` (nullable, migration `Version20260729140000`) posé uniquement
par `POST /api/me/offers-seen` (appelé une fois par montage réussi d'`OffersPage`,
jamais à la simple ouverture de l'application). `GET
/api/missions/offers/unread-count` réutilise `MissionService::list()` avec
`eligibleToMe=true` (même filtre d'éligibilité que `GET /api/missions`, aucune
duplication de règle métier) plus un filtre additionnel programmatique
`createdAfter` (`MissionFilter::$createdAfter`, volontairement absent de
`fromQuery()` — pas de nouveau paramètre public sur l'endpoint générique, seulement une
construction directe du DTO côté contrôleur dédié). `offersLastSeenAt = null` (jamais
consulté) → aucun filtre de date, comportement identique à avant ce lot pour un nouvel
utilisateur.

### Décision — deux compteurs distincts, pas un seul réutilisé à double usage

`MobileLayout.tsx` distingue désormais `offersCount` (total disponible, alimente le
sous-titre "X offres correspondent à vos disponibilités") de `offersUnreadCount`
(nouveau, alimente le badge nav). Un badge de nouveauté et un total affiché sont deux
informations différentes ; les confondre aurait recréé le bug d'origine sous un autre
nom.

---

## D-088 — `change-role` accepte `ROLE_ADMIN` comme cible (+ commande console dédiée `app:user:promote-to-admin`) ; `resend-invitation` gagne un anti-spam sous verrou pessimiste réel (pas juste applicatif) et une résilience email (audit PWA/mobile/admin, Lot 7 — étendu lors de la revue post-rapport du 29-07-2026)

Date : 29-07-2026

### Contexte

`POST /api/admin/users/{id}/change-role` rejetait `ROLE_ADMIN` comme cible
("Invalid role") — `ROLE_TO_SITE_ROLE` ne le liste pas, et le DTO
`AdminChangeRoleRequest` avait sa propre liste `Assert\Choice` sans `ROLE_ADMIN`. Aucune
procédure contrôlée n'existait donc pour promouvoir un compte existant en administrateur
autrement qu'une édition SQL manuelle. Séparément, `resendInvitation()` n'avait aucun
garde-fou anti-spam (un admin pouvait renvoyer en boucle, chaque appel invalidant le
token précédent avant que l'instrumentiste ait pu l'utiliser) et propageait toute
exception d'envoi email sans filet — le token était déjà régénéré/flushé avant l'envoi,
laissant l'ancien lien mort et le nouveau jamais envoyé si le transport échouait.

### Décision — ROLE_ADMIN valide, sans siteRole canonique, jamais de doublon

`UserAdministrationService::changeRole()` accepte `ROLE_ADMIN` explicitement (hors de
`ROLE_TO_SITE_ROLE`, qui reste dédié aux 3 rôles ayant un `siteRole` réel). Les
`SiteMembership` existantes de la cible sont conservées telles quelles (ni supprimées,
ni relabellisées avec un rôle inventé) — cohérent avec `ROLES_REQUIRING_SITE`, où
ADMIN/MANAGER n'ont jamais de site requis. `setRoles()` s'applique à l'entité déjà
managée : un `UPDATE` au flush, jamais un `INSERT`, donc aucun risque de doublon de
compte. Pas d'exécution en production dans ce lot — voir §28.7 de `docs/api.md` pour la
procédure exacte préparée (identifier le compte, authentifier un admin, appeler
l'endpoint), couverte par
`tests/Functional/AdminUserControllerChangeRoleAndResendInvitationTest.php`.

### Décision — cooldown de 60s indexé sur `invitationLastSentAt`, jamais un état local

Choix d'un délai fixe (60s) plutôt qu'un compteur de tentatives ou une fenêtre
glissante complexe — suffisant pour absorber un double-clic ou une rafale de clics sans
gêner un renvoi légitime après correction d'un problème. Le champ réutilisé
(`User.invitationLastSentAt`) n'est mis à jour qu'après un envoi email **réussi**
(`NotificationService::sendUserInvitation`), donc un échec transitoire du transport ne
bloque jamais un nouvel essai immédiat — le cooldown protège contre le spam, pas contre
la résilience.

### Décision — l'échec d'envoi ne fait plus échouer la requête (même politique que `createUser`)

`sendUserInvitation()` + l'audit + le flush associé sont désormais dans un bloc
`try/catch` : en cas d'échec, seul un `warning` est journalisé — le token régénéré reste
en place (l'admin peut simplement relancer), et ni l'audit "resent" ni
`invitationLastSentAt` ne sont mis à jour (cohérent : l'email n'a pas réellement été
renvoyé).

### Décision (revue post-rapport, 2026-07-29) — le cooldown tourne sous verrou pessimiste réel, pas un simple check applicatif

Le cooldown ci-dessus, tel que livré initialement, relisait `invitationLastSentAt` puis
écrivait plus loin dans la même méthode sans aucune protection contre l'exécution
concurrente — deux requêtes HTTP simultanées pouvaient toutes deux lire "pas de renvoi
récent" avant qu'aucune n'ait écrit (race *check-then-act* classique), produisant deux
tokens, deux emails, deux écritures d'`invitationLastSentAt`. `resendInvitation()`
enveloppe désormais le contrôle + la mutation dans
`EntityManager::wrapInTransaction()` avec `EntityManager::lock($target,
LockMode::PESSIMISTIC_WRITE)` (même pattern déjà utilisé par
`AbsenceMissionReactionService`/`DocumentPaymentService`/`FinancialCalculationService`
— `SELECT ... FOR UPDATE` sur la ligne `user`), qui sérialise deux requêtes concurrentes
pour le même utilisateur. Le verrou est relâché (commit) **avant** l'envoi de l'email —
l'I/O lente (dispatch Messenger) ne retient jamais un verrou de ligne. Preuve
d'intégration réelle avec deux connexions MySQL indépendantes (pas seulement un mock) :
`tests/Functional/ResendInvitationConcurrencyTest.php`.

Clarification corollaire : `invitationLastSentAt` horodate un **dispatch réussi vers
Messenger** (transport asynchrone), jamais une livraison SMTP confirmée — la doc et les
docblocks (`User::$invitationLastSentAt`, `NotificationService::sendUserInvitation()`)
sont mis à jour en conséquence pour ne jamais laisser croire à un accusé de réception.

### Décision (revue post-rapport, 2026-07-29) — commande console dédiée pour ROLE_ADMIN, plutôt qu'une migration avec l'email en dur

`App\Command\PromoteUserToAdminCommand` (`app:user:promote-to-admin <email>
<actorEmail>`) réutilise `UserAdministrationService::changeRole()` telle quelle (aucune
règle métier dupliquée) : recherche insensible à la casse
(`findOneByEmailInsensitive`), jamais de création de compte, idempotente (no-op auditée
si la cible est déjà `ROLE_ADMIN`), acteur obligatoirement un `ROLE_ADMIN` existant
(traçabilité réelle, jamais un pseudo-acteur "système"), garde-fou anti-auto-promotion
vérifié en priorité (avant même le contrôle de rôle de l'acteur). Pensée pour un
déploiement (`docker exec surgicalhub-php php bin/console app:user:promote-to-admin ...
--env=prod`, même convention que les commandes déjà documentées dans
`docs/production.md`) sans jamais committer d'adresse email personnelle dans une
migration Doctrine. Testée unitairement (mock des dépendances) dans
`tests/Unit/Command/PromoteUserToAdminCommandTest.php` — n'exécute rien contre la
production dans ce lot.

---

## D-090 — Planning V2 : revalidation des absences au déploiement, correction du bug de fuseau horaire en mode Modification, sémantique du diff par mission, renvoi manuel du planning (audit Planning V2, 2026-07-31)

Date : 2026-07-31

### Contexte

Audit demandé sur quatre anomalies rapportées en production : (1) des instrumentistes
en congé restaient affectés à des postes dans le planning publié ; (2) un modal de
redéploiement en mode Modification annonçait « 32 modifications » après seulement 3
éditions réelles ; (3) un chirurgien absent laissait son poste affiché « à pourvoir »
au lieu d'être neutralisé ; (4) aucune action ne permettait de renvoyer manuellement
son planning à un utilisateur précis.

### Décision — cause racine unique pour les anomalies 1 et 3 : fenêtre génération→déploiement non revalidée

`PlanningGeneratorServiceV2::preview()`/`generate()` excluent déjà correctement les
absences **au moment de la génération** (`isAbsentFast()`, requête fraîche). Le trou
se situe entre génération et déploiement : `AbsenceMissionReactionService` ne réagit
qu'aux missions déjà publiées (`ASSIGNED`/`OPEN`), jamais aux `DRAFT` d'une version non
déployée ; `AbsenceImpactService` lève bien une alerte informative pour les `DRAFT`,
mais rien ne bloquait le déploiement pour autant — `PlanningDeploymentService::deploy()`
publiait le brouillon tel quel, sans revalider les absences actuelles au moment du clic
« Déployer ».

**Correctif** : nouveau `PlanningDraftRevalidationService`, appelé par `deploy()` avant
toute mutation. Passe en lecture seule sur les missions `DRAFT` de la version :
- **Chirurgien absent** → mission neutralisée (statut `CANCELLED`, réutilisé — aucun
  nouveau statut créé, cohérent avec `AbsenceMissionReactionService::processSurgeonAbsence()`
  qui applique déjà cette règle aux missions post-déploiement). L'éventuel instrumentiste
  déjà affecté est retiré et notifié via le pipeline `MissionLifecycleChangedMessage`
  existant (`MissionPostDeployService::cancel()`, dont le garde-fou de statut accepte
  désormais aussi `DRAFT`, en plus d'`OPEN`/`ASSIGNED`).
- **Instrumentiste absent** (chirurgien présent) → **blocage total du déploiement**
  (`PlanningDraftConflictException`, HTTP 409 `DRAFT_CONFLICTS` avec le détail structuré
  de chaque conflit — mission, date, site, chirurgien, instrumentiste). Choix délibéré de
  bloquer plutôt que d'exclure silencieusement la seule mission concernée : un planning
  partiellement publié sans intention explicite du manager est exactement le mode
  d'échec que cette revalidation doit fermer.

Nouvelle règle centrale : `AbsenceOverlapService::isUserAbsentDuring()` — chevauchement
jour-inclusif, cohérent avec les trois implémentations préexistantes (générateur,
réaction aux absences, impact) qui restent inchangées mais vérifiées sémantiquement
identiques.

**Limite assumée** : la revalidation couvre les absences (instrumentiste + chirurgien),
pas l'état du poste (`SurgeonSchedulePost.active`) ni les exceptions de planning
ajoutées après génération — `Mission` n'a aucune référence directe vers le poste qui
l'a générée, une revalidation de ces axes nécessiterait un changement de modèle de
données plus large, hors périmètre de cet audit.

### Décision — anomalie 2 : deux bugs distincts dans `PlanningModificationService`

**Bug de fuseau horaire (D-066 violé)** — `combineDateTime()` construisait
`new \DateTimeImmutable("{date}T{heure}:00")` sans fuseau explicite. Interprété par PHP
avec le fuseau conteneur par défaut, **UTC** (confirmé : aucun `date.timezone` dans les
images Docker dev/prod). `Mission::getStartAt()`/`getEndAt()` sont toujours étiquetées
Europe/Brussels par `BusinessDateTimeImmutableType`. Le frontend renvoyant toutes les
lignes du mois (pas seulement les éditées), chaque ligne non touchée comparait deux
représentations du même horaire affiché dans des fuseaux différents (jusqu'à 2h
d'écart en été) → `scheduleChanged` devenait vrai pour quasiment toutes les missions.
**Conséquence plus grave que le comptage** : `MissionPostDeployService::updateSchedule()`
persistait la valeur mal étiquetée — chaque mission touchée voyait son horaire
réellement décalé de 1h (hiver) ou 2h (été) en base à l'écriture, pas seulement à
l'affichage. Corrigé en étiquetant explicitement Europe/Brussels, comme
`PlanningGeneratorServiceV2::generate()` le fait déjà. Audit exhaustif des autres
constructions `new \DateTimeImmutable(...)` du périmètre Planning V2 : aucun autre bug
trouvé — les autres usages portent sur des colonnes `date_immutable`/`time_immutable`
(pas de fuseau au niveau du stockage) ou des paramètres de requête liés en type
générique `Types::DATETIME_IMMUTABLE` (impression des chiffres sans `setTimezone()`,
contrairement au type métier).

**Clé de correspondance du diff** — même après le fix fuseau, `PlanningDiffService`
indexait par clé composite (site/chirurgien/type/date/heure-arrondie-15min), pensée
pour comparer deux **versions différentes**. Appliquée à un auto-diff (même version,
même mission, avant/après édition), un changement d'horaire fait changer la clé
elle-même → « ancien créneau disparu + nouveau créneau apparu » (2 changements) au lieu
d'une modification. Corrigé par une nouvelle méthode `computeDiffByMissionId()`, utilisée
exclusivement par `PlanningModificationService::apply()` (le diff par clé composite
reste inchangé pour `diff()`/`computeDiff()`, toujours utilisés pour comparer deux
versions distinctes — ne pas les fusionner).

**Risque sur les données historiques** : techniquement possible pour toute mission
ayant transité par `apply()` avant ce correctif. Commande d'audit en lecture seule
livrée : `app:planning:audit-modification-timezone-shifts` (compare les chiffres
horaires capturés dans les événements d'audit `MISSION_TIME_CHANGED_POST_DEPLOY` à
l'heure actuellement stockée, signale un écart de exactement 1h/2h comme signature
probable — jamais une correction automatique). Exécutée en développement local : zéro
événement de ce type trouvé, rien à signaler. **Non exécutée en production** dans cet
audit (contrainte explicite de ne pas y toucher) — recommandé avant tout déploiement de
ce correctif.

### Décision — sémantique du nombre de modifications

`apply()` retourne désormais trois nombres distincts, dérivés du diff (jamais du
comptage de mutations techniques `created`/`updated`/`cancelled`/`released`, conservé
séparément pour l'historique) : `functionalChanges` (missions ajoutées + supprimées +
modifiées dans le diff — jamais `updatedAt`, l'ordre des tableaux, ou une ligne
renvoyée inchangée par le frontend), `usersNotified`, `emailsSent` (toujours égaux
aujourd'hui — un email par personne réellement concernée, jamais plus, jamais de
doublon puisqu'un rôle exclut l'autre et que chaque destinataire n'apparaît qu'une
fois par famille). `PlanningChangeSummaryService::sendChangeSummaryEmails()` retourne
désormais la liste réelle des destinataires notifiés plutôt que `void`, pour que ce
comptage reflète ce qui a été réellement envoyé.

### Décision — renvoi manuel du planning (anomalie fonctionnelle 1)

`POST /api/planning/versions/{id}/resend/{userId}` (`PlanningResendService`) : refuse
toute version non `ACTIVE` (jamais un brouillon), charge les missions réellement
publiées de la version pour cet utilisateur (jamais un diff, jamais dépendant d'un
changement récent), réutilise exactement les mêmes templates PDF/email et le même
sujet que l'email de déploiement initial (`PlanningDeployPdfsMessageHandler` — pas de
format divergent), un seul destinataire jamais de fan-out, `PlanningVoter::PLANNING_MANAGE`
(même RBAC que le reste du module). Enregistre systématiquement un `NotificationEvent`
(nouveau type `PLANNING_RESENT_MANUAL`) avec statut `SENT`/`FAILED` et l'erreur
éventuelle.

### Tests

16 tests fonctionnels nouveaux (`PlanningModificationTimezoneTest` — été/hiver sans
dérive, édition réelle = 1 changement exact, 32 lignes envoyées/1 éditée = 1 changement ;
`PlanningDeployAbsenceRevalidationTest` — blocage instrumentiste absent, neutralisation
chirurgien absent, bornes exactes, absences sans chevauchement ; `PlanningResendControllerTest`)
+ 6 tests unitaires nouveaux pour la commande d'audit, tous verts. Deux régressions
réelles (attendues, conséquences directes des changements intentionnels) trouvées et
corrigées dans les tests existants : `PlanningDeploymentServiceTest` (nouveau paramètre
constructeur) et `MissionPostDeployServiceTest` (le test qui attendait un rejet sur
`DRAFT` attend désormais un succès). Un bug caché révélé par la correction du fuseau
dans la fixture de `PlanningModificationControllerTest` (même défaut que le bug de
production) — corrigé pour utiliser Europe/Brussels partout. Suite complète : frontend
96/96 fichiers · 823/823 tests verts (inchangé) ; backend 1638 tests, ~57 échecs
préexistants et sans rapport avec ce lot : 6 tests de suppression d'absence instables
selon l'ordre d'exécution, non liés à ce chantier ; ~51 tests financiers en échec à
cause d'une collision de numérotation de facture `FIRM-2026-219` due à l'accumulation
de données dans la base de test locale partagée — reproductible même isolément,
domaine financier jamais touché ici (détail des deux catégories ci-dessous).

### Tests préexistants hors périmètre (non corrigés dans ce chantier)

**6 variantes de suppression d'absence, instables selon l'ordre d'exécution** —
échouent lorsqu'elles tournent regroupées avec d'autres suites, passent systématiquement
en isolation. Reproduction : `docker exec surgicalhub-php-1 sh -c "cd /var/www/backend
&& php bin/phpunit --filter AbsenceControllerTest"` → 14/14 verts en isolation, contre
échec observé dans un run groupé de 12 fichiers plus tôt dans cette session (trace
capturée : `Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException` sur
`notification_event.user_id` pendant `AbsenceMissionReactionService::onAbsenceDeleted()`).
Hypothèse de cause racine : état résiduel accumulé entre tests dans la base de test
partagée (pas de transaction par test, `tearDown()` manuel), sans déclencheur fixe
identifié — 3 tentatives de reproduction du run groupé original ont toutes échoué à
reproduire l'échec (40/40, 239/239, 14/14 verts), confirmant une instabilité réelle liée
à l'ordre/au timing plutôt qu'un bug déterministe. Aucun des fichiers touchés par ce
chantier n'importe ni n'appelle de code lié à ces suites. **Ne pas corriger dans ce
chantier** — nécessite un travail dédié de nettoyage/isolation des tests, non entamé ici.

**~51 échecs financiers, collision `FIRM-2026-219`** — reproduction :
`docker exec surgicalhub-php-1 sh -c "cd /var/www/backend && php bin/phpunit
tests/Functional/FirmInvoiceServiceLot1AdaptationTest.php"`, échoue seul, avec la même
erreur `Duplicate entry 'FIRM-2026-219' for key ...` à chaque exécution — cause racine
unique confirmée par une requête en lecture seule sur la base de test partagée
(`SELECT COUNT(*) FROM firm_invoice WHERE number LIKE 'FIRM-2026-%'` → exactement 218
lignes accumulées), qui fait retomber le prochain numéro généré sur une valeur déjà
prise. Suites touchées : `DocumentPaymentControllerTest`,
`FinancialCorrectionControllerTest`, `FinancialCorrectionServiceTest`,
`FirmInvoiceServiceLot1AdaptationTest` et les tests `FirmInvoice*` associés — la
majorité des ~51 échecs sont des échecs en cascade du même conflit de clé unique, pas
~51 causes distinctes. Recommandation : réinitialiser/tronquer la base de test locale
dans un chantier séparé, dédié. **Domaine financier non touché dans ce chantier**,
conformément à la contrainte explicite.

### Portée non traitée ici

Base de données de test locale nécessitant une réinitialisation/troncature propre
(collision `FIRM-2026-219`) — action distincte, non exécutée sans instruction
explicite. Instabilité d'ordre d'exécution des tests de suppression d'absence — nécessite
un nettoyage/une meilleure isolation de l'état entre tests, non traité ici (préexistant,
hors du diff de ce chantier). Revalidation au déploiement limitée aux absences —
état du poste et exceptions de planning ajoutées après génération non couverts (voir
limite assumée ci-dessus).

## D-091 — Planning V2 : détection des conflits cross-site (double affectation chirurgien/instrumentiste), moteur central, blocage au déploiement (2026-08-01)

Date : 2026-08-01

### Contexte

Aucune détection n'existait pour le cas où la même personne (chirurgien ou
instrumentiste) se retrouve affectée à deux activités temporellement incompatibles sur
des sites différents. Deux implémentations d'un chevauchement horaire existaient déjà,
mais chacune limitée à son propre besoin : `PlanningAlertActionService::hasConflict()`
(vérifie l'éligibilité au moment d'une réaffectation manuelle d'instrumentiste, jamais
appelé pour un chirurgien) et `PlanningGeneratorServiceV2::hasConflictFast()` (exclut un
créneau du pool de génération, jamais persisté comme alerte). Rien ne revalidait
l'incompatibilité entre la génération d'un planning et son déploiement, ni ne
l'empêchait après une modification manuelle.

### Décision — règle métier retenue

Chevauchement réel, jamais une simple égalité d'heures : `missionA.startAt < missionB.endAt
ET missionA.endAt > missionB.startAt`, borne de fin exclusive (convention déjà en usage
dans les deux implémentations préexistantes ci-dessus, reprise telle quelle — pas de
nouvelle logique). Deux créneaux jointifs (08:00–10:00 puis 10:00–12:00) ne sont donc
**jamais** un conflit. Comparaison toujours en `Europe/Brussels`
(`BusinessDateTimeImmutableType`, jamais UTC — cf. D-066/D-090). Le moteur est
volontairement **cross-site par défaut** : la requête ne filtre jamais par site, un
chevauchement entre deux missions du même site est donc détecté par le même mécanisme
(double réservation sur un même site) — seul le message d'alerte distingue
explicitement site A / site B / créneaux / personne pour rester lisible.

**Aucune modélisation de temps de trajet** : deux missions strictement non chevauchantes
sur deux sites différents ne sont jamais bloquées, même si elles sont adjacentes à la
minute près. Limite assumée, documentée ici, pas de correctif prévu dans ce chantier.

**Statuts pris en compte** — seules les missions dans un état réellement actif comptent :
`DRAFT`, `OPEN`, `DECLARED`, `ASSIGNED`, `SUBMITTED`, `VALIDATED`, `IN_PROGRESS`,
`ENCODING_IN_PROGRESS`. Exclues explicitement : `REJECTED`, `CANCELLED`, `CLOSED` — une
mission annulée ou supersédée ne peut jamais générer de faux conflit.

### Décision — service central unique

`PlanningConflictDetectionService`, seul point d'entrée pour la question « cette
affectation est-elle en conflit avec une autre affectation active de cette personne ? » :
- `findConflict()` — requête temps réel à une seule mission conflictuelle, utilisée par
  la revalidation au déploiement, la modification manuelle et l'éligibilité de
  réaffectation (`PlanningAlertActionService::hasConflict()` délègue désormais
  entièrement ici, au lieu de dupliquer sa propre DQL limitée).
- `preloadActiveMissionsForPeriod()` / `hasConflictInPool()` — variante en masse,
  préchargée par personne, utilisée par le générateur pour respecter la discipline de
  budget de requêtes fixe déjà en vigueur dans `PlanningGeneratorServiceV2`.
- `syncAlertsForMission()` / `syncAlertsForVersion()` — couche de cycle de vie des
  alertes au-dessus des deux precédentes (créer si nouveau conflit, résoudre si le
  chevauchement a disparu), sur le modèle déjà établi par `AbsenceImpactService::sync()`.

Aucun autre endroit du code ne recalcule de chevauchement — `hasConflictFast()` du
générateur reste distinct (filtrage interne du pool avant affectation, pas de mission
persistée à comparer) mais partage la même convention de bornes.

### Décision — anti-duplication de l'alerte (A↔B jamais B↔A)

`PlanningAlert.mission` est une relation `ManyToOne` unique et obligatoire — impossible
de rattacher une alerte à une paire de missions. Résolu par un **ancrage déterministe** :
l'alerte est toujours créée sur la mission ayant l'identifiant le plus bas de la paire
(`min(mission, conflict)` par id), quelle que soit celle des deux missions qui a
déclenché la synchronisation ; l'autre mission est décrite dans `snapshotJson`
(`conflictingMissionId`, site, créneau). `PlanningAlertService::createIfNotDuplicate()`
garantit qu'un rappel de synchronisation depuis l'un ou l'autre côté ne crée jamais de
second enregistrement.

**Bug trouvé et corrigé pendant ce chantier** : la première version de `applySync()` ne
créait l'alerte que lorsque la mission en cours de synchronisation était déjà l'ancre —
si l'ancre elle-même n'était jamais synchronisée indépendamment (cas courant : seule la
mission "touchée" par une modification déclenche la synchronisation), le conflit était
détecté par `findConflict()` mais **aucune alerte n'était jamais créée**. Confirmé par un
script de débogage appelant directement le service dans le conteneur. Corrigé pour que
l'alerte soit toujours posée sur l'ancre calculée, indépendamment du sens d'appel.

### Décision — comportement chirurgien vs instrumentiste

`SURGEON_CONFLICT` et `INSTRUMENTIST_CONFLICT` (types déjà présents dans
`PlanningAlertType` mais jamais générés avant ce chantier) suivent des chemins de
résolution différents, sur instruction explicite :
- **Instrumentiste** : `canReassign`/`canOpenAsAvailable` activés dans
  `PlanningAlertService::computeActionFlags()` — réutilise les actions de réaffectation
  déjà existantes, aucune nouvelle mutation. `recommendAction()` retourne `REASSIGN`.
- **Chirurgien** : délibérément exclu de ces deux actions — jamais de réaffectation
  automatique d'un chirurgien. `recommendAction()` retourne `REVIEW` : le manager doit
  arbitrer manuellement lequel des deux créneaux garder.

### Décision — détection à trois moments

1. **Génération** — `PlanningGeneratorServiceV2::generate()` appelle
   `syncAlertsForVersion()` juste après le flush, en fin de génération (bulk, pool
   préchargé).
2. **Modification manuelle** — `PlanningModificationService::apply()` appelle
   `syncAlertsForMission()` pour chaque mission touchée non `REJECTED`/`CANCELLED`, après
   le flush de la modification (non bloquant — l'alerte prévient, ne refuse jamais la
   modification elle-même).
3. **Déploiement/redéploiement** — `PlanningDraftRevalidationService::revalidate()`
   revalide désormais aussi les conflits cross-site (en plus des absences, D-090), pour
   chirurgien **et** instrumentiste, sur l'état courant des données — pas celui capturé à
   la génération. Un conflit actif ajoute une entrée `type: CROSS_SITE_CONFLICT` à
   `blockingConflicts` ; `PlanningDeploymentService::deploy()` lève la même
   `PlanningDraftConflictException` que pour une absence (généralisée : message et
   docblock couvrent désormais les deux types), capturée par le contrôleur en HTTP 409
   `DRAFT_CONFLICTS` avec le détail structuré (personne, site courant, site en conflit,
   créneaux, ids de mission). **Aucun déploiement silencieux avec un conflit actif** —
   qu'il ait été introduit à la génération, par une modification manuelle ultérieure, ou
   par l'écoulement du temps entre les deux.

### Frontend

Minimal, par instruction explicite — aucune refonte visuelle :
- `AlertCard.tsx` — le message d'alerte (`buildProbleme()`) affiche désormais les deux
  sites et les deux créneaux horaires quand `alert.conflict` est renseigné (nouveau champ
  `PlanningAlertConflictV2`), avec repli sur le texte générique existant sinon.
- `GeneratePlanningTab.tsx` — le dialogue de blocage au déploiement, jusqu'ici limité aux
  conflits d'absence (D-090), généralisé pour afficher aussi les conflits cross-site
  (site ↔ site en conflit dans chaque ligne).
- Résolution toujours via les mécanismes existants (réassigner, ouvrir comme mission
  disponible, résoudre) — aucun nouvel écran.

### Tests

14 tests fonctionnels nouveaux (`PlanningCrossSiteConflictTest`, base réelle) : chirurgien
(chevauchement total, chevauchement partiel, créneaux jointifs non bloquants, mission
annulée ignorée, conflit apparu après génération bloque le déploiement), instrumentiste
(deux sites, même site avec message distinguable, créneaux jointifs non bloquants,
affectation annulée ignorée, réaffectation manuelle créant un conflit), alertes (types
corrects, jamais de doublon A/B, résolution via réassignation résout bien l'alerte),
fuseau horaire (un test dédié confirmant l'usage d'Europe/Brussels, non-régression du bug
UTC déjà corrigé en D-066/D-090). 4 tests frontend nouveaux (`AlertCard.test.tsx`) pour le
rendu des deux sites/créneaux et l'absence de réaffectation automatique pour un chirurgien.
Suite ciblée : 113/113 backend verts (415 assertions). Suite complète backend : 1658
tests, mêmes 48 erreurs/3 échecs préexistants qu'en D-090 (collision `FIRM-2026-219`,
instabilité de suppression d'absence — aucun rapport avec ce chantier, aucun fichier
touché ici ne les référence). Frontend : suite `planning-v2` 89 tests, 87 verts + 2
timeouts préexistants déjà documentés comme instables sous charge Docker
(`PostFormDialog`, `GeneratePlanningTab` réaffectation — sans rapport avec ce chantier) ;
`AlertCard.test.tsx` 4/4 verts. `npx tsc -b` et `npm run build` propres, zéro erreur.

### Portée non traitée ici

Pas de modélisation de temps de trajet entre sites (limite assumée ci-dessus). Pas de
revalidation de l'état du poste (`SurgeonSchedulePost.active`) au-delà de ce que D-090
couvre déjà. Base de test locale non réinitialisée (collision `FIRM-2026-219`,
préexistante, hors périmètre).

---

## D-091 follow-up — dérogation manager pour un conflit « double salle » au déploiement (2026-09-11)

**Statut : DONE, testé (backend et frontend verts, vérifié en direct sur données réelles), committé, déployé (`v2026.09.11-prod-4`).**

### Contexte

D-091 bloque systématiquement le déploiement dès qu'un `CROSS_SITE_CONFLICT` est détecté,
sans aucune dérogation possible — délibéré ("aucun déploiement silencieux avec un conflit
actif"), mais sans distinction pour le cas réel signalé par le manager : un chirurgien qui
opère simultanément dans **deux salles du même site**, avec un **même instrumentiste**
volant entre les deux. Ce cas n'a rien d'une double réservation involontaire — c'est un
mode de fonctionnement délibéré du bloc — mais restait aussi bloquant qu'un vrai conflit
cross-site.

### Décision — dérogation étroite, jamais un `force=true` global

`MissionConflictWaiver` (nouvelle entité, migration `Version20260911170000`) persiste une
autorisation manager explicite pour **une paire de missions précise**, jamais un
contournement global du déploiement. Réutilise entièrement
`PlanningConflictDetectionService` pour la détection (aucun second moteur) —
`MissionConflictWaiverService` ne fait qu'ajouter une question par-dessus : ce conflit
précis a-t-il déjà été autorisé, et cette autorisation correspond-elle encore à l'état
actuel des deux missions ?

Forme éligible à la dérogation, volontairement étroite : **même chirurgien ET même
instrumentiste ET même site** sur les deux missions. Un chirurgien identique mais sur des
**sites différents** reste bloquant sans dérogation possible (vrai cross-site, pas une
double salle) ; deux chirurgiens différents restent bloquants aussi. `ABSENCE` (D-090)
n'est jamais concerné — pas de deuxième mission à apparier.

**Stabilité** : `MissionConflictWaiver` fige un instantané (site/chirurgien/instrumentiste/
horaires des deux missions) au moment de l'autorisation. `PlanningDraftRevalidationService`
recompare cet instantané à l'état réel des deux missions à chaque tentative de déploiement
— si l'un des champs a changé depuis, la dérogation est invalidée (jamais silencieusement
ignorée, `invalidatedAt`/`invalidatedReason` tracés) et le conflit redevient bloquant.

Nouvel endpoint `POST /api/planning/v2/conflicts/authorize` — accepte plusieurs paires en
un appel ("tout autoriser"), chacune traitée indépendamment (`authorized`/`failed`).
Frontend : case à cocher par conflit `waivable: true` dans la boîte de dialogue de blocage,
"Tout cocher", et "Autoriser (N) et redéployer" qui enchaîne directement le redéploiement
sans second clic manuel.

### Deux bugs réels trouvés en testant sur les vraies données de dev (pas seulement des fixtures synthétiques)

1. **Dédoublonnage frontend non symétrique** : le backend signale un `CROSS_SITE_CONFLICT`
   une fois par personne (côté chirurgien ET côté instrumentiste), donc la même paire réelle
   apparaît comme `missionId=A, conflictingMissionId=B` ET `missionId=B,
   conflictingMissionId=A`. La clé de dédoublonnage initiale (`${missionId}-${conflictingId}`)
   traitait ces deux entrées comme des paires différentes — "Tout cocher" soumettait deux fois
   la même paire réelle (constaté en direct : 8 soumissions pour 4 paires uniques). Corrigé par
   une clé canonique triée (`[min,max].join('-')`), alignée sur
   `MissionConflictWaiverService::canonicalOrder()`.
2. **`PlanningDraftService::delete()` ne nettoyait pas `MissionConflictWaiver`** — trouvé en
   supprimant en direct un brouillon dont une mission avait une dérogation active :
   `ForeignKeyConstraintViolationException` (`mission_low_id`/`mission_high_id` sans `ON
   DELETE CASCADE`). Exactement le même mode d'échec que celui déjà documenté pour
   `planning_alert` dans le docblock de `CLEANUP_ON_MISSION_DELETE` — mais `MissionConflictWaiver`
   ne peut pas rejoindre cette liste generique (deux colonnes référençant `mission`, pas une) ;
   traité par un `DELETE` dédié dans la même transaction.

### Tests exécutés

`MissionConflictWaiverTest` : 7/7 (dont la régression de suppression ci-dessus). Backend
`--filter Planning` : 499/499. Backend complet : 2351/2352 (1 échec préexistant confirmé
sans rapport, `ReleasedOperatingRoomSlotFunctionalTest`). Frontend `GeneratePlanningTab` :
38/39 (1 flake de timing préexistant confirmé sans rapport). `tsc -b --noEmit` et `npm run
build` propres. Vérifié en direct dans le navigateur sur les données réelles de dev : 21
conflits détectés sur un vrai brouillon multi-postes, dont 4 paires réellement waivable
(double salle) correctement identifiées et autorisées en un lot, 13 conflits non-waivable
correctement laissés bloquants.

---

## D-092 — Refonte Catalogue/Prestations : politique commerciale "présence d'un délégué", distinction facturable/non-facturable, explicabilité complète des calculs (2026-08-02)

Date : 2026-08-02

### Contexte

Chantier fonctionnel demandé sur `/app/m/catalogue/prestations`, au-delà de la simple
règle métier "présence d'un délégué" : simplifier l'expérience manager, réduire les
erreurs de configuration tarifaire, clarifier catalogue/prestation/matériel/tarification,
et rendre tout calcul financier explicable. Audité avant toute modification : le modèle
existant (D-067 à D-079) était sain mais incomplet sur trois axes précis — (1) aucune
donnée de présence délégué nulle part, (2) aucune distinction entre "volontairement non
facturé" et "tarif oublié", (3) `FinancialCalculationLine` ne portait qu'un montant final,
sans distinction brut/ajustement.

### Décision — règle métier retenue

Une politique commerciale "présence d'un délégué" est configurée sur `FirmServiceOffering`
(Firm × InterventionType, jamais sur `Firm` seule) via quatre indicateurs :
`representativePresenceRelevant` (la question doit-elle être posée à l'encodage ?),
`representativeSuppressesInterventionFee`, `representativeSuppressesOwnMaterialFees`
(l'un ou l'autre, ou les deux), `feeApplicable` (un forfait est-il seulement attendu pour
cette prestation ?). La présence effective du délégué est une donnée **factuelle**,
encodée par l'instrumentiste sur `MissionIntervention.representativePresent` (nullable —
`null` = jamais répondu), jamais une donnée de `Mission` globale, jamais une notion
financière en elle-même. Le moteur financier décide seul de la conséquence.

**Exemple ConMed** (LCA, `representativePresenceRelevant=true`,
`representativeSuppressesInterventionFee=true`, `representativeSuppressesOwnMaterialFees=true`,
délégué présent) : forfait 150 € → 0 € (ajustement -150 €), matériel ConMed 45 € → 0 €
(ajustement -45 €), matériel Smith & Nephew de la même intervention → 20 € inchangé (la
politique ConMed ne neutralise jamais une ligne d'une autre firme, §19/§20 du prompt).
**Exemple Smith & Nephew** (`representativePresenceRelevant=false`) : même si un délégué
SN est signalé présent, aucun effet — un avertissement non bloquant
(`STALE_REPRESENTATIVE_PRESENCE_ANSWER`) signale l'incohérence sans jamais bloquer le
calcul.

### Décision — exception scopée à l'invariant D-067 (arbitrage explicite)

Voir l'amendement inséré directement dans D-067 ci-dessus. Résumé : `PricingRuleResolver`
reste 100% pur (aucune dépendance, structurellement prouvée par
`PricingRuleResolverArchitectureTest`) ; `FinancialCalculationService` est seul autorisé à
lire les 4 indicateurs non-monétaires de `FirmServiceOffering`, uniquement **après**
résolution normale du tarif, pour appliquer un ajustement — jamais une source de montant.
Ordre logique fixé : `FirmServiceOffering` répond "ce forfait doit-il exister ?" (via
`feeApplicable`) → `PricingRuleResolver` répond "quel est son montant ?" →
`FinancialCalculationService` répond "quel montant final après ajustement ?".

### Décision — statuts pris en compte pour la neutralisation

Un matériel dont la firme est **exactement** la firme principale de l'intervention à
laquelle il est rattaché (`MaterialLine.missionIntervention.primaryFirm`) peut être
neutralisé. `MaterialLine` sans intervention réelle rattachée (unattached, ou attachée à
un `MissionInterventionDraft`) n'a jamais de contexte délégué — résolue normalement, sans
neutralisation possible. Aucune contamination inter-intervention au sein d'une même
`Mission` (chaque `MissionIntervention` a son propre `representativePresent` et sa propre
politique résolue indépendamment).

### Décision — distinction facturable / non facturable (matériel)

Nouveau `MaterialItem.billingStatus` (enum `UNSPECIFIED` défaut / `BILLABLE` /
`NOT_BILLABLE`). `NOT_BILLABLE` → absence de `PricingRule` valide, aucune ligne générée,
aucune anomalie. `BILLABLE`/`UNSPECIFIED` sans règle active → anomalie bloquante inchangée
(`MISSING_FIRM_MATERIAL_RATE`) — jamais un 0€ silencieux. **Auto-promotion** : poser une
première `PricingRule` `MATERIAL_FEE` sur un matériel (`PricingRuleWriteService::create()`)
le fait automatiquement passer à `BILLABLE`, sans geste manager supplémentaire — cohérent
avec le formulaire unique "Facturable + Tarif" du prompt. Rétrograder à `NOT_BILLABLE`
n'est autorisé (`MaterialCatalogController::update()`) que si aucune `PricingRule` active
n'existe — sinon 409, le manager doit d'abord fermer le tarif. Backfill de migration :
`BILLABLE` uniquement pour les matériels ayant aujourd'hui une `PricingRule` `MATERIAL_FEE`
active, `UNSPECIFIED` partout ailleurs — jamais `NOT_BILLABLE` par défaut (ce serait
deviner une décision commerciale jamais prise).

### Décision — distinction "pas de forfait" / "tarif manquant" (prestation)

Même logique via `FirmServiceOffering.feeApplicable` (défaut `true` = comportement
identique à avant ce lot). `feeApplicable=false` → aucune résolution `INTERVENTION_FEE`
tentée, état valide, aucune ligne, aucune anomalie ("Pas de forfait"). `feeApplicable=true`
sans `PricingRule` active → anomalie bloquante inchangée ("Tarif à définir" côté UI,
jamais affiché comme 0,00 €).

### Décision — explicabilité financière (`FinancialCalculationLine`)

Trois colonnes additives : `grossAmount` (toujours renseigné — identique à `totalAmount`
si aucun ajustement), `adjustmentAmount` (toujours renseigné, `"0.00"` = aucun ajustement),
`warnings` (JSON, non bloquant, distinct des anomalies qui empêchent tout le calcul).
`snapshot` enrichi de `representativePresentSnapshot`, `representativePolicySnapshot` (les
4 indicateurs au moment du calcul), `adjustmentReasonSnapshot` (texte humain),
`billingStatusSnapshot` (lignes matériel). Un ancien calcul déjà persisté n'est **jamais**
affecté par un changement ultérieur de politique — testé explicitement
(`test_old_calculation_unaffected_by_later_policy_change`).

**Distinction des zéros (§23 du prompt), non négociable** : un 0€ de neutralisation
commerciale porte toujours `adjustmentAmount != "0.00"` et `adjustmentReasonSnapshot`
renseigné — jamais confondu avec l'absence de résolution (qui reste une anomalie
bloquante empêchant toute persistance, comportement D-073 inchangé).

### Décision — nouvelle anomalie bloquante

`MISSING_REPRESENTATIVE_PRESENCE_ANSWER` : la question est pertinente
(`representativePresenceRelevant=true`) mais `MissionIntervention.representativePresent`
est encore `null` au moment du calcul — défense en profondeur (l'UI encodage bloque déjà
la finalisation dans ce cas, §10 du prompt), jamais un 0€ implicite si la réponse n'a
jamais été donnée (`test_representative_present_never_turns_a_missing_rate_into_a_valid_zero`).

### API / DTO (extension, aucun nouvel endpoint pour le calcul)

`GET /api/financial-calculations/{id}` (existant) étend `serializeLine()` :
`grossAmount`/`adjustmentAmount`/`warnings` ajoutés au JSON. `GET /api/firms/{firmId}/service-offerings`
et `PATCH .../service-offerings/{id}` (existants) acceptent/exposent les 4 indicateurs.
`POST`/`PATCH /api/missions/{id}/interventions` (existants) acceptent
`representativePresent` en tri-état (absent = inchangé, `null` = retire explicitement,
valeur = Oui/Non) — réponse minimale (`id`/`orderIndex`/`representativePresent`), jamais
de champ financier exposé à l'instrumentiste. `GET /api/intervention-types/{id}/encoding-context`
(existant) expose désormais `representativePresenceRelevant` par prestation, pour que le
frontend sache quand poser la question. `PATCH /api/material-items/{id}` accepte
`billingStatus`, validé selon les règles ci-dessus.

### Frontend — minimal, mécanique interne masquée (§1/§2 du prompt)

`PrestationsPage.tsx` : onglet renommé "Matériel" (au lieu de "Matériel facturable") ;
boutons d'ajout compacts (`IconButton` + `aria-label`, tooltip) au lieu de boutons texte ;
états vides dédiés (`EmptyState`, icône + titre + sous-texte + CTA) pour les deux onglets ;
ajout de prestation en un seul écran (`AddOfferingDialog` réécrit : recherche
texte-libre → sélection ou `+ Créer «X»` → formulaire de création intégré, "Créer et
ajouter" enchaîne directement la création du type ET son rattachement à la firme, jamais
un second clic) ; détail d'une prestation au clic (`OfferingDetailDialog`, nouveau :
forfait/matériels suggérés/politique délégué en un seul écran) ; onglet Matériel liste
désormais **tous** les `MaterialItem` de la firme (plus seulement ceux ayant déjà un
tarif) ; suppression du bouton global "Ajouter un tarif matériel" — le tarif se pilote
depuis la ligne matériel (`MaterialRateDialog`, déjà capable de créer un premier tarif
via `RateVersionManager::onCreateFirst`) ; `MaterialItemFormDialog` gagne une prop
`firmLocked` (masque le sélecteur Firme quand le contexte le connaît déjà — Prestations —,
inchangé pour CataloguePage). `FinancialCalculationCard.tsx` : chaque ligne devient
dépliable ("Détail du calcul" — brut/délégué/règle appliquée/ajustement/final/warnings),
badge "Ajustée" visible sans déplier. `AddInterventionDialog.tsx`/`EditInterventionDialog.tsx` :
question "délégué présent ?" affichée uniquement si pertinente pour la firme × type
effectivement choisis (résolu via le contexte d'encodage déjà chargé, aucun appel réseau
supplémentaire), obligatoire avant soumission dans ce cas.

### Tests

**Backend** : `RepresentativePolicyFinancialCalculationTest` (18 tests, base réelle) —
ConMed sans/avec délégué, ConMed+SN sans contamination, SN sans impact, deux prestations
d'une même firme avec politiques différentes, deux interventions d'une même mission sans
contamination, matériel volontairement non facturable, matériel facturable sans tarif,
tarif absent involontaire jamais 0€, `feeApplicable=false`/`true`, réponse manquante
bloque, présence délégué ne transforme jamais un tarif manquant en 0€ valide, historisation
(ancien calcul inchangé), défaut sans `FirmServiceOffering` = comportement pré-lot.
`PricingRuleResolverArchitectureTest` (3 tests) — preuve structurelle par réflexion que
`PricingRuleResolver` n'a et n'aura jamais de dépendance vers `FirmServiceOffering`/
`RepresentativePolicyResolver`, et que `RepresentativePolicy` ne porte que des booléens.
Extension de `FinancialCalculationControllerTest` (détail API expose les nouveaux champs),
`InterventionControllerLot5Test` (aller-retour `representativePresent`, défaut `null`).
Un bug réel trouvé et corrigé en cours de route : `InterventionService::create()` ne
capturait pas `$dto` dans la closure de transaction (`Undefined variable $dto`) — corrigé
en pré-extrayant `$representativePresent` avant la closure, comme déjà fait pour
`$type`/`$firm`.

**Frontend** : `PrestationsPage.test.tsx` (réécrit, 19 tests) — états vides, ajout de
prestation sans modal intermédiaire, création de type dans le même flux, détail de
prestation (forfait/matériels/délégué), bascule de politique délégué, liste exhaustive du
matériel, Facturable/Non facturable, absence du bouton global tarif matériel, gestion du
tarif depuis la ligne, création de matériel sans choix de firme. `FinancialCalculationCard.test.tsx`
(nouveau, 4 tests) — détail déplié (brut/ajustement/raison/final), ligne non ajustée sans
section ajustement, warning affiché, badge "Ajustée". `AddInterventionDialog.test.tsx`
étendu (2 tests) — question jamais posée si non pertinente, posée et bloquante sinon. Un
bug UX réel trouvé et corrigé pendant l'écriture des tests : "Créer et ajouter" ne créait
que le type d'intervention, laissant un second clic manuel nécessaire pour l'ajouter comme
prestation — corrigé pour enchaîner les deux mutations automatiquement (conforme à
l'intention du prompt : un seul geste).

**Résultats** : suite ciblée backend 18+3+quelques extensions, tous verts. Suite complète
backend : mêmes ~48 erreurs/3 échecs préexistants (collision `FIRM-2026-219`, instabilité
de suppression d'absence) qu'aux chantiers précédents — aucun nouveau, aucun fichier
touché ici ne les référence. Frontend : `npx tsc -b` et `npm run build` propres ;
suite complète frontend verte (voir rapport final pour le compte exact).

### Portée non traitée ici

Pas de modélisation d'un écran de détail dédié au matériel (le mockup §15 du prompt est
représenté par des actions de ligne — "Ajouter/Définir un tarif" et "Non facturable" —
plutôt qu'un nouveau dialog, cohérent avec "pas de refonte visuelle majeure"). Pas de
réconciliation entre `billingStatus` et l'ancien flag `isImplant` au-delà de ce que D-067
avait déjà tranché (`isImplant` reste purement médical). Base de test locale non
réinitialisée (collision `FIRM-2026-219`, préexistante, hors périmètre).

## D-093 — Cycle de vie des propositions catalogue (intervention/matériel) : notification de la décision à l'instrumentiste, deux bugs corrigés au passage (2026-07-29)

Date : 2026-07-29

### Contexte

Vérification demandée du parcours "l'instrumentiste ne trouve pas l'intervention/le
matériel dont il a besoin pendant l'encodage → il propose → le manager traite → il est
informé". La création (`InterventionTypeRequestController`/`MaterialItemRequestController`,
EPIC Revue instrumentiste Lot 3) et le traitement manager
(`InterventionTypeRequestManagerController`/`MaterialItemRequestManagerController`)
existaient déjà ; le dernier maillon — prévenir l'instrumentiste de l'issue — n'existait
pas du tout côté matériel, et côté intervention se limitait à ce que l'instrumentiste
revienne consulter l'écran manuellement.

### Bugs corrigés (préexistants, indépendants de la nouvelle fonctionnalité)

1. **RBAC manquant** sur `MaterialItemRequestManagerController` (`list()`/`resolve()`/
   `ignore()`) : aucun `denyAccessUnlessGranted()`, contrairement à son jumeau
   `InterventionTypeRequestManagerController`. N'importe quel utilisateur authentifié
   pouvait lister/traiter les demandes matériel. Corrigé avec `BillingVoter::MANAGE`,
   même garde que le contrôleur intervention.
2. **Orphelinage silencieux de `MaterialLine`** : résoudre une `MaterialItemRequest`
   pendant que sa cible (`MissionInterventionDraft`) est encore ouverte attachait la
   ligne via `setMissionIntervention($req->getMissionIntervention())` — toujours `null`
   à ce stade — au lieu de suivre `$req->attachmentTarget()` (draft OU intervention
   réelle). La ligne n'était alors rattachée à rien et n'était jamais reprise par
   `MissionInterventionDraftService::repointMaterial()` au moment où le draft est
   converti. Corrigé pour toujours attacher à la cible réelle de la demande.

### Décision — notification de la décision à l'instrumentiste

Un `NotificationType` unique par issue, partagé entre intervention et matériel —
`CATALOGUE_REQUEST_RESOLVED` (accepté) / `CATALOGUE_REQUEST_IGNORED` (non retenu) — la
préférence de canal de l'instrumentiste est naturellement "informe-moi de mes
propositions catalogue", pas une préférence séparée par nature de demande ; le type de
demande (`CatalogueRequestKind::INTERVENTION_TYPE`/`MATERIAL_ITEM`) est porté en donnée de
message/payload. `MaterialItemRequestManagerController::resolve()`/`ignore()` et
`InterventionTypeRequestManagerController::resolve()`/`ignore()` dispatchent
`CatalogueRequestProcessedMessage` (async, `messenger.yaml`) après leur transaction ;
`CatalogueRequestProcessedMessageHandler` orchestre in-app → push prioritaire → repli
email uniquement si le push n'est pas réellement livrable — même pattern que
`MissionPublishedMessageHandler::notifySurgeon()` (D-083/D-084), via
`NotificationPreferenceResolver`/`OutboundNotificationService`/`NotificationService`.
`CATALOGUE_REQUEST_RESOLVED`/`_IGNORED` sont email=true par défaut (actionnable — la
personne attend un oui/non). Aucune donnée patient ni montant financier dans le payload.

### Portée non traitée ici

Vérification live uniquement en environnement de développement local (comptes/mission
jetables, nettoyés après coup) — pas de vérification en environnement de production.

## D-094 — Notification des managers/admins à la création d'une proposition catalogue ; invitation push en bas des emails applicatifs (2026-08-04)

Date : 2026-08-04

### Contexte

Suite directe de D-093 : le dernier maillon manquant du parcours propositions catalogue
était côté manager — rien ne le prévenait qu'une proposition attendait son traitement, il
fallait consulter `CatalogueRequestsPage` sans y être invité. Deuxième volet demandé dans
le même lot : réduire le volume d'emails envoyés aux personnes qui pourraient utiliser le
push à la place, via une invitation discrète en bas des emails applicatifs concernés.

### Décision — notification manager à la création

`InterventionTypeRequestController::create()` et `MaterialItemRequestController::create()`
dispatchent chacun un `CatalogueRequestCreatedMessage` (async) juste après la création
réussie de la demande. `CatalogueRequestCreatedMessageHandler` fane vers
**tous les managers/admins actifs** — `UserRepository::findManagersAndAdmins(true)`,
exactement le même ciblage que `PlanningAlertRaisedMessageHandler`/
`AbsenceImpactService::buildNotification()` (Batch 7) — sans scoping par site : une
proposition catalogue impacte le référentiel global, pas seulement le site de la mission.
Un seul `NotificationType` — `CATALOGUE_REQUEST_CREATED` — partagé entre intervention et
matériel, même raisonnement que D-093.

**In-app + push uniquement, jamais d'email — y compris en repli si le push échoue.**
Contrairement à D-093 (réponse attendue par l'instrumentiste, actionnable), une nouvelle
proposition n'est pas urgente : le manager la retrouve de toute façon sur
`CatalogueRequestsPage`, et un repli email serait du bruit pur. `CATALOGUE_REQUEST_CREATED`
n'est **pas** dans `EMAIL_ON_BY_DEFAULT` (email=false par défaut) et
`CatalogueRequestCreatedMessageHandler` n'a même pas de dépendance capable d'envoyer un
email applicatif (`NotificationService` n'y est pas injecté) — "pas de repli email" est
donc structurel, pas juste un comportement observé. Isolation par destinataire (l'échec
d'un manager n'empêche jamais la notification des autres), même pattern que
`MissionPublishedMessageHandler::notifySiteInstrumentists()`.

Deep-link : `NotificationTargetResolver::resolve()` route désormais
`CATALOGUE_REQUEST_CREATED` + destinataire manager/admin vers `/app/m/catalogue/requests`
(l'écran réel de traitement) en priorité sur la branche générique "mission liée → détail
mission" — la `Mission` reste posée en FK sur le `NotificationEvent` pour le contexte/
l'audit, ce n'est simplement pas la cible de clic pour ce type précis.

### Décision — invitation à activer les notifications push, en bas des emails applicatifs

Architecture des templates email existante : **aucun layout Twig commun**, chaque
`templates/emails/*.html.twig` est un document HTML autonome (contraintes email —
styles inline, pas d'héritage de layout robuste selon les clients mail). Reconstruire un
layout commun aurait été une refonte hors périmètre de ce lot. Solution pragmatique
retenue : un partial unique, `templates/emails/_notification_preferences_cta.html.twig`,
inclus via `{% include %}` dans chaque template concerné — un seul bloc à maintenir,
aucune duplication du HTML/texte du CTA.

Le partial ne s'affiche que si la variable `notificationPreferencesUrl` (fournie par
l'appelant) est non vide — il ne décide jamais lui-même de sa propre visibilité, toute la
décision métier (qui a droit au CTA) reste côté PHP. `NotificationService` porte un
nouveau helper privé `notificationPreferencesUrl(User $user): ?string` qui route vers la
vraie route React exposant `NotificationPreferencesSection` selon le rôle du
destinataire — `/app/i/profile` (instrumentiste), `/app/m/profile` (manager/admin,
`ProfilePageM` sert les deux rôles) — construite via `FRONTEND_URL`, jamais une URL
inventée. Retourne `null` pour tout rôle sans écran de préférences aujourd'hui
(chirurgien — même limite déjà documentée dans `NotificationTargetResolver` pour l'unique
route `/app/s`) : le partial n'affiche alors simplement rien, plutôt que de pointer vers
une route qui n'existe pas.

**Templates concernés** (tous dispatchés via `NotificationService`, tous vers un
utilisateur SurgicalHub déjà capable de se connecter) : `catalogue_request_resolved`,
`catalogue_request_ignored`, `mission_encoding_reminder`, `mission_open_notify_surgeon`,
`absences_request_missing`, `absences_confirm_encoded`. `catalogue_request_created`
ajouté en D-113 (2026-09-04) — même famille, même partial.

**Templates explicitement exclus** : `instrumentist_invitation` (avant premier login —
rien à activer), `user_email_changed_new_address`/`_old_address` (emails de sécurité liés
au changement d'adresse — jamais mélangés à une invitation produit),
`firm_invoice_sent`/`instrumentist_statement_sent` (`firm_invoice_sent` part vers un
contact de facturation externe, pas un utilisateur SurgicalHub — `instrumentist_statement_sent`
n'a par ailleurs pas d'objet `User` dans son contexte actuel, seulement l'entité
`InstrumentistStatement`).

**Hors périmètre de ce lot** (délibérément) : `planning_alert.html.twig` et les
récapitulatifs de déploiement (`planning_instrumentist`/`planning_manager`/
`planning_surgeon`/`planning_change_summary_*`) sont dispatchés directement depuis
`PlanningAlertRaisedMessageHandler`/`PlanningDeployPdfsMessageHandler`, hors
`NotificationService` — les y câbler aurait dupliqué la logique de résolution de route
dans deux handlers supplémentaires plutôt que de la centraliser. Suite rapide possible
une fois `notificationPreferencesUrl()` extrait en service partagé, mais volontairement
non traité ici pour ne pas élargir le lot.

### Portée non traitée ici

Comme D-093 : vérification live uniquement en environnement de développement local
(comptes/mission jetables, nettoyés après coup).

## D-095 — Socle mobile partagé Instrumentiste + Chirurgien : un seul `MobileLayout`, jamais deux implémentations (Lot 1, 2026-08-06)

Date : 2026-08-06

### Contexte

L'espace chirurgien (`/app/s`) n'existait jusqu'ici que comme une route non protégée
rendant un stub (`<div>Surgeon Home</div>`) dans le même `MobileLayout` que
l'instrumentiste, mais sans qu'aucun de ses branchements (tabs, chrome, notifications,
profil) ne reconnaisse ce scope — la moindre navigation vers `/app/s` retombait dans le
repli "route non reconnue" de `MobileLayout` (aucun header, aucune nav). Avant toute
implémentation, un audit ciblé de l'espace instrumentiste (`MobileLayout`, planning,
détail mission, notifications, PWA, profil, absences, patterns de requêtes existants)
a produit une matrice de réutilisation et une analyse d'écart complètes, validées
explicitement avant tout code.

### Décision — principe directeur

**Instrumentiste et Chirurgien partagent désormais le même shell mobile.** Jamais de
`InstrumentistMobileLayout`/`SurgeonMobileLayout`, jamais de duplication du header, de
l'animation des vagues (D-094), de la logique PWA, des notifications ou du profil. Les
différences entre rôles sont portées exclusivement par : les routes, le jeu de tabs de
navigation, les permissions calculées côté backend (`allowedActions`, Voters), et les
composants métier réellement spécifiques à un rôle (jamais par une deuxième
implémentation de socle). Voir `docs/architecture.md` §14 pour le détail technique.

### Décision — `RequireSurgeon`

Nouveau guard frontend, mirroir exact de `RequireInstrumentist` (redirige vers
`/login` si non authentifié, vers `/app/m/dashboard` si `role !== "SURGEON"`). Comme
pour tous les guards existants, c'est un confort de navigation, jamais la source de
vérité des droits : `MissionService::list()` auto-scope déjà `m.surgeon = :self` pour
tout appelant `ROLE_SURGEON` sans paramètre supplémentaire, et
`MissionActionsService::allowedActions()` calcule déjà les actions chirurgien
possibles (`rate_instrumentist`, `dispute_hours`) — ce lot ne fait qu'ouvrir la porte
frontend vers des règles backend qui existaient déjà avant lui.

### Décision — Profil commun, pas de `surgeonProfile` dans `MeResponse`

`pages/instrumentist/ProfilePage.tsx` (supprimé) est remplacé par
`pages/common/ProfilePage.tsx`, routé sous `/app/i/profile` **et** `/app/s/profile`.
Il lit exclusivement les champs racine de `MeResponse`, déjà communs à tous les rôles
(`id`/`email`/`firstname`/`lastname`/`phone`/`profilePictureUrl`/`sites`) — jamais
`instrumentistProfile`, qui reste spécifique et n'alimente que la section compétences
orthopédiques, conditionnelle au rôle (`{role === "INSTRUMENTIST" && ...}`). Décision
explicite : ne pas empiler `instrumentistProfile`/`surgeonProfile`/`managerProfile`
pour des données en réalité communes à `User` — un `surgeonProfile` ne sera introduit
que si un champ réellement spécifique au chirurgien apparaît (aucun identifié à ce
stade).

`User.phone` existait déjà (renseigné à l'invitation, modifiable par un admin via
`AdminUserController`) mais n'était jamais exposé sur `GET /api/me` — exposition
minimale en lecture seule ajoutée à `MeResponse`, commune à tous les rôles. Aucun
endpoint self-service de modification n'est ajouté dans ce lot.

### Décision — synchronisation : pas de généralisation pour la seule symétrie

`useInstrumentistMissionSync()` (polling optimisé, existe parce que les offres/claims
instrumentiste sont très dynamiques) n'est **pas** généralisé, et aucun
`GET /api/surgeon/missions/sync` n'est créé dans ce lot. Le chirurgien s'appuie sur
React Query standard (`refetchInterval`, refetch au focus/online déjà fournis par
défaut) au-dessus du listing `GET /api/missions` déjà auto-scopé côté backend — décision
explicite pour rester la solution la plus légère tant que le besoin fonctionnel réel
(Lot Planning chirurgien) ne démontre pas qu'elle est insuffisante. Si le volume ou un
besoin temps réel le justifie plus tard, une abstraction partagée (`useMissionSync`) sera
étudiée à ce moment — jamais dupliquée à l'identique par anticipation.

### Décision — navigation : "Plus" réutilise `AccountMenu`, jamais un second menu

Le chirurgien gagne un 4ᵉ bouton "Plus" (bottom nav mobile et rail desktop), absent
chez l'instrumentiste (qui garde exactement ses 3 tabs Aujourd'hui/Planning/Offres,
inchangés). "Plus" ouvre le **même** composant `AccountMenu` que l'avatar de la
`BrandBand`, étendu via une prop `extraItems` (chirurgien uniquement) plutôt qu'un
second système de menu : Mes demandes, Mes indisponibilités, Notifications, Installer
SurgicalHub (réutilise `usePwaInstallMenuState()`, même logique que le menu compte
desktop manager/admin), puis Mon profil/Se déconnecter communs aux deux rôles.

**Préparation pour un futur onglet Absences partagé** : le jeu de tabs
(`SURGEON_TABS`/`INSTRUMENTIST_TABS`) est un simple array, sans hypothèse de longueur
ailleurs dans `MobileLayout`/`MobileBottomNav`/`DesktopSidebar`. Un futur module
Absences self-service (domaine `Absence` existant, étendu — jamais une deuxième
entité `SurgeonAbsence`/`InstrumentistAbsence` — voir §Portée non traitée ici) sera
partagé à l'identique entre les deux rôles et n'ajoutera qu'une entrée dans ces deux
tableaux. Volontairement non livré dans ce lot : `/app/s/absences` (et
`/app/i/absences`, à ajouter au même lot) restent des replis `ComingSoonPage`
(§Portée non traitée), jamais une entrée de navbar pointant vers un écran vide en
production — aucun bouton "Absences" n'est ajouté à la bottom nav tant que le module
réel n'est pas livré.

### Décision — pages non livrées : repli explicite, jamais un écran cassé

`/app/s` (Accueil), `/app/s/planning`, `/app/s/activity`, `/app/s/requests`,
`/app/s/absences` sont routées et protégées par `RequireSurgeon` dès ce lot, mais
rendent `pages/common/ComingSoonPage.tsx` (nouveau repli générique réutilisable,
jamais une fausse donnée métier — pas de "0 mission" inventé, pas de podium vide
maquillé en donnée réelle) tant que leur lot dédié n'est pas livré. Seuls
Notifications et Profil sont réellement fonctionnels dans ce lot (réutilisation
directe de composants déjà existants, zéro nouveau moteur).

### Portée non traitée ici

Delibérément hors périmètre de ce Lot 1 (chacun son propre lot, voir la feuille de
route validée séparément) : Planning/Home/Activité chirurgien réels (agrégats,
podium personnel des interventions — jamais une comparaison entre chirurgiens),
demandes de mission (`SurgeonMissionRequest`, nouveau domaine — aucune entité
existante ne convient, le pattern architectural de `InterventionTypeRequest`/
`MissionInterventionDraftService` — transaction atomique + `AuditEvent` par issue —
sera repris plutôt que celui, plus permissif, de
`MaterialItemRequestManagerController`), absences self-service (extension du domaine
`Absence` existant, forçant systématiquement `absence.user = current authenticated
user`, jamais un `userId` client, partagée à l'identique instrumentiste/chirurgien),
lecture de l'encodage par le chirurgien (nouvelle distinction `VIEW_ENCODING` vs
`EDIT_ENCODING` sur `MissionVoter`, jamais un détournement de `EDIT_ENCODING`),
signalement d'anomalie d'encodage, export `.ics` (reporté, amélioration future non
bloquante). `NotificationService::notificationPreferencesUrl()` (D-094) retourne
toujours `null` pour `ROLE_SURGEON` — devenu obsolète depuis que `/app/s/profile`
existe réellement, non corrigé dans ce lot pour ne pas élargir son périmètre (petit
correctif isolé, sans risque, à faire au prochain lot touchant ce fichier).

## D-096 — Home + Planning chirurgien réels, réutilisation du moteur planning instrumentiste (Lot 2, 2026-08-06)

Date : 2026-08-06

### Contexte

Suite au socle mobile partagé du Lot 1 (D-095), `/app/s` et `/app/s/planning`
restaient des replis `ComingSoonPage`. Ce lot les remplace par des écrans réels, dans
le prolongement direct du principe directeur de D-095 : jamais une deuxième
implémentation quand une réutilisation est possible.

### Décision — extraction du moteur planning, pas une réécriture

Le moteur de grille/calendrier de `pages/instrumentist/PlanningPage.tsx` (date-math,
`buildMonthGridCells`, `SegmentedControl`, `WeekStrip`, `MonthGrid`, `MissionListRow`,
`EmptyStateRow`) est déplacé vers un module partagé
`features/mobile-planning/planningPrimitives.tsx`, sans changement de comportement
pour l'instrumentiste (verrouillé par `PlanningPage.test.tsx`, 24 tests inchangés, plus
un nouveau `planningPrimitives.test.tsx` dédié au contrat du module partagé). Ce qui
reste spécifique à chaque rôle (classification "à encoder"/"à couvrir", libellé de la
personne affichée, bannière d'état vide) reste hors du module partagé — voir
`docs/architecture.md` §15.1.

### Décision — couverture : un seul champ backend, jamais recalculé côté frontend

`PlanningCoverageService` gagne une méthode `isCovered(Mission $mission): bool`
(réutilise la constante `COVERED_STATUSES` déjà existante), exposée via un nouveau
champ `covered: boolean` sur `MissionListDto`/`MissionDetailDto` (voir `docs/api.md`
§3.2). Décision explicite : **jamais** `covered = instrumentist != null` côté
frontend — une mission `OPEN` peut porter un instrumentiste pré-suggéré sans être
réellement couverte, et cette approximation aurait divergé silencieusement de la
définition déjà utilisée par les KPI planning (Batch 15F). Le chirurgien comme
l'instrumentiste (ligne "Instrumentiste" ajoutée à `MissionDetailContent`) lisent
désormais la même source de vérité.

### Décision — Home chirurgien : pas de nouvel endpoint, deux requêtes self-scoped

`SurgeonHomePage` interroge deux fois `GET /api/missions` (fenêtre "à venir" 90 jours,
fenêtre "ce mois-ci"), sans `assignedToMe`. **Piège identifié avant implémentation** :
`MissionService::list()` code en dur `assignedToMe: true` sur `m.instrumentist` — le
passer pour un chirurgien aurait vidé systématiquement les résultats. Le podium
d'activité est volontairement absent de ce lot ("je préfère aucun faux chiffre à un
podium temporaire incorrect" — aucun placeholder chiffré tant que le vrai calcul
backend n'existe pas).

### Décision — détail mission chirurgien : même composant, jamais un `SurgeonMissionDetail`

`/app/s/missions/:id` réutilise `MissionDetailPageI`/`MissionDetailContent`, entièrement
piloté par `allowedActions[]`. Pour un chirurgien, `MissionActionsService` n'accorde
jamais `encoding`/`edit_hours`/`submit` (ces droits ne dépendent que de
`ROLE_MANAGER`/`ROLE_ADMIN`/`ROLE_INSTRUMENTIST`) : aucun bouton d'action
instrumentiste ne peut donc apparaître pour un chirurgien — garantie structurelle par
construction, pas une convention à respecter dans chaque nouvel écran.

`rate_instrumentist`/`dispute_hours` : présents dans `allowedActions` mais sans
aucune implémentation backend (vérifié par recherche exhaustive — aucun endpoint, DTO,
service ou Voter). Décision explicite de ne pas construire de bouton frontend pour un
contrat backend qui n'existe pas encore, plutôt que de fabriquer un comportement.

### Décision — notifications : cibles réelles, isolation de rôle inchangée

`NotificationTargetResolver` route les notifications mission chirurgien vers
`/app/s/missions/{id}` (au lieu du repli générique `/app/s`), et les notifications
planning agrégées vers `/app/s/planning`. Aucun changement d'isolation de rôle : une
notification reste spécifique au destinataire, même si le composant de détail sous-
jacent est partagé avec l'instrumentiste.

### Décision — synchronisation : toujours pas de généralisation par anticipation

Comme au Lot 1, `useInstrumentistMissionSync()` reste strictement instrumentiste ;
`SurgeonHomePage`/`SurgeonPlanningPage` s'appuient sur le comportement React Query par
défaut. Réévalué seulement si un besoin réel (pas de symétrie architecturale) le
justifie.

### Portée non traitée ici

Comme D-095 : Activité chirurgien réelle (podium, agrégats), absences self-service,
`SurgeonMissionRequest`, distinction `VIEW_ENCODING`/`EDIT_ENCODING`, signalement
d'anomalie, export `.ics`. Préparation navbar Absences (D-095) reconfirmée inchangée.

## D-097 — Absences self-service partagé Chirurgien + Instrumentiste (Lot 3, 2026-08-06)

Date : 2026-08-06

### Contexte

D-095 avait explicitement réservé ce chantier ("absences self-service, extension du
domaine `Absence` existant, forçant systématiquement `absence.user = current
authenticated user`, jamais un `userId` client, partagée à l'identique
instrumentiste/chirurgien") et posé la préparation de navbar nécessaire (§14.5). Ce lot
livre le module réel et active enfin l'onglet "Absences" dans les deux bottom nav.

### Décision — extension de `Absence`, jamais une deuxième entité

Le domaine existant (`Absence` : `user`, `dateStart`/`dateEnd` en `date_immutable`,
`reason` libre, `createdBy`, `createdAt`, aucun champ de statut) est réutilisé tel quel.
Aucune migration, aucun nouvel enum de motif — le champ `reason` reste un texte libre ;
l'UX propose des suggestions cliquables ("Congé", "Congrès / formation", "Garde /
récupération", "Indisponibilité", "Autre") qui ne font que préremplir le champ, jamais
une contrainte de valeur côté backend. `AbsenceRepository` n'existe pas dans ce
domaine (convention déjà en place : chaque service interroge `Absence` via l'EM
directement) — `SelfAbsenceController` suit la même convention plutôt que d'introduire
un repository isolé pour ce seul contrôleur.

### Décision — API self-service séparée, jamais un paramètre de rôle sur l'existant

Nouveau `SelfAbsenceController` (`/api/absences/mine`, GET/POST/PATCH/DELETE + GET
`/impact-preview`), entièrement distinct de `AbsenceController`
(`PLANNING_MANAGE`-only, manager/admin, inchangé par ce lot). Nouveau
`AbsenceVoter` (`SELF_ACCESS` — rôle SURGEON/INSTRUMENTIST uniquement, sans sujet ;
`SELF_MANAGE` — ownership + éditabilité, évalué sur une instance `Absence`).

**Invariant de sécurité absolu** : le contrôleur ne lit jamais `userId` depuis le
payload — `absence.user`/`absence.createdBy` valent systématiquement
`$currentUser`, sans exception, y compris si le client envoie un `userId` dans le
corps de la requête (silencieusement ignoré, jamais un 400 qui laisserait croire que
le champ a un effet). Pour GET (liste)/PATCH/DELETE, l'appartenance est toujours
revérifiée côté serveur (`AbsenceVoter::SELF_MANAGE`), jamais déduite de l'ID de route
seul. Testé explicitement (payload malveillant `{userId: 999}`, tentative de
modification/suppression de l'absence d'un tiers, isolation chirurgien/instrumentiste
dans les deux sens, non-régression du workflow manager existant).

### Décision — règle passé/futur : nouvelle, propre au self-service

`AbsenceController` (manager) n'impose aucune restriction temporelle sur
modification/suppression. Le self-service en introduit une, volontairement plus
stricte : une absence reste modifiable/supprimable tant que `dateEnd >= aujourd'hui`
(futur et en cours), et devient lecture seule une fois entièrement passée
(`AbsenceVoter::isStillEditable()`). Décision assumée, non déduite d'une règle
préexistante (il n'y en avait pas) — le manager conserve son pouvoir total, le
self-service ajoute un garde-fou UX propre à l'auto-déclaration.

### Décision — Jour unique / Période : convention backend jamais exposée à l'UX

Le backend représente toujours un jour unique par `dateStart === dateEnd` (inchangé,
même convention que D-050 côté manager). L'UX self-service impose un choix explicite
`[ Jour unique ] [ Période ]` (`AbsenceFormSheet`, segmented control) — reconstruit
depuis cette égalité uniquement en édition (une absence rouverte avec
`dateStart === dateEnd` présélectionne "Jour unique", sinon "Période"), jamais montré
ni demandé à l'utilisateur autrement.

### Décision — impact planning : aperçu réel, jamais bloquant, jamais un moteur dupliqué

`AbsenceImpactService` gagne une méthode `previewOverlappingMissions(User, dateStart,
dateEnd): Mission[]` — délègue à la même requête de recouvrement que `sync()`
(mêmes `ALERTABLE_STATUSES`), mais purement en lecture (aucune persistance, aucune
alerte, aucune notification). Exposée via `GET /api/absences/mine/impact-preview`,
utilisée à la fois pour l'aperçu live dans `AbsenceFormSheet` (avant sauvegarde) et
pour compter `missionsImpactedCount` dans la réponse de création/modification (même
requête, capturée avant toute mutation par `AbsenceMissionReactionService` — sans quoi
une mission auto-libérée/annulée sortirait de la requête d'`AbsenceImpactService` avant
d'avoir pu être comptée, voir l'ordre d'appel déjà documenté dans
`AbsenceMissionReactionService`). **Règle métier explicite : l'impact n'a jamais
bloqué la création** — `SelfAbsenceController::create()` persiste toujours l'absence,
quel que soit le nombre de missions concernées ; `AbsenceMissionReactionService`/
`AbsenceImpactService` existants (inchangés) traitent ensuite l'impact exactement comme
pour une absence créée par un manager (auto-libération/annulation des missions
actionnables, `PlanningAlert` pour les autres, notifications déjà existantes).

### Décision — notification manager : un vrai trou comblé, jamais un doublon

Constat (§16 du cahier des charges) : `AbsenceImpactService` ne notifie le manager que
lorsqu'une alerte est réellement levée (recouvrement avec une mission actionnable).
Avant ce lot, seul un manager créait une absence — il savait déjà. Le self-service
change cette hypothèse : un chirurgien/instrumentiste peut déclarer une absence sans
aucun recouvrement actuel, et le manager n'en apprenait alors strictement rien. Trou
comblé par un nouveau `NotificationType::ABSENCE_SELF_DECLARED` (in-app uniquement,
jamais email/push même si un manager l'active manuellement en préférence — voir
`AbsenceSelfDeclaredMessageHandler` — informationnel, pas urgent), dispatché de manière
async (`AbsenceSelfDeclaredMessage`, routé Messenger, jamais depuis la requête HTTP
elle-même) **uniquement** quand `AbsenceImpactService` n'a levé aucune nouvelle alerte
pour cette création/modification — jamais en doublon de `PLANNING_ALERT`. Pas de
nouvel `AuditEvent` : le flux manager existant (création d'une absence pour un tiers)
n'en produit déjà aucun, et en ajouter un uniquement côté self-service aurait été une
incohérence plutôt qu'une vraie correction — limite documentée explicitement (voir
rapport final Lot 3).

### Décision — frontend : un seul module, jamais deux pages par rôle

`features/self-absences/` (`selfAbsences.api.ts`/`.types.ts`, `AbsenceFormSheet.tsx`,
`AbsenceImpactPreview.tsx`, `AbsenceListItem.tsx`, `SelfAbsencesPage.tsx`) est monté
sans changement à la fois sur `/app/s/absences` et `/app/i/absences` — le composant
n'a aucune connaissance du rôle du viewer (tout le comportement différenciateur est
déjà résolu côté backend : contenu de la liste, `counterpart` de l'impact planning).
Jamais de `SurgeonAbsencesPage`/`InstrumentistAbsencesPage`.

### Décision — navbar : Absences devient un onglet direct, retiré du menu "Plus"

Conformément à la préparation D-095 (§14.5, 3 touch-points : `TabKey`,
`WAVE_SHAPE_KEY`, les deux tableaux de tabs) : chirurgien
`Accueil | Planning | Absences | Activité | Plus`, instrumentiste
`Aujourd'hui | Planning | Offres | Absences`. L'entrée "Mes indisponibilités" du menu
"Plus" chirurgien (Lot 1) est retirée — jamais deux points d'accès différents vers le
même écran une fois qu'il existe un onglet direct.

### Portée non traitée ici

Comme D-095/D-096 : Activité chirurgien réelle, `SurgeonMissionRequest`, distinction
`VIEW_ENCODING`/`EDIT_ENCODING`, signalement d'anomalie, export `.ics`. Absence
d'`AuditEvent` sur le flux self-service (voir décision ci-dessus) — limite assumée,
pas un oubli, à revisiter si le domaine `Absence` gagne un jour un audit trail
généralisé.

---

## D-098 — Activité chirurgien + podium personnel (Lot 4, 2026-08-07)

Date : 2026-08-07

### Contexte

D-097 avait explicitement réservé ce chantier ("Activité chirurgien réelle") et
préparé les 3 points de navbar (`TabKey`, `WAVE_SHAPE_KEY`, `SURGEON_TABS`) — l'onglet
"Activité" existait déjà, pointant vers `ComingSoonPage`. Ce lot livre l'écran réel :
une page d'activité personnelle simple, jamais un classement entre chirurgiens.

### Décision — source de vérité : `MissionIntervention`, jamais `MissionInterventionDraft`

`MissionInterventionDraft` (EPIC Revue instrumentiste, Lot 3) représente une ligne
*provisoire*, avant résolution du matériel — jamais comptée. Seule `MissionIntervention`
(la cible "réelle" du contrat, toujours `CATALOGUED`) rattachée à une `Mission` dont
`mission.surgeon = utilisateur authentifié` alimente l'agrégat.

### Décision — règle de comptage centralisée : `VALIDATED`/`CLOSED` uniquement

`SurgeonActivityService::COUNTED_STATUSES` (`[MissionStatus::VALIDATED,
MissionStatus::CLOSED]`) est l'unique source de vérité — aucun autre service ni
repository ne recopie cette règle. Les 9 autres statuts existants (`DRAFT`, `OPEN`,
`DECLARED`, `ASSIGNED`, `REJECTED`, `SUBMITTED`, `IN_PROGRESS`, `CANCELLED`,
`ENCODING_IN_PROGRESS`) sont explicitement exclus, testés un par un
(`SurgeonActivityControllerTest::test_status_counting_matches_rule`, dataProvider).

### Décision — groupement : `InterventionType.id` réel, `mi.code` en fallback legacy, jamais de fusion par ressemblance de label

Depuis le Lot 5 catalogue (D-068), `MissionIntervention.interventionType` est une FK
nullable — non-null pour tout encodage réel, `null` uniquement pour les lignes
historiques pré-Lot 5 (ex. mission #529). Le groupement utilise donc deux requêtes
disjointes : (1) `interventionType IS NOT NULL` → groupé par `it.id`, libellé = **`it.label`
courant du référentiel** (toujours à jour, contrairement au snapshot figé
`mi.code`/`mi.label`) ; (2) `interventionType IS NULL` → groupé par `mi.code` (seul
identifiant stable disponible pour ces lignes), libellé = `mi.label` (snapshot, faute
de mieux). Les deux groupes ne sont **jamais fusionnés entre eux**, même si un label se
ressemble — aucune résolution par similarité de texte. `interventionTypeId` vaut `null`
dans la réponse API pour le second groupe, jamais un id inventé.

### Décision — 3 requêtes DQL agrégées, aucun N+1, `interventionCount` dérivé en PHP

`SurgeonActivityService::getActivity()` exécute exactement 3 requêtes : `missionCount`
(COUNT sur `Mission` seule, utilise l'index existant `idx_mission_start_status`),
le groupement "réel" (JOIN INNER `interventionType`) et le groupement "legacy"
(`interventionType IS NULL`) — les deux derniers utilisent l'index existant
`idx_intervention_mission` pour la jointure `Mission`. `interventionCount` est la
somme PHP des deux groupes — jamais une 4e requête. Tri : `count` DESC puis `label`
ASC (tie-break déterministe, `strcmp`).

### Décision — `missionCount != interventionCount` : deux comptages distincts, jamais confondus

`missionCount` compte les `Mission` distinctes (VALIDATED/CLOSED, période, self-scopé),
indépendamment du nombre d'interventions qu'elles contiennent — une mission peut
contenir plusieurs `MissionIntervention`. Testé explicitement : 1 mission / 3
interventions → `missionCount = 1`, `interventionCount = 3`.

### Décision — API : `GET /api/surgeon/activity`, self-scopé, jamais de `surgeonId`

Nouveau `SurgeonActivityController` (`/api/surgeon/activity`, GET, params `from`/`to` —
même contrat de validation qu'`SurgeonController::planning()` existant : ISO 8601,
`from` strictement avant `to`). Nouveau `SurgeonActivityVoter::SELF_ACCESS` (rôle
`ROLE_SURGEON` uniquement, sans sujet — mirrors `AbsenceVoter::SELF_ACCESS`, D-097).
Le contrôleur résout toujours l'utilisateur authentifié (`#[CurrentUser]`) ; un éventuel
`?surgeonId=` dans la requête n'est jamais lu, testé explicitement
(`test_surgeon_id_query_param_is_ignored`). INSTRUMENTIST et MANAGER reçoivent 403 —
strictement self-service chirurgien, jamais une vue managériale déguisée. Aucune donnée
patient, tarif, montant ou rémunération instrumentiste dans la réponse (testé).

### Décision — période Mois/Année, pas de date range picker

`features/surgeon-activity/period.ts` calcule des intervalles demi-ouverts `[from, to)`
(même convention que `getRange()` dans `mobile-planning/planningPrimitives`), pilotés
par un mode `"month" | "year"` + une date de référence, avec navigation `< >` — jamais
de sélecteur de plage libre en V1. Défaut : Année en cours (volumes plus significatifs
pour un podium que la vue mensuelle).

### Décision — frontend : un seul hook partagé, Home et Activity share le même cache React Query

`useSurgeonActivity(mode, referenceYmd)` calcule la `queryKey` à partir du range dérivé
— `SurgeonHomePage` (année en cours, mêmes défauts que `SurgeonActivityPage` ouverte
sans paramètres d'URL) et `SurgeonActivityPage` elle-même produisent donc la **même**
`queryKey` dans le cas par défaut : une seule requête réseau, jamais un second calcul
côté Home. `PodiumRows` (composant partagé, `showHeading` optionnel) rend le podium à
l'identique sur les deux pages — jamais un second composant "podium Home" dupliqué.

### Décision — podium personnel, jamais un classement

Le podium (`🥇🥈🥉`, top 3 `interventions.slice(0, 3)`, déjà triées côté backend) ne
montre que les types d'intervention du chirurgien connecté — jamais le nom d'un autre
chirurgien, jamais un rang, jamais une comparaison inter-chirurgiens. Moins de 3 types
→ podium partiel (2 ou 1 case) ; aucun type → pas de podium du tout (jamais 3 cases
vides). Home : section entièrement absente si `interventions.length === 0` (jamais une
grande section vide, §12) — même règle sur la page Activity elle-même (empty state
dédié : "Aucune intervention validée pour cette période").

### Portée non traitée ici

Comme D-095/D-096/D-097 : `SurgeonMissionRequest`, distinction
`VIEW_ENCODING`/`EDIT_ENCODING`, signalement d'anomalie, export `.ics`. Drill-down
(clic sur une catégorie → liste des missions correspondantes) volontairement reporté —
nécessiterait un endpoint supplémentaire non justifié par ce lot (§13 du cahier des
charges). Aucun nouvel `AuditEvent` (endpoint en lecture seule, aucune mutation).

---

## D-099 — Demande de mission chirurgien : SurgeonMissionRequest (Lot 5, 2026-08-08)

Date : 2026-08-08

### Contexte

Le chirurgien n'a jamais reçu, et ne reçoit toujours pas, `MissionVoter::CREATE` — il ne
peut pas créer de Mission lui-même. Ce lot lui donne un moyen d'exprimer une intention
("j'aimerais une mission le X à tel site") sans jamais contourner ce garde-fou : seul un
manager/admin peut convertir une demande acceptée en Mission officielle.

**Décision structurante : `SurgeonMissionRequest` est une intention chirurgien, jamais
une Mission. Seul Manager/Admin peut convertir une demande acceptée en Mission
officielle, de façon atomique et auditée.**

### Décision — domaine dédié, jamais un détournement d'un domaine existant

Ni `MaterialItemRequest`/`InterventionTypeRequest` (référentiel catalogue, concept
métier différent), ni `Mission` à l'état `DECLARED`/`DRAFT` (représentent déjà une
Mission réelle, alors qu'ici aucune Mission n'existe tant que la demande n'est pas
acceptée). Nouvelle entité `SurgeonMissionRequest` (`surgeon`, `site`, `type`
[réutilise `MissionType`, aucun nouvel enum parallèle], `startAt`/`endAt`
[`business_datetime_immutable`, même type que `Mission`, D-066 — jamais le bug UTC
historique du Planning V2], `comment`, `status` [`PENDING`/`ACCEPTED`/`REJECTED`,
aucun `CANCELLED` en V1], `reviewedBy`/`reviewedAt`/`reviewComment`,
`createdMission` [FK nullable vers `Mission`, contrainte unique — une demande ne peut
jamais être liée à deux Missions]). Réutilise en revanche les bons patterns
architecturaux déjà établis par ces domaines : requester, status simple, review
manager, transaction, `AuditEvent`, notifications async, Voter dédié, timestamps.

### Décision — atomicité de l'acceptation : transaction unique, verrou pessimiste

`SurgeonMissionRequestService::accept()`/`reject()` sont entièrement dans un
`$em->wrapInTransaction()`, avec `$em->lock($request, LockMode::PESSIMISTIC_WRITE)` +
`$em->refresh($request)` posés AVANT toute décision — même pattern que
`MissionInterventionDraftService::resolve()` (EPIC Revue instrumentiste, Lot 3). Il est
donc structurellement impossible d'observer `status=ACCEPTED` avec
`createdMission=null` : soit toute la transaction commit (Mission créée + demande
transitionnée + audit, un seul commit), soit elle rollback entièrement.

**Concurrence (§22)** : deux managers qui `accept()`/`reject()` la même demande en
parallèle — le second à obtenir le verrou relit `status`, le trouve déjà
ACCEPTED/REJECTED (état terminal, V1 n'autorise aucune transition depuis un état
terminal), lève `SurgeonMissionRequestAlreadyReviewedException` (409,
`SURGEON_MISSION_REQUEST_ALREADY_REVIEWED`) plutôt que de rejouer la transition.
Testé explicitement : double `accept()`, `accept()` après `reject()`, `reject()`
après `accept()`.

**Conflits planning (§23)** : avant de créer la Mission, `accept()` réutilise
`PlanningConflictDetectionService::findConflict()` tel quel (même moteur que le reste
du planning V2, jamais une seconde implémentation de la détection de chevauchement).
Un conflit fait échouer l'acceptation proprement
(`SurgeonMissionRequestConflictException`, 409, `SURGEON_MISSION_REQUEST_CONFLICT`)
**avant toute écriture** — la demande reste `PENDING`, `createdMission` reste `null`,
aucune Mission fantôme n'est créée. Testé explicitement (comptage de missions avant/
après, aucune variation).

### Décision — statut de la Mission créée : `DRAFT`, fondée sur le code existant

`MissionService::create()` est l'unique point d'entrée officiel de création Mission
(R-04) — il pose systématiquement `status = MissionStatus::DRAFT`, quel que soit
l'appelant, y compris pour la création manager ad hoc existante (`MissionCreatePage`).
La publication vers le pool (`OPEN`) est un second geste explicite et distinct
(`MissionController::publish()`, son propre Voter `MISSION_PUBLISH`, sa propre
notification async) — jamais automatique à la création. `SurgeonMissionRequestService::
accept()` réutilise `MissionService::create()` tel quel (R-04 : toute mutation Mission
passe par l'application service, jamais un `new Mission()` ad hoc dans ce lot) — la
Mission créée est donc `DRAFT` par construction, cohérente avec le flux manager
existant : le manager peut encore l'ajuster/l'assigner avant de la publier
explicitement. Décision fondée sur le code observé, jamais une hypothèse.

### Décision — éligibilité site : réutilisation de `SiteMembership`

Le chirurgien ne peut demander une mission que pour un site auquel il est
effectivement affilié (`SiteMembership.user`/`site`, entité déjà existante — aucune
nouvelle table). Revérifié côté serveur à la création (jamais confiance dans un
`siteId` arbitraire) — testé explicitement (site non affilié → 403).

### Décision — sécurité : `surgeon` toujours forcé serveur, jamais un `surgeonId` client

Même invariant absolu que D-097 (Absences self-service) : le contrôleur ne lit jamais
`surgeonId`/`status`/`reviewedBy`/`createdMissionId` depuis le payload client — ces
champs sont systématiquement calculés/forcés côté serveur. Testé explicitement
(payload malveillant avec `surgeonId` d'un tiers, toujours silencieusement ignoré).

### Décision — Voter dédié, jamais `BillingVoter::MANAGE`

`SurgeonMissionRequestVoter` : `SELF_ACCESS` (rôle SURGEON, sans sujet — création/
liste chirurgien) et `MANAGE` (rôle MANAGER/ADMIN, sans sujet — liste/accept/reject
manager). Domaine planning/mission, pas catalogue/facturation — jamais
`BillingVoter::MANAGE`, qui reste réservé à son périmètre existant
(`InterventionTypeRequestManagerController` notamment).

### Décision — notifications : même famille que D-093 (propositions catalogue)

`SURGEON_MISSION_REQUEST_CREATED` (chirurgien → managers/admins actifs, in-app + push
uniquement, jamais email — pas urgent, le manager la retrouve sur l'onglet dédié,
même raisonnement que `CATALOGUE_REQUEST_CREATED`) ;
`SURGEON_MISSION_REQUEST_ACCEPTED`/`REJECTED` (manager → chirurgien requester, push
d'abord puis repli email si non livrable, même orchestration que
`CATALOGUE_REQUEST_RESOLVED`/`IGNORED` — actionnable/attendu). Aucun système de
notification parallèle inventé : mêmes `NotificationPreferenceResolver`,
`OutboundNotificationService`, Messenger async, `NotificationTargetResolver` (deux
nouvelles routes de deep-link : demande créée → `/app/m/missions/requests` pour un
manager ; demande refusée → `/app/s/requests` pour le chirurgien, aucune Mission
n'existant dans ce cas ; une demande acceptée retombe naturellement sur la route
générique `/app/s/missions/{id}`, une Mission existe désormais).

### Décision — audit : rigoureux, contrairement à l'ancien flux `MaterialItemRequest`

`SURGEON_MISSION_REQUEST_CREATED` (`recordGlobal()`, aucune Mission n'existe encore),
`SURGEON_MISSION_REQUEST_ACCEPTED` (`record()` sur la Mission fraîchement créée —
FK exploitable depuis le détail mission), `SURGEON_MISSION_REQUEST_REJECTED`
(`recordGlobal()`, aucune Mission n'existe ni n'existera). Payload sans donnée
patient : snapshot nom chirurgien, site, dates/heures, type, acteur manager,
`createdMissionId` (ACCEPTED) ou `reviewComment` (REJECTED).

### Décision — placement manager : onglet contextuel de `MissionsListPage`, jamais une nouvelle section top-level

`Catalogue > Demandes` existe déjà (`CatalogueRequestsPage`, référentiel
InterventionType/MaterialItem) — y mélanger `SurgeonMissionRequest` (planning/
scheduling, domaine distinct) aurait été incohérent. `MissionsListPage` avait déjà un
précédent exact pour ce besoin : l'onglet "À valider" bascule entre deux ROUTES
(`/app/m/missions` / `/app/m/missions/to-validate`) rendues par le même composant,
qui adapte son contenu via `location.pathname`. Un troisième onglet "Demandes
chirurgien" (`/app/m/missions/requests`) suit exactement ce même principe — badge
`PENDING` sur l'onglet via `useNavBadgeCount` (généralisé depuis le badge Catalogue
existant, aucun nouveau mécanisme de polling).

### Décision — formulaire chirurgien : nouveau, jamais une recopie du formulaire manager

`DeclareMissionPage` (instrumentiste, `/app/i/missions/declare`) est le précédent
direct le plus proche : `SheetModal` plein écran, `StepperRow` pour date/heures
(jour + minutes par pas de 15, bascule "lendemain"), `SelectField` pour
site/type, `business_datetime_immutable`-compatible côté composition. Le formulaire
chirurgien (`SurgeonMissionRequestFormPage`, `/app/s/mission-requests/new`) reprend
cette recette à l'identique, simplifiée : pas de sélecteur chirurgien (toujours
l'utilisateur authentifié), site limité aux affiliations réelles (`GET /api/me`,
`sites[]`, déjà existant — aucun nouvel endpoint). CTA "+ Demander une mission"
accessible depuis Home ET Planning chirurgien, jamais une 6ᵉ entrée navbar.

### Portée non traitée ici

Ajustement des informations (site/date/heures) par le manager avant acceptation
(§9 du cahier des charges le recommandait — "je recommande" — sans l'imposer) :
scope réduit à un commentaire de revue optionnel + accepter/refuser, pour éviter
d'introduire une seconde surface de DTO éditable non budgétée par ce lot ; le manager
peut refuser avec motif et laisser le chirurgien soumettre une nouvelle demande
ajustée. Drill-down, export `.ics`, annulation d'une demande `PENDING` par le
chirurgien (V1 : lecture seule, §19).

## D-100 — Consultation chirurgien de l'encodage + signalement d'anomalie (Lot 6, 2026-08-09)

Date : 2026-08-09

### Contexte

Le chirurgien n'a aucune visibilité sur ce que l'instrumentiste encode pour ses
missions (interventions, matériel, heures) — il ne peut ni le vérifier ni signaler une
erreur constatée. Ce lot ferme la boucle Mission → encodage instrumentiste →
consultation chirurgien → signalement éventuel → traitement manager, sans jamais
donner au chirurgien la moindre capacité d'écriture sur l'encodage.

**Décision structurante : le chirurgien consulte en lecture strictement seule ; toute
correction continue de passer par les workflows existants (édition instrumentiste,
reject/reopen manager) — signaler une anomalie n'est jamais couplé automatiquement à
une correction.**

### Décision — `VIEW_ENCODING` distinct d'`EDIT_ENCODING`, jamais un élargissement des droits d'écriture

Audit préalable de `GET /api/missions/{id}/encoding` (`MissionController::getEncoding()`)
et de son voter : gardé derrière `MissionVoter::EDIT_ENCODING`, ce qui interdisait par
construction toute consultation par un rôle qui n'a pas le droit d'éditer — y compris
le chirurgien. Nouvel attribut `MissionVoter::VIEW_ENCODING` (lecture seule) posé sur
ce même endpoint GET, `EDIT_ENCODING` restant l'unique porte pour les mutations
(inchangé, 0 régression — 63/63 tests de régression existants toujours verts). Manager/
Admin : toujours autorisé. Instrumentiste : exactement les mêmes statuts que
`canEditEncoding()` (qui peut éditer peut évidemment consulter). Chirurgien :
`mission.surgeon === currentUser`, statuts où un encodage existe ou a existé
(`DECLARED`/`ASSIGNED`/`IN_PROGRESS`/`ENCODING_IN_PROGRESS`/`SUBMITTED`/`VALIDATED`/
`CLOSED` — jamais `DRAFT`/`OPEN`, aucun instrumentiste assigné ; jamais
`REJECTED`/`CANCELLED`, mission jamais réellement advenue).

**Effet de bord bénéfique découvert pendant l'audit, pas une régression :** l'ancien
couplage bloquait aussi le manager sur `VALIDATED`/`CLOSED`/`REJECTED`
(`MissionEncodingGuard::assertEncodingAllowed()`, un garde-fou d'ÉCRITURE
— verrou `encodingLockedAt`/fenêtre instrumentiste — appelé à tort sur le chemin de
LECTURE). Un contournement de ce bug était déjà documenté côté frontend manager. Ce
lot retire l'appel au guard du chemin `GET` (aucune mutation n'y est jamais possible,
`guard` reste appelé sur tous les endpoints d'écriture, inchangé) — le manager peut
désormais consulter l'encodage d'une mission `VALIDATED`/`CLOSED` sans contournement.

### Décision — endpoint et DTO existants réutilisés tels quels, jamais un endpoint parallèle

`GET /api/missions/{id}/encoding` reste l'unique point d'entrée (attribut d'autorisation
swappé, DTO inchangé dans sa forme) — pas de
`GET /api/surgeon/missions/{id}/encoding` parallèle. Justifié par l'audit champ par
champ (§ suivant) : le DTO existant s'est révélé sûr après un correctif ciblé, sans
nécessiter de divergence de contrat.

### Décision — audit champ par champ du DTO : `billingStatus` était une fuite réelle, corrigée à la source

Le contrat exige qu'un chirurgien ne voie jamais de donnée financière — `PricingRule`,
`computedAmount`, `fee`, `tarif`, `invoice`, **billing state**, salaire/taux
instrumentiste, donnée patient. Un test de contrat (assertion sur le JSON brut,
`MissionViewEncodingTest::test_encoding_dto_never_contains_financial_or_patient_fields`)
a détecté `materialLines[].item.billingStatus` (`MaterialItemSlimDto`, `UNSPECIFIED`/
`BILLABLE`/`NOT_BILLABLE` — classification catalogue D-092, pas un montant, mais
littéralement un "billing state"). Ce champ est légitime pour l'instrumentiste
(recherche matériel pendant l'encodage) et le manager (catalogue) — jamais globalement
supprimé de `MaterialItemSlimDto`. Correctif ciblé : `MaterialItemMapper::toSlim()`
gagne un second paramètre `bool $includeBillingStatus = true` (défaut inchangé pour
tous les appelants existants), `MissionEncodingService::buildEncodingDto()` le passe à
`false` uniquement pour un viewer chirurgien (catalogue, matériels suggérés, lignes de
matériel — les trois points d'appel). `MaterialItemSlimDto::$billingStatus` devient
`?string` (défaut `null`) ; le contrôleur ajoute `AbstractObjectNormalizer::
SKIP_NULL_VALUES` **uniquement pour la réponse chirurgien** (jamais pour manager/
instrumentiste, dont la forme JSON reste strictement inchangée — un `null` explicite
aurait laissé la clé visible, insuffisant face à l'exigence "jamais voir").

### Décision — domaine dédié `EncodingAnomalyReport`, jamais un détournement de `MaterialItemRequest`

`MaterialItemRequest` est un concept catalogue (proposition de nouveau matériel), pas
un signalement de désaccord sur un encodage déjà réalisé. Nouvelle entité minimale :
`id`, `mission`, `reporter`, `type` (`INTERVENTION_MISSING`/`INTERVENTION_INCORRECT`/
`MATERIAL_INCORRECT`/`HOURS_INCORRECT`/`OTHER`), `comment` (toujours requis, y compris
`OTHER`), `status` (`OPEN`/`RESOLVED` uniquement — pas de workflow multi-étapes en V1),
`resolvedBy`/`resolvedAt`/`resolutionComment`, timestamps. Résolution manager/admin
uniquement en V1 — aucune capacité de résolution instrumentiste dans ce lot.

**Duplication accidentelle (§14) :** un seul signalement `OPEN` à la fois par
(mission, reporter) — `409` sinon. Un nouveau signalement redevient possible une fois
le précédent `RESOLVED` (un second problème peut être découvert plus tard). Testé
explicitement (E2E réel : création → résolution → nouvelle création acceptée).

**Concurrence — double résolution :** même pattern transactionnel que D-099
(`$em->wrapInTransaction()` + `LockMode::PESSIMISTIC_WRITE` + `refresh()` avant
revalidation de `status === OPEN`) — la seconde résolution simultanée échoue proprement
(`409 ENCODING_ANOMALY_REPORT_ALREADY_RESOLVED`, `EncodingAnomalyReportAlreadyResolvedException`),
jamais un second `AuditEvent`/notification. Testé explicitement (double `resolve()`).

### Décision — résoudre ne corrige jamais l'encodage automatiquement

`EncodingAnomalyReportService::resolve()` ne mute jamais `MissionIntervention`/
`MaterialLine` — uniquement le signalement lui-même (`status`, `resolvedBy`,
`resolvedAt`, `resolutionComment`). La correction réelle, si nécessaire, continue
d'utiliser les workflows existants (édition instrumentiste tant que la mission n'est
pas verrouillée, reject/reopen manager sinon) — deux actions toujours distinctes,
jamais couplées. Vérifié explicitement (comptage d'interventions avant/après
résolution, aucune variation) en test et en E2E réel.

### Décision — Voter dédié `SELF_ACCESS`/`MANAGE`, même famille que D-097/D-099

`EncodingAnomalyReportVoter` : `SELF_ACCESS` (rôle SURGEON, sans sujet — création ;
l'appartenance à la mission est revérifiée dans le service) et `MANAGE` (rôle
MANAGER/ADMIN, sans sujet — résolution). La liste (`GET`) reste gérée directement dans
le contrôleur (self-scopée pour le chirurgien de la mission, ouverte pour manager/
admin) — jamais un instrumentiste, jamais un autre chirurgien.

### Décision — notifications : même orchestration que D-093/D-099

`ENCODING_ANOMALY_REPORTED` (chirurgien → managers/admins actifs, in-app + push
uniquement, jamais email) ; `ENCODING_ANOMALY_RESOLVED` (manager → chirurgien
requester, push d'abord, repli email si non livrable). `AuditEvent` sur les deux
transitions (`ENCODING_ANOMALY_REPORTED`/`ENCODING_ANOMALY_RESOLVED`, `record()` sur la
Mission — jamais de donnée patient dans le payload).

### Décision — placement manager : intégré au détail Mission existant, jamais une nouvelle page de listing

Fondé sur l'UX actuelle observée, pas sur une abstraction théorique : les anomalies
sont rares et intrinsèquement liées à une Mission précise — `AnomalyReportsManagerPanel`
s'intègre directement dans `manager/MissionDetailPage.tsx` (aucune section rendue tant
qu'aucun signalement n'existe pour la mission — la grande majorité des missions n'en
ont jamais). Jamais une nouvelle route top-level de listing des signalements.

### Décision — présentation chirurgien strictement lecture, composants dédiés

`InterventionsSection` (éditeur instrumentiste : dialogs, `useMutation`, drag/drop)
n'est jamais réutilisé avec ses boutons masqués — un composant d'édition dont les
boutons sont cachés reste un composant d'édition (handlers, état de formulaire,
mutations latents). Nouveaux composants dédiés, purement lecture, sans aucun handler
de mutation : `ReadOnlyInterventionCard`, `ReadOnlyMaterialList`,
`EncodingHoursSummary` (`features/surgeon-encoding/components/`). Catalogue matériel
non pertinent pour une vue lecture seule (rien à sélectionner) — jamais rendu côté
chirurgien.

### Décision — formulaire de signalement : boutons radio, jamais un menu déroulant

`AnomalyReportSheet` (`SheetModal`, même pattern que D-097/D-099) — sélection du type
via `RadioGroup` (5 options, jamais un `<select>`), commentaire toujours requis (y
compris `OTHER`). Après succès, invalidation immédiate de la query
`["encodingAnomalyReports", missionId]` — retour visuel instantané (bandeau "en
attente"), jamais de rechargement manuel. Un signalement `OPEN` masque le CTA
"Signaler un problème" côté chirurgien (reflète côté client la règle serveur anti-
duplication, §14) ; un signalement `RESOLVED` affiche la réponse du manager et
réaffiche le CTA (nouveau signalement possible).

### Décision — jamais une grosse carte sur la Home chirurgien

Le statut d'un signalement (en attente / traité) ne vit que sur l'écran d'encodage
(`/app/s/missions/{id}/encoding`), jamais sur `SurgeonHomePage` — un signalement
résolu se signale via les notifications existantes, la Mission (et son écran
d'encodage) reste la surface principale, conformément au principe déjà établi pour
d'autres domaines de ce lot (D-097 à D-099 : jamais de doublon d'affichage entre une
carte Home et l'écran détaillé).

### Validation E2E réelle (contournement Chrome persistant, même stratégie que depuis le Lot 4)

Scénario complet exécuté via HTTP réel (curl) contre la stack Docker locale (comptes
seedés `php bin/console app:seed`, mission/encodage créés via une commande console
temporaire, supprimée après usage) : consultation chirurgien (200, aucun champ
financier dans le JSON brut) → mutation chirurgien refusée (403) → création
signalement (201, OPEN) → liste self-scopée (200) → doublon refusé (409) → manager
consulte (200) → résolution (200, RESOLVED) → double résolution refusée (409,
`ENCODING_ANOMALY_REPORT_ALREADY_RESOLVED`) → chirurgien voit RESOLVED → nouveau
signalement post-résolution accepté (201) → chirurgien tiers non lié à la mission
bloqué (403 sur consultation ET sur liste des signalements). Toutes les lignes créées
(mission, intervention, ligne matériel, firme, item, type, site, 2 signalements,
3 `AuditEvent`) nettoyées après validation — base locale laissée dans l'état où elle a
été trouvée.

### Portée non traitée ici

Résolution instrumentiste (V1 : manager/admin uniquement, §12). Workflow multi-étapes
de résolution (V1 : `OPEN`/`RESOLVED` seulement). Correction automatique de l'encodage
couplée à la résolution (décision explicite contraire, voir plus haut). Historique des
signalements résolus au-delà du dernier (le chirurgien voit le plus récent ; un
historique complet n'est pas demandé par ce lot).

---

## D-101 — Planning V2 : revalidation backend obligatoire de toute affectation/réaffectation d'instrumentiste (Lot 1, 2026-08-10)

Date : 2026-08-10

### Contexte — incident réel

Sophie Collette (instrumentiste), absente du 01/08 au 16/08/2026 (absence créée le
24/06/2026), s'est retrouvée `ASSIGNED` à la mission du 14/08/2026 (Delta, 08:00–18:00),
en plein milieu de sa propre absence. Investigation en lecture seule sur la production
(voir chronologie complète établie avant ce lot) : la mission a été créée et affectée
directement par `PlanningGeneratorServiceV2::generate()`, branche `overrideLines`
(`$mission->setInstrumentist($newInstrumentist)`, sans aucune revalidation), alors que
`preview()` — qui, lui, applique correctement `isAbsentFast()` — n'était pas ce qui a
produit l'affectation finale : l'éditeur (Preview Editor) envoie systématiquement le
tableau de lignes complet à `generate()`, donc toute ligne avec un `existingMissionId`
passe par cette branche, éditée ou non. Aucune `PlanningAlert` n'a jamais existé pour ce
cas — pas un problème de visibilité, un vrai trou de détection côté génération. Un
manager a corrigé le symptôme à la main le 09/08 (`release()`) sans que le système ne
l'ait jamais su.

### Décision — une source canonique unique, étendue au cas réassignation

`MissionEligibilityService::evaluate()` (D-057) restait insuffisant pour ce cas : il
exige `status === OPEN` et traite tout instrumentiste déjà présent comme
`ALREADY_ASSIGNED` — inutilisable pour un assign/reassign sur une mission déjà
`ASSIGNED`/`DRAFT`. Nouvelle méthode publique
`evaluateForReassignment(Mission, User, ?int $excludeMissionId)`, qui réutilise
exactement les mêmes requêtes (extraites en méthodes privées partagées
`isAbsentOn()`/`hasScheduleConflict()`) et ne vérifie que les 3 raisons pertinentes à un
assign/reassign **piloté par un manager** : `ABSENT`, `SCHEDULE_CONFLICT`, `INACTIVE` —
jamais `INCOMPATIBLE_STATUS`/`ALREADY_ASSIGNED`, qui n'ont pas de sens ici. Une seule
implémentation de la requête absence et une seule du conflit dans tout le code, partagée
par `evaluate()` et `evaluateForReassignment()`.

**Décision explicite — `NO_SITE_MEMBERSHIP` volontairement exclu ici.** Câbler ce
contrôle dans `assign()`/`reassign()`/`updateSchedule()`/`createPostDeploy()` cassait 13
tests fonctionnels (`MissionAssignInstrumentistControllerTest`,
`MissionLifecycleControllerTest`, `PlanningModificationControllerTest`,
`PlanningModificationTimezoneTest`, `PlanningCrossSiteConflictTest`) — aucun d'entre eux
n'a jamais configuré de `SiteMembership` pour l'instrumentiste affecté/réaffecté,
confirmant qu'un manager a toujours pu cibler n'importe quel instrumentiste
indépendamment de son affiliation formelle au site sur ces chemins. Transformer cela en
nouvelle règle bloquante aurait dépassé le périmètre de ce lot (le bug réel ne portait
que sur `ABSENT`) et entériné silencieusement une politique métier jamais discutée.
`NO_SITE_MEMBERSHIP` reste vérifié exactement où il l'était déjà avant ce lot :
`evaluate()` (`claim()` self-service) et les listes de candidats
(`evaluateAllCandidates()`/`findEligible()`). Étendre cette contrainte aux
affectations manager reste une décision métier/UX distincte, à auditer séparément si
besoin — non tranchée ici.

### Décision — chemins gardés

Revalidation ajoutée avant toute mutation d'instrumentiste, partout où elle manquait :
`MissionService::assignInstrumentistDraft()`, `MissionPostDeployService::assign()`,
`reassign()`, `updateSchedule()` (revalide l'instrumentiste courant contre le *nouveau*
horaire, réversion complète avant de lever l'exception si incompatible),
`createPostDeploy()`, et les quatre points de `PlanningGeneratorServiceV2::generate()`
qui appellent `setInstrumentist()` (branche `overrideLines`, branche `MODIFIED`, cas
`freedFrom`, et la création d'une mission entièrement nouvelle). Chemins déjà corrects,
non touchés : `claim()` (`evaluate()`), `PlanningAlertActionService::reassign()` (délègue
déjà à `PlanningConflictDetectionService`, D-091), le moteur `preview()` lui-même (hors
périmètre — "ne pas refaire le moteur de génération").

### Décision — contrat d'erreur partagé : `409 INSTRUMENTIST_INCOMPATIBLE`

Nouvelle exception `InstrumentistIneligibleException` (mappée par
`ApiExceptionSubscriber`), `error.violations` = une entrée
`{field: "instrumentistId", message: "<raison>"}` par cause en échec — même convention
que les autres 409/422 métier de ce contrôleur, aucun nouveau format de réponse.

### Décision — `generate()` ne bloque jamais tout un mois pour une ligne incompatible

Contrairement aux autres chemins (qui rejettent l'appel HTTP), la branche `overrideLines`
de `generate()` ne lève jamais d'exception : un candidat inéligible est silencieusement
rétrogradé à "sans instrumentiste" (la ligne devient `UNCOVERED`) plutôt que de faire
échouer toute la génération du mois — mais jamais silencieux pour autant. Nouveau champ
additif `rejectedAssignments` dans la réponse (`{missionId, date,
requestedInstrumentistId, requestedInstrumentistName, reasons[]}`), pour que le manager
soit informé explicitement de chaque neutralisation. Documenté dans `docs/api.md`.

### Non traité dans ce lot

UX "fantôme" (personne indisponible visible mais non sélectionnable dans les
sélecteurs) — Lot 2. Absence chirurgien/instrumentiste comme événement métier actif
(neutralisation réversible, restauration après suppression d'absence, notifications
enrichies, postes récurrents) — Lots 3–5. Bouton manuel "Vérifier les conflits" par
`PlanningVersion` — Lot 6. Ces lots seront chacun redétaillés et validés séparément
avant implémentation.

---

## D-102 — Planning V2 : UX fantôme uniforme des instrumentistes inéligibles (Lot 2, 2026-08-10)

Date : 2026-08-10

### Contexte

Suite du Lot 1 (D-101) : chaque sélecteur d'instrumentiste lié au planning (Preview
Editor, assignation manuelle, réassignation, alerte, ajout post-déploiement) affichait
soit la liste brute et non filtrée (aucune indication d'indisponibilité), soit une
annotation ad hoc calculée côté frontend (absence du jour via un appel séparé à
`getAbsences()`, "déjà affecté ailleurs" recalculé localement) — deux implémentations
divergentes de la même règle métier, aucune ne couvrant `INACTIVE`/`SCHEDULE_CONFLICT`.
Objectif : une personne indisponible reste **visible** (pour expliquer pourquoi elle
n'est pas choisie) mais **non sélectionnable**, partout, à partir d'une unique source de
vérité backend.

### Décision — DTO `CandidateEligibility` unique, jamais filtré

Chaque endpoint de liste (`/api/missions/{id}/eligible-instrumentists`,
`/api/planning/alerts/{id}/eligible-instrumentists`, nouveau
`/api/planning/v2/eligible-instrumentists`) retourne désormais tout le roster annoté,
plus jamais un split `{eligible[], ineligible[]}` ni une liste pré-filtrée :
`{id, name, email, eligible, selectable, reasons[], unavailability, conflict}`, plus
`sites[]` sur l'endpoint alerte. `eligible` est un fait brut (raisons vides) ;
`selectable` est **déjà contextualisé côté backend** sous la `policy` de la réponse
(`EligibilityResult::selectableUnder(EligibilityEnforcementPolicy)`) — le frontend ne lit
que ce champ pour désactiver une option, il ne recalcule jamais l'éligibilité.

### Décision — `evaluateRoster()`, nouvelle méthode pour les créneaux sans mission persistée

Le Preview Editor édite des lignes qui n'ont pas encore de `Mission` en base (génération
avant déploiement, création de mission en Mode Modification, brouillon bulk-assign).
`evaluateAllCandidates()`/`evaluateForReassignment()` (D-101) exigent une `Mission`
existante — nouvelle méthode `evaluateRoster(?Hospital $site, DateTimeImmutable $start,
DateTimeImmutable $end, ?int $excludeMissionId)` : roster actif complet (pas de filtre
site sur la requête candidats — `NO_SITE_MEMBERSHIP` reste une raison informative, pas un
filtre d'exclusion), 4 requêtes DB (D-036), même détection absence/conflit que les autres
méthodes. Nouvel endpoint `GET /api/planning/v2/eligible-instrumentists`
(`siteId?, date, startTime, endTime, excludeMissionId?, policy?`).

### Décision — politique explicite, jamais recalculée côté frontend

`EligibilityEnforcementPolicy` (D-101) devient *string-backed* pour transiter en query
param. `STRICT_ASSIGNMENT` (défaut) bloque `ABSENT`/`SCHEDULE_CONFLICT`/`INACTIVE` ;
`PLANNING_MODIFICATION` ne bloque que `ABSENT`/`INACTIVE` — `SCHEDULE_CONFLICT` reste
visible (badge d'avertissement) mais sélectionnable, cohérent avec D-091/D-052 (un
manager peut délibérément accepter un chevauchement cross-site en Mode Modification,
surfacé ensuite comme `PlanningAlert` plutôt que bloqué). Le frontend transmet la
`policy` selon le contexte (`isModification ? PLANNING_MODIFICATION :
STRICT_ASSIGNMENT`) mais ne réimplémente jamais `blockingReasons()`.

### Décision — `NO_SITE_MEMBERSHIP` reste non bloquant (rappel D-101)

Conformément à D-101 : `NO_SITE_MEMBERSHIP` continue à apparaître dans `reasons[]` à
titre informatif ("Non affiliée à ce site") mais **ne rend jamais `selectable: false`**,
sous aucune des deux politiques. Aucune régression introduite ici — ce lot ne fait
qu'exposer ce que le backend savait déjà via un canal uniforme.

### Décision — un concern purement local reste local : "déjà affecté ailleurs" (Preview Editor)

Le Preview Editor peut réassigner une personne sur un autre créneau du même aperçu, non
encore persisté — le backend ne peut pas le voir (ce ne sont pas des `Mission` en base).
Ce contrôle reste calculé côté client (`findSameDayAssignmentElsewhere`, inchangé), en
warning non bloquant, superposé *après* le ghosting backend (qui garde priorité s'il
bloque déjà pour une autre raison). Ce n'est pas une duplication de règle métier
backend — c'est une information que le backend ne peut structurellement pas avoir avant
déploiement.

### Décision — composants factorisés

`SearchableSelect` (`SearchableOption.disabled`, nouveau — distinct de `muted` qui reste
purement cosmétique) devient le seul endroit qui sait rendre un vrai fantôme
(`getOptionDisabled`, `aria-disabled`, blocage clic/clavier). `eligibilityReasons.ts`
centralise le libellé FR par raison (remplace les copies locales dans
`GeneratePlanningTab.tsx` et `ReassignMissionDialog.tsx`) et le calcul du badge compact
(`candidateGhostLabel`). `useRosterEligibility.ts` fournit les hooks React Query
(`useRosterEligibility` pour un créneau, `useMergedRosterEligibility` pour un bulk-assign
multi-créneaux — intersection conservative : un candidat n'est offert que s'il est
sélectionnable pour *chaque* créneau sélectionné).

### Non traité dans ce lot

Lots 3–6 (absence chirurgien avant génération, restauration réversible, email manager,
bouton "Vérifier les conflits") — inchangés, toujours à détailler séparément.

---

## D-103 — Planning V2 : absence chirurgien avant génération, occurrences de Post neutralisées (Lot 3, 2026-08-10)

Date : 2026-08-10

### Contexte

Avant ce lot, une absence chirurgien enregistrée pour une date où aucune `Mission` n'a
encore été générée ne laissait aucune trace : `PlanningGeneratorServiceV2::preview()`
calcule bien `SKIPPED` à la volée quand on ouvre l'aperçu, mais rien n'est persisté tant
que personne n'a cliqué sur "Prévisualiser" pour ce mois précis. Le chirurgien, le
manager, et l'instrumentiste habituel du poste n'apprenaient donc l'impact qu'au moment
(tardif) de la génération, voire jamais si le mois n'était jamais prévisualisé.

### Décision — réutilisation intégrale du moteur de récurrence, aucune nouvelle structure de calcul

`PlanningGeneratorServiceV2::isOccurrenceActive()` (privée) reste l'unique implémentation
du calcul d'occurrence — nouvelle méthode publique `theoreticalOccurrenceDates(post, from,
to)`, simple boucle jour par jour qui l'appelle, aucune règle dupliquée. Nouveau service
`SurgeonAbsenceOccurrenceImpactService`, séparé de `AbsenceMissionReactionService`
(missions déjà matérialisées) et `AbsenceImpactService` (alertes manager sur missions non
auto-mutables) — le domaine de ce lot est strictement le complément : occurrences
théoriques sans aucune `Mission`.

### Décision — `PlanningOccurrenceException(type: CANCELLED)` réutilisée, avec provenance

`PlanningOccurrenceException::CANCELLED` représentait déjà exactement ce concept
("occurrence neutralisée, Post intact") mais uniquement pour une action manager manuelle,
sans lien vers une `Absence`. **Migration** (`Version20260810120000`) : `source`
(`OccurrenceExceptionSource` : `MANAGER` défaut / `SURGEON_ABSENCE`) et
`source_absence_id` (FK nullable vers `absence`, `ON DELETE SET NULL` — même convention
que `planning_alert.absence_id`, `Version20260621100004` : une absence supprimée ne doit
jamais bloquer ni effacer l'historique dépendant). Alternative rejetée : encoder la
provenance uniquement dans le payload JSON d'un `AuditEvent` — rendrait impossible une
requête directe "quelles exceptions viennent de cette absence", nécessaire au Lot 4.

**Règle d'idempotence et de non-écrasement** : `SurgeonAbsenceOccurrenceImpactService` ne
crée **jamais** d'exception si une existe déjà pour `(post, occurrenceDate)` — peu importe
son type ou sa provenance. Une annulation manuelle du manager n'est jamais dupliquée ni
réattribuée à l'absence ; réexécuter le traitement pour la même absence (ou une mise à
jour) ne recrée jamais une exception déjà posée.

### Décision — instrumentiste "potentiellement impacté" : champ direct uniquement

`SurgeonSchedulePost::$instrumentist`, jamais d'inférence depuis l'historique des missions
ni depuis le site. Un poste sans instrumentiste par défaut est quand même neutralisé
(traçabilité manager conservée), simplement sans destinataire instrumentiste.

### Décision — Mission déjà existante : aucun double traitement

Avant de créer une exception, le service vérifie qu'aucune `Mission` (n'importe quel
statut) n'existe déjà pour ce couple chirurgien+site+créneau — si une existe, l'occurrence
relève déjà de `AbsenceMissionReactionService`, ce lot ne fait rien.

### Décision — Preview : occurrence neutralisée visible, jamais silencieusement absente

`preview()` ignorait déjà totalement toute occurrence portant une exception
CANCELLED/MOVED (`continue`, aucune ligne émise) — comportement inchangé pour une
annulation manager (elle sait déjà pourquoi). Pour `source = SURGEON_ABSENCE`, nouvelle
méthode `emitSurgeonAbsenceNeutralizedLine()` : émet une ligne `status = SKIPPED`
identique à celle que le calcul d'absence "naturel" produit déjà dans le mois courant —
aucun nouveau statut, aucun changement frontend nécessaire (le Preview Editor traite déjà
`SKIPPED` comme "chirurgien absent"). Ne re-vérifie pas l'absence en direct : l'exception
elle-même fait foi, y compris si l'absence a depuis été raccourcie (cohérent avec §16/17 —
la restauration reste au Lot 4).

### Décision — notifications groupées, deux nouveaux `NotificationType`

`ABSENCE_OCCURRENCE_CANCELLED` (instrumentiste habituel) et
`ABSENCE_OCCURRENCE_CANCELLED_MGR` (chaque manager/admin actif) — noms raccourcis pour
tenir dans la colonne `notification_preference.notification_type VARCHAR(32)` (guard-test
`DefaultNotificationPreferenceResolverTest`) — additifs,
aucune migration. Un seul `SurgeonAbsenceOccurrencesNeutralizedMessage` par traitement
d'absence (jamais un par occurrence), géré par
`SurgeonAbsenceOccurrencesNeutralizedMessageHandler` sur le même gabarit que
`AbsenceMissionsReactedMessageHandler` (D-062) : groupement par destinataire, email
batché, in-app unitaire par occurrence, `NotificationPreferenceResolver`. Sur mise à jour
de l'absence en libre-service (`SelfAbsenceController::reactAndSync()`), le garde-fou
`ABSENCE_SELF_DECLARED` ("rien n'a été impacté") est étendu pour tenir compte des
neutralisations de ce lot, en plus des alertes mission existantes — jamais de double
notification pour le même événement.

### Décision — suppression d'absence : aucun code ajouté dans ce lot

Le FK `ON DELETE SET NULL` et le snapshot `AuditEvent`
(`PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE`, `mission = null`) suffisent à
rendre l'information "détectable et traçable" pour le Lot 4, sans qu'aucun nouveau chemin
de code soit nécessaire ici — cohérent avec le no-op déjà existant
d'`AbsenceMissionReactionService::onAbsenceDeleted()`.

### Non traité dans ce lot

Restauration automatique après suppression/réduction d'une absence (Lot 4). Email
manager récapitulatif enrichi de deep-links (Lot 5, hors scope actuel). Bouton "Vérifier
les conflits" (Lot 6). Absence instrumentiste sur Post futur sans Mission — symétrique
mais explicitement exclue (§22), traitée dans un lot séparé le cas échéant.

---

## D-104 — Planning V2 : restauration réversible après suppression/réduction d'une absence (Lot 4, 2026-08-11)

Date : 2026-08-11

### Contexte

Avant ce lot, `AbsenceMissionReactionService::onAbsenceDeleted()` était un no-op délibéré
(D-062) : supprimer une absence ne restaurait jamais rien, seule une notice générique
"à réévaluer manuellement" était envoyée aux managers — aucun lien durable entre une
`Absence` et ce qu'elle avait provoqué n'existait. Le Lot 3 a introduit exactement ce lien
pour les occurrences futures (`source`/`sourceAbsence`) ; ce lot ajoute son équivalent pour
les Missions déjà générées, via l'enrichissement du payload `AuditEvent` déjà existant
(colonne `json`, aucune migration), et implémente la restauration effective des deux.

### Décision — aucune nouvelle structure de tracking, `AuditEvent.payload` suffit

`MissionPostDeployService::release()`/`cancel()` gagnent un paramètre optionnel
`causedByAbsenceId` (jamais renseigné par un appelant manager), stocké dans le payload
existant aux côtés de `previousStatus` (nouveau) et `fromInstrumentistId`
(préexistant). Cela répond exactement à la question du §13 : *"l'état actuel de la
Mission est-il toujours exactement celui produit par la réaction à l'absence ?"* — en
comparant le **dernier** `AuditEvent` de la Mission (`ORDER BY id DESC LIMIT 1`) au
`causedByAbsenceId` de l'absence en cours de suppression. Toute action manager
intermédiaire (`assign`/`reassign`/`updateSchedule`/`createPostDeploy`) écrit un
`AuditEventType` différent, qui casse la correspondance et bloque toute restauration
automatique — sans aucun champ dédié "verrou manager".

### Décision — restauration Mission toujours revalidée, jamais aveugle

Nouvelle méthode `MissionPostDeployService::restoreAfterCancellation()`
(`CANCELLED → ASSIGNED|OPEN`, transition qui n'existait nulle part ailleurs — `CANCELLED`
reste terminal partout ailleurs dans le code). Revalide systématiquement l'ancien
instrumentiste via `guardEligibility()`/`evaluateForReassignment()` (D-101) avant de le
réaffecter ; en cas d'inéligibilité, restaure quand même la Mission (`OPEN` plutôt que de
la laisser `CANCELLED`) et crée une alerte `REASSIGNMENT_REQUIRED`. Côté instrumentiste,
`assign()` (déjà existant, `OPEN|ASSIGNED` accepté) suffit — pas de nouvelle méthode.

### Décision — suppression en deux phases (le seul point d'ordonnancement réel)

Piège découvert en testant : `MissionEligibilityService::evaluateForReassignment()`
interroge la table `Absence` en direct pour `ABSENT` — si la réconciliation Mission
tournait *avant* la suppression réelle de la ligne, l'ancien instrumentiste paraîtrait
encore absent à cause de l'absence qu'on est justement en train de supprimer, et la
revalidation échouerait systématiquement. Autre piège : Doctrine remet l'identifiant
auto-généré à `null` sur l'objet PHP une fois la ligne réellement supprimée (`getId()`
ne peut plus être utilisé après `remove()+flush()` — capturer l'id en amont).
Résultat : `beginDeletion()` (occurrences, a besoin du FK encore intact, tourne *avant*
`remove()`) puis `completeDeletion()` (missions, a besoin de la ligne réellement partie,
tourne *après* `remove()+flush()`), un seul message combiné dispatché à la fin. La mise à
jour (réduction) n'a pas ce problème — la ligne n'est jamais supprimée, sa nouvelle plage
déjà flushée suffit à ce que la revalidation soit correcte du premier coup.

### Décision — occurrences : recherche non liée au FK `sourceAbsence`

Le FK `sourceAbsence` ne référence que la **première** absence à avoir neutralisé une
occurrence (règle d'idempotence du Lot 3 : jamais d'écrasement). Avec deux absences
chevauchantes, supprimer la seconde (qui ne possède jamais le FK) doit quand même
redéclencher l'examen. La requête candidate est donc scopée par chirurgien + fenêtre de
dates + `source = SURGEON_ABSENCE`, jamais par `sourceAbsence = :cetteAbsence` — la
décision finale reste toujours le contrôle "une autre absence couvre-t-elle encore cette
date, en excluant celle-ci" (déjà correct par construction, indépendant du FK).

### Décision — `PlanningOccurrenceException` : suppression (Option A), pas de nouveau statut

Cohérent avec la convention déjà établie par `PlanningOccurrenceExceptionService::
deleteException()` ("métadonnée de planification pure, pas de donnée historique") :
restaurer une occurrence supprime la ligne, un `AuditEvent`
(`PLANNING_OCCURRENCE_RESTORED_AFTER_ABSENCE`) conserve l'historique. Aucune migration.

### Décision — PlanningAlert : zéro code nouveau

`AbsenceImpactService::onAbsenceDeleted()`/`onAbsenceUpdated()` (inchangés) résolvaient
déjà exactement les alertes portant un FK `absence` non nul
(`SURGEON_ABSENCE`/`INSTRUMENTIST_ABSENCE`/`REASSIGNMENT_REQUIRED`), avec re-pointage
vers une absence encore active si elle existe (D-050) — et laissaient déjà
`SCHEDULE_CONFLICT`/`OCCURRENCE_CANCELLED` (FK `absence` toujours nul) complètement
intacts. Aucun changement nécessaire.

### Décision — notifications uniquement sur restauration réelle

Un seul `PlanningRestoredAfterAbsenceMessage` par traitement (jamais un par
occurrence/mission), combinant les deux catégories pour le résumé manager (§21).
`AbsenceMissionReactionService::onAbsenceDeleted()`'s notice générique reste, texte
ajusté (ne prétend plus "jamais restauré automatiquement").

### Non traité dans ce lot

Email manager enrichi de deep-links (Lot 5, si applicable). Bouton "Vérifier les
conflits" (Lot 6). Restauration en cas de modification manuelle en Mode Modification
(hors périmètre — jamais un chemin traversé par ce lot).

## D-105 — Planning V2 : email manager consolidé après impact d'une absence (Lot 5, 2026-08-11)

Date : 2026-08-11

### Contexte

Avant ce lot, trois pipelines manager-facing indépendants existaient pour un seul
événement métier "absence" :
`AbsenceMissionsReactedMessageHandler` (Lot "mission reaction", n'envoyait en réalité
**rien** au manager — seulement à l'instrumentiste/chirurgien concerné),
`SurgeonAbsenceOccurrencesNeutralizedMessageHandler` (Lot 3, `ABSENCE_OCCURRENCE_CANCELLED_MGR`),
et `PlanningRestoredAfterAbsenceMessageHandler` (Lot 4, `MISSION_RESTORED_MGR`). Une
même absence créée pouvant simultanément annuler des Missions déjà générées ET
neutraliser des occurrences futures, un manager pouvait recevoir deux emails distincts
pour un seul geste ("Absence Dr X" → email A puis email B), et rien n'empêchait un
troisième canal parallèle d'apparaître au fil des lots suivants.

### Décision — un point de consolidation unique, pas un canal parallèle

`AbsenceController`/`SelfAbsenceController` sont déjà les seuls points du code qui
appellent les trois (quatre, en comptant `AbsenceImpactReconciliationService`) services
métier en séquence pour une même requête HTTP create()/update()/delete() — c'est donc le
seul endroit qui voit déjà tous leurs résultats ensemble. Chaque service a été étendu
pour **retourner** ses données structurées (au lieu de se contenter de dispatcher son
propre message individuel) : `AbsenceMissionReactionService::onAbsenceCreated()/
onAbsenceUpdated()` retournent désormais les résumés de mission au lieu de `void` ;
`SurgeonAbsenceOccurrenceImpactService` retourne `occurrences` en plus de `created` ;
`AbsenceImpactReconciliationService::completeDeletion()/reconcileForUpdate()` retournent
les tableaux `restoredOccurrences`/`restoredMissions` au lieu de simples compteurs.
Nouveau service `AbsenceImpactSummaryService::dispatch()`, appelé une seule fois en fin
de `create()`/`update()`/`delete()`, agrège ces résultats en six compartiments
(`missionsCancelled`, `missionsReleased`, `missionsRestoredAssigned`,
`missionsRestoredOpen`, `futureOccurrencesCancelled`, `futureOccurrencesRestored`) et
dispatche un unique `AbsenceImpactSummaryMessage`, uniquement si au moins un
compartiment est non vide. Alternative écartée : généraliser directement le message Lot 4
existant plutôt que d'en créer un nouveau — rejetée parce que `PlanningRestoredAfterAbsenceMessage`
ne porte que la moitié des cas (restauration), jamais les nouveaux impacts (annulation/
libération), et le retyper aurait cassé sa sémantique "restauration uniquement" utilisée
ailleurs pour les notifications individuelles (conservées telles quelles, voir plus bas).

### Décision — canaux individuels conservés, seul le canal manager est retiré des anciens handlers

Les trois notifications individuelles préexistantes (`ABSENCE_OCCURRENCE_CANCELLED` à
l'instrumentiste habituel du poste, `ABSENCE_OCCURRENCE_RESTORED`/`MISSION_RESTORED` au
chirurgien/instrumentiste concerné, `ABSENCE_INSTRUMENTIST_RELEASED`/
`ABSENCE_SURGEON_MISSION_OPENED`/`ABSENCE_MISSION_CANCELLED` via
`AbsenceMissionsReactedMessageHandler`) ne sont **pas** dupliquées par ce lot — seul le
problème réel (deux emails manager pour un seul événement) est résolu. Les méthodes
`notifyManagers()` de `SurgeonAbsenceOccurrencesNeutralizedMessageHandler` et
`PlanningRestoredAfterAbsenceMessageHandler` sont supprimées ; les types `NotificationType`
`ABSENCE_OCCURRENCE_CANCELLED_MGR`/`ABSENCE_OCCURRENCE_RESTORED_MGR`/`MISSION_RESTORED_MGR`
sont **conservés comme cas d'enum morts** (jamais plus dispatchés) plutôt que supprimés —
un `NotificationPreference` déjà enregistré en base pour l'un de ces types lèverait un
`ValueError` illisible à la lecture si le cas disparaissait purement et simplement.
Nouveau type unique `ABSENCE_IMPACT_SUMMARY` (in-app + email par défaut), quel que soit
le rôle (chirurgien/instrumentiste) ou l'action (créée/modifiée/supprimée) — un manager
configure une seule préférence "impact absence", pas quatre.

### Décision — le no-op générique de suppression est retiré, pas seulement inchangé

`AbsenceMissionReactionService::onAbsenceDeleted()` envoyait auparavant une notice
in-app générique et systématique ("l'absence a été supprimée, réévaluez manuellement")
à chaque suppression, **même quand rien n'avait réellement été restauré** — ce qui
contredit directement la règle §3/§4 du lot ("ne parler que des changements réellement
effectués", "aucun email/notice sans impact réel"). Cette notice est retirée ; la méthode
devient un no-op véritable. Le résumé consolidé la remplace entièrement pour le cas où
une restauration réelle a eu lieu ; dans le cas contraire (rien à restaurer), silence
total — comportement voulu, pas une régression.

### Trou trouvé et corrigé — `ABSENCE_SELF_DECLARED` pouvait mentir

`SelfAbsenceController::reactAndSync()` décidait d'envoyer la notice de repli
`ABSENCE_SELF_DECLARED` ("rien ne s'est passé") en vérifiant les résultats
d'`AbsenceImpactService`, `SurgeonAbsenceOccurrenceImpactService` et
`AbsenceImpactReconciliationService` — **jamais** celui d'`AbsenceMissionReactionService`,
dont le retour était `void` avant ce lot. Une absence auto-déclarée qui libérait/annulait
réellement une Mission pouvait donc déclencher la notice générique "aucune action
requise" alors qu'un vrai changement venait d'avoir lieu. Corrigé en incluant
`$missionSummaries` dans la condition de garde.

### Décision — déduplication Messenger : garantie documentée, pas de nouvel outbox

Aucun mécanisme d'idempotence/outbox n'existe pour `AbsenceImpactSummaryMessageHandler`
(ni pour les handlers des Lots 3/4 avant lui) — un replay Messenger du même message
re-persiste une seconde `NotificationEvent` et redispatch un second email. Documenté
explicitement par un test dédié
(`test_replaying_the_same_message_is_not_deduplicated_documented_current_behavior`)
plutôt que masqué. Construire un outbox était explicitement hors périmètre (§15).

### Décision — un seul template paramétré, pas trois quasi identiques

`emails/absence_manager_impact_summary.html.twig` remplace les deux anciens templates
manager (`absence_surgeon_occurrence_neutralized_manager.html.twig`,
`absence_restored_manager.html.twig`, supprimés) avec une structure unique séparant
"Impact automatique" (`missionsCancelled`, `futureOccurrencesCancelled`,
`missionsRestoredAssigned`, `futureOccurrencesRestored`) de "Action requise"
(`missionsReleased`, `missionsRestoredOpen`) — c'est cette distinction, pas
l'action/le rôle, qui détermine si l'objet de l'email doit alerter ("Planning à
couvrir") ou simplement informer ("Impact planning"/"Planning mis à jour").

### Non traité dans ce lot

Deep links vers mission/planning/alerte (aucun mécanisme de génération de lien
réutilisable n'existe dans le code — introduire ce pattern est hors périmètre, §12).
Bouton "Vérifier les conflits" (Lot 6).

## D-106 — Planning V2 : vérification manuelle des conflits d'un planning déjà généré (Lot 6, 2026-08-12)

Date : 2026-08-12

### Contexte

Trois niveaux de sécurité existent désormais sur Planning V2 : prévention (D-101),
réaction automatique aux absences (D-103/D-104), et email manager consolidé (D-105).
Aucun ne couvre le cas d'un trou résiduel — donnée historique, race condition, ou lot
futur avec un gap similaire — sur un `PlanningVersion` déjà `ACTIVE`. Ce lot ajoute un
filet de sécurité manuel : un bouton "Vérifier les conflits" qui re-audite l'état
courant sans jamais régénérer.

### Décision — orchestrateur pur, aucune réimplémentation métier

L'audit préalable a confirmé que l'essentiel du moteur existait déjà :
`PlanningConflictDetectionService::syncAlertsForMission()` (D-091, cross-site/cross-
version par construction, dédoublonnage et résolution d'alertes obsolètes déjà
corrects) est réutilisé tel quel pour `SCHEDULE_CONFLICT` — aucune ligne de logique de
conflit n'a été réécrite. Nouveau `PlanningVersionAuditService` : un pur orchestrateur
qui appelle les services métier existants dans l'ordre, n'appelle jamais
`mission->setStatus()`/`setInstrumentist()` directement.

### Décision — deux nouvelles primitives "mission-first", factorisées proprement

`AbsenceMissionReactionService` et `AbsenceImpactReconciliationService` partaient tous
les deux d'une `Absence` connue (créée/supprimée) pour trouver les Missions concernées
— l'inverse de ce qu'il faut ici ("étant donné cette Mission, est-elle actuellement
incohérente ?"). Plutôt que dupliquer leur logique de mutation, deux primitives
"mission-first" ont été ajoutées à ces mêmes classes, réutilisant leurs méthodes
privées existantes (`processInstrumentistAbsence()`/`processSurgeonAbsence()` pour la
réaction, `restoreAfterCancellation()`/`assign()` pour la reconciliation) :
`AbsenceMissionReactionService::reconcileMissionAgainstCurrentAbsences()` (résout
l'absence couvrante via une requête directe, jamais depuis un événement) et
`AbsenceImpactReconciliationService::reconcile{Cancelled,Released}MissionIfNoLongerJustified()`
(même contrôle "encore justifié ?" que la reconciliation Lot 4, avec l'id sentinelle
`0` — qu'aucune vraie `Absence` ne porte jamais — à la place d'un id d'absence
spécifique à exclure, puisqu'aucune suppression n'est en cours ici).

### Décision — INACTIVE : alerte uniquement, jamais de correction automatique

Contrairement à `ABSENT` (entité temporelle avec cycle de vie complet, restauration
Lot 4 incluse), un compte `User.active = false` n'a pas de contrepartie "réactivation"
outillée — aucune mécanique n'existerait pour restaurer une mission libérée
automatiquement si l'instrumentiste redevient actif. Choix validé explicitement : un
nouveau `PlanningAlertType::INSTRUMENTIST_INACTIVE` (alerte uniquement, jamais de
mutation), symétrique à `SCHEDULE_CONFLICT` dans sa philosophie ("fait" mais sans
restauration outillée → jugement manager), bidirectionnel comme toute alerte de ce
service (créée si absente, résolue si l'instrumentiste redevient actif ou est
réaffecté — indépendamment de tout autre type d'alerte sur la même mission, §14).

### Décision — statuts PlanningVersion supportés

Seul `ACTIVE` est audité (`ConflictHttpException` → 400 sinon). `DRAFT` a déjà son
propre chemin de revalidation (`PlanningDraftRevalidationService`, au moment du
déploiement) ; `ARCHIVED` est superseded, l'auditer n'a pas de sens opérationnel.

### Décision — occurrences futures : primitive dédiée, sans forcer la réutilisation de `occurrenceStillJustified()`

`AbsenceImpactReconciliationService::occurrenceStillJustified()` (Lot 4) exige un objet
`Absence $excluding` non nullable pour en extraire l'id à exclure — inapplicable ici
(aucune absence précise n'est en cours de suppression). Plutôt que forcer cette
signature à accepter `null`, une nouvelle méthode
`reconcileOccurrenceIfNoLongerJustified()` réimplémente le même contrôle minimal ("le
poste est-il encore actif, la date est-elle encore théorique, une absence couvre-t-elle
encore ce chirurgien à cette date — sans exclusion") — quelques lignes dupliquées,
préférées à une signature élargie qui aurait complexifié l'appelant Lot 4 existant pour
un cas qui ne le concerne pas.

### Décision — performance et transactions

Chaque Mission du `PlanningVersion` audité est chargée en une requête (statuts
`OPEN`/`ASSIGNED`/`CANCELLED`), puis traitée individuellement — chaque mutation
réutilise sa propre transaction déjà existante (verrou pessimiste inclus, côté
`MissionPostDeployService`/`AbsenceMissionReactionService`), jamais une transaction
globale enveloppant tout le scan. Une anomalie sur une Mission (exception inattendue)
est journalisée et n'interrompt jamais l'examen des autres — un résultat partiel
contrôlé vaut mieux qu'un 500 qui masque tout le reste. Pas de nouveau verrou
distribué : les primitives déjà verrouillées suffisent à empêcher deux scans
concurrents de produire un double release/cancel sur la même Mission.

### Non traité dans ce lot

Réconciliation des occurrences futures pour un chirurgien qui n'a aucune Mission dans
la version auditée (scope volontairement limité au site+période de la version, §6F
"si pertinent"). Deep links dans le résumé frontend (aucun mécanisme réutilisable,
inchangé depuis D-105).

## D-107 — Absence instrumentiste avant génération : notifier le chirurgien via le binôme théorique du Post (2026-08-12)

Date : 2026-08-12

### Contexte

D-103 (Lot 3) ne traitait que l'absence CHIRURGIEN avant génération : neutraliser les
occurrences théoriques et prévenir l'instrumentiste par défaut du Post. Le cas
symétrique — une instrumentiste absente, dont le nom apparaît comme `instrumentist` par
défaut sur des `SurgeonSchedulePost` de un ou plusieurs chirurgiens — n'avait jamais été
construit (documenté comme trou volontaire dans le docblock de
`SurgeonAbsenceOccurrenceImpactService`, §22 de son propre historique). Audit confirmé :
aucune fonctionnalité existante ne couvrait ce cas ; ce n'était pas un manque de tests,
un vrai service manquait.

### Décision — service notification-only, jamais de `PlanningOccurrenceException`

Contrairement à l'absence chirurgien (qui neutralise réellement l'occurrence — personne
pour opérer), une absence instrumentiste ne change rien à ce qui doit se passer :
l'occurrence doit toujours être planifiée, seulement pas avec cette instrumentiste-là.
La sécurité à la génération est déjà garantie par D-101
(`MissionEligibilityService::evaluateForReassignment()`, jamais de réaffectation
silencieuse à un instrumentiste absent). Nouveau
`InstrumentistAbsenceOccurrenceImpactService` : symétrique de
`SurgeonAbsenceOccurrenceImpactService` dans sa structure (réutilise
`theoreticalOccurrenceDates()` sans dupliquer la logique de récurrence), mais ne crée
**aucune** `PlanningOccurrenceException` — rien à neutraliser, donc rien à persister
pour la correction de la génération. Ce choix évite explicitement toute nouvelle
migration : ni nouvelle table, ni nouvelle colonne.

### Décision — idempotence par différence de plage, sans marqueur persistant

Sans `PlanningOccurrenceException` pour servir de garde-fou "déjà traité" (comme le fait
le service chirurgien), il fallait un autre mécanisme pour éviter de renotifier le
chirurgien à chaque `PATCH` (y compris ceux qui ne changent que `reason`, sans toucher
aux dates — `AbsenceController::update()` relance systématiquement tout le pipeline,
sans garde de "dates inchangées"). Solution retenue : chaque appel reçoit l'ancienne
plage de dates de l'absence (`null` à la création) et calcule
`theoreticalOccurrenceDates()` sur l'ancienne ET la nouvelle plage ; seule la différence
(dates nouvellement couvertes = "impacté", dates qui sortent de la plage = "n'est plus
impacté") est communiquée. Une mise à jour qui ne touche pas `dateStart`/`dateEnd`
produit une différence vide dans les deux sens → aucune notification. Aucune table, aucun
champ, aucune requête JSON fragile — juste de l'arithmétique de dates, correcte par
construction et strictement plus simple qu'une alternative basée sur `AuditEvent`
(payload JSON non interrogeable proprement en DQL) ou sur un nouveau type
`PlanningOccurrenceException` (risque de pollution sémantique d'une table dont le rôle
est justement "cette occurrence dévie du planning normal").

### Décision — un seul type de notification consolidé, pas de doublon `_MGR`

Une seule recap par chirurgien par run, couvrant à la fois les occurrences nouvellement
impactées et celles redevenues couvertes (`NotificationType::ABSENCE_OCCURRENCE_UNCOVERED`,
28 caractères, sous la contrainte `length: 32` de `notification_preference`). Suit la
philosophie de consolidation du Lot 5 (D-105) plutôt que d'ajouter un second type
`_RESTORED` symétrique à `ABSENCE_OCCURRENCE_CANCELLED`/`ABSENCE_OCCURRENCE_RESTORED` —
un seul message porte les deux directions (`newlyImpacted`/`noLongerImpacted`), le
handler groupe par chirurgien et construit un email avec deux sections.

### Décision — pas de fuite entre chirurgiens, pas de doublon avec le chemin Mission

`loadActivePosts()` filtre strictement sur `post.instrumentist = :instrumentist` — une
instrumentiste sur plusieurs Posts de chirurgiens différents ne notifie jamais que le(s)
chirurgien(s) réellement concerné(s) par au moins une occurrence théorique dans la
fenêtre. `hasExistingMission()` (même requête que le service chirurgien) court-circuite
toute occurrence déjà matérialisée en Mission — déjà géré par
`AbsenceMissionReactionService`, jamais de double traitement.

### Non traité dans ce lot

UX de l'espace chirurgien/instrumentiste au moment de l'encodage d'une absence (aucun
résumé "postes futurs potentiellement impactés" distinct de "missions déjà planifiées
impactées" dans l'écran de confirmation — signalé comme amélioration possible, pas fait
ici). Migration `notification_preference.notification_type` (aurait permis un nom non
abrégé mais évitée entièrement grâce au choix d'un seul type consolidé).

## D-108 — Diffusion OPEN + escalade J-14 : audit, email de repli groupé, escalade différée (2026-08-12)

Date : 2026-08-12

### Contexte

Audit préalable (sans rien supposer) de ce qui existe déjà quand une absence
instrumentiste ouvre une Mission déjà générée (ASSIGNED→OPEN). Résultat : l'essentiel
existait déjà et fonctionne — `MissionPostDeployService::release()` → `MissionLifecycleChangedMessage`
→ `MissionLifecycleChangedMessageHandler::sendOpenMissionAvailableNotifications()`
notifie déjà en in-app + push **tous** les instrumentistes éligibles via
`MissionEligibilityService::findEligible()` (qui exclut déjà ABSENT/INACTIF par
construction, aucune logique dupliquée) ; le chirurgien est déjà informé immédiatement
via deux canaux indépendants (`SURGEON_POST_UNCOVERED` in-app+push, pipeline "libre" ;
`ABSENCE_SURGEON_MISSION_OPENED` in-app+email, recap absence Lot 3bis/D-062). Le seul
vrai trou : **aucun repli email** quand le push est indisponible.

### Décision — repli email groupé, jamais un doublon du push

Nouveau `NotificationType::ABSENCE_POOL_MISSION_AVAILABLE` (28 caractères, sous la
contrainte `length: 32`), envoyé uniquement aux instrumentistes éligibles
**sans aucun abonnement push enregistré** — signal binaire non ambigu (existence d'un
`PushSubscription`), volontairement différent de "push tenté et échoué" pour ne jamais
toucher au pipeline générique partagé (`sendOpenMissionAvailableNotifications()`, utilisé
par tous les appelants, pas seulement les absences) et donc ne jamais risquer un double
envoi. Un seul email par destinataire par traitement d'absence, regroupant toutes les
missions nouvellement disponibles pour lui dans ce run (`AbsenceMissionsReactedMessageHandler::
notifyEligiblePoolWithoutPushByEmail()`) — même philosophie de consolidation que D-105.
Zéro migration : aucune nouvelle table, réutilise `PushSubscription` (déjà existante) en
lecture seule.

### Limite connue, documentée plutôt que cachée

Un abonnement push existant mais dont l'envoi échoue réellement (clé expirée,
signature VAPID invalide, etc.) ne déclenche **pas** ce repli — seul l'absence totale
d'abonnement le fait. Confirmé en direct : un faux `PushSubscription` de test a produit
une erreur `push.flush_failed` sans email de secours. Corriger ce cas précis
nécessiterait de faire remonter un statut par-destinataire depuis le pipeline générique
partagé (actuellement `sendToUsers()` est un envoi par lot sans suivi individuel) —
changement plus large que le périmètre de ce lot, non fait ici.

### J-14 — non implémenté, arrêt volontaire avant toute nouvelle architecture

Audit confirmé : **aucun mécanisme de planification récurrente n'existe dans ce
dépôt** — pas de `symfony/scheduler`, pas de transport Messenger planifié, pas de cron
dans `docker-compose.yml`. Les deux commandes `#[AsCommand]` qui y ressemblent
(`MissionStartDueCommand`, `PlanningDeploymentReconcileStuckCommand`) documentent
elles-mêmes explicitement ne pas être branchées sur un déclencheur automatique. Un J-14
réel nécessiterait : (1) une nouvelle architecture de planification (nouveau paquet ou
nouveau service cron/systemd externe à ce dépôt), et (2) un nouveau champ persistant
pour mémoriser "escalade déjà envoyée" par Mission (sans quoi la commande renotifierait
chaque jour). Les deux déclenchent la règle d'arrêt explicite demandée avant toute
implémentation — non fait, présenté comme choix à trancher séparément.

## D-109 — Correction d'une race condition réelle sur les alertes de conflit (2026-08-12)

Date : 2026-08-12

### Contexte

Découvert en testant en direct deux scans "Vérifier les conflits" simultanés sur le
même `PlanningVersion` (deux requêtes HTTP concurrentes réelles, `Promise.all`) : **4
lignes d'alerte créées pour une seule paire de missions en conflit** (2×
`SURGEON_CONFLICT` + 2× `INSTRUMENTIST_CONFLICT`), confirmé par lecture SQL fraîche. Un
vrai bug, pas un artefact d'environnement.

### Cause racine

`PlanningAlertService::createIfNotDuplicate()` est un simple "vérifier puis créer" —
sans sérialisation, deux appelants concurrents sur exactement la même paire peuvent
tous deux lire "aucune alerte active" avant que l'un ou l'autre ne committe son INSERT.

### Correction — verrou pessimiste sur la mission-ancre, aucune migration

`PlanningConflictDetectionService::applySync()` enveloppe désormais le "vérifier puis
créer" dans `$this->em->wrapInTransaction()` avec `$this->em->lock($anchor,
LockMode::PESSIMISTIC_WRITE)` — exactement la même convention déjà utilisée partout
ailleurs dans ce dépôt pour les mutations liées à une Mission (voir
`MissionPostDeployService::start()`/`claim()`). Le second appelant bloque jusqu'à ce que
le premier committe, puis retrouve correctement l'alerte déjà créée. Rejoué en direct
après correction : exactement 2 alertes (une par type), plus aucun doublon. Suite
`PlanningVersionAuditFunctionalTest` (16/16) et suite complète rejouées sans régression.

## D-110 — Escalade J-14 des Missions OPEN (2026-08-12)

Date : 2026-08-12

### Contexte

Suite directe de D-108 : le J-14 y avait été explicitement laissé de côté ("arrêt
volontaire avant toute nouvelle architecture") car il exigeait deux décisions non
encore prises — comment planifier une exécution récurrente, et comment mémoriser
"escalade déjà envoyée" sans jamais renotifier deux fois pour la même Mission couverte
puis redevenue non couverte. Design validé séparément par l'utilisateur : **Option A**
(commande Symfony classique + cron/systemd externe, pas de `symfony/scheduler` pour ce
seul besoin) et un champ persistant **réinitialisable**, pas un simple horodatage figé.

### Décision — `mission.uncoveredEscalationSentAt`, réinitialisé à chaque sortie réelle d'OPEN

Nouvelle colonne nullable `mission.uncovered_escalation_sent_at`
(`Version20260812115428`). Sémantique : "une escalade a été envoyée pour l'épisode OPEN
**courant**" — pas "une escalade a déjà été envoyée un jour pour cette Mission". Le
champ est remis à `NULL` par un point unique et centralisé,
`MissionPostDeployService::resetEscalationIfLeavingOpen(Mission $mission, MissionStatus
$previousStatus)`, appelé depuis les 6 méthodes qui mutent réellement le statut d'une
Mission (audit exhaustif confirmé : `MissionPostDeployService` est la seule classe du
dépôt à appeler `Mission::setStatus()` pour OPEN/ASSIGNED/CANCELLED — tout le reste,
`AbsenceMissionReactionService`, `AbsenceImpactReconciliationService`,
`PlanningVersionAuditService`, `PlanningAlertActionService`, `PlanningModificationService`,
délègue à 100 % à ces mêmes méthodes) : `release()`, `start()`, `cancel()`,
`restoreAfterCancellation()`, `claim()`, `assign()`. La règle est purement
comportementale (`previousStatus === OPEN && nouveauStatus !== OPEN`), donc s'applique
identiquement à **tous** les chemins de sortie d'OPEN présents et futurs sans liste
d'exceptions à maintenir — y compris OPEN→CANCELLED (`cancel()`) et la restauration
réelle CANCELLED→OPEN (`restoreAfterCancellation()`, chemin Lot 4 utilisé exclusivement
par `AbsenceImpactReconciliationService`), qui redémarre un nouvel épisode avec le
marqueur à `NULL`.

Nouvelle commande `app:planning:check-uncovered-escalations`
(`CheckUncoveredEscalationsCommand`) : sélectionne les Missions `status = OPEN`,
`startAt` dans le futur et `<= now + 14 jours` (calculé en `Europe/Brussels`, même
convention que D-064/D-083 — un Mission qui devient OPEN directement à l'intérieur de
la fenêtre, ex. libérée à J-7, est escaladée dès le prochain run, pas retenue jusqu'à
une date J-14 déjà passée), et `uncoveredEscalationSentAt IS NULL`. Pour chaque
candidate : `MissionPostDeployService::markUncoveredEscalationSent()` prend un verrou
`PESSIMISTIC_WRITE` (même convention que `claim()`/`start()`), revalide "toujours OPEN
ET pas encore escaladée" **après** l'acquisition du verrou, marque, journalise un
`AuditEvent::MISSION_UNCOVERED_ESCALATION_SENT` (acteur `system@surgicalhub.internal`,
même convention que les autres tâches automatisées) et committe — le message
`MissionUncoveredEscalationMessage` n'est dispatché qu'après ce commit, jamais avant.
Deux exécutions simultanées (cron + lancement manuel) sur la même Mission : la seconde
trouve le marqueur déjà posé après son propre verrou et repart sans rien muter ni
notifier — aucun double envoi possible par construction, pas par convention fragile.
L'échec d'une Mission (log + comptage) n'interrompt jamais le reste du batch.

Notification (`MissionUncoveredEscalationMessageHandler`, nouveau
`NotificationType::MISSION_UNCOVERED_ESCALATION`, canaux in-app + push + email via
`NotificationPreferenceResolver`/Messenger existants, aucun pipeline parallèle) : au
chirurgien uniquement, contenu générique sans donnée patient, avec la formulation
demandée ("Nous vous invitons désormais à anticiper une solution alternative et, si
nécessaire, à demander une aide opératoire auprès de la firme concernée") — aucune
résolution automatique de quelle firme contacter, volontairement laissée à
l'appréciation humaine.

### Risque de double message — audité, pas de nouvelle décision nécessaire

Un release direct à l'intérieur de la fenêtre J-14 déclenche déjà une notification
immédiate (`SURGEON_POST_UNCOVERED` et/ou `ABSENCE_SURGEON_MISSION_OPENED`). Risque de
collision quasi simultanée avec l'escalade J-14 ? Non, structurellement : aucun
mécanisme de planification différée (`DelayStamp` ou équivalent) n'existe dans ce
dépôt, et `check-uncovered-escalations` n'est déclenché que par une exécution cron
distincte et journalière — jamais par l'évènement de libération lui-même. Les deux
notifications restent donc temporellement disjointes par construction (immédiate vs.
prochain tick cron, au minimum plusieurs heures d'écart en pratique), sans recouper le
même instant. Aucune modification de pipeline nécessaire.

### Tests

18 tests automatisés nouveaux : 4 unitaires (`MissionUncoveredEscalationMessageHandlerTest`),
12 d'intégration (`CheckUncoveredEscalationsCommandIntegrationTest` — sélection,
fenêtre J-14 exacte incluant le cas "OPEN directement à J-7", idempotence sur double
run, OPEN→CANCELLED réinitialise, restauration réelle CANCELLED→OPEN redémarre un
épisode escaladable, OPEN→ASSIGNED→OPEN redémarre un épisode escaladable, erreur sur
une Mission n'interrompt pas le batch), 2 de concurrence
(`CheckUncoveredEscalationsConcurrencyTest` — deux exécutions simultanées sur la même
Mission). Validation live complémentaire (fixture réelle, nettoyée après coup) : cycle
complet OPEN escaladée → `assign()` (marqueur réinitialisé, confirmé en lecture DB
fraîche) → `release()` légal à l'intérieur de la fenêtre → nouvelle escalade confirmée
(nouvel `AuditEvent`, nouveau message traité par le worker Messenger réel, email
intercepté par MAIL_SAFE_MODE) → second run confirmé sans doublon (toujours exactement
2 `AuditEvent` d'escalade sur la Mission, un par épisode). Suite backend complète
rejouée après implémentation : une régression réelle détectée et corrigée avant
validation finale — `BusinessDateTimeColumnConventionTest` (garde architecturale D-066)
a signalé `Mission::uncoveredEscalationSentAt` comme colonne `DateTimeImmutable` non
classifiée ; le champ n'étant jamais alimenté que par `new \DateTimeImmutable()` côté
serveur (jamais une valeur client), il a été ajouté à la liste blanche de ce test avec
la même justification que les champs `*SentAt` existants (`encodingReminderSentAt`,
`OutboundNotification::sentAt`, etc.). Après correction : suite complète verte, seuls
les 3 échecs préexistants et sans rapport de `OffersUnreadCountControllerTest` (déjà
identifiés indépendamment lors de runs précédents) subsistent.

### Non fait dans ce lot — décision opérationnelle séparée

Au moment de l'implémentation, aucun cron ni systemd réel n'a été configuré ni activé,
conformément à la demande explicite. Procédure d'activation documentée dans
`docs/production.md` (même schéma que D-083 : script `flock`, fréquence recommandée,
vérification de la timezone serveur).

**Mise à jour (2026-08-14)** — activé en production sur autorisation explicite
séparée, immédiatement après le déploiement de `v2026.08.14-prod` : cron `deploy`,
`CRON_TZ=Europe/Brussels` (timezone serveur réelle vérifiée : `Etc/UTC`), tick
quotidien 07:00 heure belge réelle. Détail complet (script, test d'activation, 6
Missions réelles escaladées lors du premier run) dans `docs/production.md`.

## D-111 — Tarification firme conditionnée à un choix obligatoire (2026-08-15)

Date : 2026-08-15

### Contexte

Cas réel remonté : Globus facture un forfait différent pour une TLIF selon l'implant
intersomatique effectivement posé (`Signature` vs `Altera`, montants distincts pour 1 et
2 niveaux). Le modèle standard `Firm × InterventionType → PricingRule` (D-067, D-072)
ne porte qu'un seul forfait par couple — insuffisant pour ce cas, qui doit rester
l'exception, jamais le comportement par défaut des autres prestations. Exigence
explicite du prompt : généricité totale, aucune sémantique clinique en dur (« Signature »/
« implant » ne doivent jamais apparaître dans le code, seulement dans la configuration
saisie par le manager), et aucun tarif ni notion de `PricingRule` jamais visible côté
instrumentiste.

### Décision — discriminant optionnel sur `PricingRule`, jamais un second moteur

Deux entités génériques, `RequiredChoiceGroup` (question libre + `active`) et
`ChoiceOption` (label libre + `MaterialItem` facultatif), rattachées à
`FirmServiceOffering` en `OneToMany` — relation choisie délibérément (coût quasi nul)
pour ne jamais enfermer le modèle dans « un seul groupe par prestation », même si l'API
V1 n'expose et n'autorise qu'un seul groupe actif à la fois par prestation (choix V1
documenté ici, pas une limite technique : `FirmServiceOffering::getGroup()` retourne
le groupe unique en pratique, `getActiveChoiceGroup()` le filtre par état opérationnel).

`PricingRule` gagne une colonne `choice_option_id` nullable (`Version20260814130000`) :
`null` = comportement strictement inchangé (forfait unique, immense majorité des
prestations) ; renseignée = la règle ne s'applique qu'aux `MissionIntervention` ayant
sélectionné exactement cette option. **`PricingRuleResolver` reste l'unique moteur de
résolution** (même invariant que D-067) : `resolveInterventionFee()` gagne un paramètre
`?ChoiceOption $choiceOption = null`, jamais lu depuis `FirmServiceOffering` — la
`ChoiceOption` est résolue et passée en paramètre par l'appelant
(`FinancialCalculationService`), exactement comme `InterventionType`/`MaterialItem`
aujourd'hui. `hasOverlap()`/`matchingRules()` traitent ce discriminant comme faisant
partie de la cible (nullable-aware : une règle `choiceOption=null` et une règle
`choiceOption=X` ne se chevauchent jamais, deux options différentes du même groupe non
plus) — vérifié par `PricingRuleResolverChoiceOptionTest`.

Nouveau service `RequiredChoiceGroupResolver`, même nature et même exception scopée à
l'invariant D-067 que `RepresentativePolicyResolver` (D-092) : seul point de lecture de
`RequiredChoiceGroup`/`ChoiceOption` côté résolution, jamais importé par
`PricingRuleResolver`. Consommé par `InterventionService` (validation d'encodage — une
réponse est exigée dès que le groupe est « opérationnel », défini comme `active` **et**
au moins 2 options actives : un choix à une seule option n'en est pas un) et par
`FinancialCalculationService::resolveFirmInterventionLine()` (défense en profondeur,
anomalie `MISSING_REQUIRED_CHOICE_ANSWER` si un intervalle de configuration laisse un
calcul sans réponse malgré le blocage à l'encodage).

**Chemin legacy corrigé** — `FirmInvoiceService::preview()/generate()` (Lot 1/D-067,
toujours actif en parallèle du nouveau chemin `FinancialCalculationLine`) résolvait la
`PricingRule` par un matcher maison filtrant uniquement par code + date, sans notion de
discriminant : deux règles `Signature`/`Altera` sur le même `InterventionType` auraient
matché indifféremment au premier trouvé, un risque réel de forfait erroné et non
déterministe. `findInterventionRule()` prend désormais la `MissionIntervention` entière
et filtre aussi par `choiceOption` (nullable-aware, même sémantique que le resolver) —
seule façon de garder les deux chemins de facturation cohérents entre eux sans dupliquer
la logique de résolution.

### Encodage instrumentiste — persistance et exclusivité matérielle

`MissionIntervention.selectedChoiceOption` (FK nullable) suit exactement le pattern déjà
établi pour `representativePresent` (D-092) : tri-état à la mise à jour
(absent/`null` explicite/valeur), validation serveur (`assertChoiceAnswered()`), jamais
un montant dans aucune réponse HTTP d'encodage. `resolveChoiceOption()` revalide qu'une
option fournie par le client appartient bien au groupe de la prestation (firm, type)
effectivement concernée — défense contre un id arbitraire.

Exclusivité automatique entre options d'un même groupe (§4/§7 du prompt) : nouveau
`ChoiceMaterialExclusivityService`, ignorant tout `PricingRule`/montant, qui (1) rejette
l'ajout d'un `MaterialLine` dont le `MaterialItem` appartient à une AUTRE option du
groupe que celle sélectionnée (`IncompatibleChoiceMaterialException`, 409
`INCOMPATIBLE_CHOICE_MATERIAL`) et (2) détecte les lignes déjà encodées qui deviendraient
incompatibles lors d'un changement de sélection. Un changement de choix qui laisserait du
matériel incompatible exige une confirmation explicite
(`confirmRemoveIncompatibleMaterial=true`, sinon 409
`CHOICE_OPTION_CHANGE_REQUIRES_CONFIRMATION` portant la liste des lignes concernées) —
jamais de suppression silencieuse ; confirmé, la suppression et la nouvelle sélection
sont appliquées dans la même transaction.

### Configuration manager

`FirmOfferingChoiceGroupController` (endpoints sous
`/api/firms/{firmId}/service-offerings/{offeringId}/choice-group[...]`, `BillingVoter::
MANAGE`) : `PUT` crée ou réutilise/réactive toujours le même groupe (jamais une
recréation à chaque bascule de mode, qui perdrait silencieusement question/options déjà
configurées), `DELETE` désactive sans jamais rien supprimer. Les options suivent la même
convention que `InterventionType`/`MaterialItem` : `active=false` pour retirer un choix
de la circulation, suppression physique refusée (409) dès qu'une `PricingRule` ou une
sélection instrumentiste réelle la référence — jamais de perte d'historique
financier/encodage. `FirmServiceOfferingController::serialize()` expose deux vues
distinctes : `choiceGroup` (question + options actives uniquement, **seulement si
opérationnel**, visible à tout rôle authentifié — c'est ce que consomme l'écran
instrumentiste) et `choiceGroupConfig` (vue complète, y compris non opérationnelle et
options désactivées, réservée au manager) — jamais un montant dans l'une ou l'autre.

Manager Catalogue (`ForfaitDialog`, `PrestationsPage.tsx`) : nouveau toggle « Forfait
unique / Selon un choix obligatoire », visible uniquement si la prestation a un forfait
attendu (`feeApplicable`). Le forfait unique standard exclut désormais explicitement les
`PricingRule` scopées à une `ChoiceOption` de son propre historique versionné
(`RateVersionManager`) — les deux moteurs de versioning affichés sur le même écran ne se
mélangent jamais. Chaque option porte son propre historique de tarifs versionné,
identique dans son fonctionnement au forfait standard.

### Compatibilité rétroactive

Toutes les prestations existantes continuent de fonctionner sans aucune migration
métier manuelle : `choice_option_id` nullable partout, `RequiredChoiceGroupResolver`
retourne `RequiredChoicePolicy::none()` par défaut (aucune `FirmServiceOffering`, aucun
groupe, ou groupe non opérationnel) — comportement 100% inchangé tant qu'un manager ne
configure pas explicitement un groupe.

### Tests

25 tests automatisés nouveaux : intégration (`PricingRuleResolverChoiceOptionTest` —
discriminant, chevauchement nullable-aware ; `FinancialCalculationServiceChoiceOptionTest`
— scénario réel TLIF 1/2 niveaux × Signature/Altera, prestation standard non affectée,
absence de double comptage avec `MATERIAL_FEE`, anomalie de défense en profondeur),
fonctionnels (`FirmOfferingChoiceGroupControllerTest` — configuration manager, validations,
sécurité de la vue filtrée instrumentiste ; `InterventionControllerChoiceOptionTest` —
réponse obligatoire, exclusivité matérielle, confirmation de changement). Suite backend
complète rejouée après implémentation.

### Non fait dans ce lot

Aucun déploiement, aucune configuration de production — implémentation, tests et
documentation uniquement, sur demande explicite. Le picker de matériel instrumentiste
(`MaterialWizard`/`FirmItemPicker`) ne grise pas proactivement les options incompatibles
avec une explication inline (§7 du prompt) : l'exclusivité est entièrement appliquée
côté serveur (409 avec message précis, déjà relayé par le toast d'erreur existant), une
UX de désactivation proactive dans le picker reste un axe d'amélioration possible, non
requis pour la correction fonctionnelle.

## D-112 — Planning V2 : occurrence couverte par une absence chirurgien, jamais une mission à couvrir (2026-08-16)

Date : 2026-08-16

### Contexte

Ticket terrain : ligne "Dr Étienne Willemart — lundi 19 octobre" affichant encore
l'ancienne instrumentiste "Salve Decorte" à côté du badge "Chirurgien absent", inspecteur
la présentant comme "libérée" à cause de l'absence, et — le plus grave — retirer
manuellement cette instrumentiste depuis l'éditeur Planning V2 (`/app/m/planning/v2`,
mode Modification) faisait passer la Mission en OPEN ("Mission ouverte / À pourvoir"),
comme n'importe quel poste réellement décousert. Or une occurrence dont le chirurgien
est absent n'a, par construction, plus aucun poste à couvrir : la faire réapparaître dans
le pool OPEN est une erreur métier, pas seulement un défaut d'affichage.

### Décision — deux défauts distincts, aucun nouveau statut ni second moteur de mutation

**Défaut #1 (affichage) :** dans `GeneratePlanningTab.tsx`, marquer une ligne `SKIPPED`
côté éditeur (`handleCancelMission`, `handleBulkSkip`) ne réinitialisait pas
`instrumentistId`/`instrumentistName` — seul `handleInstrumentistChange` le faisait déjà.
Une ligne fraîchement passée à SKIPPED dans l'état local (non encore soumise) pouvait donc
continuer d'afficher le nom de l'ancienne instrumentiste. Corrigé en alignant les trois
handlers : toute transition vers SKIPPED efface systématiquement l'instrumentiste.
Le rendu de la colonne instrumentiste et de l'inspecteur (`Inspector.tsx`) traite
désormais `status === "SKIPPED"` comme un cas à part entier — jamais l'ancien nom, jamais
"À pourvoir" (qui implique un poste à remplir), jamais de dropdown de recherche ni de
suggestion "Libérés disponibles + Assigner", jamais le bouton "Remettre au pool
(ouverte)" : un simple `/` accompagné du badge "Chirurgien absent" déjà existant.

**Défaut #2 (métier, le plus important) :** `PlanningModificationService::applyLineToMission()`
appelait inconditionnellement `MissionPostDeployService::release()` (ASSIGNED → OPEN) dès
que l'éditeur envoyait `instrumentistId: null` pour une Mission ASSIGNED — sans jamais
vérifier si le chirurgien de cette Mission est actuellement absent à sa date. Corrigé en
réutilisant tel quel `AbsenceMissionReactionService::reconcileMissionAgainstCurrentAbsences()`
(primitive déjà existante depuis le Lot 6/D-106, construite pour l'audit manuel "Vérifier
les conflits") comme garde-fou avant `release()` : si le chirurgien (ou l'instrumentiste)
est actuellement absent, la méthode applique elle-même `cancel()`/`release()` — avec le
même `AuditEvent` et le même `causedByAbsenceId` que la réaction automatique à la création
d'une absence (D-062) — et `release()` n'est appelé qu'en son absence (cas réellement
normal). Aucune nouvelle méthode de mutation, aucun nouveau statut : la distinction
Case A (chirurgien présent, comportement `release()` existant inchangé) / Case B
(chirurgien absent, jamais OPEN, jamais pool, jamais d'offre à un instrumentiste) est
entièrement portée par la primitive de réconciliation déjà auditée.

**Règle retenue, formalisée ici :** une occurrence couverte par une absence chirurgien
n'est jamais une mission à couvrir. L'instrumentiste n'est pas présenté comme affecté
dans le Planning manager (`/`), et aucune action de libération ne peut transformer cette
occurrence en mission OPEN.

### Restauration après suppression de l'absence

Aucun changement à `AbsenceImpactReconciliationService` (Lot 4/D-104) : la Mission
mutée par `reconcileMissionAgainstCurrentAbsences()` porte le même `AuditEvent`
(`MISSION_CANCELLED_POST_DEPLOY` + `causedByAbsenceId`) que celle mutée par la réaction
automatique à la création — la restauration (suppression de l'absence) fonctionne donc à
l'identique, sans code spécifique à ce nouveau chemin. Vérifié en live (voir Tests).

### Photos de profil (§6 du ticket)

`User.profilePicturePath` était déjà porté par les DTOs Mission (`MissionMapper`) mais
absent des lignes de prévisualisation Planning V2. `PreviewLineResponse`/`buildLine()`
(`PlanningGeneratorServiceV2`) gagnent `surgeonPhotoPath`/`instrumentistPhotoPath`
(lus directement sur les entités déjà chargées — aucune requête N+1, y compris pour le
second passage "instrumentiste libéré" du D-034) ; `missionToPreviewLine()` les lit sur
`mission.surgeon`/`mission.instrumentist` côté Mode Modification. Les avatars des lignes
de planning (`GeneratePlanningTab.tsx`) passent désormais `photoUrl` à `PersonAvatar`,
qui portait déjà la règle photo réelle → sinon initiales (aucun composant dupliqué). Le
sélecteur d'instrumentiste (`SearchableSelect`) l'utilisait déjà correctement.

### Tests

Backend : 2 tests d'intégration (`PlanningModificationControllerTest`) prouvant
qu'un retrait d'instrumentiste sur une Mission ASSIGNED dont le chirurgien est
actuellement absent produit `cancelled:1/released:0` et un statut CANCELLED (jamais
OPEN), et qu'un retrait normal (chirurgien présent) reste inchangé (`released:1`) ; 1
test d'intégration prouvant que la suppression de l'absence restaure ensuite la Mission
(ASSIGNED, instrumentiste réappliqué) via le mécanisme existant du Lot 4 ; 3 tests unitaires
(`PlanningGeneratorServiceV2Test`) pour la propagation des chemins de photo, y compris le
second passage "instrumentiste libéré". Frontend : 2 tests (`GeneratePlanningTab.test.tsx`)
pour l'affichage de ligne/inspecteur avec des données volontairement obsolètes (nom
d'instrumentiste encore présent malgré SKIPPED), 1 test pour la photo réelle vs repli
initiales. Suite complète rejouée : 2076/2076 backend (3 échecs pré-existants et sans
rapport, `OffersUnreadCountControllerTest`, reproductibles isolément avant tout changement
de ce lot) et 1161/1161 frontend, verts.

### Vérification terrain (dev, données synthétiques)

Scénario rejoué en conditions réelles via une commande de fixture temporaire (site,
chirurgien, instrumentiste, `PlanningVersion` ACTIVE, Mission ASSIGNED, absence chirurgien
couvrant la date — supprimée après usage) : appel réel à `POST
/api/planning/versions/{id}/apply-modifications` retirant l'instrumentiste →
`cancelled:1/released:0` confirmé par API et par lecture DB directe, `AuditEvent`
`MISSION_CANCELLED_POST_DEPLOY` avec `causedByAbsenceId` ; `DELETE /api/absences/{id}` →
Mission restaurée ASSIGNED avec son instrumentiste d'origine, `AuditEvent`
`MISSION_RESTORED_AFTER_SURGEON_ABSENCE`. Toutes les fixtures nettoyées après coup.

### Non fait dans ce lot

Aucun commit, aucun déploiement — sur instruction explicite. Aucune modification de la
logique d'absence instrumentiste, de la détection de conflits, ni du système d'avatars
au-delà du câblage `profilePicturePath` déjà prévu par `PersonAvatar`. Anomalie relevée
mais hors périmètre : `PlanningV2AbsentSurgeonTerrainCommand` (fixture terrain temporaire)
supprimée après usage, jamais committée.

### Addendum — récupération Git (2026-09-07)

Le code ci-dessus est resté non committé dans l'arbre de travail pendant plusieurs
semaines après cette session — jamais perdu (confirmé : `git log --all -S`, `git fsck
--unreachable` et `git reflog` ne montrent aucun commit contenant ce code, à aucun
moment, sur aucune branche), mais jamais recommis non plus. `docs/production.md`
(déploiement `v2026.09.06-prod`) en trace explicitement l'existence à cette date : *«
D-112 (Planning V2, chantier séparé et toujours en cours) mis de côté par `git stash`
avant la construction de l'archive — jamais inclus dans `git archive HEAD`, restauré à
l'identique juste après »* — confirmant que le code existait déjà, restait à l'état
« en cours » de façon assumée, et n'a jamais atteint la production à aucune date,
contrairement à ce que le titre de cette section pourrait laisser croire par sa seule
date de ticket (2026-08-16, jamais une date de commit ou de déploiement).

Audit complet mené le 2026-09-07 (session de récupération de l'agenda Lot D après une
coupure de courant, qui a mis ce même code au jour) : voir le commit
`fix(planning): recover D-112 absent-surgeon modification safeguards` qui committe
enfin ce code, séparément et sans date rétroactive. Le correctif indépendant sur
`getFreedInstrumentists()` (auto-suggestion d'une ligne à elle-même — jamais documenté
ici, découvert dans le même fichier de travail) en est délibérément exclu ; voir commit
séparé `fix(planning): exclude inspected line from freed instrumentist suggestions`.

## D-113 — Correctif workflow Demandes Catalogue : révision de D-094 (email manager à la création), motif structuré sur « Ignorer », synchronisation frontend (2026-09-04)

Date : 2026-09-04

### Contexte

Audit de bout en bout de `/app/m/catalogue/requests` sur trois signalements terrain :
(1) une nouvelle demande n'apparaît pas dans la liste sans un F5 complet du navigateur ;
(2) l'action « Ignorer la demande » ne transmet aucun motif au demandeur ; (3) les
managers ne sont pas notifiés par email à la création d'une proposition catalogue.

### Cause racine #1 — collision de clé React Query entre le badge de nav et la liste

`DesktopLayout.tsx` (badge « Demandes », toujours monté) et `CatalogueRequestsPage.tsx`
utilisaient la **même clé** React Query (`["material-requests","PENDING"]` /
`["intervention-type-requests","PENDING"]`) avec des `queryFn` renvoyant des **formes
différentes** : le badge un `number`, la page `{items,total}`. Deux observers partageant
une clé avec des fetchers incompatibles pollue le cache selon l'ordre de montage —
combiné à `staleTime: 30_000` global et à l'absence de toute invalidation liée à la
navigation, seul un rechargement complet (qui vide le cache en mémoire) affichait des
données fiables. Corrigé en unifiant clé + `queryFn` + forme (`{items,total}`) entre le
badge et la liste, chacun appliquant son propre `select` (`data.total` pour le badge —
jamais `items.length`, pour rester correct si la liste devient un jour paginée). La page
invalide en plus `["material-requests"]`/`["intervention-type-requests"]` à l'ouverture,
sans jamais recourir à `window.location.reload()`.

Le deep-link notification existant (`NotificationTargetResolver`) pointait vers
`/app/m/catalogue/requests` sans identifiant de demande. Il porte désormais le couple
`?kind=MATERIAL_ITEM|INTERVENTION_TYPE&requestId={id}` — jamais `requestId` seul :
`MaterialItemRequest` et `InterventionTypeRequest` ont des espaces d'ID indépendants et
peuvent partager le même id, ce qui rendrait un identifiant unique ambigu côté frontend.

### Cause racine #2 — « Ignorer » sans motif, sans verrouillage

`MaterialItemRequestManagerController::ignore()`/`resolve()` mutaient directement
l'entité en base sans motif, sans décideur/date, et sans verrouillage pessimiste
(contrairement à `MissionInterventionDraftService::resolve()/ignore()`, qui verrouille
déjà). Corrigé :

- `App\Enum\CatalogueRequestIgnoreReason` (`ALREADY_EXISTS`, `MATERIAL_ALREADY_EXISTS`,
  `DUPLICATE`, `INVALID_REQUEST`, `OTHER`) — motif structuré, `label()` porte le seul
  wording FR (jamais transporté tel quel entre services/messages, voir plus bas) ;
- `MaterialItemRequest`/`InterventionTypeRequest` gagnent `ignoreReason`, `ignoreComment`,
  `decidedBy`, `decidedAt` (migration additive `Version20260904120000`) — motif et
  explication **toujours obligatoires**, jamais seulement pour `OTHER` : le demandeur doit
  toujours recevoir une réponse utile ;
- `MaterialItemRequestService::resolve()/ignore()` (nouveau, logique déplacée du
  controller) verrouille désormais la demande (`em->lock(PESSIMISTIC_WRITE)` +
  `refresh()`) avant de vérifier son statut — deux managers ouvrant simultanément la même
  demande obtiennent un 409 propre (`MaterialItemRequestAlreadyProcessedException`,
  nouveau, même registre que `DraftAlreadyResolvedException`) au lieu d'une double
  résolution silencieuse. Comportement observable de `resolve()` inchangé — verrouillé par
  un test dédié (`test_resolve_response_shape_is_unchanged_by_the_service_refactor`) ;
  `MissionInterventionDraftService::ignore()` reçoit motif/explication en plus de
  `strategy`/`reassignTarget` (orthogonal) et les pose sur `InterventionTypeRequest`, en
  enrichissant le payload des `AuditEvent` `MISSION_INTERVENTION_DRAFT_IGNORED_AS_HISTORY`/
  `MATERIAL_REASSIGNED` existants plutôt que d'en créer un troisième ;
- `AuditEventType::MATERIAL_ITEM_REQUEST_IGNORED` (nouveau — `MaterialItemRequest`
  n'avait jusqu'ici aucune obligation d'audit) ;
- `CatalogueRequestProcessedMessage` transporte désormais `?ignoreReason`
  (`CatalogueRequestIgnoreReason`, le **code métier**) et `?explanation` (texte brut) —
  jamais un libellé déjà traduit : les controllers qui dispatchent ce message restent de
  simples adaptateurs HTTP, le wording FR est produit uniquement par
  `NotificationService`/les templates (`CatalogueRequestIgnoreReason::label()`).
  `catalogue_request_ignored.html.twig` affiche désormais motif + explication, le push
  ignore inclut le motif.

### Décision — D-113 révise D-094 : email manager désormais activé par défaut à la création

D-094 excluait volontairement l'email à la création d'une proposition catalogue ("un
repli email serait du bruit pur") — `CatalogueRequestCreatedMessageHandler` n'avait même
pas de dépendance capable d'en envoyer un. Le besoin produit a changé : une proposition
catalogue ne doit plus pouvoir rester sans traitement faute d'avoir été vue dans l'app.
**Décision : `CATALOGUE_REQUEST_CREATED` ajouté à `EMAIL_ON_BY_DEFAULT`** — les managers/
admins destinataires reçoivent désormais in-app + push (inchangés) + **email**, les trois
canaux restant gouvernés indépendamment par `NotificationPreferenceResolver` (un manager
peut désactiver l'email dans ses préférences). L'email est un canal **indépendant**, pas
un repli du push (contrairement à `CatalogueRequestProcessedMessageHandler`) : envoyé dès
que la préférence l'autorise, que le push ait réussi ou non. `NotificationService` est
désormais injecté dans `CatalogueRequestCreatedMessageHandler` (adapté plutôt que
dupliqué dans une seconde chaîne de notification), nouvelle méthode
`catalogueRequestCreatedNotifyManager()` + template `catalogue_request_created.html.twig`
(ajouté aux templates couverts par le partial `_notification_preferences_cta.html.twig`
introduit en D-094). Aucun envoi SMTP synchrone dans le controller : la création dispatche
toujours `CatalogueRequestCreatedMessage` (async, Messenger), l'email est envoyé par le
handler. Aucune donnée patient dans le payload/contexte email.

### Limite connue — idempotence Messenger

Un traitement nominal (un message consommé une fois) ne dispatche qu'un seul email par
destinataire — couvert par test. Comme pour toute autre notification asynchrone déjà
existante dans le projet, `recordEmailQueued`/`SendTemplatedEmailMessageHandler` ne
fournissent aujourd'hui aucune garantie de déduplication au-delà de la sémantique de
livraison de Messenger : une redélivraison après un crash entre l'envoi effectif et l'ack
du message pourrait en théorie dupliquer un email. Limitation générale déjà présente
ailleurs dans le projet, pas spécifique à ce lot — non traitée ici pour ne pas ouvrir un
chantier d'idempotence Messenger transverse hors périmètre.

### Portée non traitée ici

Pas de nouvel endpoint « get single request » : si la demande ciblée par un deep-link
n'est plus PENDING au moment du clic (déjà traitée par un autre manager), la page ouvre
simplement l'onglet En attente sans mise en évidence — dégradation gracieuse, pas un
échec. `SurgeonMissionRequest` (D-099) reste sur son propre pipeline de notification,
volontairement non fusionné avec `CatalogueRequestKind` (objet métier réellement
différent).

## D-114 — Communication des absences chirurgiens : « Libération de salle » (Lot A), « Gestion du bloc » (Lot B), rattrapage et journal manager (Lot C), bypass volontaire de NotificationPreferenceResolver (2026-09-05)

**Statut : Lot A DONE (commit `6085735`), Lot B DONE (commit `d3dd382`), Lot C DONE (commit
`6f91bdc`). Déployé en production le 2026-09-06 (`v2026.09.06-prod`) — les trois lots
ensemble, jamais partiellement (voir `docs/production.md`, historique des versions
déployées, pour le rapport complet). Tâche planifiée `app:absences:send-scheduled-communications`
disponible mais **toujours pas activée** en production (décision distincte, voir
`docs/production.md` §Tâche planifiée).**

Date : 2026-09-05

### Contexte

Nouveau besoin métier : lorsqu'un chirurgien encode une absence, deux communications
distinctes doivent pouvoir être déclenchées, chacune activable/désactivable par site par le
manager — (1) informer les chirurgiens collègues du même site que des blocs opératoires se
libèrent, (2) informer la gestion du bloc du congé à venir avec un délai configurable. Le
travail est découpé en 3 sous-lots (A/B/C) ; cette ADR couvre le Lot A (« Libération de
salle » uniquement) et pose la décision architecturale valable pour les trois.

### Décision — pas de passage par `NotificationPreferenceResolver`/`NotificationType`

Ces deux communications sont des **diffusions organisationnelles obligatoires**, gouvernées
par un réglage de site que seul le manager contrôle — pas des préférences personnelles de
destinataire. `NotificationPreferenceResolver::resolve(User $user, NotificationType $type)`
est structurellement conçu pour l'inverse : un choix individuel, par utilisateur, révocable
par cet utilisateur lui-même, sans aucune notion de site ni de destinataire hors `User`
(confirmé par audit du code existant avant implémentation).

Faire transiter « Libération de salle » par ce resolver introduirait deux niveaux de
décision contradictoires : le manager active la communication pour le site, mais un
chirurgien collègue pourrait individuellement la désactiver pour lui-même — ce n'est pas le
modèle voulu. Pour « gestion du bloc » (Lot B), le destinataire n'est de toute façon pas un
`User` (une adresse mailbox configurée par site), donc structurellement hors du domaine du
resolver.

**Décision actée :**
- Aucun nouveau cas `NotificationType` pour ces deux flux.
- Aucun passage par `NotificationPreferenceResolver`.
- Activation/désactivation exclusivement via `AbsenceCommunicationSiteConfig` (nouvelle
  entité, une ligne par site).
- Traçabilité via un journal dédié (`SurgeonAbsenceCommunication` +
  `SurgeonAbsenceCommunicationDelivery`), pas via `OutboundNotification` (voir plus bas).

**Portée du bypass, volontairement limitée** : cette décision ne s'applique qu'à cette
catégorie précise de communication — une diffusion organisationnelle obligatoire, configurée
par le manager au niveau du site, avec un destinataire qui peut ne pas être un `User`
SurgicalHub. Elle ne doit jamais servir de précédent pour contourner
`NotificationPreferenceResolver` sur une notification personnelle classique ailleurs dans le
projet.

### Journal dédié plutôt que `OutboundNotification`

`OutboundNotification` (D-084) suppose un `recipientUser` non nul et est visible uniquement
en `ROLE_ADMIN` (`OutboundNotificationVoter`) — deux contraintes incompatibles avec ce
besoin : la gestion du bloc (Lot B) n'a pas de `User` associé, et ce journal doit être
visible par le manager, pas seulement l'admin. Plutôt que de relâcher les contraintes d'une
entité existante au risque de la rendre incohérente pour son usage actuel, une entité dédiée
est introduite : `SurgeonAbsenceCommunication` (la décision logique — snapshot immuable
« quoi/pour qui/quelles occurrences ») et `SurgeonAbsenceCommunicationDelivery` (un envoi
réel par destinataire, avec son propre statut). Une « Libération de salle » donnant lieu à
plusieurs emails individuels distincts (un par chirurgien collègue), le statut de livraison
ne peut pas être un champ unique agrégé au niveau de la communication logique : l'échec d'un
envoi à un collègue ne doit jamais masquer le succès des autres, ni inversement.

Le statut d'une `SurgeonAbsenceCommunicationDelivery` ne passe à `SENT`/`FAILED` que par
confirmation réelle du pipeline d'envoi (`SendTemplatedEmailMessageHandler` une fois
`$mailer->send()` confirmé sans exception, ou `OutboundNotificationEmailFailureListener`
une fois les retries Messenger épuisés) — jamais de manière optimiste au moment du dispatch
Messenger, qui ne prouve rien côté SMTP. `SendTemplatedEmailMessage` gagne un champ
`absenceCommunicationDeliveryId`, indépendant et symétrique à `outboundNotificationId`
(D-084) — le listener d'échec existant est étendu pour couvrir les deux journaux plutôt que
dupliqué.

### Calcul des occurrences BLOCK libérées (§2 de la demande)

Nouveau service `SurgeonAbsenceBlockOccurrenceResolver`, strictement en lecture — ne modifie
jamais `SurgeonSchedulePost`/`RecurrenceRule` (invariant R-02 du freeze Planning V2).
Réutilise `PlanningGeneratorServiceV2::theoreticalOccurrenceDates()` comme unique moteur de
récurrence (jamais réimplémenté) et le pattern de requête de
`SurgeonAbsenceOccurrenceImpactService::loadActivePosts()` (Lot 3/D-103), avec un filtre
`type = BLOCK` en plus — les `CONSULTATION` ne sont jamais concernées. Une occurrence portant
déjà une `PlanningOccurrenceException` (quel qu'en soit le type) est exclue, même convention
que `SurgeonAbsenceOccurrenceImpactService::hasExistingException()` : il n'y a rien à libérer
si l'occurrence n'aura de toute façon pas lieu.

Seules les occurrences **encore futures** au moment du traitement sont retenues (jamais un
bloc déjà passé), aussi bien à la création qu'au moment d'un complément.

### Aucune rétractation, mais un complément sur allongement (§3/§7)

Une fois une « Libération de salle » envoyée, elle n'est jamais corrigée ni rétractée —
raccourcir ou supprimer le congé ne déclenche rien côté collègues. En revanche, un
allongement qui révèle de **nouvelles** occurrences BLOCK jamais annoncées déclenche un
complément ne listant que ces nouvelles dates. Le calcul du delta compare les occurrences
théoriques actuelles à l'union des `occurrencesSnapshot` déjà journalisés pour
`(absence, site)`, toutes révisions confondues (clé `postId|date`).

`revisionNumber` (0 pour le premier envoi, incrémenté pour chaque complément légitime) +
contrainte unique DB `(absence_id, site_id, type, revision_number)` garantissent
l'idempotence : le calcul du prochain `revisionNumber` a lieu sous verrou pessimiste sur
l'`Absence`, dans une transaction Doctrine réelle (`recordRoomRelease()`), la contrainte
unique restant le dernier garde-fou en cas de course concurrente.

### Envoi individuel, jamais groupé (§16)

Chaque chirurgien collègue reçoit un email individuel et distinct (`SendTemplatedEmailMessage`,
un `To` unique) — aucune adresse n'est exposée entre destinataires, aucune extension du
Mailer n'était nécessaire pour ce lot (contrairement à la gestion du bloc, Lot B, qui
nécessitera `cc`/`replyTo`).

### Worker Messenger — restart obligatoire au déploiement de ce lot

`SendTemplatedEmailMessage` gagne un nouveau champ (`absenceCommunicationDeliveryId`) et son
handler ainsi que `OutboundNotificationEmailFailureListener` changent de comportement. Le
worker Messenger étant un process long-running (les classes déjà chargées ne sont jamais
rechargées en cours de vie du process), un restart est **obligatoire** lors du déploiement de
ce lot — vérifié en conditions réelles lors du test Docker/Mailpit de ce lot : sans restart,
le worker continue silencieusement d'exécuter l'ancienne version du handler (l'email part
correctement, mais `recordDeliverySuccess()`/`recordDeliveryFailure()` ne sont jamais
appelés, laissant les livraisons bloquées en `SCHEDULED` indéfiniment). Ceci n'est pas un bug
applicatif : c'est la règle déjà documentée dans `docs/deployment-versioning.md` §4.5
(« Si le worker doit reprendre une nouvelle version du code des handlers Messenger »),
qui s'applique ici parce que le contrat de `SendTemplatedEmailMessage` a changé — pas une
exception ni un cas particulier à ce lot.

### Non fait dans le Lot A (repris ci-dessous par le Lot B)

Email « gestion du bloc » (délai configurable, scheduling différé, `cc`/`replyTo`),
modification/suppression avec choix Oui/Non pour la gestion du bloc, rattrapage historique
des absences déjà existantes, journal manager complet (UI). Le schéma des tables créées ici
(`AbsenceCommunicationSiteConfig`, `SurgeonAbsenceCommunication`,
`SurgeonAbsenceCommunicationDelivery`) est déjà dimensionné pour ces lots (champs
`notifyBlockManagementEnabled`/`blockManagementEmailTo`/`blockManagementEmailCc`/
`blockManagementDelayDays`, `scheduledAt`/`cancelledAt` sur la delivery) afin d'éviter une
seconde migration structurelle, mais aucune logique d'envoi ni contrat API ne les exploite
avant le Lot B.

## Lot B — « Gestion du bloc » : configuration, scheduling, modification et suppression (2026-09-05)

### Contexte

Second sous-lot de D-114 : informer une adresse mailbox « gestion du bloc » configurée par
site (pas un `User` SurgicalHub) qu'un chirurgien crée, modifie ou supprime un congé, avec
un délai configurable avant le début du congé, un `Reply-To` vers le chirurgien concerné
(jamais une usurpation du `From` SMTP), et une adresse `To` + plusieurs `CC` configurables
côté site, le chirurgien étant automatiquement ajouté en copie. Réutilise intégralement le
modèle de données et les composants du Lot A (`AbsenceCommunicationSiteConfig`,
`SurgeonAbsenceCommunication`/`Delivery`, `SurgeonAbsenceBlockOccurrenceResolver`,
`AbsenceCommunicationJournalService`) — aucun second journal, aucune nouvelle entité pour
la communication elle-même.

### Résolution live au moment de l'envoi (jamais figée à la programmation)

Contrairement à un scénario où To/CC seraient résolus une fois pour toutes à la création de
la communication, `AbsenceCommunicationJournalService::resolveLiveBlockManagementRecipients()`
relit l'état ACTUEL de `AbsenceCommunicationSiteConfig` et l'email courant du chirurgien
juste avant chaque dispatch réel (immédiat ou via le cron, potentiellement des jours plus
tard) — décision actée explicitement avec l'utilisateur. Conséquence directe :
- Fonction désactivée entre la programmation et l'échéance → la delivery passe `CANCELLED`
  (`lastError` explicite), jamais un envoi silencieux ni une erreur bloquante.
- Fonction toujours activée mais adresse `To` devenue invalide/manquante → `FAILED` avec
  `lastError`, jamais de fallback inventé (pas d'adresse générique, pas de silence).
- Les snapshots `recipientEmailSnapshot`/`recipientCcSnapshot` du journal représentent
  toujours ce qui a **réellement été utilisé** pour l'email envoyé, jamais les valeurs
  historiques prévues à la programmation.

### Mutable en place tant que jamais envoyée, immuable dès `SENT` (contraste avec le Lot A)

`ROOM_RELEASE` (Lot A) est immuable dès la création. `BLOCK_MANAGEMENT_ABSENCE` (Lot B) est
volontairement différente : tant que sa delivery unique est encore `SCHEDULED` (ou
`CANCELLED`-avant-envoi), rien n'a réellement été communiqué — `AbsenceCommunicationJournalService::upsertPendingBlockManagementNotice()`
peut donc muter la même ligne en place (recalcul de `scheduledAt`, aperçu de destinataires,
réactivation) sans jamais créer de doublon, à chaque création/modification de l'absence tant
qu'aucun envoi réel n'a eu lieu. Une fois `SENT` (ou `FAILED`, une tentative a déjà eu lieu),
la ligne devient immuable comme le reste du journal : tout changement de dates ultérieur crée
une **nouvelle** communication `BLOCK_MANAGEMENT_MODIFICATION` (revision propre, séquence
indépendante de celle de `BLOCK_MANAGEMENT_ABSENCE`), jamais une correction en place — et
jamais générée si les dates n'ont en réalité pas changé (comparaison stricte des snapshots).

### Scheduling réel — cron horaire + claim atomique (`dispatchClaimedAt`)

`app:absences:send-scheduled-communications` (calqué sur `CheckUncoveredEscalationsCommand`,
D-110) scanne les deliveries `SCHEDULED` dont `scheduledAt` est échu, claim chacune sous
verrou pessimiste (re-vérifie `SCHEDULED` — une exécution concurrente ou une suppression a pu
déjà la traiter entre le scan et le claim), résout To/CC live, puis dispatche strictement
après le commit de la transaction de claim. Cron recommandé : `0 * * * *` (le délai se compte
en jours, une granularité plus fine n'a aucune utilité réelle).

**Gap de concurrence identifié et corrigé pendant l'implémentation, hors plan initial** : le
plan prévoyait de s'appuyer uniquement sur `status = SCHEDULED` comme garde-fou, avec le
principe déjà établi (Lot A) que `status` n'est mis à jour qu'après confirmation réelle
d'envoi (`SendTemplatedEmailMessageHandler`). Or ce principe crée précisément la fenêtre de
risque que la demande initiale (§11) mettait en garde explicitement contre : entre le moment
où une delivery est dispatchée vers Messenger et le moment où le handler confirme
`SENT`/`FAILED`, `status` reste `SCHEDULED` — un second scan (cron concurrent, ou un run qui
suit de près) retrouverait la même ligne encore `SCHEDULED` et la redispatcherait, provoquant
un double envoi réel au bloc opératoire. Corrigé par l'ajout d'un nouveau champ
`dispatchClaimedAt` (`\DateTimeImmutable`, nullable) sur `SurgeonAbsenceCommunicationDelivery`
— posé une seule fois, atomiquement, sous le même verrou pessimiste que le claim, jamais
réinitialisé. Le scan initial du cron filtre déjà `dispatchClaimedAt IS NULL` (évite de
re-sélectionner une ligne en cours de traitement), et le claim lui-même re-vérifie cette
condition sous verrou avant de la poser — une ligne déjà claim (par ce run ou un run
concurrent/rapproché précédent) retourne `already-handled` sans jamais redispatcher, même si
son `status` est encore `SCHEDULED`. `dispatchClaimedAt` répond à la question « a-t-on déjà
tenté ? », `status` répond à « quel est le résultat confirmé ? » — deux questions
distinctes, jamais confondues. **Nouvelle migration additive** (`Version20260905130000`,
`ALTER TABLE ... ADD dispatch_claimed_at DATETIME DEFAULT NULL`) malgré le Lot A qui annonçait
« aucune migration nécessaire pour le Lot B » — déviation justifiée : purement additive,
aucun risque sur les données existantes (Lot A ne l'utilise jamais), et directement requise
par l'exigence de robustesse explicite de la demande (§11 : « ne pas faire confiance
uniquement à `status = SCHEDULED` sans stratégie de verrou/claim atomique »). Couvert par
`SendScheduledAbsenceCommunicationsCommandIntegrationTest::test_a_delivery_already_claimed_is_never_redispatched_even_if_still_scheduled`.

### Modification et suppression

Modification avant envoi (delivery encore `SCHEDULED`) : mutation en place, jamais d'email —
rien n'a encore été communiqué. Modification après envoi (dates réellement différentes du
dernier snapshot connu) : nouvelle communication `BLOCK_MANAGEMENT_MODIFICATION`, dispatch
immédiat. Suppression : un site encore `SCHEDULED` est **toujours** annulé silencieusement
(`cancelPendingDelivery()`), quel que soit le choix utilisateur — ce choix ne gouverne que les
sites déjà `SENT`. `GET /api/absences/{id}/deletion-info` calcule côté backend, jamais déduit
côté client, si au moins un site a réellement déjà reçu la communication ; si oui, une seule
question Oui/Non globale couvre tous les sites déjà notifiés (décision actée : pas une
question par site). « Oui » crée une communication `BLOCK_MANAGEMENT_CANCELLATION` par site
réellement notifié, réutilisant la configuration **actuelle** du site (§23 : le toggle
désactivé empêche les nouveaux préavis automatiques, jamais la correction/annulation d'un
message déjà envoyé). Le texte de l'email d'annulation ne contient jamais la phrase
« Mes vacations opératoires prévues sur cette période peuvent donc être maintenues. »,
explicitement proscrite par la demande — vérifié par test
(`BlockManagementCommunicationFunctionalTest::test_deletion_after_send_with_yes_sends_cancellation_with_exact_text_and_no_forbidden_sentence`).

### Extension du Mailer — `cc`/`replyTo`

`SendTemplatedEmailMessage` gagne `cc: array = []` et `replyTo: ?string = null` (positionnels
après `absenceCommunicationDeliveryId`, tous par défaut vides — rétrocompatible avec le Lot A
et tous les appelants antérieurs). `SendTemplatedEmailMessageHandler` applique `->cc(...)` si
non vide et `->replyTo(...)` si non nul. Le `From` SMTP reste systématiquement l'adresse
SurgicalHub configurée (`MAILER_FROM_ADDRESS`) — jamais usurpé par l'adresse du chirurgien,
qui n'apparaît qu'en `Reply-To`.

### Worker Messenger — second restart obligatoire

Même raisonnement que le Lot A (§ ci-dessus) : le contrat de `SendTemplatedEmailMessage`
change une seconde fois (`cc`/`replyTo`). Le worker Messenger doit être redémarré au
déploiement de ce lot, pour la même raison exacte (process long-running, classes jamais
rechargées) — voir `docs/deployment-versioning.md` §4.5.

### Non fait dans ce lot

Rattrapage historique des absences déjà existantes au moment du déploiement (seules les
créations/modifications/suppressions **après** déploiement déclenchent la gestion du bloc),
journal manager complet (UI de consultation de l'historique des communications — hors
périmètre, seul le réglage de configuration a une UI). Aucune modification du Lot A.

## Lot C — Rattrapage historique et journal manager (2026-09-05)

### Contexte

Troisième et dernier sous-lot de D-114. Les Lots A et B ne réagissent qu'aux
créations/modifications/suppressions d'absence survenant **après** leur déploiement — toute
absence déjà encodée avant cette date n'a jamais déclenché ni « Libération de salle » ni
« Gestion du bloc ». Le Lot C ajoute (1) un rattrapage explicite, filtré par date de création
de l'absence, avec preview obligatoire et exécution idempotente, et (2) un journal manager en
lecture consultant l'historique complet des communications déjà envoyées par les Lots A/B.
Aucune nouvelle règle métier de communication n'est introduite : ce lot orchestre et expose
en lecture ce que les Lots A/B savent déjà faire.

### Rattrapage — filtre strict sur `Absence.createdAt`, jamais une règle permanente

Le cutoff (`createdFrom`) filtre exclusivement sur la date de création de l'absence en base
— jamais `dateStart`/`dateEnd`, jamais une date de BLOCK, jamais une date d'envoi. Une
absence créée avant le cutoff est ignorée quelle que soit la période de son congé (même
future) ; une absence créée à partir du cutoff (borne inclusive) est éligible quelle que
soit la période de son congé (même passée — voir plus bas). Ce cutoff n'est un paramètre que
de l'appel `preview`/`execute` : il n'est **jamais persisté** comme réglage permanent, et ne
gouverne en rien le traitement des absences créées après le rattrapage, qui continue de
suivre exactement le comportement standard des Lots A/B, indépendamment de tout cutoff
choisi lors d'un rattrapage antérieur.

### `AbsenceCommunicationBackfillService` — un orchestrateur volontairement fin

`preview()` et `execute()` partagent une classification en lecture
(`classifyRoomRelease()`/`classifyBlockManagement()`) qui rejoue fidèlement les MÊMES
conditions que `RoomReleaseCommunicationService::react()` et la branche "neverSent" de
`BlockManagementCommunicationService::react()`, sans jamais rien écrire. C'est une
duplication assumée de leur logique de **décision** (jamais de leur logique d'**écriture**)
— seul moyen d'offrir une preview strictement sans effet de bord (§5 de la demande) tout en
gardant les deux chemins alignés ; toute dérive future entre cette classification et le
comportement réel des deux services serait un bug, verrouillé par les tests qui les exercent
côte à côte.

`execute()` ne relit jamais le résultat d'une preview passée — il ne prend en entrée que le
cutoff et la sélection d'IDs d'absence, puis pour chaque absence : revalide entièrement
l'éligibilité (créée depuis le cutoff, utilisateur toujours chirurgien), puis appelle
directement `RoomReleaseCommunicationService::onAbsenceUpdated()` et
`BlockManagementCommunicationService::onAbsenceUpdated()` (avec les dates courantes de
l'absence comme "précédentes", ce qui les fait suivre exactement le même chemin qu'une vraie
modification sans changement réel de dates). Aucune seconde couche d'idempotence n'a été
nécessaire : `onAbsenceUpdated()` des deux services est déjà idempotent par construction
(delta d'occurrences jamais annoncées pour la Libération de salle, find-or-create +
comparaison de snapshot pour la Gestion du bloc) — rejouer le rattrapage plusieurs fois sur
la même absence ne crée donc jamais de doublon, exactement comme un `PATCH` réel sans
changement de dates ne le ferait pas. Chaque absence est traitée indépendamment (try/catch
par absence) : un échec isolé n'affecte jamais les absences déjà traitées avec succès dans
la même exécution — aucune transaction globale sur le lot entier.

### Sélection au grain de l'absence complète, jamais par (absence, site)

Décision actée : la sélection dans `execute()` porte sur l'absence entière, jamais sur un
couple (absence, site) individuel — bien que la demande initiale ait envisagé cette
granularité plus fine (§8). `onAbsenceUpdated()` des deux services traite déjà, en un seul
appel, tous les sites concernés d'une absence avec son propre filtrage par site (config
activée, occurrences BLOCK, destinataires éligibles, etc.) — filtrage déjà correct et déjà
testé par les Lots A/B. Reproduire un filtrage par site dans le rattrapage aurait dupliqué
exactement la logique que ce lot doit au contraire réutiliser telle quelle, à l'encontre du
principe « rester fin » explicitement demandé (§11). La preview reste néanmoins détaillée
par site pour la transparence (§7) : le manager voit précisément ce qui va se passer pour
chaque site avant de décider d'inclure ou d'exclure l'absence entière.

### Congé déjà terminé — jamais de notification rétroactive de gestion du bloc (§14)

Cas distinct du filtre de cutoff : une absence peut être éligible au cutoff (créée
récemment) tout en portant une période de congé entièrement passée (`dateEnd < aujourd'hui`)
— scénario réaliste pour un rattrapage exécuté longtemps après la mise en production. Pour
la Libération de salle, rien de spécifique n'était nécessaire : `react()` ne retient déjà que
les occurrences BLOCK **futures**, donc un congé entièrement passé ne produit naturellement
aucun email. Pour la Gestion du bloc en revanche, `resolveForWindow()` n'a aucune notion de
« futur » — sans garde-fou, un congé déjà terminé aurait pu déclencher une notification
rétroactive absurde. Nouveau statut `ABSENCE_ALREADY_ENDED` (`AbsenceBackfillBlockManagementAction`,
n'existait pas dans le vocabulaire proposé par la demande) : si `dateEnd < aujourd'hui`,
aucune communication de gestion du bloc n'est jamais créée ni programmée pour cette absence,
quel que soit le réglage du site — la Libération de salle continue, elle, de fonctionner
normalement (blocs futurs restants s'il y en a).

### Statut global d'une communication — convention pour le journal (§19)

Aucune colonne persistée dérivée n'a été ajoutée : le statut global affiché dans le journal
est calculé à la volée à partir des `SurgeonAbsenceCommunicationDelivery` du parent, jamais
stocké. Convention retenue, valable en liste comme en détail :
priorité **SCHEDULED > FAILED > SENT > CANCELLED** — le premier statut, dans cet ordre, porté
par au moins une delivery, l'emporte. Pour `BLOCK_MANAGEMENT_*` (toujours exactement une
delivery, invariant verrouillé au Lot B), cette règle se réduit trivialement au statut de
l'unique delivery, sans jamais de contradiction possible. Pour `ROOM_RELEASE` (une delivery
par collègue), une seule delivery encore `SCHEDULED` classe tout le parent `SCHEDULED` (envoi
encore en cours) ; une seule `FAILED` (aucune `SCHEDULED` restante) classe `FAILED` — signal
le plus utile pour un manager, qui doit alors ouvrir le détail pour voir quelles deliveries
ont réellement échoué, plutôt que d'inventer un état `PARTIAL` absent du modèle existant. Le
filtre `status` du journal applique la même convention via des sous-requêtes SQL
EXISTS/NOT EXISTS corrélées (jamais un filtre en mémoire côté PHP).

### Journal manager — lecture seule, snapshots uniquement

`SurgeonAbsenceCommunicationRepository` (nouveau, câblé sur l'entité déjà existante — aucune
migration) porte la pagination et les filtres, entièrement en base (site, chirurgien, type,
statut global, chevauchement de période). Le détail comme la liste ne dépendent jamais de
l'existence de l'`Absence` source (`absence_id` peut être `NULL, ON DELETE SET NULL depuis le
Lot A) — chirurgien, site, période, sujet, corps et deliveries proviennent exclusivement des
snapshots déjà posés par les Lots A/B, vérifié explicitement par test après suppression réelle
de l'absence source (§20). RBAC identique à toute la fonctionnalité : `PlanningVoter::PLANNING_MANAGE`
uniquement, aucune donnée patient/tarif/clinique n'y transite jamais (§21).

### Non fait dans ce lot

Filtre `status` sur une combinaison de plusieurs statuts à la fois (un seul à la fois pour
l'instant, suffisant pour l'usage manager identifié) ; recherche texte libre dans le journal
(jamais demandée comme prioritaire — §17 : « ne pas surcharger l'écran ») ; nouvel index SQL
dédié (`created_at`, `type`) — les index déjà posés par le Lot A (`surgeon_id`, `site_id`)
couvrent les filtres les plus déterminants ; à ajouter seulement si un audit SQL réel en
production en démontre le besoin (§23, jamais par anticipation). Non déployé en production —
sur instruction explicite répétée.

### Revue finale Lot C — corrections et invariants confirmés (2026-09-06)

**Cutoff interprété en `Europe/Brussels`, jamais en UTC naïf.** `Absence.createdAt` est posé
par `new \DateTimeImmutable()` dans un runtime PHP dont le fuseau par défaut est UTC (vérifié
en conteneur) — une convention distincte et non interchangeable avec les champs de type
`Mission.startAt`, documentés ailleurs comme wall-clock déjà traité comme
`Europe/Brussels`. Le cutoff saisi par le manager (une simple date) est donc interprété comme
minuit `Europe/Brussels` puis converti en UTC avant comparaison
(`AbsenceCommunicationBackfillController::parseCreatedFrom()`) — sans cette conversion, une
absence créée entre minuit UTC et minuit Brussels (1h ou 2h selon la saison) aurait été
classée du mauvais côté du cutoff. Verrouillé par 4 tests de bornes autour de minuit Brussels.

**Concurrence réelle entre deux `execute()` sur la même absence — Room Release.**
`alreadyAnnouncedOccurrenceKeys()` était lu par un simple SELECT AVANT l'acquisition du
verrou pessimiste dans le chemin "mise à jour" de `RoomReleaseCommunicationService::react()`
— deux exécutions concurrentes (deux managers relançant le même rattrapage, ou un rattrapage
concurrent d'une vraie modification) pouvaient toutes deux lire "jamais annoncé" avant qu'aucune
n'ait committé, puis annoncer deux fois les mêmes dates aux mêmes collègues sous deux révisions
distinctes. Nouvelle méthode `AbsenceCommunicationJournalService::recordRoomReleaseDelta()` :
recalcule le delta d'occurrences jamais annoncées SOUS LE MÊME VERROU que le calcul du
`revisionNumber`, jamais avant ; retourne `null` (no-op) si le delta est vide une fois
recalculé sous verrou. Prouvé par deux connexions DBAL indépendantes (une tient le verrou sans
committer, l'autre doit être réellement bloquée sous `innodb_lock_wait_timeout` court) —
`RoomReleaseCommunicationDeltaConcurrencyTest`. Le chemin "création" (`recordRoomRelease()`,
Lot A, inchangé) n'était pas concerné : il n'a par construction aucune notion de delta à
recalculer. Le chemin "gestion du bloc, jamais encore traité"
(`upsertPendingBlockManagementNotice()`) était déjà correct — son find-or-create s'exécute
intégralement sous verrou depuis le Lot B ; prouvé par
`BlockManagementCommunicationConcurrencyTest`.

**Limite assumée, non corrigée dans ce lot : `recordBlockManagementFollowUp()` (MODIFICATION)
n'a pas de déduplication de contenu sous verrou** — seule l'unicité du `revisionNumber` est
garantie (déjà documenté ainsi depuis le Lot B). Deux appels concurrents à
`BlockManagementCommunicationService::react()` pour un site déjà `SENT` dont les dates ont
réellement changé pourraient en théorie produire deux communications `MODIFICATION`
distinctes portant le même nouveau snapshot de dates. Non exploitable par le rattrapage du
Lot C (qui n'emprunte jamais ce chemin pour une absence jamais traitée, sa cible normale) ;
laissé en l'état comme décision Lot B pré-existante, hors périmètre d'une régression
directement introduite par ce lot.

**`classifyBlockManagement()` — divergence preview/execute corrigée.** La preview classait à
tort TOUTE communication existante (y compris une dont l'unique delivery a été `CANCELLED`
avant tout envoi réel) comme `ALREADY_PROCESSED`, alors que le vrai `$neverSent` de
`BlockManagementCommunicationService::react()` traite une delivery `SCHEDULED` ou `CANCELLED`
comme "jamais réellement communiqué" (réactivable en place). Corrigé pour reproduire
exactement ce `$neverSent` — la preview annonce désormais ce qu'`execute()` fera réellement.

**Pagination du journal — tie-breaker stable (§18).** `findForManager()` trie désormais par
`createdAt DESC, id DESC` : deux communications créées à la même microseconde (rattrapage
créant plusieurs lignes très rapprochées) ne changent jamais d'ordre relatif d'une page à
l'autre, `id` étant strictement monotone contrairement à `createdAt`.

**Granularité transactionnelle par absence (§17), confirmée par test.** `execute()` n'ouvre
aucune transaction globale sur le lot : chaque absence est traitée dans son propre
`try`/`catch`, et chaque écriture réelle (Room Release, Gestion du bloc) commit sa propre
transaction indépendamment via `wrapInTransaction()`. Un échec sur une absence (verrou tenu
par une transaction concurrente, remonté comme `ERROR` avec message, jamais une exception
HTTP 500) ne fait jamais annuler ce qui a déjà été committé pour une autre absence du même
lot — prouvé par un test à deux absences dont l'une est bloquée par une connexion DBAL
concurrente tenant le verrou pessimiste.

**Configuration relue en temps réel à `execute()`, jamais depuis la preview (§15).**
`execute()` ne prend en entrée que le cutoff et la sélection d'IDs — jamais un payload de
preview. Un site désactivé entre preview et execute ne produit plus rien (même si la preview
promettait `WILL_SEND`) ; symétriquement, un site activé après une preview `DISABLED` est
correctement traité par `execute()`. Vérifié par deux tests dédiés.

**Rattrapage partiel Room Release (§12).** Un site déjà partiellement annoncé (ex. un envoi
manuel antérieur au Lot C) ne reçoit, via le rattrapage, que le delta réel des occurrences
jamais annoncées — jamais une réannonce complète des dates déjà connues ; un second
rattrapage n'envoie plus rien. Vérifié par test avec un envoi partiel pré-existant (1 date sur
3) suivi d'un rattrapage puis d'un relaunch.

**Contenu de `lastError` — déjà sûr pour affichage manager, aucun changement nécessaire.**
Le chemin d'échec SMTP réel (`OutboundNotificationEmailFailureListener::normalizeThrowableMessage()`,
D-084, réutilisé tel quel par ce domaine) redacte déjà toute sous-chaîne de type URI (DSN
pouvant porter un mot de passe) et tronque à 200 caractères ; les autres messages
(désactivation, config invalide, absence supprimée, site plus concerné) sont des phrases
métier écrites explicitement, jamais un message d'exception brut.

**Affichage `CANCELLED` — déjà distinct d'un échec, aucun changement nécessaire.** Le journal
frontend affiche `CANCELLED` comme « Annulé » avec un token de couleur neutre
(`textSecondary`/gris clair), visuellement distinct du token critique utilisé pour `FAILED` —
jamais présenté comme une erreur.

**Confirmation du rattrapage — texte et libellé renforcés (§23/§24).** Le texte d'alerte avant
exécution énonce désormais explicitement « Des emails seront réellement envoyés et des
programmations réellement créées (...). Cette action n'est pas une simple sauvegarde. » ; le
bouton de confirmation est libellé « Traiter » (jamais « Enregistrer » ni un « Confirmer »
ambigu). Le bouton est protégé côté frontend contre un double-clic rapide (`disabled` pendant
`isPending`, plus une garde explicite dans le handler) — la garantie réelle contre un double
traitement reste, comme documenté plus haut, l'idempotence côté backend.

**Vérification navigateur — limite confirmée, aucun contournement tenté.** Le plugin
`@vitejs/plugin-basic-ssl` est inconditionnellement actif dans `frontend/vite.config.ts`
(aucune bascule HTTP conditionnelle réelle malgré une note ambiguë dans `docs/docker.md`),
ce qui déclenche l'interstitiel de sécurité Chrome et bloque toute automation DevTools.
Aucune modification de la configuration Vite ni contournement fragile de Chrome n'a été
tenté (hors périmètre explicitement demandé). *UI non vérifiée manuellement en navigateur à
cause du certificat local auto-signé ; couverte par tests composants + API HTTP réelle.*

## Revue post-déploiement — déplacement des coordonnées « gestion du bloc » vers l'établissement (2026-09-06)

### Contexte : verrou circulaire découvert en production

Après déploiement (`v2026.09.06-prod`), un manager a signalé que le toggle « Prévenir
automatiquement la gestion du bloc » (Planning → Paramètres → Communication des absences)
était impossible à activer pour un site jamais configuré : le toggle envoyait immédiatement
un `PATCH {notifyBlockManagementEnabled: true}` seul, alors que le backend
(`AbsenceCommunicationSiteConfigService::validate()`) exigeait déjà un `blockManagementEmailTo`
valide dans l'état résultant. Le formulaire qui aurait permis de saisir cette adresse n'était
lui-même affiché qu'une fois `notifyBlockManagementEnabled` confirmé `true` côté serveur — un
verrou circulaire strict, sans issue possible depuis l'UI existante.

### Audit et décision architecturale

Au-delà du bug d'implémentation frontend (corrigible seul en changeant l'ordre des appels),
l'audit a confirmé un défaut de modélisation plus profond : `blockManagementEmailTo`/
`blockManagementEmailCc` vivaient dans `absence_communication_site_config` — une table dont
le nom même scope ces colonnes à la communication d'absence — alors que ce sont des
coordonnées organisationnelles de l'établissement (qui prévenir pour le bloc opératoire),
indépendantes du fait qu'une communication d'absence existe ou non. `Hospital` ne possédait
aucune structure de contacts à réutiliser (`name`/`address`/`timezone`/`photoPath`
uniquement) — décision : déplacer physiquement ces deux champs vers `Hospital`
(`blockManagementContactEmail`/`blockManagementContactCc`), sans créer de table de contacts
labellisés séparée (aucun précédent de ce genre ailleurs dans le projet, complexité non
justifiée par le besoin réel). `AbsenceCommunicationSiteConfig` ne porte désormais plus que
le comportement : `notifyColleaguesEnabled`/`notifyBlockManagementEnabled`/
`blockManagementDelayDays`.

### Migration

`Version20260906100000` — additive puis migration de données puis suppression des deux
colonnes devenues obsolètes, dans le même `up()` (jamais de perte, copie systématique avant
suppression) : `ALTER TABLE hospital ADD block_management_contact_email`/
`block_management_contact_cc` (le second avec `DEFAULT (JSON_ARRAY())`, expression par
défaut supportée depuis MySQL 8.0.13, pour peupler directement `[]` sur toute ligne
existante sans détour nullable→backfill→NOT NULL), `UPDATE hospital ... JOIN
absence_communication_site_config` (copie 1:1 par `site_id`), puis `ALTER TABLE
absence_communication_site_config DROP block_management_email_to`/`block_management_email_cc`.
N'affecte jamais `surgeon_absence_communication`/`surgeon_absence_communication_delivery` —
les snapshots déjà journalisés (déjà immuables par construction) restent hors du périmètre
de cette migration, aucune communication historique n'est modifiée.

### Nouvelle UX

**Fiche établissement** (`Établissements` → `Modifier`, `HospitalsPage.tsx`) — nouvelle
section « Contacts du bloc opératoire » dans le dialogue d'édition existant (pas de nouvelle
page dédiée : ce dialogue est déjà, fonctionnellement, la fiche établissement) : adresse
principale + liste CC dynamique (ajout/suppression, validation email, dédoublonnage insensible
à la casse, retrait de l'adresse principale si dupliquée dans les CC — même logique que
l'ancien formulaire, désormais portée par `SiteController::applyBlockManagementContact()`).

**Communication des absences** (`AbsenceCommunicationSettings.tsx`) — plus aucun champ
To/CC : affichage en lecture seule du contact actuel de l'établissement (« Gestion du bloc :
… », « Copies : … », ou un texte explicite si aucun contact configuré), avec un lien
« Modifier les contacts de l'établissement » (`navigate('/app/m/hospitals?edit={siteId}')` —
`HospitalsPage` lit ce paramètre au montage pour ouvrir directement la fiche concernée).

**Toggle « Prévenir automatiquement la gestion du bloc » — verrou circulaire corrigé.** Le
toggle n'envoie plus jamais de `PATCH` toggle-seul à l'activation :
- établissement sans contact valide → aucun appel API, message explicite affiché directement
  (« Configurez d'abord l'adresse de la gestion du bloc dans la fiche de l'établissement. »)
  avec un bouton d'accès direct à la fiche établissement concernée ;
- établissement avec contact valide → ouvre un formulaire ne portant plus que le délai
  (« Envoyer X jours avant le début du congé »), qui envoie `{notifyBlockManagementEnabled:
  true, blockManagementDelayDays}` en un seul PATCH une fois validé — jamais le toggle seul.

Désactiver (`notifyBlockManagementEnabled: false`) reste un PATCH immédiat sans condition
(toujours valide, comme avant). Le garde-fou technique backend (`400` si `notifyBlockManagementEnabled`
résultant est `true` sans contact établissement valide) reste en place mais n'est plus
jamais le parcours normal — uniquement un filet de sécurité contre un appel API direct.

### Résolution live et idempotence des Lots A/B/C — inchangées

`AbsenceCommunicationJournalService::resolveLiveBlockManagementRecipients()` lit désormais
`$site->getBlockManagementContactEmail()`/`getBlockManagementContactCc()` au lieu de
`$config->...` — mais le principe de résolution **live**, jamais figée à la programmation,
reste strictement identique : To/CC/chirurgien/Reply-To sont toujours recalculés au moment
réel du dispatch (immédiat ou cron), qu'ils viennent de `Hospital` ou (avant ce lot) de
`AbsenceCommunicationSiteConfig`. Si les contacts établissement sont modifiés entre la
programmation et l'échéance, la nouvelle adresse est utilisée ; s'ils sont supprimés,
`resolveLiveBlockManagementRecipients()` retourne `status: 'invalid'` exactement comme un
`blockManagementEmailTo` autrefois vidé — `FAILED`, jamais un fallback silencieux sur
l'ancien snapshot programmé. `AbsenceCommunicationBackfillService::classifyBlockManagement()`
lit de même `$site->getBlockManagementContactEmail()` pour la preview — aucune divergence
introduite entre preview et execute. Room Release, le journal historique (snapshots déjà
envoyés, jamais modifiés rétroactivement — vérifié par test explicite), le cron
(`dispatchClaimedAt`, claim atomique) et l'idempotence des trois lots restent
structurellement inchangés — seule la source de lecture d'un couple de champs a changé.

### Tests

Backend : `SiteControllerTest` (nouveau — validation email/CC, dédoublonnage, mise à jour
partielle, RBAC) ; `AbsenceCommunicationSiteConfigControllerTest` (adapté — le garde-fou
technique lit désormais le contact établissement) ; `SendScheduledAbsenceCommunicationsCommandIntegrationTest`
(3 tests ajoutés : contact supprimé avant échéance → `FAILED` jamais un fallback, chirurgien
déjà présent dans les CC établissement → jamais dupliqué, snapshot déjà confié à l'envoi
immuable même après modification ultérieure des contacts) ; tous les fixtures des suites
Lot A/B/C existantes adaptées pour configurer le contact sur `Hospital` au lieu de
`AbsenceCommunicationSiteConfig`, sans changement de leur intention. Frontend : formulaire
contacts (`HospitalsPage.test.tsx`, nouveau describe), affichage lecture seule + lien +
message bloquant + formulaire délai-seul (`AbsenceCommunicationSettings.test.tsx`, describe
« Gestion du bloc » réécrit intégralement).

Non déployé.

## Finalisation D-114 — nom du chirurgien dans les emails Room Release (2026-09-06)

### Contexte

Revue métier post-déploiement : l'email « Libération de salle » (Lot A) ne mentionnait que
le site et les créneaux libérés, jamais **qui** libère ces créneaux — un collègue devait
deviner ou recouper avec le planning pour savoir quel chirurgien est concerné. Décision :
ajouter le nom du chirurgien dans le **corps** de l'email, jamais dans l'objet (qui reste
`Libération de salle — {Site}`, inchangé), et rester strictement centré sur la libération de
salle — jamais reformuler en « Dr X est absent du ... au ... » (ça révélerait l'intervalle de
congé complet, hors du périmètre fonctionnel de cet email et hors de ce qui est réellement
utile au collègue).

### Implémentation

Nouvelle méthode `User::getDrName(): string` (« Dr {Prénom Nom} », repli sur l'email si aucun
prénom/nom) — centralisée sur l'entité plutôt que dupliquée, puisqu'elle était déjà présente
en privé et à l'identique dans `BlockManagementCommunicationService::drName()` (Lot B) ;
`RoomReleaseCommunicationService` n'avait jamais eu besoin de cette logique jusqu'ici. Les
trois points de rendu du template (`emails/absence_room_release.html.twig`) —
`recordRoomRelease()` immédiat, `recordRoomReleaseDelta()` (complément d'allongement), et le
contexte de dispatch final reconstruit depuis la communication persistée — passent tous
`drName` désormais, `strict_variables: true` dans la config Twig du projet aurait fait
échouer le rendu si l'un des trois avait été oublié (confirmé par les tests). Corps :

> Bonjour,
> **Dr {Prénom Nom} libère le(s) créneau(x) opératoire(s) suivant(s) à {Site} :**
> – {jour} {date} — {période}
> …
> Si vous souhaitez disposer de l'un de ces créneaux, veuillez vous rapprocher de
> l'organisation du bloc selon la procédure habituelle.

Le nom est figé dans le `bodySnapshot` au moment de l'envoi comme le reste du corps (aucun
changement structurel : Room Release ne recalculait déjà que via un seul rendu Twig,
directement réutilisé comme snapshot ET comme contexte de dispatch — jamais la double
représentation texte-brut/HTML du Lot B) : un changement ultérieur du prénom/nom du
chirurgien sur son profil n'affecte jamais un email déjà envoyé — vérifié par test explicite
(`test_body_snapshot_keeps_original_surgeon_name_after_profile_change`).

### Tests ajoutés

`RoomReleaseCommunicationFunctionalTest` : nom du chirurgien présent dans le corps + objet
inchangé + intervalle de congé (dateStart/dateEnd de l'absence, format `d/m/Y`) jamais exposé
+ absence du mot « absent » (2 tests). Les cas déjà couverts par la suite existante
(extension → nouvelles dates uniquement, raccourcissement → aucun correctif, dates BLOCK
futures uniquement, CONSULTATION exclue) n'ont pas eu besoin d'ajout, la logique de sélection
des occurrences étant totalement inchangée — seul le contexte de rendu Twig a gagné un champ.

### Ajustements UX mineurs, même revue

- `HospitalsPage.tsx` — la légende de « Contacts du bloc opératoire » ne scope plus
  exclusivement ces coordonnées à la communication des absences (« Ces coordonnées sont
  utilisées pour les communications organisationnelles envoyées à la gestion du bloc »,
  avec mention de la gestion du bloc comme cas d'usage actuel plutôt qu'exclusif) — anticipe
  une réutilisation future de ces contacts par d'autres canaux.
- `AbsenceCommunicationSettings.tsx` — le libellé de l'affichage lecture seule passe de
  « Gestion du bloc : » à « Adresse principale : » (la ligne « Copies : » reste inchangée),
  pour rester cohérent avec les libellés du formulaire de la fiche établissement
  (`HospitalsPage.tsx`) qui utilise déjà ces mêmes termes.

Non déployé.

## Lot D — « Salles disponibles » : vue structurée des créneaux BLOCK libérés (2026-09-07)

### Contexte

Réorientation explicite d'un « Lot D » initialement envisagé autour de Google Calendar : le
besoin réel est une vue **native** SurgicalHub des créneaux opératoires `BLOCK` réellement
libérés — indépendante du canal email (Room Release, Lot A), qui reste un canal d'alerte
séparé et jamais la source de vérité. Réutilise entièrement la source de vérité déjà posée
par D-114 (`SurgeonAbsenceBlockOccurrenceResolver`) : un créneau n'apparaît ici que selon
exactement la même règle que Room Release/Gestion du bloc (une occurrence `BLOCK` théorique
réelle, jamais `CONSULTATION`, jamais un créneau passé) — jamais un second moteur de
récurrence. Google Calendar n'est pas implémenté (décision produit explicite). Le futur
« Lot E — Intérêt et attribution » (`assignedToSurgeon`/`assignedAt`/`closedAt`) reste
volontairement hors périmètre — proposé mais non codé, comme demandé.

### Modèle volontairement minimal — `ReleasedOperatingRoomSlot`

Nouvelle entité dédiée (pas de réutilisation de `SurgeonAbsenceCommunication`, qui reste
propre au canal email) : `site`, `postId` (identité stable de la récurrence, distincte de la
relation `schedulePost` — simple jointure de confort), `occurrenceDate`, `period`,
`startTime`/`endTime` (nullable), `surgeon`, `sourceAbsence` (nullable), `status` (**seule
valeur possible pour ce lot : `AVAILABLE`** — l'enum `ReleasedRoomSlotStatus` ne porte
aujourd'hui aucun autre cas, donc aucun statut Lot E non fonctionnel ne peut jamais fuiter
dans l'UI), `createdAt`. Contrainte unique `(site_id, post_id, occurrence_date)` — une ligne
par occurrence, jamais mise à jour ni supprimée après création (non-rétractation
structurellement garantie par l'absence délibérée de toute méthode de suppression :
raccourcissement ou suppression d'une absence ne retire jamais un slot déjà publié ;
extension ⇒ nouvelles occurrences seulement, via `existsFor()`).

Vue **indépendante de `AbsenceCommunicationSiteConfig::notifyColleaguesEnabled`** (décision
produit actée) : un site avec l'email « Libération de salle » désactivé voit quand même ses
créneaux ici — ce toggle ne gouverne que le canal email, jamais la visibilité opérationnelle
du fait métier lui-même.

`ReleasedOperatingRoomSlotService` s'insère comme **9ᵉ collaborateur indépendant** dans
`AbsenceController`/`SelfAbsenceController::create()`/`update()` (jamais dans `delete()`,
cohérent avec la non-rétractation), après les collaborateurs Room Release/Gestion du bloc,
même convention établie depuis le Lot A : chacun interroge ce dont il a besoin, aucun
orchestrateur partagé, aucun couplage de succès/échec entre canaux.

### Revue post-implémentation (2026-09-07) — résilience aux suppressions et contrainte unique

**Suppression de `Hospital`/`User`.** `site`/`surgeon` sont `ON DELETE SET NULL` (corrigé en
cours d'implémentation : la première version de la migration posait un `RESTRICT` implicite,
qui cassait ~34 tests pré-existants dont le `tearDown()` supprime librement leurs fixtures
Hospital/User sans connaître cette nouvelle table). Un établissement ou un chirurgien
supprimé après coup ne fait jamais planter l'API ni disparaître le créneau : celui-ci reste
visible avec `site`/`surgeon` à `null`, affiché « — » côté UI. **Limite documentée et
assumée** : contrairement à `SurgeonAbsenceCommunication` (qui fige subject/body/dates), ni
le nom du site ni celui du chirurgien ne sont snapshotés sur cette ligne — un nom est donc
irrémédiablement perdu si l'entité source est supprimée plus tard. Accepté pour ce lot
(aucun flux de suppression réelle de site/chirurgien identifié en production aujourd'hui) ;
si ce besoin apparaît, ajouter `siteNameSnapshot`/`surgeonNameSnapshot` par une migration
additive dédiée — jamais en réutilisant la relation existante comme historique. Vérifié par
`ReleasedOperatingRoomSlotFunctionalTest::test_slot_survives_hospital_and_surgeon_deletion_with_no_crash`.

**Contrainte unique et `site_id` nullable.** MySQL ne compare jamais deux `NULL` comme égaux
dans un index UNIQUE — un doublon `(NULL, 5, date)` ne se bloquerait donc jamais lui-même.
Sans conséquence ici : `ReleasedOperatingRoomSlotService::react()` ne construit jamais une
ligne avec `site` à `null` (le paramètre est un `Hospital` non-nullable, toujours résolu
depuis un `SurgeonSchedulePost` réel via le resolver) — `site_id` ne devient `NULL` qu'après
coup, via `ON DELETE SET NULL`, jamais à l'écriture (verrouillé par un test de réflexion sur
la signature de `setSite()`). Un doublon orphelin est de plus structurellement impossible :
supprimer un `Hospital` suppose d'abord la suppression de ses `SurgeonSchedulePost` (FK
`RESTRICT`), après quoi le resolver ne peut plus jamais retrouver d'occurrence pour cet
établissement disparu — aucune nouvelle ligne ne peut donc plus jamais être créée pour lui,
orpheline ou non.

### Backfill — `app:available-rooms:backfill-from-room-release`

Job à exécuter **une fois** au déploiement : projette les `occurrencesSnapshot` des
`SurgeonAbsenceCommunication` de type `ROOM_RELEASE` **encore futures** vers
`ReleasedOperatingRoomSlot` — jamais l'historique passé, jamais un recalcul depuis les
absences elles-mêmes (respecte exactement ce que le Lot A a déjà considéré comme libéré,
sans réinterpréter). Idempotent par la même contrainte unique que le service temps réel.

Clarification demandée en revue sur un premier essai manuel (« 41 créés puis 0/47 ») : les
deux runs n'ont pas scanné le même total de communications `ROOM_RELEASE` — de nouvelles
communications ont continué d'être créées entre les deux exécutions (activité réelle du 9ᵉ
collaborateur en temps réel, qui tourne déjà en continu depuis le déploiement du service).
Le nombre total examiné diffère donc légitimement d'un run à l'autre ; ce qui compte est que
**zéro nouvelle ligne n'a été créée** au second run, chaque occurrence étant déjà connue
(soit du premier backfill, soit déjà projetée en temps réel par le service). Démontré
explicitement, indépendamment de toute pollution de la base de test partagée, par
`BackfillAvailableRoomsFromRoomReleaseCommandTest::test_second_run_creates_nothing_and_reports_all_as_already_existing`
— assertions scopées aux lignes propres du test (comparaison d'ID avant/après), jamais au
compteur global affiché par la commande (qui porte sur l'intégralité de la table, non fiable
en base de test partagée entre classes).

### Horaires — jamais inventés

`startTime`/`endTime` sont snapshotés uniquement depuis `ShiftPeriodConfig` (site + période,
actif) **au moment de la création** du slot — jamais recalculés ni inventés si aucune
configuration n'existe pour ce site/période, auquel cas les deux champs restent `null` et
l'UI affiche la période seule sans horaire. Aucune conversion de fuseau horaire n'est
nécessaire : `ShiftPeriodConfig` porte déjà des heures wall-clock `Europe/Brussels`, copiées
telles quelles (`type: time_immutable`), cohérent avec la convention déjà établie pour ce
type de champ ailleurs dans le projet.

### Endpoints et RBAC

`GET /api/planning/available-rooms` (manager, `PlanningVoter::PLANNING_MANAGE` — aucun
scoping par site pour ce rôle, comme partout ailleurs dans le projet) et
`GET /api/me/available-rooms` (chirurgien, strictement scopé à ses propres affiliations
`SiteMembership` — jamais un `siteId` client de confiance au-delà de cette intersection).
Filtres `siteId`/`status`/`surgeonId` (manager uniquement)/`includePast`, pagination
`page`/`limit` (borné à 100).

### Deep link email — bug de repli texte brut trouvé et corrigé

Ajout d'un lien vers `/app/s/planning/salles-disponibles` dans le corps de l'email « Libération
de salle » (Lot A). Bug réel trouvé en revue : `SendTemplatedEmailMessageHandler` dérive le
corps texte brut via `strip_tags($htmlBody)` en l'absence de `textTemplate` dédié — une
marque `<a href="{{ url }}">Voir les salles disponibles →</a>` aurait perdu l'URL de
l'attribut `href` dans cette version texte, laissant une phrase orpheline non actionnable
pour les clients email en texte brut. Corrigé en alignant sur la convention déjà établie
ailleurs dans le projet (`mission_encoding_reminder.html.twig`) : l'URL sert elle-même de
texte visible du lien (`<a href="{{ roomsUrl }}">{{ roomsUrl }}</a>`), garantissant qu'elle
survit au `strip_tags()`. Vérifié par
`RoomReleaseCommunicationFunctionalTest::test_body_includes_deep_link_that_survives_the_plain_text_fallback`
(applique le même `strip_tags()`/`html_entity_decode()` que le handler réel et vérifie que
l'URL complète reste présente). Le lien ne bénéficie d'aucune règle de visibilité
supplémentaire au-delà du scoping normal de la page cible (`/me/available-rooms`, scopé aux
affiliations du destinataire) — un collègue sans accès au site concerné suit le lien vers une
page qui, simplement, ne lui montrera jamais ce créneau.

### UX

`/app/s/planning/salles-disponibles` (chirurgien, nouvelle page dédiée + lien depuis
`SurgeonPlanningPage.tsx`) et un nouvel onglet « Salles disponibles » dans le Planning V2
manager (`PlanningV2Tabs.tsx`/`PlanningV2Page.tsx`) — vue liste uniquement pour ce lot (pas
de vue calendrier, jugée non nécessaire pour ce volume). Champs obligatoires affichés :
date, site, période, horaires (si connus), « Libérée par {Dr X} », statut « Disponible ».

### Migration

`Version20260906140000` écrite à la main (`doctrine:migrations:diff` échoue sur ce projet à
cause d'un problème d'introspection DBAL pré-existant, sans rapport avec ce lot) — crée
`released_operating_room_slot`, FK `site_id`/`surgeon_id`/`schedule_post_id`/`source_absence_id`
toutes `ON DELETE SET NULL`, contrainte unique `(site_id, post_id, occurrence_date)`, index
`(surgeon_id)` et `(site_id, status, occurrence_date)`.

### Non fait dans ce lot (Lot E)

`assignedToSurgeon`/`assignedAt`/`closedAt`, tout mécanisme d'« Intérêt »/attribution, tout
second statut au-delà d'`AVAILABLE`, Google Calendar. Proposé et discuté avec l'utilisateur,
explicitement non codé sur instruction (« Ne code pas Lot E maintenant »).

Non déployé.

## Correction — backfill Lot D basé sur les absences, jamais sur le journal ROOM_RELEASE (2026-09-07)

### Angle mort découvert par audit prod

Après déploiement (`v2026.09.07-prod`), un audit demandé en amont de l'exécution du backfill
(§Backfill ci-dessus) a révélé que `released_operating_room_slot` était vide en production
alors que 7 communications `ROOM_RELEASE` portaient des occurrences futures — confirmant que
le backfill n'avait jamais tourné (aucune trace en base ni en logs). Le dry-run réel (requête
SQL reproduisant fidèlement `occurrencesSnapshot` → slot) a montré qu'un run couvrirait 14
occurrences futures distinctes. Mais l'audit a aussi croisé, via l'endpoint de preview
existant du Lot C (`POST .../backfill/preview`, qui réutilise le vrai
`SurgeonAbsenceBlockOccurrenceResolver`), la liste des absences ayant réellement des
occurrences `BLOCK` futures avec la liste des absences possédant au moins une communication
`ROOM_RELEASE` — révélant **l'absence #42** (chirurgien réel, congé 2026-10-19 → 2026-10-30,
4 occurrences `BLOCK` futures confirmées par le resolver) : **zéro** `SurgeonAbsenceCommunication`
de quelque type que ce soit pour cette absence. Le backfill initial (`app:available-rooms:
backfill-from-room-release`, §Backfill ci-dessus), en lisant exclusivement le journal
`ROOM_RELEASE`, ne pouvait structurellement jamais la voir — angle mort confirmé, jamais un
cas isolé : toute absence dont le site avait `notifyColleaguesEnabled=false`, ou sans
collègue affilié, ou créée avant le déploiement du Lot A sans être rattrapée par le Lot C,
produit exactement le même trou.

### Reformulation de la question posée par le backfill

Décision actée avec l'utilisateur : le backfill des salles disponibles doit répondre à
« quelles salles ont réellement été libérées par des absences existantes et ont encore une
occurrence future ? », jamais à « quels emails `ROOM_RELEASE` ont déjà été envoyés ? » — deux
questions distinctes, la seconde n'étant qu'un sous-ensemble incomplet de la première.

### `app:available-rooms:backfill` remplace `app:available-rooms:backfill-from-room-release`

L'ancienne commande (jamais exécutée en production — aucune donnée historique à préserver)
est supprimée, avec son test dédié. La nouvelle commande ne lit plus jamais
`SurgeonAbsenceCommunication` : elle sélectionne toutes les `Absence` dont `dateEnd >=
aujourd'hui` (pur filtre de performance — une fenêtre entièrement passée ne peut structurellement
produire aucune occurrence future, jamais un changement de comportement), puis appelle pour
chacune la même logique que le 9ᵉ collaborateur temps réel.

**Aucune duplication de la logique d'écriture** — extraction, jamais duplication : la partie
lecture de `ReleasedOperatingRoomSlotService::react()` (resolveForWindow + filtre futur +
`existsFor()`) est extraite dans une nouvelle méthode publique `resolveFutureOccurrences()`,
réutilisée à la fois par `react()` (persist réel, chemin temps réel inchangé) et par la
commande (dry-run en lecture pure, exécution réelle qui rappelle `onAbsenceUpdated()` —
jamais une réimplémentation du persist). Contrairement au rattrapage Lot C
(`AbsenceCommunicationBackfillService`, qui duplique volontairement une lecture pour ne
jamais dépendre du service réel dans son mode preview), ici l'extraction est directement
partagée par les deux chemins : aucune dérive future possible entre ce que la commande
annonce et ce que le service fait réellement, par construction.

Conséquence directe de la réutilisation de `resolveFutureOccurrences()`/`onAbsenceUpdated()` :
la nouvelle commande hérite structurellement de toutes les garanties déjà établies du service
— indépendance de `notifyColleaguesEnabled`, exclusion `CONSULTATION`, exclusion du passé,
idempotence par `(site_id, post_id, occurrence_date)`, non-rétractation (une absence
supprimée après coup n'efface jamais un slot déjà créé, `sourceAbsence` devient `NULL` via
`ON DELETE SET NULL`) — et n'importe jamais aucun concept d'email/communication : le service
qu'elle appelle ne connaît ni `SendTemplatedEmailMessage`, ni `SurgeonAbsenceCommunication`,
ni `AbsenceCommunicationJournalService`. Aucun email ne peut donc jamais partir, aucun
historique D-114 (Lots A/B/C) n'est jamais touché — vérifié explicitement par test
(`test_backfill_creates_no_communication_journal_entry`).

### `--dry-run`

Nouvelle option, lecture strictement pure (aucun `persist`/`flush` — garanti structurellement
par le fait que le mode dry-run n'appelle jamais `onAbsenceUpdated()`, seulement
`resolveFutureOccurrences()`) : affiche absences analysées, occurrences `BLOCK` futures
trouvées, déjà existantes, et celles qui seraient créées (détail chirurgien/site/date/
période/postId). Vérifié par test que le dry-run ne modifie rien puis que le run réel produit
exactement ce que le dry-run annonçait.

### Tests

`AvailableRoomsBackfillCommandTest` (remplace `BackfillAvailableRoomsFromRoomReleaseCommandTest`,
supprimé) : absence avec historique `ROOM_RELEASE` → slot créé ; absence sans historique
(réplique exacte du cas #42) → slot créé quand même ; `notifyColleaguesEnabled=false` → slot
créé quand même ; `CONSULTATION` → jamais créée ; occurrence passée au sein d'une absence
encore sélectionnée → exclue, seule l'occurrence future produit un slot ; second run →
idempotent, mêmes IDs ; absence supprimée après coup → jamais recréée, slot déjà créé
survivant confirmé ; multi-site → un slot par site concerné ; aucune
`SurgeonAbsenceCommunication` créée ; dry-run sans écriture puis run réel identique à
l'annonce. 10/10 verts en local, aucune régression sur `ReleasedOperatingRoomSlotFunctionalTest`/
`AvailableRoomsControllerTest` (18/18 verts) après le refactor du service.

Non déployé — le backfill réel en production reste en attente d'une décision explicite
séparée, après revue du diff/dry-run par l'utilisateur.

## Revue intégration agenda — Lot D dans le planning chirurgien (2026-09-07)

**Suite à une coupure de courant ayant interrompu la session initiale**, cette revue a été
reprise et vérifiée en live dans le navigateur (§17 de la demande) sur les conteneurs Docker
locaux (MySQL natif WAMP redémarré manuellement, `docker compose up -d`). Cette vérification a
immédiatement révélé un bug bloquant, corrigé avant tout déploiement — voir « Bug découvert en
vérification live » ci-dessous.

### Contexte

Après le Lot D initial (vue « Salles disponibles » en liste seule, ci-dessus), revue avec
l'utilisateur pour intégrer ces créneaux directement dans le planning chirurgien
(`SurgeonPlanningPage`) plutôt que de laisser la liste comme seul point d'accès. Deux volets
distincts en sont ressortis, livrés ensemble dans ce commit :

### 1. Filtres de fenêtre + endpoint `count` dédié

`GET /api/planning/available-rooms` et `GET /api/me/available-rooms` gagnent `dateFrom?`/
`dateTo?` (`Y-m-d`, 400 si malformé) et `period?` (`MATIN`/`APRES_MIDI`/`JOURNEE`, 400 si
invalide) — le calendrier chirurgien ne requête ainsi que la fenêtre affichée (mois/semaine),
jamais tout l'historique. `includePast` reste réservé au manager : côté chirurgien il n'est
même pas lu par `parseFilters()` (`allowIncludePast: false`), pas seulement ignoré après coup
— un chirurgien ne doit structurellement jamais pouvoir demander le passé sur son propre
espace, y compris en le forçant explicitement dans la query string (couvert par
`test_surgeon_never_sees_the_past_even_with_includePast_forced`).

Nouvel endpoint `GET /api/me/available-rooms/count` (`ReleasedOperatingRoomSlotRepository::
countForList()`, un seul `SELECT COUNT`) pour le badge CTA « Salles disponibles (N) » du
planning chirurgien — jamais charger la liste complète seulement pour afficher un nombre.

`SurgeonAvailableRoomsPage` (vue liste existante) expose ces filtres à l'utilisateur : chips
de plage rapide (Aujourd'hui/Cette semaine/30 prochains jours/À venir — défaut), Autocomplete
établissement (dérivé d'une requête large non bornée par les filtres actifs, pour que les
options ne se rétrécissent jamais elles-mêmes), sélecteur période — tous synchronisés dans
l'URL pour un deep-link filtrable.

### 2. Intégration calendrier + panneau détail du jour

`SurgeonPlanningPage` requête `GET /api/me/available-rooms` bornée à la fenêtre affichée et
affiche les créneaux comme un second canal visuel — pastille/badge bleu (`#1B5FD0` en accent,
même famille que la variante `aVenir` de `DateTile`), jamais le vert mission ni l'ambre
« à couvrir »/« à encoder » : un point mission et un badge salle peuvent coexister sur le même
jour sans se confondre, ni en vue semaine (`WeekStrip`, deux pastilles séparées) ni en vue
mois (`MonthGrid`, badge numérique superposé à la cellule).

Cliquer un jour qui n'a aucune salle disponible garde le comportement historique inchangé
(ouverture directe du dialogue mission). Dès qu'il y a au moins une salle ce jour-là, un
nouveau panneau « détail du jour » s'ouvre à la place, listant missions et salles disponibles
dans deux sections distinctes (`AvailableRoomsListSection`, réutilisée telle quelle depuis
`SurgeonAvailableRoomsPage`) — jamais une salle absorbée silencieusement dans le dialogue
mission, qui n'a pas de sens pour un objet sans statut de mission.

### Bug découvert en vérification live — format de date UTC cassait la liste calendrier

Tous les tests (backend et frontend) passaient et la revue de code n'a rien détecté, mais la
vérification en navigateur réel (§17) a montré que **le calendrier chirurgien n'affichait
jamais aucune salle**, alors que le badge compteur du CTA affichait le bon total (`31`) — deux
appels API distincts, un seul cassé. Cause : `SurgeonPlanningPage.tsx` réutilisait le `range`
déjà calculé pour la requête missions (`getRange()`, qui retourne `.toISOString()` — un
horodatage UTC complet, ex. `2026-08-31T22:00:00.000Z` pour le 1ᵉʳ septembre local) comme
`dateFrom`/`dateTo` de `getMyAvailableRooms()`. Le backend exige strictement `Y-m-d`
(`DateTimeImmutable::createFromFormat('!Y-m-d', ...)`) et renvoie 400 sur tout ce qui porte une
heure — la requête échouait silencieusement (`roomsQuery.data?.items ?? []` retombe sur une
liste vide sans UI d'erreur dédiée), et `.toISOString()` décale en plus la date d'un jour en
arrière pour un fuseau Bruxelles (UTC+1/+2), donc même une conversion naïve en tronquant la
partie horaire aurait été fausse d'un jour.

**Pourquoi aucun test ne l'a attrapé** : les mocks `getMyAvailableRoomsMock` de
`SurgeonPlanningPage.test.tsx` vérifiaient uniquement que les valeurs passées correspondaient à
`range.from`/`range.to` (peu importe leur format), jamais que ces valeurs respectaient le
contrat `Y-m-d` réellement imposé par le backend — un mock au niveau module ne peut structurellement
pas détecter un problème de contrat d'API. C'est exactement le type de régression que seule une
vérification live/E2E peut révéler.

**Correctif** : nouvelle fonction `getYmdRange()` dans `planningPrimitives.tsx`, miroir de
`getRange()` mais qui construit le `Y-m-d` local directement (`formatDateToYmd()`) depuis les
mêmes `Date` locales, jamais via `.toISOString()` — `to` y est inclusif (dernier jour affiché),
contrairement au `to` exclusif de `getRange()` (dimensionné pour une requête missions qui
compare des `startAt` datetime). `SurgeonPlanningPage.tsx` calcule désormais `roomsRange` via
`getYmdRange(view, date)`, indépendamment de `range`. Régression couverte par un nouveau test
dédié (`dateFrom`/`dateTo` envoyés au format Y-m-d, jamais un ISO horodaté) qui aurait détecté
ce bug exact — vérifié en relisant l'ancien code avec ce test : il aurait échoué.

### Tests

Backend : `AvailableRoomsControllerTest` (dateFrom/dateTo/period/count/400 malformé/
`includePast` chirurgien toujours ignoré/siteId au sein des sites affiliés/tri chronologique
croissant). Frontend : `SurgeonPlanningPage.test.tsx` (badge, panneau détail jour, pastilles
semaine/mois, régression format `Y-m-d`), `SurgeonAvailableRoomsPage.test.tsx` (nouveau
fichier — chips de plage, filtre établissement/période, deep-link URL). 16/16 verts sur
`AvailableRoomsControllerTest` seule (2262/2262 sur la suite backend complète, hors un flake
préexistant sans rapport —
`InterventionTypeControllerTest::test_similar_suggests_a_high_confidence_candidate_without_blocking_creation`,
reproduit identique sur `main` avant ce commit) ; 35/35 verts sur les deux fichiers de tests
frontend touchés.

Note de périmètre : trois correctifs indépendants découverts en marge de cette revue —
affichage de l'état `SKIPPED` dans l'inspecteur planning-v2 (`Inspector.tsx`,
`GeneratePlanningTab.tsx`), photos chirurgien/instrumentiste dans les lignes de preview
(`PreviewLineResponse`, `PlanningGeneratorServiceV2`, `generatePreviewGrouping.ts`), et
réconciliation d'absence lors du retrait manuel d'un instrumentiste
(`PlanningModificationService`) — appartiennent au chantier **D-112** déjà documenté ci-dessus
(2026-08-16) et restent volontairement hors de ce commit ; un correctif indépendant
supplémentaire (auto-suggestion de `getFreedInstrumentists()`) reste également hors commit.

### Vérification live (§17)

Sur les conteneurs Docker locaux (`docker compose up -d`, MySQL natif WAMP redémarré
manuellement suite à la coupure de courant) avec 34 créneaux réels backfillés depuis les
absences fixtures D-114 restées en base (`app:available-rooms:backfill`, aucune donnée
synthétique inventée) :

- **Manager** (`manager@surgeryhub.be`) : `GET /api/planning/available-rooms` → 34 créneaux,
  tous sites confondus, triés chronologiquement — confirmé par API directe.
- **Chirurgien affilié à Delta Test** (compte fixture `d114-delta-colleague-1@d114test.invalid`,
  mot de passe repositionné localement via `app:create-dev-user` pour la vérification) : badge
  CTA « Salles disponibles 31 », grille mois avec badges numériques bleus sur les jours
  concernés (9, 10, 12, 14, 16, 17, 21-24, 29, 30), légende « Salle disponible » affichée, clic
  sur un jour à 2 créneaux → panneau détail affichant les deux lignes avec pastille
  « Disponible », jamais un statut de mission. Page `/app/s/planning/salles-disponibles` :
  liste triée, chip « 30 prochains jours » → URL `?range=30d`, `dateFrom`/`dateTo` corrects
  (`2026-09-07`/`2026-10-07`). Rechargement direct de l'URL avec `?range=30d` → même état
  restauré (deep-link confirmé).
- **Chirurgien non affilié** (compte de test dédié, aucune `SiteMembership`) : badge CTA absent
  (`count: 0`), aucun badge dans la grille mois, aucune section « Salles disponibles » —
  confirmé à la fois par `GET /api/me/available-rooms/count` (`{"count":0}`) et visuellement
  dans le navigateur.

C'est cette vérification qui a révélé le bug `Y-m-d` ci-dessus — la grille mois du chirurgien
affilié ne montrait initialement aucun badge malgré un badge CTA correct à 31, jusqu'au
correctif `getYmdRange()`.

Non déployé.

## D-115 — Planning V2 : réouverture, modification et suppression des brouillons persistés (CAS D, 2026-09-08)

**Statut : backend + frontend DONE, testé (backend et frontend verts), non commité. Non déployé.**

Date : 2026-09-08

### Contexte

Un manager qui génère un brouillon (`generate()` → `PlanningVersion` DRAFT + `Mission[]`
DRAFT) puis quitte la page n'avait aucun moyen de le rouvrir, le modifier ou le supprimer :
le mois restait « déjà généré » aux yeux d'une nouvelle génération (le doublon silencieux
était déjà refusé, voir Batch 8/9), mais rien ne permettait de reprendre le brouillon
existant. Le manager se retrouvait bloqué.

### Décision

**Source de vérité d'un brouillon** : `PlanningVersion` + ses `Mission[]` en statut `DRAFT`
— jamais reconstruite silencieusement à partir d'un nouveau `preview()`. Un `SurgeonSchedulePost`,
une absence ou un `ShiftPeriodConfig` peuvent avoir changé depuis la création du brouillon ;
un re-preview produirait un planning différent de celui réellement enregistré et effacerait
implicitement le travail du manager. `preview()` reste utilisé par `PlanningDraftService::reopen()`,
mais uniquement pour re-dériver de l'information non persistée et purement d'affichage
(occurrences `SKIPPED` d'un chirurgien actuellement absent) — jamais comme source de vérité.
Le rattachement des lignes du preview aux vraies `Mission` DRAFT persistées se fait par le même
mécanisme de `claimMission()` que `preview()` utilise déjà pour toute occurrence `COVERED`/`MODIFIED`
(aucun nouveau matching introduit).

**`SKIPPED` reste non persisté.** Une occurrence ignorée (chirurgien absent au moment du
`preview()`/`reopen()`) ne crée et n'a jamais créé de `Mission` — c'est un statut calculé, pas
un choix du manager à mémoriser. Elle ne dépend que de l'état d'absence courant, recalculé à
chaque `reopen()` : si le chirurgien redevient disponible, l'occurrence redevient normalement
`UNCOVERED`/`COVERED` sans action manuelle. Aucune structure persistante dédiée n'a donc été
nécessaire ; le cycle de vie de `MissionStatus` n'a pas été détourné (voir §7 du brief CAS D).

**Unicité** : au plus un brouillon (`PlanningVersionStatus::DRAFT`) non déployé par période +
scope exact (`site` ou bucket `site=null`), déjà imposé par le garde-fou Batch 8/9
(`PlanningV2GenerationController::assertNoUndeployedDraftExists()`). Ce lot ne fait
qu'enrichir le 409 existant : `PlanningDraftAlreadyExistsException` porte désormais l'id du
brouillon existant, exposé au frontend sous `{code: 'PLANNING_DRAFT_ALREADY_EXISTS', versionId}`
au lieu d'un `{error:{code:'CONFLICT'}}` générique — jamais de suppression automatique de
l'ancien brouillon.

**Divergence du modèle source** : `PlanningVersion.previewHash` (nouvelle colonne, migration
`Version20260908090000`) capture `computePreviewVersion()` au moment du `generate()`. À la
réouverture, `PlanningDraftService::reopen()` recalcule ce hash et le compare — `divergent:
bool` dans la réponse est **purement informationnel** : il ne bloque jamais la réouverture, ne
remplace jamais rien, et vaut toujours `false` pour un brouillon créé avant ce lot (`previewHash`
nullable, pas de détection rétroactive).

**Suppression** : refusée avec `PlanningVersionNotDraftException` → 409
`PLANNING_VERSION_NOT_DRAFT` dès que la version elle-même n'est pas DRAFT, ou qu'au moins une
de ses missions a quitté DRAFT par un autre chemin (déploiement partiel, modification directe).
Restaure la sémantique de suppression DRAFT d'avant D-079 (route orpheline V1 retirée sans
remplacement V2 au commit `570a551`).

### Backend

- **Endpoints** (intégrés aux contrôleurs existants — pas de nouveau contrôleur dédié, cohérence
  architecturale préférée à l'URL exacte du brief) :
  - `GET /api/planning/v2/drafts/{id}` — réouvre un brouillon (`PlanningV2GenerationController::reopenDraft()`).
  - `PATCH /api/planning/v2/drafts/{id}` — applique des modifications de lignes au brouillon
    (`updateDraft()`).
  - `DELETE /api/planning/versions/{id}` — supprime un brouillon entièrement DRAFT
    (`PlanningVersionController::delete()`) ; route V1 orpheline (D-079) réactivée avec la
    bonne garde V2.
  - `GET /api/planning/versions?status=DRAFT&...` (déjà existant, Batch 15F) — sert de liste
    des brouillons ; pas de doublon `GET /api/planning/v2/drafts` créé.
- **Service** : `PlanningDraftService` (`reopen()`, `update()`, `delete()`) — toute mutation
  passe par lui, jamais le contrôleur. Réutilise `MissionEligibilityService::evaluateForReassignment()`
  pour toute réaffectation (aucune règle d'éligibilité dupliquée) et `PlanningGeneratorServiceV2::createMissionFromLine()`
  (méthode extraite de `generate()`, comportement inchangé) pour toute ligne sans `Mission`
  existante (ex. un Post ajouté au scope après la création du brouillon).
- **Migration** : `Version20260908090000` — `planning_version.preview_hash VARCHAR(64) NULL`,
  purement additive.
- **Limitation connue — corrigée par D-115bis** : un brouillon de groupe de sites
  persistait avec `site = null`, indistinguable du bucket « aucun filtre de site » —
  `PlanningVersion` n'avait pas de colonne pour mémoriser le groupe/l'ensemble de sites.
  `PlanningDraftService::requireSingleSiteScope()` refusait explicitement la réouverture
  d'un tel brouillon (`400`) plutôt que de prévisualiser le mauvais (ou tous les) site(s).
  Voir D-115bis ci-dessous pour le correctif (snapshot explicite du scope). Limitation
  analogue toujours ouverte en Batch 8 §B/§I pour la détection de doublon (hors périmètre
  D-115bis).

### Tests

10/10 scénarios du brief couverts par `PlanningV2DraftControllerTest` (génération → liste →
réouverture → modification → persistance après « quitter/revenir » → suppression → refus si
non-DRAFT → refus de double génération → Post ajouté après coup → instrumentiste inéligible
refusé), plus la mise à jour de l'assertion 409 dans `PlanningV2GenerationControllerTest`. Voir
le rapport de validation CAS D pour le détail des résultats d'exécution.

### Frontend

`GeneratePlanningTab.tsx` — aucun second éditeur créé : un brouillon rouvert reste dans le
flux « Génération » existant (`preview`/`generated`/`deployed`), jamais le flux « Modification »
(réservé aux versions ACTIVE, sémantique de mutation différente — `MissionPostDeployService`
post-déploiement vs. mutation directe pré-déploiement ici).

- **Détection** : le chip du mois et la liste « Plannings déjà générés » distinguent désormais
  trois états à partir de la même liste non filtrée (`GET /api/planning/versions`) — ACTIVE
  (« · déjà généré », action Modifier), DRAFT (« · Brouillon », action Ouvrir), ARCHIVED
  (inerte, déjà supersédé).
- **Ouvrir** : `openDraftMutation` appelle `reopenDraft()`, peuple `preview`/`previewResponses`
  avec les lignes retournées (déjà rattachées aux vraies `Mission` via `existingMissionId`),
  aligne `selectedMonthIds`/`targetId` sur la version, et bascule un flag `draftVersionId` qui
  remplace le bouton « Générer les missions » par « Enregistrer les modifications » — sans
  dupliquer l'écran d'édition.
- **Bannière de divergence** : affichée dans l'en-tête quand `divergent: true` (toast au moment
  de l'ouverture + encart persistant) — jamais bloquante, jamais un remplacement silencieux des
  lignes déjà enregistrées.
- **Enregistrer** : `saveDraftMutation` appelle `updateDraft()` avec les `effectiveLines`
  courantes (mêmes lignes que celles envoyées à `generate()` en mode génération neuve) et
  peuple `generated` avec la même forme que `generatePlanningV2()` — le bouton « Déployer »
  existant fonctionne donc sans aucune modification supplémentaire.
- **Supprimer** : confirmation (`deleteDraftTarget`) accessible depuis l'en-tête du brouillon
  ouvert et depuis chaque ligne DRAFT de la liste historique ; `deleteDraftMutation` appelle
  `deletePlanningVersionDraft()` puis invalide `["planning-v2", "versions-history"]`.

### Tests exécutés

Backend : suite complète 2273/2273 (hors 2 échecs préexistants confirmés sans rapport —
`test_open_mission_pipeline_v2_deploy_to_claim` et
`ReleasedOperatingRoomSlotFunctionalTest::test_slot_survives_hospital_and_surgeon_deletion_with_no_crash`,
ni l'un ni l'autre touchés par ce lot, reproduits identiquement sur `HEAD` avant tout
changement CAS D) + 8/8 `PlanningV2DraftControllerTest`. Frontend : 1270/1270 (dont 36/36
`GeneratePlanningTab.test.tsx`, 5 nouveaux scénarios CAS D). `tsc -b --noEmit` : une seule
erreur préexistante et sans rapport (`PrestationsPage.tsx`, hors périmètre) — aucune erreur
introduite par ce lot ; `npm run build` bloqué par cette même erreur préexistante (déjà
documentée ailleurs comme dette non liée à Planning V2).

Non déployé.

## D-115bis — Réouverture d'un brouillon multi-sites / groupe de sites / Tous sites (2026-09-11)

**Statut : DONE, testé (backend et frontend verts), committé, déployé (`v2026.09.11-prod-3`).**

Date : 2026-09-11

### Contexte

Limitation connue de D-115 : `PlanningDraftService::reopen()` refusait (`400`) la réouverture
de tout brouillon dont la version avait `site = null` — cas d'un `PlanningVersion` généré
pour un groupe de sites ou pour « Tous sites ». `preview()`/`computePreviewVersion()`
supportaient déjà `siteGroupId` en entrée ; seul `reopen()` était bloqué, faute de pouvoir
reconstruire le scope exact du brouillon au moment de la réouverture.

### Décision — snapshot explicite du scope, jamais de reconstruction dynamique

Reconstruire le scope à la volée à partir des `Mission` DRAFT déjà persistées a été écarté :
deux défauts rédhibitoires identifiés à l'audit — (1) un site du groupe sans aucune mission
ce mois-là (aucun `SurgeonSchedulePost` actif) disparaîtrait silencieusement du scope
reconstruit, sans que rien ne le distingue d'un groupe réellement plus restreint ; (2)
`SiteGroupMembership` est mutable à tout moment — une reconstruction dynamique ferait dériver
le scope d'un vieux brouillon si la composition du groupe change après sa création, ce qui
viole l'exigence de stabilité historique (un brouillon doit rouvrir avec le scope qu'il avait
à sa génération, jamais celui du groupe aujourd'hui).

**`PlanningVersion` gagne deux colonnes** (migration `Version20260911090000`, additive) :
- `scopeSiteIds` (JSON nullable) — snapshot figé, à la génération, des ids de sites du scope.
  **Seule source de vérité pour la réouverture** d'un brouillon `site = null`.
- `siteGroup` (FK nullable vers `SiteGroup`, `ON DELETE SET NULL`) — purement informatif,
  utilisé uniquement pour l'affichage (nom du groupe dans l'historique/le sélecteur) ; jamais
  relu pour reconstruire un scope.

`PlanningGeneratorServiceV2::resolveSiteIds()` accepte désormais un paramètre optionnel
`explicitSiteIds` : quand fourni (cas `reopen()`), il court-circuite entièrement la
résolution `siteId`/`siteGroupId` — `preview()`/`computePreviewVersion()`/`generate()`
en héritent en paramètre additionnel, changement rétrocompatible par construction (paramètres
optionnels en fin de signature).

### Compatibilité avec les brouillons déjà existants

Un brouillon multi-sites créé avant ce lot n'a pas de `scopeSiteIds` (colonne inexistante à
l'époque). Deux mécanismes complémentaires, jamais un seul :

1. **Backfill de migration** — `Version20260911090000` peuple `scope_site_ids` pour tout
   `PlanningVersion` `site IS NULL AND status = 'DRAFT'` à partir des `site_id` distincts de
   ses propres `Mission` déjà persistées (`JSON_ARRAYAGG` sur sous-requête `DISTINCT`).
   Vérifié sur les brouillons réels d'octobre/novembre présents en dev (backfillés à
   `[1, 3]`).
2. **Auto-réparation applicative** — `PlanningDraftService::resolveGroupScopeSiteIds()` : si
   `scopeSiteIds` est toujours absent au moment d'un `reopen()` (migration non encore
   appliquée sur cet environnement, ou brouillon créé entre deux étapes de déploiement),
   reconstruit le scope à partir des `Mission` DRAFT de la version et le persiste
   immédiatement — ne lève `BadRequestHttpException` que si la version n'a strictement aucune
   mission DRAFT à partir de laquelle reconstruire (cas où même le fallback ne peut pas
   garantir un scope correct).

Le brouillon ne perd donc jamais sa capacité de réouverture, et son scope n'est jamais
attribué au hasard : soit le snapshot existe, soit il est reconstruit depuis les données
réellement persistées de ce brouillon précis (jamais depuis l'état courant d'un `SiteGroup`).

### Tests

`PlanningV2DraftControllerTest` — 8 nouveaux scénarios (25/25 au total dans ce fichier) :
réouverture site unique / groupe / tous sites, persistance après quitter/revenir, ajout de
mission ad hoc sur brouillon rouvert, re-dérivation `SKIPPED` après absence créée
post-génération, suppression d'un brouillon multi-sites, **composition du groupe modifiée
après génération ⇒ le brouillon garde son scope initial** (test de stabilité), auto-réparation
d'un brouillon pré-existant sans snapshot, refus explicite si aucune mission ne permet de
reconstruire le scope.

### Frontend

`GeneratePlanningTab.tsx` : le bouton « Ouvrir » fonctionne à l'identique pour un brouillon
mono-site, groupe ou tous sites. `openDraftMutation` aligne `targetId` sur `siteGroupId` (via
le même décalage `GROUP_ID_OFFSET` que le sélecteur site/groupe). `matchesScope()` distingue
désormais deux groupes différents portant tous deux `site = null` par leur `siteGroupId`
plutôt que de faire correspondre toute sélection de groupe à n'importe quel brouillon
`site = null`. Libellé de la liste des brouillons : nom du groupe si connu, sinon
« Tous sites » (fallback inchangé pour les brouillons antérieurs à ce lot, avant backfill).

### Tests exécutés (implémentation initiale)

Backend : suite complète 2332/2332 (hors 1 échec préexistant confirmé sans rapport —
`ReleasedOperatingRoomSlotFunctionalTest::test_slot_survives_hospital_and_surgeon_deletion_with_no_crash`,
reproduit à l'identique sans les changements D-115bis) + `--filter Planning` 486/486. Frontend :
1281/1282 (le seul échec, `GeneratePlanningTab.test.tsx` — scénario « Ajouter » CAS D, est un
flake de timing préexistant à ~5-6,6s contre un seuil par défaut de 5000ms, reproduit à
l'identique sans les changements D-115bis). `npm run build` : OK. `git diff --check` : propre.

### Complément — confirmation explicite du scope legacy (2026-09-11, même jour)

Un audit en lecture seule de la production (avant tout code) a montré que le backfill
(`scope_site_ids` reconstruit depuis `DISTINCT Mission.site`) ne peut jamais être certifié
complet : un site sans aucune Mission persistée ce mois-là (ex. chirurgien absent dès la
génération) serait silencieusement absent du scope reconstruit, sans que rien ne le
distingue d'un groupe réellement plus restreint. Les deux seuls brouillons multi-sites
réels en prod (id 10 octobre, id 11 novembre, backfillés à `[1, 3]`) correspondent au seul
`SiteGroup` existant (« BOOST », membership actuelle `{1, 3}`) — cohérence forte, mais non
prouvée : rien ne garantit que la membership n'a pas changé depuis, ni qu'un 3ᵉ site
(`CHIREC - Edith Cavell`, jamais doté d'un `SurgeonSchedulePost`) n'était pas dans le
périmètre demandé avec zéro occurrence.

**Décision** : `PlanningVersion` gagne `scopeSource` (`PlanningVersionScopeSource` :
`SNAPSHOT | RECONSTRUCTED | CONFIRMED`, migration `Version20260911100000`, jamais un simple
booléen). `SNAPSHOT` posé par `generate()` en même temps que `scopeSiteIds` (certain par
construction). Le backfill de migration et le self-heal de `reopen()` posent
`RECONSTRUCTED` — jamais promu silencieusement à certain.

`PlanningDraftService::assertScopeConfirmed()` refuse (409
`PLANNING_DRAFT_SCOPE_CONFIRMATION_REQUIRED`) `update()` (couvre sauvegarde ET ajout ad hoc,
même endpoint) et le `deploy()` de `PlanningV2GenerationController` tant que
`scopeSource === RECONSTRUCTED`. `reopen()` et `delete()` restent volontairement libres — un
manager doit pouvoir inspecter un brouillon legacy, ou le supprimer, sans d'abord certifier
un périmètre qu'il souhaite peut-être simplement abandonner.

Nouvel endpoint `POST /api/planning/v2/drafts/{id}/confirm-scope` (`{siteIds: number[]}`) —
`PlanningDraftService::confirmScope()` : valide chaque id contre `Hospital`, dédoublonne,
persiste et passe `scopeSource` à `CONFIRMED`. Refuse une reconfirmation
(`PLANNING_DRAFT_SCOPE_ALREADY_CONFIRMED`) plutôt que d'accepter silencieusement une
resoumission qui écraserait une décision déjà verrouillée. Ne relit jamais la composition
courante d'un `SiteGroup` — le manager fournit la liste finale, potentiellement corrigée
(site ajouté ou retiré) par rapport à la reconstruction proposée.

Frontend : bandeau non ignorable (jamais juste informatif comme celui de divergence) tant
que `scopeSource === RECONSTRUCTED`, avec dialogue de confirmation (cases à cocher
pré-remplies depuis `scopeSiteIds`, éditables). « Enregistrer les modifications » désactivé
(tooltip explicite) et « Ajouter » entièrement absent tant que non confirmé.

**Vérifié en conditions réelles** (pas seulement en tests automatisés) sur les deux
brouillons réels de dev (copies des id 10/11 de prod) : bandeau affiché sur un vrai
`RECONSTRUCTED`, dialogue pré-rempli identique à l'audit prod (`Delta` + `Basilique` cochés,
`Edith Cavell` décoché), confirmation avec ajout d'`Edith Cavell` persistée en base
(`scope_site_ids: [1, 3, 8]`, `scope_source: CONFIRMED`), sauvegarde fonctionnelle après
confirmation. Un incident sans rapport (permissions `var/cache/dev` cassées par un `rm -rf`
antérieur, `www-data` ne pouvait plus écrire) a été trouvé et corrigé au passage — bloquait
toute requête HTTP réelle en dev, jamais les tests CLI.

### Tests exécutés (complément)

`PlanningV2DraftControllerTest` : 38/38 (12 nouveaux scénarios). `--filter Planning` :
499/499. Backend complet : 2344/2345 (même échec préexistant `ReleasedOperatingRoomSlot...`,
sans rapport). Frontend complet : 1281/1282 (même flake de timing préexistant). `tsc -b
--noEmit` : clean. `npm run build` : OK. `git diff --check` : clean.

Déployé — voir `docs/production.md`.

## D-117 — Signalement Sophie Colette : re-claim impossible après release (BUG B, P0) et absence journalière bloquant un claim (BUG A) (2026-09-09)

**Statut : DONE, non commité, non déployé.**

Date : 2026-09-09

### Contexte

Deux signalements distincts d'une même instrumentiste (Sophie Colette), tous deux
reproduits avec preuve HTTP+DB réelle (copie de production sanitisée en dev — voir
§ Copie prod→dev ci-dessous) avant toute correction :

- **Mission de Juan Toussain (11/09)** : `POST /api/missions/{id}/claim` → 409 « Not
  eligible to claim this mission: Absent ce jour ». Cause : `Absence` est volontairement
  journalière (`dateStart`/`dateEnd` de type `DATE`, sans heure) — l'instrumentiste avait
  noté « Juste le matin » en commentaire libre (sans effet structurel), et
  `MissionEligibilityService::isAbsentOn()` bloque donc toute la journée, y compris une
  mission l'après-midi. Refus backend correct au regard du modèle actuel — le vrai
  problème est que l'utilisatrice n'avait aucun moyen de comprendre pourquoi, ni d'agir.
- **Mission de Jérôme De Muylder (21/09)** : déjà relâchée manuellement par le manager
  (`MISSION_RELEASED_TO_POOL`) avant ce chantier — `status=OPEN`, `instrumentist=NULL`.
  Un nouveau claim sur cette même mission échouait pourtant avec 409 « Mission already
  claimed », alors qu'elle était réellement disponible.

### Décision 1 — `MissionClaim` est un historique, jamais une garde métier

`MissionPostDeployService::claim()` consultait
`MissionClaim::findOneBy(['mission' => $mission])` comme troisième garde, en plus des
vérifications déjà suffisantes (`status === OPEN`, `instrumentist === null`, toutes deux
relues à l'intérieur du verrou pessimiste). Or `MissionClaim` n'a plus d'index unique sur
`mission_id` depuis `Version20260212093000` (« *replace UNIQUE index on mission_id by
normal index* ») — le schéma autorise déjà plusieurs lignes historiques par mission,
exactement pour ce cas (claim → release → re-claim). L'exigence métier est stricte et sans
ambiguïté : **`MissionClaim` ne doit plus jamais être consulté pour une décision
métier — uniquement `Mission.status`/`Mission.instrumentist` (source de vérité), toujours
relus à l'intérieur du verrou pessimiste**. La garde a été supprimée sans aucun
remplacement — les deux vérifications déjà présentes suffisent, et le verrou pessimiste
reste l'unique garantie anti-double-claim (aucune régression : un second claim concurrent
sur une mission déjà `ASSIGNED` reste refusé, désormais par `MissionVoter::canClaim()`
avant même d'atteindre le service — 403, comportement préexistant inchangé).

### Décision 2 — les absences restent journalières ; retirer un seul jour est une opération de découpage, jamais une nouvelle granularité horaire

Décision explicite de **ne pas** introduire de notion matin/après-midi ni d'heure sur
`Absence`, et de **ne jamais parser** le commentaire libre (`reason`) pour en déduire une
règle métier. À la place : une opération dédiée, `SelfAbsenceController::removeDay()`
(`POST /api/absences/mine/{id}/remove-day`), qui retire une date précise d'une période
d'absence en la ramenant à l'une des trois opérations déjà connues et déjà testées :

```text
A. toute la période = ce seul jour           → suppression complète (delete)
B. le jour est le premier de la période      → raccourcissement du début (update)
C. le jour est le dernier de la période      → raccourcissement de la fin (update)
D. le jour est au milieu de la période       → raccourcissement + création d'une 2e période
```

Les trois cas réutilisent **exactement** le pipeline de réaction déjà existant
(`reactAndSync()` — `AbsenceImpactService`, `AbsenceMissionReactionService`, les deux
services d'impact sur occurrences, `AbsenceImpactReconciliationService`, et les trois
collaborateurs de communication) — jamais de voie parallèle qui contournerait
`AbsenceImpactService` (§13 du brief). `delete()`/`create()` ont été factorisés en méthodes
privées réutilisables (`deleteAbsence()`, `createAbsenceAndReact()`) sans changer leur
comportement — comportement préexistant validé par la suite complète
`SelfAbsenceControllerTest`/`AbsenceControllerTest` (322/322 verts, zéro régression).

Autorisation : réutilise `AbsenceVoter::SELF_MANAGE` tel quel (déjà « l'instrumentiste ne
peut agir que sur sa propre absence ») — aucune logique `if ($user->getId() === ...)`
nouvelle dans le contrôleur.

Traçabilité : un nouvel `AuditEventType::ABSENCE_DAY_REMOVED`, enregistré via
`AuditService::recordGlobal()` (événement sans Mission associée — D-072 avait déjà ouvert
cette voie pour les événements mission-indépendants). Payload minimal
(`absenceId`, `removedDate`, `originalDateStart`, `originalDateEnd`) — aucune donnée
patient.

### Décision 3 — erreur de claim structurée, jamais de parsing de texte côté frontend

`ConflictHttpException('Not eligible to claim this mission: ' . labels)` (texte libre,
jamais fiable à parser côté client) remplacée par `MissionClaimIneligibleException`,
mappée par `ApiExceptionSubscriber` sur `error.code = 'MISSION_CLAIM_INELIGIBLE'` avec
`error.reason` (raison primaire, valeur brute de `EligibilityReason`) et, uniquement quand
la raison est `ABSENT`, `error.absenceId`/`error.date`/`error.absenceDateStart`/
`error.absenceDateEnd` — résolus via `MissionEligibilityService::findBlockingAbsence()`
(nouvelle méthode publique, même requête que `isAbsentOn()`, aucune règle dupliquée).
Le frontend (`OffersPage.tsx`) branche exclusivement sur ces champs structurés, jamais sur
le texte du message.

### UX (BUG A)

Sur un claim refusé avec `reason=ABSENT`, l'écran Offres affiche une modal expliquant la
cause et proposant, sur confirmation explicite uniquement (**jamais de retrait
automatique**, §16 du brief), de retirer l'absence pour ce seul jour — texte adapté selon
qu'il s'agit d'une absence d'un seul jour ou d'une période plus large. Après succès,
rafraîchissement de `["missions"]`/`["absences"]` et invitation à recliquer sur « Prendre
la mission » — jamais de claim enchaîné automatiquement.

### Tests

- `MissionLifecycleControllerTest` (+7, BUG B) : claim initial, claim concurrent (403),
  **claim → release → re-claim par un autre instrumentiste** (RED confirmé avant
  correction : échouait avec 409 « Mission already claimed » ; vert après), claim → release
  → re-claim par le même instrumentiste, historique + mission `ASSIGNED` toujours refusé
  (403, pour la vraie raison), historique + inéligibilité réelle refusé pour la vraie
  raison (jamais « already claimed »), et l'erreur structurée complète (`reason`,
  `absenceId`, `date`, `absenceDateStart`/`absenceDateEnd`).
- `SelfAbsenceControllerTest` (+10, BUG A) : cas A/B/C/D, date hors période (400), absence
  d'un autre utilisateur (403), absence inexistante (404), claim réel réussissant après
  retrait, les autres jours de la période restent absents, événement d'audit.
- Suite complète Mission/Claim/Eligibility : 815/816 (seul échec = flake préexistant sans
  rapport, date de fixture désormais dans le passé). Suite complète Absence : 322/322.

### Copie prod → dev (investigation, §5-§7 du brief)

Dump `surgicalhub` frais (root, `--single-transaction`), transféré par `scp`, checksum
SHA-256 vérifié après transfert, fichier temporaire supprimé du serveur immédiatement
après. Sanitisation avant tout démarrage de l'application sur cette copie : mots de passe
remplacés par le hash de `password` (généré via `security:hash-password`, jamais en
clair), `refresh_tokens`/`invitation_token`/`google_id` vidés, `push_subscription` vidée.
`MAILER_DSN` confirmé pointant exclusivement vers Mailpit local (aucun SMTP réel
atteignable). Aucun webhook/SMS dans le schéma. Sauvegarde de la base dev locale
pré-existante effectuée avant import. Reproduction confirmée en conditions réelles
(connexion navigateur comme l'utilisatrice, claim réel, 409 réel) avant toute écriture de
test ou de correctif.

Non déployé.

---

## D-118 — Suivi des encodages : `EncodingState` dérivé et ventilation pré-VALIDATED partagée (2026-09-12)

**Statut : backend fait, non commité côté frontend, non déployé.**

Date : 2026-09-12

### Contexte

La page « Statistiques financières » (D-077) était détournée de son rôle : faute d'un
écran de suivi opérationnel, les managers l'utilisaient pour savoir si les instrumentistes
avaient encodé leurs missions. Elle ne peut structurellement pas répondre à cette
question.

Constat reproduit à l'audit : une période pouvait afficher « 104 missions / 11 exécutées /
0 validées » puis « Aucune donnée financière sur cette période ». Les deux blocs viennent
de sources disjointes :

- l'activité compte directement sur `mission` / `mission_execution` ;
- les montants n'existent qu'à travers un `FinancialCalculation`, or
  `FinancialCalculationService::calculate()` exige `MissionStatus::VALIDATED` et n'est
  **jamais** déclenché automatiquement.

Avec zéro mission validée sur la période, il est donc **impossible** d'avoir la moindre
donnée financière — même si les 11 missions exécutées sont intégralement encodées. Le
message « Aucune donnée » était exact mais n'expliquait rien.

Le pipeline financier existant (D-077 §17, neuf compteurs) ne comble pas ce vide : il
décrit uniquement l'**après**-VALIDATED (validé sans calcul, calculé sans document, etc.)
et n'a aucune visibilité sur ce qui empêche une mission d'atteindre VALIDATED.

### Décision 1 — `EncodingState` est dérivé, jamais persisté, jamais un `MissionStatus`

`MissionStatus` mélange trois axes distincts :

- cycle de vie de l'affectation : `DRAFT`, `OPEN`, `ASSIGNED`, `REJECTED`, `CANCELLED` ;
- fenêtre horaire écoulée : `IN_PROGRESS` (transition automatique, D-064) ;
- cycle d'encodage : `ENCODING_IN_PROGRESS`, `SUBMITTED`, `VALIDATED`, `CLOSED` (D-070).

Ajouter des statuts pour le suivi aurait aggravé ce mélange. `EncodingState` est donc une
projection calculée à la lecture, définie **une seule fois** dans
`App\Service\EncodingStateResolver`.

| État | Règle |
|---|---|
| `NOT_APPLICABLE` | `DRAFT`, `OPEN`, `REJECTED`, `CANCELLED` — aucun encodage attendu |
| `LOCKED` | `invoiceGeneratedAt != null` **ou** statut `CLOSED` |
| `VALIDATED` | statut `VALIDATED` |
| `SUBMITTED` | statut `SUBMITTED` |
| `IN_PROGRESS` | statut `ENCODING_IN_PROGRESS` **ou** trace d'encodage existante |
| `TO_ENCODE` | mission terminée (`endAt <= now`), aucune trace |
| `UPCOMING` | mission non terminée, aucune trace |

Précédence stricte, dans cet ordre. Trois points méritent justification :

**`NOT_APPLICABLE` prime sur tout.** Une mission annulée après un début d'encodage ne doit
jamais rester comptée comme un retard : sans cette priorité, elle polluerait le KPI « à
encoder » indéfiniment.

**`VALIDATED` reste distinct de `LOCKED` alors que `validate()` pose systématiquement
`encodingLockedAt`.** Tester `encodingLockedAt` ferait littéralement disparaître l'état
`VALIDATED`. La distinction utile est ailleurs : une mission `VALIDATED` reste réouvrable
(`reopen()`), une mission facturée ou `CLOSED` ne l'est pas. `LOCKED` désigne donc le
verrouillage réellement irréversible, pas la validation manager.

**Une « trace d'encodage » ne se réduit pas à `encodingStartedAt`.** `start()` est optionnel
(D-070) : l'instrumentiste peut saisir interventions, matériel et heures puis soumettre
directement depuis `ASSIGNED`. La trace est donc `encodingStartedAt != null` **ou**
au moins une intervention **ou** au moins une ligne de matériel active **ou** des données
d'exécution réelles. Sans cela, une mission largement encodée s'afficherait « à encoder ».

### Décision 2 — la dérivation vit en PHP, jamais en `CASE` SQL

Le résumé de période porte sur toute la population, pas sur la page affichée. La tentation
était de le calculer en SQL. Refusé : cela aurait dupliqué la règle métier en deux
implémentations condamnées à diverger.

`App\Dto\EncodingStateFacts` est le point de rencontre : les deux chemins d'alimentation
(liste hydratée par Doctrine, résumé lu en SQL plat) construisent les mêmes faits et
appellent le même `resolve()`. Le coût assumé est une boucle PHP sur des lignes plates de
huit colonnes scalaires — jamais une hydratation d'entités, conformément à D-077 §22.

`$now` est **toujours** passé explicitement et calculé une seule fois par réponse : sinon
une mission qui se termine pendant le parcours basculerait de `UPCOMING` à `TO_ENCODE` en
cours de route, et le résumé ne totaliserait plus la liste.

### Décision 3 — heures : `plannedMinutes` + `effectiveMinutes` + `effectiveSource`

`MissionExecutionService::resolveEffectiveDuration()` (D-071) reste la source canonique
unique. Le suivi expose côte à côte la durée planifiée, la durée effective, et la source
qui dit laquelle a servi (`PLANNED` / `ACTUAL_TIMES` / `ACTUAL_EXPLICIT`).

Le contrat **n'expose délibérément aucun champ `encodedMinutes`** :
`resolveEffectiveDuration()` peut retomber sur le planifié, et nommer ce résultat
« encodé » laisserait croire à une saisie qui n'a jamais eu lieu.

**Écart documenté avec la spécification fonctionnelle.** « Planning Instrumentiste v1.0 »
§5.4 énonce : *mission non SUBMITTED → heures planifiées ; mission SUBMITTED → heures
encodées*. Cette règle **n'est implémentée nulle part** dans le code : le seul résolveur
existant se fonde sur la présence de données réelles dans `MissionExecution`, jamais sur
`Mission.status`. L'écart est conservé tel quel et **non corrigé silencieusement** dans ce
chantier, pour deux raisons :

1. `SUBMITTED` répond à « l'instrumentiste déclare-t-il avoir fini ? » ; la source
   temporelle répond à « dispose-t-on de données réelles ? ». Ce sont deux axes
   indépendants, et masquer des heures réelles au motif que la mission n'est pas encore
   soumise cacherait une donnée qui existe.
2. La règle §5.4 est spécifiée dans le contexte du résumé mensuel **personnel de
   l'instrumentiste**, pas d'un agrégat manager.

`resolveEffectiveDuration()` est donc confirmé comme règle canonique de SurgicalHub. La
spécification devra être mise à jour ou explicitement restreinte à son écran d'origine.

### Décision 4 — une seule ventilation pré-VALIDATED, deux consommateurs

`App\Dto\EncodingTrackingSummary` est la source unique de la ventilation par état. Elle
alimente :

- le cockpit `GET /api/billing/encoding-tracking` (KPI du haut de page) ;
- la page Statistiques financières, pour expliquer une période sans donnée financière
  (« 11 missions exécutées, mais aucune n'est actuellement éligible : 6 à encoder, 2 en
  cours, 3 soumises non validées ») au lieu d'afficher « Aucune donnée ».

Les deux ventilations ne se recouvrent jamais : celle-ci décrit l'**avant**-VALIDATED,
les neuf compteurs de `FinancialPipelineDto` décrivent l'**après**. Elles se lisent bout à
bout. La sémantique des neuf compteurs existants est inchangée.

La fenêtre de période réutilise **strictement** `COALESCE(me.actual_start_at, m.start_at)`,
identique à `FinancialStatisticsQueryService::activityRow()`. Sans cette identité, la
ventilation explicative ne totaliserait pas le nombre de missions affiché juste au-dessus,
et le message serait incohérent.

`encodingExpected` (total moins `NOT_APPLICABLE`) est le seul dénominateur honnête d'un
taux d'encodage : une mission annulée n'est pas un encodage manquant.

### Décision 5 — statut financier synthétique, sans rejouer le moteur

Sur ce cockpit, le financier est secondaire : repérer un blocage, jamais analyser des
montants. `EncodingFinancialState` n'expose aucun montant et ne résout aucun tarif.

Les définitions réutilisent telles quelles celles de D-077 (calcul actif =
`CALCULATED`/`APPROVED`/`LOCKED` ; document émis = `SENT`/`PAID`, jamais `GENERATED`) pour
que les deux écrans ne puissent pas se contredire. Le statut `PAID` du document fait foi
plutôt que de dupliquer `DocumentPaymentService::computeBalance()`.

**Les anomalies ne sont pas un état persisté.** `FinancialCalculationService` les lève en
exception et les trace via un `AuditEvent FINANCIAL_CALCULATION_FAILED`. Le suivi lit donc
cet historique — jamais relancer le moteur de tarification depuis un endpoint de lecture.
Une tentative échouée est considérée résolue dès qu'un calcul actif plus récent existe.
`ANOMALY` prime sur `CALCULATED` car un `recalculate()` échoué laisse l'ancien calcul actif
(D-073) : afficher « Calculé » masquerait l'échec.

### Décision 6 — `MissionPopulationClauseBuilder` extrait et partagé

`missionPopulationClause()` était privé dans `FinancialStatisticsQueryService`. Ce que
« filtré par firme » signifie (la firme principale d'au moins une intervention, pas une
colonne de `mission`) est une règle métier, pas un utilitaire SQL. Elle est extraite dans
`App\Service\MissionPopulationClauseBuilder`, utilisée par les deux modules.

`missionType`, propre au suivi des encodages, n'est **pas** ajouté à
`FinancialStatisticsFilter` : ce serait modifier le contrat gelé de D-077 pour un besoin
qui ne le concerne pas. Il est passé séparément aux méthodes du repository.

### Endpoints

- `GET /api/billing/encoding-tracking` — résumé de la période + page de missions.
  Les deux dans une seule réponse : le cockpit les affiche toujours ensemble, et deux
  endpoints laisseraient les KPI se désynchroniser de la liste entre deux rafraîchissements.
- `GET /api/billing/encoding-tracking/summary` — ventilation seule, pour la page
  Statistiques.

Filtres : `from` (inclusif), `to` (exclusif), `siteId`, `surgeonId`, `instrumentistId`,
`firmId`, `interventionTypeId`, `missionType`, `encodingState` (liste séparée par des
virgules), `page`, `limit` (max 200). Convention de période et sentinels hérités de D-077 —
jamais `now()` comme borne fonctionnelle.

`BillingVoter::MANAGE` sur les deux routes. Aucun accès instrumentiste à ces agrégats.
Consultation non auditée, cohérent avec D-077 §28.

### Confidentialité

Aucune donnée patient n'est exposée : ni nom, ni identifiant, ni motif d'intervention.
Seuls l'horaire, les intervenants professionnels, le site et des compteurs transitent. Un
test fonctionnel vérifie explicitement l'absence de ces champs dans le payload brut.

### Performance

Budget de requêtes constant, indépendant du nombre de missions :

- résumé : 1 requête de faits + 1 requête d'anomalies ;
- liste : 1 comptage + 1 requête d'ids + 1 hydratation + 1 faits + 1 financier.

Les compteurs d'interventions et de matériel viennent de sous-requêtes corrélées
(`idx_intervention_mission`, `idx_material_line_mission`), jamais d'un parcours de
collections Doctrine. L'hydratation est bornée à la page et joint l'exécution, sans quoi
`resolveEffectiveDuration()` déclencherait un lazy-load par mission.

Un test fonctionnel compare le nombre réel de requêtes SQL à 3 puis 12 missions et échoue
si le budget augmente.

### Migration

**Aucune.** Tous les états sont dérivés de données déjà présentes (`Mission.status`,
`encodingStartedAt`, `invoiceGeneratedAt`, `MissionExecution`, `mission_intervention`,
`material_line`, `financial_calculation`, `audit_event`). Aucun champ ajouté, aucun index
nécessaire — les index requis existent déjà.

### Limite assumée

Le filtre `encodingState` s'applique **après** dérivation, donc après pagination :
l'état n'existe pas en base et ne peut pas être poussé en SQL. Sur une page filtrée par
état, `total` reflète la population avant filtrage. C'est acceptable dans l'usage visé —
le cockpit filtre d'abord par période et par personne, et le filtre d'état sert à isoler
une vue courte (« à traiter »), pas à paginer un mois entier. À revoir si l'usage réel
contredit cette hypothèse.

### Addendum — `encoding.isStale` (intégration frontend, 2026-09-12)

Gap découvert en construisant la vue "À traiter" : `EncodingTrackingSummary` sait compter
`staleInProgress` (IN_PROGRESS + mission déjà terminée) pour la période entière, mais
`EncodingTrackingItem` n'exposait pas cette information par mission. Sans elle, la vue
"À traiter" aurait dû comparer `endAt` à "maintenant" elle-même côté frontend — exactement
la règle métier que ce chantier interdit de dupliquer.

Ajout minimal : `EncodingTrackingItem::$isStale`, calculé dans
`EncodingTrackingService::list()` avec la même condition que `summarize()`
(`state === IN_PROGRESS && endAt !== null && endAt <= $now`), exposé sous
`encoding.isStale`. Aucun changement de la définition de `EncodingState` ni du contrat de
`summary` — pur ajout additif au niveau de l'item.
