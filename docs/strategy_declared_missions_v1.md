# Plan de changement Backend

## Lot B1 — Modèle & migrations (zéro logique métier)

### Enum MissionStatus

Ajouter DECLARED, REJECTED.

### Entity Mission

Ajouter declaredAt (nullable, datetime_immutable)

Ajouter declaredComment (nullable, string/text)

(optionnel mais utile) declaredBy ou réutiliser createdBy si déjà fiable.
Règle : pour DECLARED, createdBy == instrumentist.

### Migration Doctrine

Colonnes + index éventuel sur (status, declaredAt) pour listing manager.

✅ Critère de fin : migrations passent, aucune régression sur existant.

## Lot B2 — RBAC + transitions (cœur sécurité)

### MissionVoter

Ajouter attributs :

- DECLARE
- APPROVE_DECLARED
- REJECT_DECLARED

Règles minimales :

- DECLARE : rôle INSTRUMENTIST + éligibilité site
- APPROVE/REJECT : MANAGER/ADMIN + site rights

### MissionActionsService

Étendre le calcul allowedActions[] quand status=DECLARED :

- Instrumentiste owner : view, encoding, submit, edit_hours
- Manager/Admin : approve, reject, edit, view
- Surgeon : view

✅ Critère de fin : un GET /api/missions / GET /api/missions/{id} renvoie des actions cohérentes selon rôle.

## Lot B3 — Endpoints dédiés (contrat API stable)

### Controller / DTO

POST /api/missions/declare

DTO request : siteId, surgeonId, type, startAt, endAt, comment

POST /api/missions/{id}/approve-declared

POST /api/missions/{id}/reject-declared

### MissionService

declareMission(...) : crée mission DECLARED, set instrumentist, createdBy, declaredAt/comment, schedulePrecision (EXACT si start/end, sinon APPROX), status.

approveDeclared(mission) : DECLARED → ASSIGNED

rejectDeclared(mission) : DECLARED → REJECTED (terminal)

### Règles de garde

Interdire :

- publish si DECLARED
- claim si DECLARED
- validate/close si DECLARED ou REJECTED

Encodage :

- autoriser GET /encoding sur DECLARED
- autoriser mutations encodage sur DECLARED
- interdire mutations encodage sur REJECTED

✅ Critère de fin : tests manuels Postman sur les 3 endpoints + erreurs 400/403 cohérentes.

## Lot B4 — Notifications + audit (robustesse)

### Audit events

- MISSION_DECLARED
- MISSION_DECLARED_APPROVED
- MISSION_DECLARED_REJECTED

### Notifications

- À la déclaration : manager/admin (site)
- À approve/reject : instrumentiste

✅ Critère de fin : traces d’audit et notifications enregistrées/émises.

## Lot B5 — Listing / UX manager (qualité produit)

### Filtres listing

Ajouter filtre status=DECLARED pour vues manager

Trier par declaredAt desc en priorité dans une vue “à traiter” (optionnel)

### Garde-fous anti-abus (optionnel maintenant, mais facile)

métriques/compteurs côté admin (pas forcément UI tout de suite)

# Plan de changement Frontend (PWA Instrumentiste + Manager)

## Lot F1 — Contrat allowedActions[] (pas de logique métier côté UI)

### Types

Ajouter MissionStatus = 'DECLARED' | 'REJECTED'

Étendre le type AllowedAction :

- approve, reject
- (instrumentiste) declare, edit_hours si tu utilises des actions UI

### UI gating

Tout affichage de bouton dépend uniquement de allowedActions.includes(...).

✅ Critère : aucun écran ne “déduit” les droits.

## Lot F2 — Instrumentiste : “Déclarer mission imprévue”

### Entry point

Bouton global dans “Mes missions” : “Déclarer une mission”

### Flow minimal (mobile-first)

Écran formulaire :

- Site (select)
- Chirurgien (select/search)
- Type (BLOCK/CONSULTATION)
- Start/End (datetime)
- Comment (obligatoire)

Submit → POST /api/missions/declare

Redirection vers écran mission/encoding

### UX

Message clair : “Mission en attente de validation du manager”

Badge DECLARED

✅ Critère : instrumentiste peut déclarer et encoder immédiatement.

## Lot F3 — Écrans mission/encodage : gérer DECLARED et REJECTED

### Mission detail

Afficher status badge

Si REJECTED :

- tout en lecture seule
- afficher message “Mission rejetée par le manager”

### Encodage

Autoriser les actions existantes si backend permet

Désactiver tout si allowedActions ne contient pas l’action

✅ Critère : pas de crash, pas d’incohérence.

## Lot F4 — Manager : traitement des missions DECLARED

### Liste manager

Ajouter onglet/filtre “À valider” = status DECLARED

### Détail mission DECLARED

Boutons visibles si allowedActions contient :

- approve → call /approve-declared
- reject → call /reject-declared + modal commentaire (optionnel)

### Post-action

Approve : mission passe ASSIGNED, UI se met à jour

Reject : mission passe REJECTED, UI se met à jour

✅ Critère : manager peut traiter 100% du flux sans workaround.

## Lot F5 — Heures (si pas déjà propre)

### Bloc “Prestation / heures”

Si allowedActions contient edit_hours :

- permettre édition

Sinon lecture seule

Toujours passer par endpoint service existant (PATCH /api/missions/{id}/service) (déjà dans spec)

✅ Critère : cohérence freelance/horaire + audit.

# Stratégie de déploiement & tests (pratique)

## Tests backend minimaux (Postman)

Instrumentiste :

- declare OK
- claim sur DECLARED → 403
- publish sur DECLARED → 403

Manager :

- approve OK
- reject OK
- approve une mission non DECLARED → 400

Encodage :

- CRUD interventions/material-lines sur DECLARED OK
- sur REJECTED → 403

## Tests frontend

Instrumentiste :

- bouton “déclarer” visible
- status/badge correct
- encoding accessible

Manager :

- onglet “à valider”
- approve/reject mettent à jour la liste
