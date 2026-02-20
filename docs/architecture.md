# Architecture — SurgicalHub Backend (Symfony)

Dernière mise à jour : 2026-02-20 (Europe/Brussels)

## 1. Contexte et objectifs

SurgicalHub est une API Symfony orientée “missions” permettant :

- aux managers/admins de créer et publier des missions,
- aux instrumentistes de consulter des offres éligibles, claim, encoder (interventions + matériel), puis submit,
- aux instrumentistes de déclarer des missions imprévues, soumises à validation manager,
- aux chirurgiens d’évaluer l’instrumentiste et de gérer des litiges d’heures,
- à l’équipe support (manager/admin) d’avoir une visibilité élargie (vues \*:read_manager), sans exposer de données financières aux rôles non autorisés.

Contraintes clés :

- Aucune donnée patient.
- RBAC strict via Voters.
- Gouvernance manager-centric.
- Flux mobile-first instrumentiste.
- Traçabilité complète des actions critiques.

## 2. Architecture du code (dossiers)

Racine : backend/src

- Controller/
- Dto/Request/
- Entity/
- Enum/
- Security/Voter/
- Service/
- EventSubscriber/ApiExceptionSubscriber.php

Règles :

- Contrôleurs minces.
- Logique métier exclusivement dans Service/\*.
- Aucun contrôle de rôle direct dans les controllers.
- Aucune inférence de droit côté frontend.

## 3. Sécurité & Auth

Inchangé :

- JWT obligatoire.
- Login classique + refresh + Google.
- /healthz public.

## 4. Modèle métier — Missions & cycle de vie

### 4.1 Mission

Une Mission représente un créneau d’activité (planifié ou déclaré).

Statuts

- DRAFT
- OPEN
- ASSIGNED
- DECLARED (nouveau)
- REJECTED (nouveau – uniquement pour DECLARED)
- SUBMITTED
- VALIDATED
- CLOSED

### 4.2 Flux planning classique

DRAFT → OPEN → ASSIGNED → SUBMITTED → VALIDATED → CLOSED

### 4.3 Flux mission déclarée (nouveau)

Création :

INSTRUMENTIST → DECLARED

Transitions autorisées :

DECLARED → ASSIGNED (approve par manager)
DECLARED → REJECTED (reject par manager)

Contraintes :

- Une mission DECLARED n’est pas publiée.
- Elle n’est pas claimable.
- Elle n’est pas facturable.
- Elle ne peut pas être VALIDATED.
- Elle ne peut pas être CLOSED.
- REJECTED est terminal.

### 4.4 Champs supplémentaires Mission

- declaredAt (nullable)
- declaredComment (nullable, obligatoire si DECLARED)

Règle :

Si status = DECLARED :

- createdBy = instrumentist
- instrumentist_user_id = createdBy

## 5. Publications (offres)

Inchangé pour OPEN.

Important :

Les missions DECLARED ne génèrent jamais de MissionPublication.

## 6. Claim (anti-double)

Inchangé.

Interdiction :

Claim impossible si status = DECLARED.

## 7. Encodage opératoire

Structure inchangée :

- MissionIntervention
- MaterialLine
- MaterialItemRequest

Règles supplémentaires :

- Encodage autorisé sur DECLARED.
- Lock financier interdit tant que mission non approuvée.
- Invoice génération impossible si mission issue de DECLARED non validée.

## 8. Endpoints (mis à jour)

Missions

- POST /api/missions
- PATCH /api/missions/{id}
- POST /api/missions/{id}/publish
- POST /api/missions/{id}/claim
- POST /api/missions/{id}/submit
- GET /api/missions
- GET /api/missions/{id}

🆕 Missions déclarées

- POST /api/missions/declare
- POST /api/missions/{id}/approve-declared
- POST /api/missions/{id}/reject-declared

Encodage

Inchangé.

## 9. Services applicatifs (responsabilités mises à jour)

MissionService

Responsabilités étendues :

- create
- patch
- publish
- claim
- submit
- declare
- approveDeclared
- rejectDeclared

Règles :

- declare : force status DECLARED
- approve : transforme en ASSIGNED
- reject : transforme en REJECTED
- Toutes les transitions passent par MissionService.

MissionActionsService

Doit intégrer :

Si status = DECLARED :

Instrumentiste (owner) :

- view
- encoding
- submit
- edit_hours

Manager/Admin :

- approve
- reject
- edit

Surgeon :

- view

Aucun droit implicite.

MissionVoter (mis à jour)

Nouvelles capacités :

- DECLARE
- APPROVE_DECLARED
- REJECT_DECLARED

Toujours via Voters exclusivement.

## 10. Audit & Events

Nouveaux événements :

- MISSION_DECLARED
- MISSION_DECLARED_APPROVED
- MISSION_DECLARED_REJECTED

Aucune suppression autorisée.

Toutes transitions loggées.

## 11. Notifications

Ajouts :

- Déclaration → manager
- Approbation → instrumentiste
- Rejet → instrumentiste

Log complet des notifications.

## 12. Sécurité anti-abus

Architecture impose :

- Historique complet.
- Rejection ratio traçable.
- Aucun impact financier sans validation.
- Impossible de convertir DECLARED en OPEN.

## 13. Points d’attention techniques

### 13.1 Enum MissionStatus

Ajouter :

DECLARED
REJECTED

Migration obligatoire.

### 13.2 Transitions contrôlées

Aucune modification directe de status via patch générique.

Toutes transitions passent par MissionService.

### 13.3 Cohérence multi-site

Lors de declare :

Vérifier éligibilité instrumentiste au site
(EMPLOYEE membership ou FREELANCER autorisé).

### 13.4 Encodage & finance

Interdire :

- génération d’ImplantSubMission facturable
- calcul final service financier
- invoice generation

tant que mission issue de DECLARED non validée.

## Résumé architectural

Avec D-013 intégré :

- Planning reste manager-centric.
- Réalité terrain intégrée.
- Aucun pouvoir excessif côté instrumentiste.
- Aucun impact financier non validé.
- RBAC respecté.
- Frontend simplifié via allowedActions[].
