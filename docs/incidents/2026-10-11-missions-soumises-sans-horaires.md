# Missions soumises ou validées sans horaires réels — audit du 2026-10-11 (D-143)

Audit réalisé **en lecture seule** sur la base de production (`SELECT` uniquement, aucune
écriture). Aucune donnée n'a été corrigée ni inventée. Les identifiants de personnes sont
donnés par numéro d'utilisateur ; aucune donnée patient n'est concernée.

## 1. Cause exacte

| Constat | Cause |
|---|---|
| Une mission pouvait être soumise sans aucune heure | **Défaut de validation backend** : `MissionEncodingWorkflowService::complete()` (D-070) ne contrôlait que la justification « sans matériel » (D-080). Aucun contrôle des heures. |
| Aucune mission n'a d'heure réelle de début ou de fin | **Ancien parcours** : le dialogue « Heures prestées » n'envoyait qu'une durée (`actualDurationMinutes`) ; le modèle D-071 ne pouvait pas stocker début + fin + pause ensemble. |
| Une mission sans heure a pu être validée | `validate()` ne contrôlait pas non plus les heures ; la valorisation se serait repliée sur le planifié (`EffectiveDurationSource::PLANNED`). |
| Heures modifiables après soumission | `MissionExecutionVoter::UPDATE` sans borne de statut. |

Ce n'est ni une incohérence de statuts ni une donnée corrompue : les statuts et l'audit sont
cohérents, les règles manquaient.

## 2. Cas de l'instrumentiste #35 (Diane de Moor)

| Mission | Date | Statut | Soumise le | Interventions | Matériel | Heures | Commentaire « sans matériel » (extrait) |
|---|---|---|---|---|---|---|---|
| 434 | 07/09/2026 (planifié 10 h) | **VALIDATED** (29/09) | 18/09 17:47 | 0 | 0 | aucune | « … 6h de prestation » |
| 435 | 08/09/2026 (planifié 10 h) | SUBMITTED | 18/09 17:45 | 0 | 0 | aucune | « … 5H prestation » |
| 451 | 14/09/2026 (planifié 10 h) | SUBMITTED | 18/09 17:43 | 0 | 0 | aucune | (activité décrite, sans heures) |

Les trois missions ont été soumises en deux minutes, quatre à onze jours après la journée
opératoire, en décrivant l'activité dans le commentaire libre faute de pouvoir le faire
autrement. Les heures indiquées dans ce texte libre (6 h, 5 h) **diffèrent fortement du
planifié (10 h)** : une valorisation sur le planifié aurait surestimé la prestation.

## 3. Inventaire complet (production, 2026-10-11)

