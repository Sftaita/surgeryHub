ARCHITECTURE — SurgicalHub Backend (Symfony) — Architecture & Modèle Métier

Dernière mise à jour : 2026-01-25 (Europe/Brussels)

---

1. Contexte et objectifs

SurgicalHub est une API Symfony orientée “missions” permettant :

- aux managers/admins de créer/publier des missions,
- aux instrumentistes de consulter des offres éligibles, claim, encoder
  (interventions + matériel), puis submit,
- aux chirurgiens d’évaluer l’instrumentiste et de gérer des litiges d’heures
  via services/disputes,
- à l’équipe support (manager/admin) d’avoir une visibilité élargie
  (vues \*:read_manager), sans exposer de données financières aux rôles non autorisés.

Contraintes clés :

- Aucune donnée patient (conception v2.1)
- RBAC strict via Voters
- Flux mobile-first instrumentiste : rapide, peu de friction, endpoints dédiés

---

2. Architecture du code (dossiers)

Racine :
C:\WAMP64\WWW\SURGICALHUB\BACKEND\SRC

2.1 Découpage

- Controller/ :
  Endpoints HTTP (API JSON), validation input, appels services

- Dto/Request/ :
  DTO d’entrée (validation Symfony Assert) + DTO de réponse

- Entity/ :
  Modèle Doctrine (entités + relations)

- Enum/ :
  Enums métier (MissionStatus, MissionType, PublicationScope, etc.)

- Security/Voter/ :
  Règles d’accès (MissionVoter, ServiceVoter, RatingVoter,
  ExportVoter, InstrumentistVoter)

- Service/ :
  Logique métier (orchestration, règles, mapping DTO)

- EventSubscriber/ApiExceptionSubscriber.php :
  Format d’erreur JSON homogène

  2.2 Règle d’implémentation

- Contrôleurs “minces” :
  - désérialisation + validation DTO
  - denyAccessUnlessGranted(...)
  - délégation vers un service métier
  - retour JSON via DTO / groupes

- Logique métier uniquement dans Service/\*

---

3. Modèle Métier — Mission & Offres

3.1 Mission

Une Mission représente un créneau (site + start/end + type) avec cycle de vie :

- DRAFT : créée, éditable planning
- OPEN : publiée, visible/offerte selon règles
- ASSIGNED / IN_PROGRESS : instrumentiste affecté
- SUBMITTED : encodage soumis
- VALIDATED / CLOSED : post-traitement manager (à affiner)

Champs clés :

- site (Hospital)
- startAt, endAt
- schedulePrecision (EXACT/APPROXIMATE)
- type (BLOCK / CONSULTATION / …)
- surgeon (User)
- instrumentist (User|null)
- status (MissionStatus)
- createdBy (User manager/admin)

  3.2 Publications (Offres)

Une Mission OPEN doit avoir au moins une MissionPublication :

- scope = POOL :
  visible aux instrumentistes éligibles

- scope = TARGETED :
  visible uniquement à un instrumentiste cible

- channel = IN_APP
- publishedAt

  3.3 Claim (anti-double)

Un claim :

- matérialisé par MissionClaim (1:1 unique par mission)
- met à jour la mission :
  - instrumentist = currentUser
  - status = ASSIGNED
- contrainte DB : unique sur mission_id
- verrouillage transactionnel : PESSIMISTIC_WRITE

---

4. Encoding — Interventions / Firms / Matériel

4.1 Problème métier

Une mission peut contenir plusieurs interventions
(ex. mission 13–18 : 2 LCA + 1 PTG).

L’instrumentiste doit :

1. ajouter une intervention
2. optionnel : ajouter une firm/fournisseur
3. encoder des lignes de matériel
4. si item absent du catalogue : le signaler

4.2 Entités d’encodage

MissionIntervention

- mission (ManyToOne)
- code (ex: ACL/LCA, TKA/PTG)
- label
- orderIndex
- firms (OneToMany -> MissionInterventionFirm)
- materialLines (OneToMany -> MaterialLine)

MissionInterventionFirm

- missionIntervention (ManyToOne)
- firmName
- materialLines (OneToMany -> MaterialLine)
- materialItemRequests (OneToMany -> MaterialItemRequest)

MaterialLine
Ligne réellement consommée/utilisée

- mission (ManyToOne, obligatoire)
- missionIntervention (nullable)
- missionInterventionFirm (nullable)
- item (MaterialItem, obligatoire)
- quantity (decimal string)
- comment (nullable)
- createdBy (User, obligatoire)
- implantSubMission (nullable)

Règle :
Pas de material lines sur mission type CONSULTATION.

