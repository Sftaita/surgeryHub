# H5b — Inventaire exhaustif des actions métier et de leur trace (audit avant modification)

Réalisé le 2026-10-05, **avant toute modification H5b**, à partir du code : toutes les routes
d'écriture (`POST` / `PUT` / `PATCH` / `DELETE`) des 48 contrôleurs concernés, les services
appelés, les commandes et handlers, et les écritures existantes `AuditEvent` /
`UserAuditEvent` / `activity_event` / journaux spécialisés.

Rappel H1 : **tout** `AuditEvent` et `UserAuditEvent` est recopié dans `activity_event` dans la
même transaction (`AuditEventMirrorListener`, `sourceRef = audit_event:{id}` /
`user_audit_event:{id}`). Une action déjà auditée est donc déjà dans le journal global : H5b
ne la double pas.

Légende « Lacune » : **aucune** = rien en base ne raconte l'action ; **partielle** = trace
incomplète ou illisible.

| Domaine | Action | Source de vérité | Audit existant | ActivityEvent existant | Lacune | Action H5b |
|---|---|---|---|---|---|---|
| Utilisateurs | création / invitation / renvoi / complétion (écran admin) | `user` | `UserAuditEvent` | miroir H1 | — | — |
| Utilisateurs | création instrumentiste / chirurgien (écrans dédiés `POST /api/instrumentists`, `/api/surgeons`) | `user` | **aucun** | invitation seule (H5a, livraison) | **aucune trace métier de la création** | `UserAuditEvent` USER_CREATED + USER_INVITATION_SENT (miroir H1) |
| Utilisateurs | suspension / réactivation (écran admin) | `user.active` | `UserAuditEvent` | miroir | — | — |
| Utilisateurs | suspension / réactivation (`/api/instrumentists/{id}/suspend|activate`) | `user.active` | **aucun** | — | **aucune** | `UserAuditEvent` USER_SUSPENDED / USER_REACTIVATED |
| Utilisateurs | changement de rôle, changement d'email | `user` | `UserAuditEvent` | miroir | — | — |
| Affiliations | ajout / retrait de site (écran admin) | `site_membership` | `UserAuditEvent` | miroir | — | — |
| Affiliations | ajout / retrait de site (écrans instrumentiste / chirurgien) | `site_membership` | **aucun** | — | **aucune** (impacte l'éligibilité) | `UserAuditEvent` USER_SITE_ADDED / REMOVED |
| Utilisateurs | spécialités chirurgien (`PATCH /api/users/{id}/specialties`) | `user` | aucun | — | aucune (faible) | **laissé** : donnée descriptive, sans effet d'éligibilité / planning / facturation |
| Utilisateurs | tarifs hérités `User.hourlyRate…` (`PATCH /api/instrumentists/{id}/rates`) | `user` | aucun | — | **aucune** (financier) | ActivityEvent `INSTRUMENTIST_LEGACY_RATES_UPDATED` (diff) |
| Utilisateurs | photo de profil, onboarding, `offers-seen` | `user` | — | — | — | **laissé** : cosmétique / technique |
| Préférences | `PATCH /api/me/notification-preferences/{type}` | `notification_preference` | aucun | — | **aucune** | `NOTIFICATION_PREFERENCES_UPDATED` (diff du type modifié) |
| Push | abonnement / désabonnement / expiration fournisseur (404/410) | `push_subscription` | aucun | — | **aucune** | `PUSH_SUBSCRIPTION_CREATED` / `_REMOVED` (raison contrôlée), sans endpoint ni clé |
| Sites | création / modification / suppression | `hospital` | aucun | — | **aucune** | `SITE_CREATED` / `_UPDATED` (champs structurants) / `_DELETED` ; photo = cosmétique, laissée |
| Groupes de sites | création / modification / suppression, ajout / retrait d'un site | `site_group`, `site_group_membership` | aucun | — | **aucune** (impacte génération / visibilité) | `SITE_GROUP_*`, `SITE_GROUP_SITE_ADDED` / `_REMOVED` |
| Planning habituel | poste chirurgien : création / modification / désactivation | `surgeon_schedule_post` (+ récurrence) | aucun | — | **aucune** — gouverne les impacts N1 | `SURGEON_SCHEDULE_POST_CREATED` / `_UPDATED` (avant/après) / `_DEACTIVATED` |
| Planning habituel | périodes (matin / après-midi / journée) par site | `shift_period_config` | aucun | — | **aucune** (horaires N1, D-103) | `SHIFT_PERIOD_*` |
| Planning habituel | exception d'occurrence **manuelle** (annulation, déplacement, remplaçante, horaire) | `planning_occurrence_exception` | aucun | — | **aucune** | `PLANNING_OCCURRENCE_EXCEPTION_CREATED` / `_UPDATED` / `_DELETED` |
| Planning habituel | exception d'occurrence **automatique** (absence chirurgien) | idem, `source = SURGEON_ABSENCE` | `AuditEvent PLANNING_OCCURRENCE_*` | miroir + `USER_PLANNING_IMPACTED` (H4) | — | — (jamais doublé) |
| Planning | génération, déploiement, suppression de brouillon, Modification | `planning_version`, `mission` | partiel | H1 | — | — |
| Planning | archivage de version | `planning_version` | `AuditEvent PLANNING_ARCHIVED` | miroir | — | — |
| Missions | création manuelle (`POST /api/missions`, brouillon) | `mission` | **aucun** | — | **aucune** | `AuditEvent MISSION_CREATED` (miroir H1) |
| Missions | modification d'un brouillon (`PATCH /api/missions/{id}`) | `mission` | **aucun** | — | **aucune** | `AuditEvent MISSION_UPDATED` (diff structuré) |
| Missions | publication, demande nominative, attribution directe, refus, claim, release, cancel, reassign, ajout post-déploiement, restauration, reprise de salle, ouverture depuis alerte | `mission` | `AuditEvent` | miroir + H2/H3/H4 | — | — (gap analysis : rien à ajouter) |
| Missions | déclaration (legacy), approbation / refus | `mission` | `AuditEvent` | miroir | — | — |
| Absences | création / modification / suppression / jour retiré | `absence` | partiel | H1 + H4 | — | — |
| Alertes | création d'une alerte (conflit, absence, réassignation requise) | `planning_alert` | **aucun** | — | **partielle** (seules les actions sont tracées) | `PLANNING_ALERT_RAISED` |
| Alertes | acquittement / résolution / ignorance / réassignation / ouverture au pool | `planning_alert` | `AuditEvent` | miroir | — | — |
| Salles libérées | libération / reprise / réouverture | `released_operating_room_slot` | `AuditEvent ROOM_SLOT_*` | miroir | — | — |
| D-114 | communication créée (collègues / gestion du bloc), révisions, livraisons | `surgeon_absence_communication(_delivery)` | journal spécialisé | **aucun** | **aucune corrélation globale** | `SURGEON_ABSENCE_COMMUNICATION_RECORDED` + livraison sur la couche basse commune (`outbound_notification` + tentatives + événements de canal) |
| D-114 | réglages par site | `absence_communication_site_config` | aucun | — | **aucune** (gouverne les envois) | `ABSENCE_COMMUNICATION_SETTINGS_UPDATED` |
| Catalogue | type d'intervention : création / modification / désactivation / suppression | `intervention_type` | **aucun** (seule la fusion) | — | **aucune** | `INTERVENTION_TYPE_CREATED` / `_UPDATED` / `_DELETED` |
| Catalogue | fusion de types | idem | `AuditEvent INTERVENTION_TYPE_MERGED` | miroir | — | — |
| Catalogue | propositions (intervention / matériel) : création, acceptation, ignorance, réaffectation | `*_request`, brouillons | `AuditEvent MISSION_INTERVENTION_DRAFT_*`, `MATERIAL_ITEM_REQUEST_IGNORED` | miroir | — | — |
| Matériel | article : création / modification (firme, référence, implant, actif, facturation) | `material_item` | **aucun** | — | **aucune** | `MATERIAL_ITEM_CREATED` / `_UPDATED` |
| Firmes | création / modification / désactivation / suppression | `firm` | **aucun** | — | **aucune** | `FIRM_CREATED` / `_UPDATED` / `_DELETED` ; logo = cosmétique |
| Firmes | contact de facturation | `firm` | aucun | — | **aucune** (destinataires des factures) | inclus dans `FIRM_UPDATED` |
| Offres | offre de service : création / modification | `firm_service_offering` | **aucun** | — | **aucune** (facturation, matériel suggéré) | `FIRM_OFFERING_CREATED` / `_UPDATED` |
| Offres | matériel suggéré : ajout / retrait / réordonnancement | `suggested_material` | aucun | — | **aucune** | `FIRM_OFFERING_MATERIAL_ADDED` / `_REMOVED` (réordonnancement = cosmétique, laissé) |
| Offres | groupe de choix obligatoire / options | `required_choice_group`, `choice_option` | aucun | — | **aucune** (choix obligatoire à l'encodage) | `FIRM_OFFERING_CHOICE_*` |
| Tarification | règles de prix firme, tarifs instrumentiste (versions) | `pricing_rule`, `instrumentist_rate` | `AuditEvent PRICING_RULE_*` / `INSTRUMENTIST_RATE_*` | miroir | partielle (résumé générique) | résumés lisibles du miroir |
| Encodage | démarrage / complétion / validation / rejet / réouverture / relance | `mission` | `AuditEvent MISSION_ENCODING_*` | miroir | — | — |
| Encodage | intervention ajoutée / modifiée / retirée | `mission_intervention` | **aucun** | — | **aucune** (base de facturation) | `MISSION_INTERVENTION_ADDED` / `_UPDATED` / `_REMOVED` |
| Encodage | matériel utilisé ajouté / modifié / retiré, choix obligatoire | `material_line`, `mission_intervention.selectedChoiceOption` | **aucun** | — | **aucune** | `MISSION_MATERIAL_LINE_ADDED` / `_UPDATED` / `_REMOVED` ; choix dans `MISSION_INTERVENTION_UPDATED` |
| Exécution | MissionExecution, durée, litiges | `mission_execution` | `AuditEvent MISSION_EXECUTION_*` | miroir | — | — |
| Exécution | anomalies d'encodage, demandes chirurgien | | `AuditEvent` | miroir | — | — |
| Évaluations | note instrumentiste / chirurgien | `*_rating` | aucun | — | aucune | **laissé** : appréciation personnelle, pas une action de workflow ; score / commentaire jamais journalisés |
| Calcul financier | création / recalcul / approbation / verrouillage / remplacement / échec | `financial_calculation(_line)` | `AuditEvent FINANCIAL_CALCULATION_*` | miroir | — | — |
| Documents financiers | facture / décompte : création, émission, annulation | `firm_invoice`, `instrumentist_statement` | `AuditEvent *_ISSUED` (statement inclus via `issue()`) | miroir | — | — |
| Documents financiers | **envoi par email** (destinataire, file, SENT / FAILED), renvoi | — | **aucun** | — | **aucune** (lacune H5a) | `FIRM_INVOICE_SENT` / `_RESENT`, `INSTRUMENTIST_STATEMENT_SENT` / `_RESENT` + livraison sur la couche basse commune ; PDF figé |
| Corrections | notes de crédit / débit, correction émise | | `AuditEvent` | miroir | — | — |
| Paiements | paiement, remboursement, solde (append-only) | `payment` | `AuditEvent DOCUMENT_*`, `REFUND_RECORDED` | miroir | — | — (aucun `PAYMENT_UPDATED` : le modèle l'interdit) |
| Exports | export d'activité chirurgien | `export_log` | journal spécialisé | **aucun** | **aucune corrélation globale** | `EXPORT_GENERATED` (miroir, `sourceRef = export_log:{id}`) |
| Notifications | in-app directs hérités (`MISSION_DECLARED*`, `PLANNING_MISSION_ASSIGNED`, `PLANNING_DEPLOYED`, `PLANNING_ALERT_REASSIGNED_*`, offre de reprise de salle) | `notification_event` | — | — | **non tracés** | migrés vers le dispatcher ou justifiés + garde-fou |
| Notifications | push direct `ROOM_TAKEOVER_MISSION_OFFER` | — | — | — | **non tracé** | dispatcher |
| Documents générés | `generated_document` | table | — | — | aucune classification | `retention_class` (IMMUTABLE_EVIDENCE / REGENERABLE), clé de source, empreinte d'entrée |

## Domaines déjà correctement couverts (laissés tels quels)

Missions et cycle de vie (H1–H4), absences (H1/H4), génération / déploiement / Modification
(H1/H3), salles libérées, encodage (transitions), exécution, calcul financier, émission /
annulation / corrections / paiements des documents financiers, tarification, utilisateurs via
l'écran d'administration, notifications (H2/H5a).

## État après H5b (2026-10-05)

Toutes les lacunes de la matrice sont traitées par le mécanisme indiqué dans la colonne
« Action H5b » (D-132). Deux ajustements découverts pendant l'implémentation :

- `MissionConflictWaiver` (autorisation manager de déployer malgré un conflit inter-sites,
  D-091) était silencieux : `PLANNING_CONFLICT_WAIVER_GRANTED` / `_INVALIDATED` (motif jamais
  copié).
- Les audits financiers miroités avaient pour sujet `AuditEvent` (clés `firmInvoiceId` /
  `instrumentistStatementId` non reconnues) : ils sont désormais sur la chronologie du document,
  avec ses envois et paiements.

La couverture est **vérifiée par un test**, non par cette liste : `ActivityCoverageTest` classe
chaque entité Doctrine (gouvernée / journalisée par un mécanisme nommé / exclue avec raison) et
échoue sur toute nouvelle entité non classée ou toute entrée périmée.

Relevé hors périmètre (non corrigé) : D-114 ne communique pas une occurrence habituelle non
matérialisée sur un site doté d'un `ShiftPeriodConfig` (exception D-103 créée avant le
résolveur D-114) — voir D-132 « Conséquences ».
