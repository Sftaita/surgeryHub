# Décisions d’architecture — SurgicalHub Backend

Ce document trace les **décisions d’architecture structurantes** prises pour le backend **SurgicalHub** afin d’assurer :

- cohérence métier,
- maintenabilité du code,
- traçabilité des choix techniques,
- alignement frontend ↔ backend.

---

## D-001 — Séparation mission vs encodage

**Date :** 18-01-2026

### Décision

Le détail d’encodage opératoire (interventions, firms, matériel) **n’est pas inclus** dans
`GET /api/missions/{id}`.

Un endpoint dédié est créé :

`GET /api/missions/{id}/encoding`

### Motivation

- Éviter l’alourdissement du payload mission standard.
- Séparer clairement :
  - le **planning** (mission),
  - de l’**exécution opératoire** (encodage).
- Permettre l’évolution du modèle d’encodage sans impacter :
  - les listings,
  - les écrans manager,
  - le frontend instrumentiste Lot 3.

### Conséquences

- Deux appels frontend :
  - mission (planning + allowedActions),
  - encoding (interventions / matériel).
- Mapping dédié via `MissionEncodingService`.
- DTOs spécifiques et stables pour l’UI mobile.

---

## D-002 — Option B : encodage libre par interventions

**Date :** 18-01-2026

### Décision

Une mission peut contenir **plusieurs interventions**, créées librement par l’instrumentiste.

Aucune typologie d’intervention n’est imposée par la mission.

### Exemple métier

Mission 13h–18h :

- 2 × LCA
- 1 × PTG

Chaque intervention possède :

- ses firms,
- ses lignes de matériel,
- ses demandes de matériel manquant.

### Conséquences

- Fidélité maximale à la réalité opératoire.
- Pas de rigidité côté backend.
- UI encodage simple et progressive.

---

## D-003 — Hiérarchie d’encodage

**Date :** 18-01-2026

### Structure retenue

```
Mission
└─ MissionIntervention
   ├─ MissionInterventionFirm
   ├─ MaterialLine
   └─ MaterialItemRequest
```

### Règles métier

- **MaterialLine**
  - matériel existant dans le catalogue,
  - réellement utilisé.
- **MaterialItemRequest**
  - matériel absent / inconnu,
  - signalement à destination du manager.

Une ligne peut être liée :

- à une intervention,
- à une firm,
- ou aux deux.

---

## D-004 — Gestion du matériel implantable

**Date :** 18-01-2026

### Décision

Les items implantables (`MaterialItem.isImplant = true`) déclenchent automatiquement :

- la création,
- ou l’association à une **ImplantSubMission**.

Le regroupement se fait par :

- `firmName` si disponible,
- sinon `manufacturer`.

### Motivation

- Préparer les futures étapes :
  - reporting,
  - validation,
  - facturation.
- Isoler la logique implant sans polluer l’encodage standard.

### Conséquences

- Implémentation centralisée dans `InterventionService`.
- Aucune logique implant côté frontend.

---

## D-005 — RBAC strict via Voters

**Date :** 17-01-2026

### Décision

Toute logique d’autorisation passe **exclusivement par des Voters**.

❌ Aucun contrôle de rôle direct dans les controllers.  
❌ Aucun droit inféré côté frontend.

### Points clés

- `EDIT_ENCODING`
  - instrumentiste assigné,
  - manager / admin.
- `CLAIM`
  - instrumentiste éligible,
  - règles POOL / TARGETED,
  - règles EMPLOYEE / FREELANCER.
- Données financières :
  - **jamais exposées** hors manager / admin.

---

## D-006 — allowedActions[] comme contrat frontend

**Date :** 20-01-2026

### Décision

Le backend calcule dynamiquement un tableau `allowedActions[]` pour chaque mission.

Le frontend :

- n’infère **jamais** un droit,
- n’anticipe **jamais** un statut,
- affiche uniquement ce qui est explicitement autorisé.

### Motivation

- Sécurité renforcée.
- Simplicité frontend.
- Évolutivité métier sans refonte UI.

---

## D-007 — Missions de type CONSULTATION

**Date :** 18-01-2026

### Décision

Les missions de type `CONSULTATION` **ne peuvent pas contenir de matériel**.

### Implémentation

- Vérification dans `InterventionService`.
- Rejet immédiat :
  - `400 BadRequest`.

### Motivation

- Éviter les erreurs métier.
- Simplifier la logique de facturation future.

---

## D-008 — Garde-fou temporel sur l’encodage

**Date :** 20-01-2026

### Décision

