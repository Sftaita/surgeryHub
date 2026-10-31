# D-177 — mise en production et repli (procédure durable)

Branche `rollback/d177` (sur GitHub) : version de repli de D-177 + ces scripts + leurs tests.
Copie sur le serveur : `/home/deploy/d177/`. Release de repli pré-construite sur le serveur.

- `M` = `953ce9a7c83925a14ccecb0e56c97833439f54bd` (fusion de la PR #58, arbre = 69f1732 audité).
- `RB` = `be3e4f194d4537ab0b30869dfe86007d4ae4fc74`, release construite sur le serveur (les commits
  suivants de la branche ne touchent que `deploy/rollback/d177/` : procédure, scripts SQL, tests ;
  le code applicatif est identique) (version de repli : code d'avant D-177 + les 5 valeurs
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

### Décision B1 / B2 (toujours worker arrêté)
1. Retirer la ligne crontab `room_takeover_expire_priorities.sh` ; `docker stop surgicalhub-worker`
   (plus aucun message traité entre la décision et la bascule).
2. `./run_sql.sh 00_decision.sql` → trois compteurs + verdict :
   - `publications_d177` : publications avec `expires_at`, `closed_at` ou `close_reason` ;
   - `audits_d177` : les 5 valeurs d'audit ajoutées par D-177 (`ROOM_TAKEOVER_PRIORITY_OFFERED`,
     `_BYPASSED`, `_ENDED`, `ROOM_SLOT_TAKEOVER_REGULARIZED`, `_REGULARIZATION_REVERTED`) ;
   - `messages_d177` : messages de la file (toutes files) que d32dbe7 ne sait pas décoder.

   **B1 uniquement si les trois sont à 0.** Le compteur de publications seul ne suffit pas : une
   reprise publiée directement au pool (aucune instrumentiste habituelle éligible) ne crée aucune
   priorité mais enregistre `ROOM_TAKEOVER_PRIORITY_BYPASSED`, que d32dbe7 ne sait pas lire —
   historique de la mission **et journal manager entier** en erreur 500 (démontré, cf. §C).
   En cas de doute : B2 (la version de repli est sûre dans tous les cas).

### B1. Aucune trace D-177 (0 / 0 / 0)
1. `deploy/deploy.sh rollback d32dbe78b3afdc7042ce62013f29de00c27c42f6` (redémarre php et worker).
2. Re-vérification immédiate : `./run_sql.sh 00_decision.sql`. Le code D-177 a servi des requêtes
   jusqu'à la bascule ; si une trace est apparue entre-temps (verdict B2), enchaîner B2 depuis
   l'étape 1 (le worker d32dbe7 est alors arrêté avant 01_queue.sql).

### B2. Traces D-177 présentes (au moins un compteur > 0)
Le code d32dbe7 brut ne lit plus l'historique (audits D-177 → erreur 500) et verrait les
priorités closes « en attente » : on active `RB`, après transition des données.
1. Crontab D-177 retirée et worker arrêté (étape « Décision »).
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
- `tests/run_rollback_cycle.sh` (MySQL isolé, jamais la production ; trois backends prêts : D-177,
  d32dbe7, RB) — 19 contrôles, rejoués le 2026-10-31 : 19/19.
  1. Reprise publiée directement au pool par le code D-177 (HTTP réel) : 0 publication D-177,
     audit `BYPASSED` présent → `00_decision.sql` = B2 (l'ancienne règle aurait dit B1).
  2. d32dbe7 brut sur ces données : `/api/missions/{id}/audit` et `/api/manager/audit-events` en 500.
  3. B2 (01 → 02 → 04, RB) : toutes les lectures en 200, empreinte missions / affectations /
     publications identique, demande nominative ordinaire toujours en attente, aucune notification
     supplémentaire (worker + 3 crons du serveur), mission de reprise prenable depuis le pool.
  4. Repli avec les messages de la reprise encore en file : aucun message indécodable, file vidée
     par le worker RB, sollicitation du pool envoyée une seule fois (même volume que sous D-177).
  5. Retour en avant (05, code D-177) : lectures en 200, `expire-priorities --dry-run` 0 / 0, aucune
     notification supplémentaire.
  6. Missions ordinaires seules (sans reprise) : verdict B1, d32dbe7 brut lit tout sans erreur.
- Cycle des priorités (expirées, en attente, échues, acceptées ; code D-177 et d32dbe7 alternés) :
  rapport du 2026-10-31.
