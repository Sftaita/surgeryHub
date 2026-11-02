# Plan de déploiement — D-156 (prestations regroupées multi-salles)

Préparé le 2026-11-02. **Rien n'est exécuté sans l'accord explicite de l'utilisateur**, à
chaque étape marquée « accord ». Suit `docs/production-checklist.md` et `deploy/deploy.sh`
(D-163) ; ce document ne précise que ce qui est propre à D-156.

## 0. État de départ (relevé en lecture seule le 2026-11-02)

| Élément | Valeur |
|---|---|
| Release active | `releases/d9e6ee98e583dfee3de6c3b611bf0236a2e66480`, `v2026.11.01-prod-2`, `OFFICIAL` |
| Migrations | 94 exécutées, dernière `Version20261031180000`, 0 nouvelle |
| Écart avec la PR #67 | uniquement les commits D-156 (base de la branche = `d9e6ee9`) |
| Disque | 35 Go libres |
| Paires multi-salles en prod | 9 (instrumentiste #18, chirurgien #8, site #1), aucune heure, intervention, matériel ni calcul |
| Postes #3 / #4 / #5 du Dr Deltour | inchangés (Sophie #19) — **aucune opération prévue** |

## 1. Avant fusion (accord : fusion de la PR #67)

1. Revue de la PR #67 ; `main` toujours à `d9e6ee9`, sinon re-tester sur le nouveau `main`.
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

## 3. Sauvegardes vérifiées (avant `activate`)

1. `/home/deploy/scripts/backup_mysql.sh` → `all_<horodatage>.sql.gz` ; `gzip -t` OK.
2. Dump de la **seule** base : `mysqldump --single-transaction --databases surgicalhub | gzip`
   → `surgicalhub_pre_deploy_D156_<horodatage>.sql.gz` ; `gzip -t` OK, une seule instruction
   `USE`, taille cohérente (~0,8 Mo compressé le 2026-11-02).
3. Archive code `src_pre_deploy_<horodatage>.tar.gz` ; images
   `surgicalhub-{php,nginx}:rollback-d9e6ee9`.
4. Empreinte de référence (même requête que la recette) : `mission`, `mission_execution`,
   `financial_calculation`, `financial_calculation_line`, `mission_intervention`,
   `material_line`, `mission_assignment`, `surgeon_schedule_post` → fichier `fp_before`.

## 4. Exécution (accord : déploiement)

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

## 7. Retour arrière

| Situation | Opération |
|---|---|
| Avant `migrate` | `deploy/deploy.sh rollback d9e6ee98e583dfee3de6c3b611bf0236a2e66480` — base inchangée |
| Après `migrate`, aucune présence commune déclarée (`SELECT COUNT(*) FROM worked_presence` = 0) | **A** : rollback du code ; les colonnes et tables ajoutées sont ignorées par l'ancien code (toutes nullables / vides) |
| Après `migrate`, des présences communes existent | **B** : `down()` des 3 migrations lancé depuis le **nouveau** code (ordre inverse : `20261102090000`, `20261019090000`, `20261018090000`) puis rollback du code. `down()` de `20261018090000` supprime les présences ; les parts imputées restent dans `actual_duration_minutes` (relevé des présences fait avant). Sans B, l'ancien code écrirait des heures propres sur une mission liée → refus du CHECK (erreur, pas de corruption) |
| Rattachement `--apply` à annuler seul | `UPDATE mission SET schedule_post_id = NULL, schedule_occurrence_date = NULL WHERE schedule_post_id IS NOT NULL` (le générateur revient au rapprochement par horaires) — ou restauration du dump de la seule base |
| Incident de données | **C** : restauration de la seule base `surgicalhub` depuis `surgicalhub_pre_deploy_D156_*` (instantané de sécurité d'abord ; jamais le dump `all_*`) |

Jamais de tag `*-prod` pour un état restauré ; incident documenté.

## 8. Après validation

Tag `vYYYY.MM.DD-prod`, `deploy/deploy.sh mark`, `docs/production.md`, registre D-156 →
`deploye` puis `valide-production`. Tarif de Salve (#18) : voir D-156 « Finalisation » —
aucune action sans décision.