Un instrumentiste **ne peut pas encoder avant le début réel de la mission**.

### Règles

- Manager / admin : autorisés à tout moment.
- Instrumentiste :
  - interdit avant `startAt`,
  - autorisé après début réel.

### Implémentation

- Service dédié : `MissionEncodingGuard`.
- Centralisation de la règle métier.

---

## D-009 — Catalogue matériel en lecture libre

**Date :** 31-01-2026

### Décision

Le catalogue `MaterialItem` est :

- accessible en lecture à **tous les rôles**,
- sans Voter dédié.

### Motivation

- Recherche fluide côté encodage.
- Pas de donnée sensible.
- Simplification frontend (autocomplete, quick search).

---

## D-010 — Erreurs API normalisées

**Date :** 16-01-2026

### Décision

Toutes les erreurs API passent par `ApiExceptionSubscriber`.

### Format standard

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_FAILED",
    "message": "...",
    "violations": [],
    "debug": {}
  }
}
```

### Avantages

- Frontend prévisible.
- Logs exploitables.
- Mapping clair exceptions → HTTP status.
- `UniqueConstraintViolationException` → `409 CONFLICT` (claim).

---

## D-011 — Documentation vivante en Markdown

**Date :** 18-01-2026

### Décision

Trois documents de référence maintenus à jour :

- `docs/api.md`
- `docs/architecture.md`
- `docs/decisions.md`

### Objectifs

- Éviter la perte de contexte entre lots / conversations.
- Servir de source de vérité :
  - backend,
  - frontend,
  - onboarding futur.

# Décisions d’architecture — SurgicalHub Backend

Ce document trace les **décisions d’architecture structurantes** prises pour le backend **SurgicalHub** afin d’assurer :

- cohérence métier,
- maintenabilité du code,
- traçabilité des choix techniques,
- alignement frontend ↔ backend.

---

## D-001 — Séparation mission vs encodage

**Date :** 18-01-2026

### Décision

Le détail d’encodage opératoire (interventions, firms, matériel) **n’est pas inclus** dans
`GET /api/missions/{id}`.

Un endpoint dédié est créé :

`GET /api/missions/{id}/encoding`

### Motivation

- Éviter l’alourdissement du payload mission standard.
- Séparer clairement :
  - le **planning** (mission),
  - de l’**exécution opératoire** (encodage).
- Permettre l’évolution du modèle d’encodage sans impacter :
  - les listings,
  - les écrans manager,
  - le frontend instrumentiste Lot 3.

### Conséquences

- Deux appels frontend :
  - mission (planning + allowedActions),
  - encoding (interventions / matériel).
- Mapping dédié via `MissionEncodingService`.
- DTOs spécifiques et stables pour l’UI mobile.

---

## D-002 — Option B : encodage libre par interventions

**Date :** 18-01-2026

### Décision

Une mission peut contenir **plusieurs interventions**, créées librement par l’instrumentiste.

Aucune typologie d’intervention n’est imposée par la mission.

### Exemple métier

Mission 13h–18h :

- 2 × LCA
- 1 × PTG

Chaque intervention possède :

- ses firms,
- ses lignes de matériel,
- ses demandes de matériel manquant.

### Conséquences

- Fidélité maximale à la réalité opératoire.
- Pas de rigidité côté backend.
- UI encodage simple et progressive.

---

## D-003 — Hiérarchie d’encodage

**Date :** 18-01-2026

### Structure retenue

```
Mission
└─ MissionIntervention
   ├─ MaterialLine
   └─ MaterialItemRequest
