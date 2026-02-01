API SurgicalHub — Backend (Symfony 7)

Toutes les routes sont préfixées par /api.
Format : JSON uniquement.
Authentification : JWT (IS_AUTHENTICATED_FULLY).
Gestion des erreurs : HTTP standard (400, 401, 403, 404, 409, 422, 500).

Pagination standard

- page (défaut : 1)
- limit (défaut : 20, max : 100)

---

1. Sécurité & Autorisations (RBAC)

1.1 Rôles

- ROLE_ADMIN
- ROLE_MANAGER
- ROLE_SURGEON
- ROLE_INSTRUMENTIST

  1.2 Voters

- MissionVoter : view, create, publish, claim, submit, edit, edit_encoding
- ServiceVoter : update, dispute
- RatingVoter
- ExportVoter
- InstrumentistVoter

  1.3 Règle métier critique
  Aucune donnée financière ne doit être exposée aux rôles SURGEON ou INSTRUMENTIST.

---

2. Missions

2.1 Créer une mission
POST /missions

- AuthZ : MissionVoter::CREATE
- Body : MissionCreateRequest
- Response : MissionDetailDto

---

2.2 Modifier une mission (DRAFT uniquement)
PATCH /missions/{id}

- AuthZ : MissionVoter::EDIT
- Body : MissionPatchRequest
- Response : MissionDetailDto

---

2.3 Publier une mission
POST /missions/{id}/publish

- AuthZ : MissionVoter::PUBLISH
- Body : MissionPublishRequest
- Response : 204 No Content

---

2.4 Claim d’une mission (instrumentiste)
POST /missions/{id}/claim

- AuthZ : MissionVoter::CLAIM
- Body : vide
- Response : MissionDetailDto

---

2.5 Lister les missions
GET /missions

Query : MissionFilter

- status
- type
- siteId
- periodStart
- periodEnd
- eligibleToMe
- assignedToMe
- page
- limit

Response :
{
"items": ["MissionListDto"],
"total": 42,
"page": 1,
"limit": 20
}

---

2.6 Détail d’une mission
GET /missions/{id}

- AuthZ : MissionVoter::VIEW
- Response : MissionDetailDto

---

2.7 Soumettre une mission (instrumentiste)
POST /missions/{id}/submit

- AuthZ : MissionVoter::SUBMIT
- Body : MissionSubmitRequest
- Response : MissionDetailDto

---

3. Encodage opératoire (Option B)

3.1 Récupérer l’encodage complet d’une mission
GET /missions/{id}/encoding

- AuthZ : MissionVoter::EDIT_ENCODING
- Response : MissionEncodingDto

Inclut :

- interventions
- firms
- materialLines
- materialItemRequests

---

4. Interventions

4.1 Créer une intervention
POST /missions/{missionId}/interventions

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MissionInterventionCreateRequest
- Response : MissionIntervention

---

4.2 Modifier une intervention
PATCH /missions/{missionId}/interventions/{id}

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MissionInterventionUpdateRequest
- Response : MissionIntervention

---

4.3 Supprimer une intervention
DELETE /missions/{missionId}/interventions/{id}

- AuthZ : MissionVoter::EDIT_ENCODING
- Response : 204 No Content

---

5. Firms (par intervention)

5.1 Ajouter une firme
POST /interventions/{interventionId}/firms

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MissionInterventionFirmCreateRequest
- Response : MissionInterventionFirm

---

5.2 Modifier une firme
PATCH /interventions/{interventionId}/firms/{id}

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MissionInterventionFirmUpdateRequest
- Response : MissionInterventionFirm

---

5.3 Supprimer une firme
DELETE /interventions/{interventionId}/firms/{id}

- AuthZ : MissionVoter::EDIT_ENCODING
- Response : 204 No Content

---

6. Material Lines (matériel utilisé)

6.1 Ajouter une ligne de matériel
POST /missions/{missionId}/material-lines

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MaterialLineCreateRequest
- Response : MaterialLine

---

6.2 Modifier une ligne de matériel
PATCH /missions/{missionId}/material-lines/{id}

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MaterialLineUpdateRequest
- Response : MaterialLine

---

6.3 Supprimer une ligne de matériel
DELETE /missions/{missionId}/material-lines/{id}

- AuthZ : MissionVoter::EDIT_ENCODING
- Response : 204 No Content

---

7. Demandes de matériel manquant

7.1 Signaler un matériel absent du catalogue
POST /missions/{missionId}/material-item-requests

- AuthZ : MissionVoter::EDIT_ENCODING
- Body : MaterialItemRequestCreateRequest
- Response : MaterialItemRequest

---

8. Catalogue matériel

8.1 Lister le catalogue
GET /material-items

- Query : MaterialItemFilter
- Response : MaterialItemSlimDto[]

---

9. Services & Litiges

9.1 Modifier le service instrumentiste
PATCH /missions/{missionId}/service

- AuthZ : ServiceVoter::UPDATE
- Body : ServiceUpdateRequest
- Response : InstrumentistService

---

9.2 Créer un litige
POST /services/{serviceId}/disputes

- AuthZ : ServiceVoter::DISPUTE_CREATE
- Body : ServiceDisputeCreateRequest
- Response : ServiceHoursDispute

---

10. Ratings

10.1 Noter l’instrumentiste
POST /missions/{id}/instrumentist-rating

- AuthZ : RatingVoter::RATE_INSTRUMENTIST
- Body : InstrumentistRatingRequest

---

10.2 Noter le chirurgien
POST /missions/{id}/surgeon-rating

- AuthZ : RatingVoter::RATE_SURGEON
- Body : SurgeonRatingRequest

---

11. Utilisateurs

11.1 Lister les instrumentistes
GET /instrumentists

- AuthZ : InstrumentistVoter::LIST

---

11.2 Lister les chirurgiens
GET /surgeons

- AuthZ : manager/admin

---

11.3 Utilisateur courant
GET /me

- AuthZ : authentifié
- Response : MeResponse

---

12. Sites

12.1 Lister les hôpitaux
GET /sites

---

13. Exports

13.1 Exporter activité chirurgien
POST /exports/surgeon-activity

- AuthZ : ExportVoter::SURGEON_ACTIVITY
- Body : ExportSurgeonActivityRequest
- Response : ExportLog

---

14. Changelog

18-01-2026

- Ajout encodage opératoire via /missions/{id}/encoding
- Ajout interventions / firms / material lines
- Séparation claire mission vs encodage
- Renforcement RBAC et sécurité données financières
