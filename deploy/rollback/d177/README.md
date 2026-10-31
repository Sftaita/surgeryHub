# D-177 — mise en production et repli (procédure durable)

Branche `rollback/d177` (sur GitHub) : version de repli de D-177 + ces scripts + leurs tests.
Copie sur le serveur : `/home/deploy/d177/`. Release de repli pré-construite sur le serveur.

- `M` = `953ce9a7c83925a14ccecb0e56c97833439f54bd` (fusion de la PR #58, arbre = 69f1732 audité).
- `RB` = commit de tête de cette branche (version de repli : code d'avant D-177 + les 5 valeurs
  d'audit D-177 + migration conservée + documentation + ce dossier).
- Release active avant D-177 : `d32dbe78b3afdc7042ce62013f29de00c27c42f6` (v2026.10.30-prod).

## A. Mise en production (ordre corrigé : migration AVANT la bascule)

Pourquoi : `deploy.sh activate` met le nouveau code en service (php + worker) avant `migrate`.
Le code D-177 sur l'ancien schéma lève `Unknown column 't0.expires_at'` (HTTP 500 dès qu'une
publication est lue, missions ordinaires comprises — démontré). La migration est additive
(3 colonnes NULL + 1 index) et le code en service (d32dbe7) fonctionne sur le schéma migré
(suite complète de d32dbe7 sur schéma migré). Donc : migrer d'abord, depuis l'image M, avec la
commande de `deploy.sh migrations` sans `--dry-run` ; aucune modification de deploy.sh.

1. Préparation (sans impact) : `deploy/deploy.sh prepare|upload|stage|build M`, `deploy/deploy.sh migrations M`
   (dry-run : seule `Version20261030150000`, `ALTER TABLE … ADD` + `CREATE INDEX`).
2. Sauvegarde : `/home/deploy/scripts/backup_mysql.sh` (noter le chemin, `gzip -t`).
3. Migration pré-bascule (d32dbe7 reste en service) :
   ```bash
   cd /opt/stack/apps/surgicalhub && IMAGE_TAG=M docker compose --project-directory /opt/stack/apps/surgicalhub \
     -f releases/M/deploy/production/docker-compose.yml run --rm --no-deps -T -v /var/www/backend/var/cache \
     php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
   ```
   Contrôle : `SHOW COLUMNS FROM mission_publication` (expires_at, closed_at, close_reason), API et
   worker en service toujours sains (le conteneur en service signale 1 migration « inconnue » : normal).
4. `deploy/deploy.sh activate M` (sauvegarde + bascule php/worker/nginx, code vérifié).
5. `deploy/deploy.sh migrate M` (contrôle : déjà à jour, cache vidé, redémarrages).
6. Contrôles en lecture seule (aucune donnée créée) : §5 de docs/deployment-versioning.md ;
   empreinte des publications existantes inchangée ; 0 publication D-177 ; `docker logs surgicalhub-worker` ;
   `docker compose exec -T php php bin/console app:room-takeover:expire-priorities --dry-run --env=prod` → 0 / 0 ;
   `GET /api/planning/available-rooms` et `/history` (compte manager réel, lecture) → 200.
7. Seulement ensuite : `room_takeover_expire_priorities.sh` → `/home/deploy/scripts/`, exécution manuelle
   (log OK), puis crontab `*/5 * * * * /home/deploy/scripts/room_takeover_expire_priorities.sh >> /home/deploy/logs/room-takeover-expire-priorities.log 2>&1`.
8. Version officielle (`deploy.sh mark`) après validation.

## B. Repli

**Jamais de migration « down »** dès qu'une publication D-177 a existé (colonnes conservées :
le code de repli les ignore). La release de repli `RB` est déjà construite sur le serveur ;
le repli n'exige ni PR ni build pendant l'incident.

Décision : `SELECT COUNT(*) FROM mission_publication WHERE expires_at IS NOT NULL OR closed_at IS NOT NULL;`

### B1. Aucune publication D-177 (0)
Retirer la ligne crontab ; `deploy/deploy.sh rollback d32dbe78b3afdc7042ce62013f29de00c27c42f6`.

### B2. Des priorités existent (> 0)
Le code d32dbe7 brut ne lit plus l'historique (audits D-177 → erreur 500) et verrait les
priorités closes « en attente » : on active `RB`, après transition des données.
1. Retirer la ligne crontab `room_takeover_expire_priorities.sh` ; `docker stop surgicalhub-worker`.
2. `./run_sql.sh 01_queue.sql` (messages D-177 indécodables : copiés puis retirés de la file).
3. `./run_sql.sh 02_transition.sql` (instantané ; priorités closes au pool → refus technique journalisé).
4. `./run_sql.sh 04_pending_report.sql` → **liste remise aux managers** (mission, début, instrumentiste, échéance).
5. `deploy/deploy.sh activate RB` (sauvegarde, bascule ; aucune migration) puis contrôles §5.
6. Crontab du mode repli : `*/5 * * * * /home/deploy/d177/due_priorities.sh`.

### Procédure managers pendant le repli
- Une priorité en attente reste réservée à son instrumentiste jusqu'à son échéance (jamais ouverte
  au pool avant). L'instrumentiste peut accepter ou refuser normalement.
- À l'échéance, `due_priorities.sh` la transforme en demande refusée (log :
  `/home/deploy/logs/d177-rollback-due-priorities.log`, qui liste les missions à rediffuser) : la mission
  apparaît « Demande refusée — à rediffuser » ; le manager **publie au pool** ou **attribue directement**.
- Mission qui commence avant l'échéance (colonne `mission_avant_echeance`) : le manager attribue
  directement (possible même pendant la priorité) ou obtient la réponse de l'instrumentiste.
- `04_pending_report.sql` chaque matin tant que dure le repli : liste à jour et fiable.

### B3. Retour en avant
1. Retirer la crontab `due_priorities.sh` ; `docker stop surgicalhub-worker`.
2. `deploy/deploy.sh rollback M` (release M, images M).
3. `./run_sql.sh 05_roll_forward.sql` (annule exactement les marquages journalisés).
4. `docker start surgicalhub-worker` ; `expire-priorities --dry-run` puis exécution : les priorités
   échues pendant le repli expirent maintenant (pool ouvert à ce moment-là) ou sont closes sans
   pool si la mission a été attribuée / annulée ; puis réinstaller la crontab D-177.

## C. Tests
- `deploy/tests/d177-sequence.sh` (banc de deploy/tests, Linux) : migration pré-bascule sur l'image M
  pendant que A reste en service, puis activation ; repli refusé hors branche principale, accepté avec
  `DEPLOY_MAIN_REF=origin/rollback/d177`, construit sans impact, activable, retour en avant.
- Cycle de données (bases isolées, code D-177 et code d32dbe7 alternés) : voir le rapport du 2026-10-31.
