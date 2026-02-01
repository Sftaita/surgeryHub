Decisions d’Architecture — SurgicalHub Backend

Ce document trace les décisions structurantes prises pour le backend SurgicalHub afin d’assurer cohérence,
maintenabilité et traçabilité.

---

D-001 — Séparation Mission vs Encodage

Date : 18-01-2026

Décision :
Le détail d’encodage opératoire (interventions, firms, matériel) n’est pas inclus dans GET /missions/{id}.
Un endpoint dédié est créé : GET /missions/{id}/encoding.

Motivation :

- Éviter l’alourdissement du payload mission standard.
- Séparer clairement le planning (mission) de l’exécution opératoire (encodage).
- Faciliter l’évolution du modèle d’encodage sans impacter les écrans mission.

Conséquences :

- Deux appels côté frontend pour l’écran instrumentiste : mission + encoding.
- Mapping dédié via MissionEncodingService et DTOs spécifiques.

---

D-002 — Option B : Encodage libre par interventions

Date : 18-01-2026

Décision :

- Une mission peut contenir plusieurs interventions.
- L’instrumentiste crée librement les interventions réellement effectuées.

Exemple métier :
Mission 13h–18h

- 2 × LCA
- 1 × PTG

Chaque intervention possède ses propres firms et lignes de matériel.

Conséquences :

- Pas de typologie d’intervention imposée par la mission.
- Traçabilité fidèle à la réalité opératoire.

---

D-003 — Hiérarchie Encodage

Date : 18-01-2026

Structure retenue :

Mission
└─ MissionIntervention
└─ MissionInterventionFirm
├─ MaterialLine
└─ MaterialItemRequest

Règles :

- MaterialLine = matériel existant dans le catalogue.
- MaterialItemRequest = matériel manquant ou inconnu.
- Une ligne peut être liée à une intervention et/ou une firme.

---

D-004 — Matériel implantable

Date : 18-01-2026

Décision :
Les items implantables (MaterialItem.isImplant = true) déclenchent la création ou l’association
à une ImplantSubMission.

Groupement par firme si possible.

Motivation :

- Préparer les exports et la facturation ultérieure.
- Isoler la logique implant sans polluer l’encodage standard.

---

D-005 — RBAC strict via Voters

Date : 17-01-2026

Décision :
Toute logique d’autorisation passe par des Voters.
Aucun contrôle de rôle direct dans les controllers.

Points clés :

- EDIT_ENCODING : instrumentiste assigné ou manager/admin.
- CLAIM : instrumentiste éligible (POOL/TARGETED + règles employment).
- Données financières visibles uniquement pour managers/admins.

---

D-006 — Missions de type CONSULTATION

Date : 18-01-2026

Décision :
Les missions CONSULTATION ne peuvent pas contenir de MaterialLine.

Implémentation :

- Vérification dans InterventionService.
- Rejet via 400 BadRequest.

---

D-007 — Erreurs API normalisées

Date : 16-01-2026

Décision :
Toutes les erreurs passent par ApiExceptionSubscriber.

Format :

{
"error": {
"status": 422,
"code": "VALIDATION_FAILED",
"message": "...",
"violations": []
}
}

Avantage :

- Frontend prévisible.
- Logs exploitables en production.

---

D-008 — Documentation vivante en Markdown

Date : 18-01-2026

Décision :
Trois fichiers de référence maintenus à jour :

- api.md
- decision.md
- architecture.md

Objectif :

- Éviter la perte de contexte entre conversations.
- Servir de source de vérité pour frontend et backend.

---

Historique

18-01-2026 : Création initiale du document.