```

### Règles métier

- **MaterialLine**
  - matériel existant dans le catalogue,
  - réellement utilisé.
- **MaterialItemRequest**
  - matériel absent / inconnu,
  - signalement à destination du manager.

Une ligne peut être liée :

- à une intervention,
- à une firm,
- ou aux deux.

---

## D-004 — Gestion du matériel implantable

**Date :** 18-01-2026

### Décision

Les items implantables (`MaterialItem.isImplant = true`) déclenchent automatiquement :

- la création,
- ou l’association à une **ImplantSubMission**.

Le regroupement se fait par :

- `firmName` si disponible,
- sinon `manufacturer`.

### Motivation

- Préparer les futures étapes :
  - reporting,
  - validation,
  - facturation.
- Isoler la logique implant sans polluer l’encodage standard.

### Conséquences

- Implémentation centralisée dans `InterventionService`.
- Aucune logique implant côté frontend.

---

## D-005 — RBAC strict via Voters

**Date :** 17-01-2026

### Décision

Toute logique d’autorisation passe **exclusivement par des Voters**.

❌ Aucun contrôle de rôle direct dans les controllers.  
❌ Aucun droit inféré côté frontend.

### Points clés

- `EDIT_ENCODING`
  - instrumentiste assigné,
  - manager / admin.
- `CLAIM`
  - instrumentiste éligible,
  - règles POOL / TARGETED,
  - règles EMPLOYEE / FREELANCER.
- Données financières :
  - **jamais exposées** hors manager / admin.

---

## D-006 — allowedActions[] comme contrat frontend

**Date :** 20-01-2026

### Décision

Le backend calcule dynamiquement un tableau `allowedActions[]` pour chaque mission.

Le frontend :

- n’infère **jamais** un droit,
- n’anticipe **jamais** un statut,
- affiche uniquement ce qui est explicitement autorisé.

### Motivation

- Sécurité renforcée.
- Simplicité frontend.
- Évolutivité métier sans refonte UI.

---

## D-007 — Missions de type CONSULTATION

**Date :** 18-01-2026

### Décision

Les missions de type `CONSULTATION` **ne peuvent pas contenir de matériel**.

### Implémentation

- Vérification dans `InterventionService`.
- Rejet immédiat :
  - `400 BadRequest`.

### Motivation

- Éviter les erreurs métier.
- Simplifier la logique de facturation future.

---

## D-008 — Garde-fou temporel sur l’encodage

**Date :** 20-01-2026

### Décision

Un instrumentiste **ne peut pas encoder avant le début réel de la mission**.

### Règles

- Manager / admin : autorisés à tout moment.
- Instrumentiste :
  - interdit avant `startAt`,
  - autorisé après début réel.

### Implémentation

- Service dédié : `MissionEncodingGuard`.
- Centralisation de la règle métier.

---

## D-009 — Catalogue matériel en lecture libre

**Date :** 31-01-2026

### Décision

Le catalogue `MaterialItem` est :

- accessible en lecture à **tous les rôles**,
- sans Voter dédié.

### Motivation

- Recherche fluide côté encodage.
- Pas de donnée sensible.
- Simplification frontend (autocomplete, quick search).

---

## D-010 — Erreurs API normalisées

**Date :** 16-01-2026

### Décision

Toutes les erreurs API passent par `ApiExceptionSubscriber`.

### Format standard

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_FAILED",
    "message": "...",
    "violations": [],
    "debug": {}
  }
}
```

### Avantages

- Frontend prévisible.
- Logs exploitables.
- Mapping clair exceptions → HTTP status.
- `UniqueConstraintViolationException` → `409 CONFLICT` (claim).

---

## D-011 — Documentation vivante en Markdown

**Date :** 18-01-2026

### Décision

Trois documents de référence maintenus à jour :

- `docs/api.md`
- `docs/architecture.md`
- `docs/decisions.md`

### Objectifs

- Éviter la perte de contexte entre lots / conversations.
- Servir de source de vérité :
  - backend,
  - frontend,
  - onboarding futur.

### Historique

- 16-01-2026 : normalisation des erreurs API.
- 17-01-2026 : RBAC via Voters.
- 18-01-2026 : séparation mission / encodage, interventions libres, implants.
- 20-01-2026 : `allowedActions[]`, garde-fou temporel encodage.
- 31-01-2026 : catalogue matériel libre, Lot 3 stabilisé.

---

## D-012 — Firms en référentiel (fabricants) + dérivation via MaterialItem

**Date :** 12-02-2026

### Décision

- `Firm` devient une entité **de référence** (id, name, active).
- L’instrumentiste ne peut **jamais** créer/éditer/supprimer une firm.
- `MaterialItem.manufacturer` référence `Firm` (FK stricte).
- Les firms **n’appartiennent pas** aux interventions ; elles apparaissent **uniquement via les items consommés**.

### Conséquences

- Suppression de `MissionInterventionFirm` et des routes `/interventions/{id}/firms`.
- `MaterialLine` référence un `MaterialItem` ; la firm est dérivée via `MaterialItem.manufacturer`.
- Les DTO d’encodage exposent la firm uniquement dans l’objet `item`.

### Historique

- 16-01-2026 : normalisation des erreurs API.
- 17-01-2026 : RBAC via Voters.
- 18-01-2026 : séparation mission / encodage, interventions libres, implants.
- 20-01-2026 : `allowedActions[]`, garde-fou temporel encodage.
- 31-01-2026 : catalogue matériel libre, Lot 3 stabilisé.
