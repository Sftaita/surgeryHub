# Plan de déploiement — D-156 (prestations regroupées multi-salles)

Préparé le 2026-11-02. Actualisé le 2026-11-03 après la fusion de la PR #69.
**Rien n'est exécuté sans l'accord explicite de l'utilisateur**, à
chaque étape marquée « accord ». Suit `docs/production-checklist.md` et `deploy/deploy.sh`
(D-163) ; ce document ne précise que ce qui est propre à D-156.

> **État historique** : les §§ 0–6 décrivent le premier déploiement D-156 du 02/11/2026
> (`1e45953`, trois migrations exécutées : 97 au total). Le complément « encodage unique »
> (`366627f`, PR #69 fusionnée) est un déploiement distinct, non encore autorisé au dernier
> contrôle, sans nouvelle migration ; voir §9. Ne pas rejouer les procédures initiales.

## 0. État de départ (relevé en lecture seule le 2026-11-02, avant premier déploiement)

| Élément | Valeur |
|---|---|
| Release active | `releases/d9e6ee98e583dfee3de6c3b611bf0236a2e66480`, `v2026.11.01-prod-2`, `OFFICIAL` |
| Migrations | 94 exécutées, dernière `Version20261031180000`, 0 nouvelle |
| Écart avec la PR #67 | uniquement les commits D-156 (base de la branche = `d9e6ee9`) |
| Disque | 35 Go libres |
| Paires multi-salles en prod | 9 (instrumentiste #18, chirurgien #8, site #1), aucune heure, intervention, matériel ni calcul |
| Postes #3 / #4 / #5 du Dr Deltour | inchangés (Sophie #19) — **aucune opération prévue** |

## 1. Fusion (faite le 2026-11-02 sur accord)

1. PR #67 fusionnée : `main` = `1e45953bacba44a00a9c30f16c3b2e0fdb35fbd2`, arbre identique au
   commit testé `547cf70` ; CI verte (inventory, frontend) au moment de la fusion.
2. Fusion par merge commit (arbre identique au dernier commit testé de la branche) ; noter le
   SHA complet `$SHA`.
3. `deploy/deploy.sh pending $SHA` (D-169) : présenté et relu.

## 2. Contrôle préalable en lecture seule (le jour J, avant toute écriture)

```
docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod
#   attendu : Executed 94, New 0 (avant build) — toute autre valeur = arrêt
docker exec -i mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" surgicalhub' <<'SQL'
SHOW TABLES LIKE 'worked_presence';                       -- attendu : vide
SHOW TABLES LIKE 'planning_multi_room_confirmation';      -- attendu : vide
SHOW COLUMNS FROM mission LIKE 'schedule_post_id';         -- attendu : vide
SELECT COUNT(*) FROM mission;                              -- noter
SELECT id, instrumentist_id, period, active FROM surgeon_schedule_post WHERE id IN (3,4,5);
SQL
```
Plus la requête des paires multi-salles (relevé du 2026-11-02, `prod_multiroom.sql`) : toute
paire encodée depuis (heures propres sur les deux missions) est listée avant de poursuivre —
elle serait bloquée en valorisation par `OVERLAPPING_HOURS_IN_PRESTATION` (jamais payée deux
fois), à régulariser en déclarant la présence commune (conversion confirmée des heures propres).

## 2 bis. Tarification de Salve (#18) — aucune valorisation avant décision

Relevé en lecture seule (2026-11-02), règle du moteur : tarif horaire dont
`validFrom ≤ début réel (sinon planifié) < validTo`.

| Période | Tarif applicable | Effet si on valorisait |
|---|---|---|
| avant le 2026-09-07 | **aucun** | calcul refusé, anomalie `MISSING_INSTRUMENTIST_RATE` (rien n'est payé à 0) |
| 2026-09-07 → 2026-09-30 | #8 : **0,00 €** (saisi à 0 le 2026-09-07 par le manager #7) | prestation instrumentiste valorisée **0,00 €**, sans anomalie |
| à partir du 2026-10-01 | #12 : 65,00 € | normal (calculs d'octobre déjà à 65 €) |

Les 9 paires (18 missions, toutes de Salve, affectation unique `LEGACY` ouverte, chirurgien
#8, site #1, aucune heure, aucun calcul) :
- 5 paires **sans tarif** : 01/07 (258/259), 08/07 (274/275), 15/07 (286/287), 26/08 (410/411),
  02/09 (426/427) ;
- 4 paires **à 0 €** : 09/09 (438/439), 16/09 (456/457), 23/09 (469/470), 30/09 (487/488).

Au total, 41 missions de Salve de juillet à septembre (non annulées) : 23 sans tarif, 18 à
0 €, aucune ligne financière instrumentiste déjà calculée avant octobre. Aucune valorisation
n'est automatique (seulement sur action du manager : calcul d'une mission ou recalcul groupé
de la facturation firmes) ; le déploiement n'en déclenche aucune. **Décision (2026-11-02)** :
65 €/h à partir du 01/10 uniquement, jamais rétroactif ; tarifs historiques inchangés ; aucune
valorisation automatique ni régularisation des missions antérieures au 01/10 (régime de la
période). Sujet séparé de D-156, sans effet sur le déploiement. Aucun tarif n'est modifié par
ce plan.

## 3. Sauvegardes vérifiées (avant `activate`)

1. `/home/deploy/scripts/backup_mysql.sh` → `all_<horodatage>.sql.gz` ; `gzip -t` OK.
2. Dump de la **seule** base : `mysqldump --single-transaction --databases surgicalhub | gzip`
   → `surgicalhub_pre_deploy_D156_<horodatage>.sql.gz` ; `gzip -t` OK, une seule instruction
   `USE`, taille cohérente (~0,8 Mo compressé le 2026-11-02).
3. Instantané D-156 de référence (`deploy/rollback/d156/snapshot.sql`, vide avant déploiement).
4. Archive code `src_pre_deploy_<horodatage>.tar.gz` ; images
   `surgicalhub-{php,nginx}:rollback-d9e6ee9`.
5. Empreinte de référence (même requête que la recette) : `mission`, `mission_execution`,
   `financial_calculation`, `financial_calculation_line`, `mission_intervention`,
   `material_line`, `mission_assignment`, `surgeon_schedule_post` → fichier `fp_before`.

## 4. Première mise en production (déjà exécutée le 2026-11-02 ; historique)

```
deploy/deploy.sh paths $SHA && deploy/deploy.sh prepare $SHA && deploy/deploy.sh upload $SHA
deploy/deploy.sh stage $SHA && deploy/deploy.sh build $SHA
deploy/deploy.sh migrations $SHA     # dry-run : exactement les 3 migrations ci-dessous
```
Dry-run attendu, **dans cet ordre**, uniquement des `CREATE TABLE` / `ADD` / `ADD CONSTRAINT` /
`CREATE INDEX` en `up()` :

| Migration | Contenu | Durée sur copie prod |
|---|---|---|
| `Version20261018090000` | table `worked_presence`, `mission_execution.presence_id` (FK RESTRICT, CHECK une seule source d'heures), `mission.room_label` | < 1 s |
| `Version20261019090000` | table `planning_multi_room_confirmation` (CASCADE avec son brouillon) | < 1 s |
| `Version20261102090000` | `mission.schedule_post_id` (FK SET NULL) + `schedule_occurrence_date`, 2 index | < 1 s |

Puis `deploy/deploy.sh activate $SHA` et `deploy/deploy.sh migrate $SHA`. Redémarrer php,
worker, puis nginx (recette `deploy.sh`). Après migration : `migrations:status` = 97, 0
nouvelle ; `schema:validate --skip-sync` OK ; empreinte `fp_after` **identique** à
`fp_before` (vérifié sur la copie du 2026-11-02).

## 5. Rattachement des anciennes missions (accord distinct, après §4)

```
docker exec surgicalhub-php php bin/console app:mission:link-schedule-posts --from=2026-06 --to=2027-03 --env=prod
```
- Vérification seule. Attendu (copie du 2026-11-02, deux fois) : **221 `CLEAR`, 0
  `INTERCHANGEABLE`, 0 `TO_REVIEW`**, code 1 (liens à écrire). Toute ligne `TO_REVIEW` ou
  `INTERCHANGEABLE` = arrêt et présentation à l'utilisateur ; un écart de nombre est expliqué
  avant de continuer (missions créées ou générées depuis le 2026-11-02).
- Puis, sur accord : même commande avec `--apply` (une transaction ; n'écrit que
  `schedule_post_id` et `schedule_occurrence_date`, seulement sur des missions sans lien ;
  jamais un `TO_REVIEW`). Relancer la vérification : « Aucun lien à écrire ».
- Contrôles SQL (tous attendus à 0, constatés à 0 sur la copie) : chirurgien/site du poste ≠
  mission ; date d'occurrence ≠ date de la mission hors occurrence déplacée ; jour de semaine
  hors récurrence du poste ; deux missions vivantes pour une même occurrence. Écart horaire
  maximal mission / créneau du poste : 15 min.
- Les 9 paires multi-salles du mercredi sont liées aux postes #3 / #4 ; les jeudis, seul le
  poste #6 rattache des missions (la 2e salle a été déplacée à 13:00, hors tolérance : état
  actuel conservé). La paire aux horaires identiques 314 / 315 (missions manuelles) n'est
  liée à aucun poste.

## 6. Tests santé et test ciblé

Checklist §6 (containers, front 200, login 400, `.env` 404, logs, `app:release:show`). Test
ciblé via l'API publique avec comptes jetables `@surgicalhub.internal` et `MAIL_SAFE_MODE=on` :
lecture d'une prestation (`GET /api/missions/487/prestation` → 2 missions, aucune présence),
aperçu Planning V2 d'un mois non généré (lignes `occurrenceDate`, groupes du mercredi
`REQUIRED`), roster de l'éditeur. **Aucune écriture sur des missions réelles.** Nettoyage
vérifié (requête vide).

## 7. Retour arrière — non destructif

**Principe : on ne supprime jamais une table ni une colonne D-156, on ne lance jamais `down()`
en production.** Le schéma D-156 est purement additif ; l'ancien code (`d9e6ee9`) le lit sans
erreur (vérifié). Les données créées après le déploiement (présences communes, parts imputées,
liens poste ↔ mission, libellés de salle, confirmations) restent en base et redeviennent
utilisables telles quelles au redéploiement.

Risque mesuré (copie de production, 2026-11-02) : sans protection, l'ancien code, qui ne
connaît pas les présences communes, **écrase en silence** la part imputée d'une mission liée
quand une durée est saisie (`PATCH /api/missions/{id}/service`, 270 → 540 min). Une saisie
début/fin, elle, est refusée par la contrainte CHECK. D'où les verrous en base ci-dessous.

| Situation | Procédure |
|---|---|
| Avant `migrate` | `deploy/deploy.sh rollback d9e6ee98e583dfee3de6c3b611bf0236a2e66480` — base inchangée |
| Après `migrate`, `SELECT COUNT(*) FROM worked_presence` = 0 | Même retour du code ; schéma et liens conservés, aucun verrou nécessaire (l'ancien code ignore les colonnes ajoutées) |
| Après `migrate`, des présences communes existent | **Retour protégé**, ci-dessous |

**Retour protégé (aucune donnée supprimée) :**
1. Instantané : `deploy/rollback/d156/snapshot.sql` (lecture seule) → fichier horodaté conservé,
   plus le dump de la seule base `surgicalhub` (`gzip -t` vérifié). Ces deux fichiers
   permettent de restaurer les données D-156 en toute circonstance.
2. Verrous : `deploy/rollback/d156/guard-install.sql` (triggers, idempotent). Pendant le
   retour arrière, une mission liée à une présence commune est **gelée** : ses heures et son
   lien ne changent pas, elle ne change ni d'instrumentiste ni d'horaire, n'est ni annulée ni
   refusée, et n'est pas valorisée ; `worked_presence` est en lecture seule. Toute autre
   mission fonctionne normalement. Une action refusée échoue en erreur (sans écriture).
3. Retour du code : `deploy/deploy.sh rollback d9e6ee98e583dfee3de6c3b611bf0236a2e66480`.
4. Liste des missions gelées communiquée aux managers (requête de détail de `snapshot.sql`).
5. Redéploiement de D-156 : `deploy/rollback/d156/guard-uninstall.sql` **juste avant**
   d'activer le nouveau code, puis `snapshot.sql` → résultat identique à l'étape 1.

Preuve (copie de production du 2026-11-02, base migrée avec 2 présences communes, 4 missions
liées, 221 liens) : avec les verrous, l'ancien code refuse durée seule, début/fin,
valorisation, réaffectation, annulation et suppression d'une présence (0 écriture) ; il
valide normalement une mission soumise et enregistre des heures sur une mission non liée ;
instantanés avant / pendant / après redéploiement identiques ; le nouveau code relit la
prestation (570 min, parts 300 / 270) et la valorise.

**Annuler uniquement le rattachement** : `UPDATE mission SET schedule_post_id = NULL,
schedule_occurrence_date = NULL WHERE schedule_post_id IS NOT NULL` (le générateur revient au
rapprochement par horaires) ; aucun autre effet.

**Dernier recours** (incident de données hors D-156) : restauration de la **seule** base
`surgicalhub` depuis `surgicalhub_pre_deploy_D156_*` (instantané de sécurité d'abord ; jamais le
dump `all_*`). Les présences créées après le déploiement seraient alors à ressaisir à partir de
l'instantané de l'étape 1 — d'où l'ordre : toujours le retour protégé avant une restauration.

## 8. Après validation

Tag `vYYYY.MM.DD-prod`, `deploy/deploy.sh mark`, `docs/production.md`, registre D-156 →
`deploye` puis `valide-production`. Tarif de Salve (#18) : voir D-156 « Finalisation » —
aucune action sans décision.

Jamais de tag `*-prod` pour un état restauré ; incident documenté.

## 9. Complément « encodage unique » — PR #69, fusionnée sur main

- Commit applicatif `366627f2451020c6e4a4cf6a6e7bf98ba44eb86b` (PR #69),
  **non déployé** lors de la rédaction de cette mise à jour. La production exécute
  `1e45953` en `PENDING_VALIDATION` au dernier contrôle ; à revérifier avant toute action.
- La PR #69 supprime le choix de salle côté instrumentiste (une liste unique d'interventions
  et de matériel, une seule présence, une soumission globale et décisions manager communes).
  Aucune nouvelle migration ni modification des tarifs. Recette visuelle sur copie isolée,
  tests métier et CI rapportés conformes, validation de la production encore distincte.
- Sur accord de déploiement : vérifier d'abord le commit actif, le registre des travaux,
  l'intégrité des données, créer et vérifier une sauvegarde récente de la base `surgicalhub`,
  préparer la release `366627f`, confirmer **0 migration** en simulation, activer, vérifier
  API, frontend, worker, journaux et empreinte des données historiques.
- **Retour arrière du seul complément** : de `366627f` vers `1e45953`, rollback **du code
  uniquement**, sans `down()`, les tables et présences D-156 restant compatibles. L'ancien
  formulaire instrumentiste redemande alors de choisir une salle.
- **Retour arrière complet pré-D-156** : vers `d9e6ee9`, appliquer le retour protégé du §7
  si des présences communes existent : snapshot, sauvegarde, verrous SQL, puis retour du code.
  Ne jamais supprimer les tables D-156 ni leurs données.
- **Traçabilité** : ne pas marquer rétroactivement `1e45953` comme version officielle si le
  complément la remplace. Après activation **et validation fonctionnelle** de `366627f`,
  attribuer un tag daté au commit effectivement actif puis exécuter `deploy.sh mark`.
- **Rattachement historique** : `app:mission:link-schedule-posts --apply` reste interdit
  jusqu'à accord distinct, après une nouvelle simulation en lecture seule.
- Aucun changement de tarif de Salve, ni des postes #3/#4/#5 du Dr Deltour, ni des alertes
  historiques n'est autorisé dans cette opération.