MaterialItemRequest
Signalement item manquant/introuvable

- mission (ManyToOne)
- missionIntervention (nullable)
- missionInterventionFirm (nullable)
- label (obligatoire)
- referenceCode (nullable)
- comment (nullable)
- createdBy (User)

  4.3 Catalogue MaterialItem

MaterialItem :

- manufacturer (nullable)
- referenceCode
- label
- unit
- isImplant (bool)
- active (bool)

Utilisé par MaterialLine.item

4.4 Regroupement implants (ImplantSubMission)

Quand MaterialItem.isImplant = true :

- association à ImplantSubMission (mission + firm)
- résolution via firmName ou manufacturer

Objectif :
Reporting / validation / workflow implants

---

5. Endpoints d’encodage

Choix retenu : Option B (endpoint dédié encoding)

GET /api/missions/{id}/encoding

- renvoie la vue encodage structurée
- évite d’alourdir GET /api/missions/{id}

Routes CRUD associées :

- Interventions :
  POST/PATCH/DELETE /api/missions/{missionId}/interventions...

- Firms :
  POST/PATCH/DELETE /api/interventions/{interventionId}/firms...

- Material lines :
  POST/PATCH/DELETE /api/missions/{missionId}/material-lines...

- Material item requests :
  endpoints dédiés à compléter

---

6. Services applicatifs (Responsabilités)

6.1 MissionService

- create/patch/publish/claim/submit
- listing GET /api/missions
- règles eligibleToMe
- claim transactionnel anti-double
- dates en ATOM via Mapper

  6.2 MissionActionsService

- calcule allowedActions[] selon rôle + statut + ownership
- claim visible uniquement si éligible

  6.3 MissionVoter
  Source RBAC :

- VIEW, CREATE, PUBLISH, CLAIM, SUBMIT, EDIT, EDIT_ENCODING

  6.4 InterventionService

- CRUD interventions/firms/material lines
- interdit sur CONSULTATION
- résolution implants
- vérifications NotFound

  6.5 MissionEncodingService (si présent)

- construit l’agrégat MissionEncodingDto
- utilisé pour GET /api/missions/{id}/encoding

  6.6 MaterialCatalogService (si présent)

- listing / filtres MaterialItem

  6.7 MaterialItemRequestService (si présent)

- création signalements item manquant
- workflow manager review futur

---

7. Mapping DTO (sorties)

7.1 Missions standard
MissionListDto / MissionDetailDto :

- id, site, startAt, endAt, schedulePrecision, type, status,
  surgeon, instrumentist, allowedActions[]

MissionMapper :

- dates en DateTimeInterface::ATOM
- DTO explicites (pas dépendant des groupes Doctrine)

  7.2 Encoding DTO

- MissionEncodingDto
- MissionEncodingInterventionDto
- MissionEncodingFirmDto
- MissionEncodingMaterialLineDto
- MissionEncodingMaterialItemRequestDto

Principe :
Structure stable pour UI mobile :
Intervention -> Firm -> Lines + Requests

---

8. Sérialisation & groupes

- mission:read :
  vue non-financière instrumentiste/chirurgien

- mission:read_manager :
  ajoute champs internes manager-only

Règle :
Aucun champ financier exposé hors managers/admins.

---

9. Erreurs API (format)

Géré par ApiExceptionSubscriber :

{
"error": {
"status": 422,
"code": "VALIDATION_FAILED",
"message": "Validation failed",
"violations": [],
"debug": {
"exceptionClass": "...",
"exceptionMessage": "..."
}
}
}

- debug seulement en environnement dev
- UniqueConstraintViolationException -> 409 CONFLICT (utile pour claim)

---

10. Points d’attention techniques

10.1 Migrations Doctrine
TableNotFoundException indique :

- entité créée mais migration non exécutée

Action :

- générer + exécuter migrations avant tests encoding

  10.2 Intégrité des liens missionId
  Pour endpoints nested :

- vérifier appartenance intervention/line à la mission
- éviter modifications cross-mission par ID

  10.3 Timezone / dates

- stockage datetime_immutable
- sérialisation ATOM
- filtres periodStart/periodEnd stables
- tri eligibleToMe : ASC (prochaines missions d’abord)
  sinon DESC

---

11. Prochaine étape (à intégrer)

À documenter et implémenter :

- MaterialCatalog endpoints : recherche, filtre active, pagination
- MaterialItemRequest endpoints : création depuis UI encodage
- Tests fonctionnels instrumentiste : OPEN -> CLAIM -> ENCODE -> SUBMIT
- Contrôles d’intégrité : interventionId/firmId hors mission -> 404/422
