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
| 16-07-2026 | D-067 — Catalogue financier des firmes : prestations non liantes, moteur indépendant (Lot 1) |