| Catégorie | Nombre | Missions | Impact |
|---|---|---|---|
| Soumise/validée **sans aucune heure** | 5 | 434 (VALIDATED), 435, 451 (instr. #35) ; 1224 (SUBMITTED, instr. #19, mission déclarée) ; 1225 (VALIDATED, instr. #20, mission déclarée) | **Aucun calcul financier n'existe encore** pour ces 5 missions — aucune facture ni relevé à corriger. Depuis D-143, 434/1225 ne pourraient plus être validées, 435/451/1224 ne peuvent plus l'être. |
| Soumise/validée avec **durée seule** (ancien parcours) | 48 | 10 SUBMITTED (soumises 04/08 → 05/09), 38 VALIDATED | Déclarations légitimes ; restent validables (D-143). Aucune action requise. |
| Missions passées jamais encodées | 94 | 29 ASSIGNED, 65 IN_PROGRESS (fin < 2026-10-11) | Hors fenêtre des relances automatiques au premier passage (pas de rafale) ; visibles dans « Suivi des encodages », relance manuelle possible. |
| Rappel automatique D-083 déjà envoyé | 0 | — | Confirme que la tâche n'a jamais été planifiée. |

Requêtes utilisées : jointure `mission` ⟕ `mission_execution`, filtrées sur les statuts
`SUBMITTED|VALIDATED|CLOSED`, comptage `mission_intervention` / `material_line`, journal
`audit_event` des missions concernées, `financial_calculation` par mission.

## 4. Procédure de régularisation proposée (à valider avant toute action)

Principe : **jamais d'heure inventée, jamais d'écriture SQL directe** ; tout passe par les
écrans et endpoints existants, donc audité.

1. **Missions 435, 451, 1224 (SUBMITTED sans heure)** — le manager les renvoie à
   l'instrumentiste (« Rejeter », commentaire : « Merci de renseigner vos heures réelles de
   début et de fin »). La mission repasse `ENCODING_IN_PROGRESS`, rien n'est perdu ;
   l'instrumentiste saisit début/fin/pause et soumet à nouveau (contrôle D-143).
   *Alternative* si l'instrumentiste confirme ses heures par écrit au manager : saisie par le
   manager (`PATCH /api/missions/{id}/execution`, `hoursSource = MANAGER`, autorisé par D-143
   tant que la mission n'est pas validée) — **aucun écran manager n'expose encore cette
   saisie**, la voie standard reste donc le renvoi à l'instrumentiste.
2. **Missions 434 et 1225 (VALIDATED sans heure)** — avant tout calcul financier :
   « Rouvrir » (`reopen`, autorisé : aucun calcul verrouillé), puis même démarche qu'au point 1.
   Pour 434, le commentaire mentionne 6 h : à confirmer avec l'instrumentiste, jamais à
   reprendre automatiquement.
3. **48 durées seules** — aucune action. Elles restent la déclaration de référence ; si un
   besoin de traçabilité début/fin apparaît, une demande ciblée aux instrumentistes suffit.
4. **Contrôle après régularisation** (lecture seule) : 0 mission
   `SUBMITTED|VALIDATED|CLOSED` sans `mission_execution.actual_duration_minutes` ni
   `actual_start_at`/`actual_end_at`.

Les étapes 1 et 2 modifient des données de production : **à exécuter uniquement après
accord explicite**, par un manager depuis l'interface.

## 5. Checklist nominative de régularisation (version intégrée D-144 — à exécuter après déploiement et autorisation)

Préalable : version intégrée déployée (validation sans heure bloquée, valorisation sans heure
bloquée — `MISSING_ACTUAL_HOURS`). Toutes les actions passent par l'interface manager, sont
auditées (`AuditEvent` + `activity_event`) et n'écrivent **aucune** heure à la place de
l'instrumentiste. Parcours prouvé sur le code intégré par
`MissionEncodingWorkflowControllerTest::test_historical_*` (renvoi, réouverture, saisie,
re-soumission, validation, droits).

| # | Mission | Instr. | Statut prod (11/10) | Action manager | Ce que fait ensuite l'instrumentiste | Contrôle |
|---|---|---|---|---|---|---|
| 1 | **435** (08/09) | #35 | SUBMITTED, aucune heure | « Rejeter » — commentaire : « Merci de renseigner vos heures réelles de début et de fin. » | saisit début / fin / pause, soumet | statut SUBMITTED, heures présentes, puis « Valider » |
| 2 | **451** (14/09) | #35 | SUBMITTED, aucune heure | idem | idem | idem |
| 3 | **1224** (21/09) | #19 | SUBMITTED (mission déclarée), aucune heure | idem | idem | idem |
| 4 | **434** (07/09) | #35 | VALIDATED (29/09), verrouillée, aucun calcul | « Rouvrir » — commentaire : « Heures réelles manquantes (régularisation D-143). » | saisit ses heures (le texte libre « 6h de prestation » n'est **pas** repris automatiquement), soumet | re-« Valider » ; calcul financier possible ensuite |
| 5 | **1225** (24/09) | #20 | VALIDATED (26/09), verrouillée, aucun calcul | « Rouvrir » — même commentaire | idem | idem |

Avant chaque « Rouvrir » : vérifier (lecture seule) qu'aucun `financial_calculation` n'existe
pour la mission — vrai au 2026-10-11 ; s'il en existait un verrouillé, la réouverture serait
refusée (D-073) et la mission resterait en l'état.

Contrôle final (lecture seule) : aucune mission `SUBMITTED|VALIDATED|CLOSED` sans
`actual_duration_minutes` ni `actual_start_at`/`actual_end_at` :

```sql
SELECT m.id, m.status FROM mission m LEFT JOIN mission_execution e ON e.mission_id = m.id
WHERE m.status IN ('SUBMITTED','VALIDATED','CLOSED') AND (e.id IS NULL OR (e.actual_duration_minutes IS NULL AND e.actual_start_at IS NULL));
```

**Nécessite ton autorisation** : les 5 renvois / réouvertures (modifications de données de
production) et l'information préalable des instrumentistes #35, #19, #20.
