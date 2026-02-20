# Décisions d’architecture — SurgicalHub Backend

Ce document trace les décisions d’architecture structurantes prises pour le backend SurgicalHub afin d’assurer :

- cohérence métier,
- maintenabilité du code,
- traçabilité des choix techniques,
- alignement frontend ↔ backend.

## D-001 — Séparation mission vs encodage

Date : 18-01-2026

### Décision

Le détail d’encodage opératoire (interventions, firms, matériel) n’est pas inclus dans
GET /api/missions/{id}.

Un endpoint dédié est créé :

GET /api/missions/{id}/encoding

### Motivation

Éviter l’alourdissement du payload mission standard.

Séparer clairement :

- le planning (mission),
- de l’exécution opératoire (encodage).

Permettre l’évolution du modèle d’encodage sans impacter :

- les listings,
- les écrans manager,
- le frontend instrumentiste Lot 3.

### Conséquences

Deux appels frontend :

- mission (planning + allowedActions),
- encoding (interventions / matériel).

Mapping dédié via MissionEncodingService.

DTOs spécifiques et stables pour l’UI mobile.

## D-002 — Option B : encodage libre par interventions

Date : 18-01-2026

### Décision

Une mission peut contenir plusieurs interventions, créées librement par l’instrumentiste.

Aucune typologie d’intervention n’est imposée par la mission.

### Motivation

Fidélité maximale à la réalité opératoire.

Pas de rigidité côté backend.

UI encodage simple et progressive.

## D-003 — Hiérarchie d’encodage

Date : 18-01-2026

### Structure retenue

```text
Mission
└─ MissionIntervention
├─ MaterialLine
└─ MaterialItemRequest
```

### Règles métier

MaterialLine

- matériel existant dans le catalogue,
- réellement utilisé.

MaterialItemRequest

- matériel absent / inconnu,
- signalement à destination du manager.

## D-004 — Gestion du matériel implantable

Date : 18-01-2026

### Décision

Les items implantables (MaterialItem.isImplant = true) déclenchent automatiquement :

- la création,
- ou l’association à une ImplantSubMission.

### Motivation

Préparer les futures étapes :

- reporting,
- validation,
- facturation.

## D-005 — RBAC strict via Voters

Date : 17-01-2026

### Décision

Toute logique d’autorisation passe exclusivement par des Voters.

Aucun contrôle de rôle direct dans les controllers.

Aucun droit inféré côté frontend.

## D-006 — allowedActions[] comme contrat frontend

Date : 20-01-2026

### Décision

Le backend calcule dynamiquement un tableau allowedActions[] pour chaque mission.

Le frontend :

- n’infère jamais un droit,
- n’anticipe jamais un statut,
- affiche uniquement ce qui est explicitement autorisé.

## D-007 — Missions de type CONSULTATION

Date : 18-01-2026

### Décision

Les missions de type CONSULTATION ne peuvent pas contenir de matériel.

## D-008 — Garde-fou temporel sur l’encodage

Date : 20-01-2026

### Décision

Un instrumentiste ne peut pas encoder avant le début réel de la mission.

## D-009 — Catalogue matériel en lecture libre

Date : 31-01-2026

### Décision

Le catalogue MaterialItem est accessible en lecture à tous les rôles.

## D-010 — Erreurs API normalisées

Date : 16-01-2026

### Décision

Toutes les erreurs API passent par ApiExceptionSubscriber.

## D-011 — Documentation vivante en Markdown

Date : 18-01-2026

### Décision

Trois documents de référence maintenus à jour :

- docs/api.md
- docs/architecture.md
- docs/decisions.md

## D-012 — Firms en référentiel (fabricants) + dérivation via MaterialItem

Date : 12-02-2026

### Décision

Firm devient une entité de référence.

L’instrumentiste ne peut jamais créer/éditer/supprimer une firm.

Les firms apparaissent uniquement via MaterialItem.manufacturer.

## 🆕 D-013 — Missions déclarées par instrumentiste (unforeseen activity control)

Date : 20-02-2026

### Décision

Un instrumentiste peut déclarer une mission imprévue via un flux contrôlé.

Cette mission est créée avec le statut :

DECLARED

Elle doit obligatoirement être validée ou rejetée par un Manager/Admin.

Les chirurgiens ne peuvent jamais créer de mission.

### Motivation

Refléter la réalité terrain (urgences, dépassements bloc).

Permettre l’encodage sans briser la cohérence planning.

Maintenir un contrôle manager-centric du système.

Éviter la création sauvage de missions validées automatiquement.

Préserver la robustesse juridique et financière.

### Règles métier

Une mission DECLARED :

- est créée uniquement par un INSTRUMENTIST,
- lie automatiquement instrumentist_user_id = created_by_user_id,
- n’est pas publiée,
- n’est pas claimable,
- n’est pas facturable,
- ne peut pas être VALIDATED,
- ne peut pas être CLOSED.

Transitions autorisées :

DECLARED → ASSIGNED (approve)
DECLARED → REJECTED

REJECTED est un statut terminal.

### Gouvernance

Seul un MANAGER/ADMIN peut :

- approuver
- rejeter

Le chirurgien a uniquement un droit de consultation.

Aucune suppression autorisée.

Audit obligatoire.

### allowedActions impact

Si mission.status = DECLARED :

Instrumentiste (owner) :

- view
- encoding
- submit (draft)
- edit_hours

Manager/Admin :

- approve
- reject
- edit

Surgeon :

- view uniquement

### Sécurité & anti-abus

Historique complet conservé.

Rejection ratio mesurable.

Aucun impact financier sans validation.

Impossible de convertir une mission DECLARED en OPEN.

### Impact technique

Ajout enum MissionStatus::DECLARED

Ajout enum MissionStatus::REJECTED

Nouvelles capacités Voter :

- DECLARE
- APPROVE_DECLARED
- REJECT_DECLARED

Extension MissionActionsService

Nouveaux endpoints dédiés

Nouveaux événements d’audit :

- MISSION_DECLARED
- MISSION_DECLARED_APPROVED
- MISSION_DECLARED_REJECTED

## Historique mis à jour

20-02-2026 : D-013 — introduction missions DECLARED instrumentiste.

Fin du document
