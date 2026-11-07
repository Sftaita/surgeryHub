# Plan de déploiement — version intégrée H1–H5b × D-143 × D-144 (préparé, NON exécuté)

Préparé le 2026-10-11. **Rien n'a été exécuté en production.** À dérouler uniquement après
validation explicite, en suivant [`deployment-versioning.md`](deployment-versioning.md) et
[`production-checklist.md`](production-checklist.md) — ce document ne fait que les instancier
pour cette version.

## 0. Rapport d'écart (relevé en lecture seule le 2026-10-11)

```text
Commit local candidat : tête de integration/h1h8-encodage-rappels (à fusionner dans main d'abord)
Commit serveur        : 12695c2 (v2026.10.10-prod-2, D-141) — marqueur FinancialCalculationService::evaluate() présent
Dernier tag prod      : v2026.10.10-prod-2
Écart                 : origin/main 796f515 (docs) + D-143 (3 commits) + instantané H1–H5b + fusion + D-144
Migrations en attente : 6 — Version20261003120000, 20261004100000, 20261005100000,
                        20261006100000, 20261007100000 (H1–H5b), 20261011090000 (D-143)
État prod             : 76/76 exécutées, 0 indisponible, file messenger_messages vide
Anomalie détectée     : aucune (prod cohérente, voir production-checklist §4)
Décision              : déployer HEAD complet après fusion validée dans main (jamais la branche
                        d'intégration directement, jamais un sous-ensemble de fichiers)
```

Particularité vérifiée : les migrations H1–H5b sont **datées avant** deux migrations déjà
jouées en prod (`Version20261007180000`, `Version20261010090000`). Rejoué sur une base dans
l'état exact de la prod (76 migrations exécutées une à une) : `migrate --dry-run` ne planifie
que les 6 nouvelles, `migrate` les applique toutes (82/82, 0 erreur). `up()` relu : aucune
instruction destructrice (seulement des clauses `ON DELETE` de clés étrangères).

## 1. Préalables (avant toute action serveur)

1. Fusion de `integration/h1h8-encodage-rappels` dans `main` validée par l'utilisateur, puis
   push de `main` (la règle §9 exige que le commit déployé soit sur `origin`).
2. Le dossier principal (`C:\wamp64\www\SurgicalHub`) garde son état H1–H8 non commité :
   après fusion, ne **jamais** déployer depuis ce dossier (toujours `git archive` du commit).
3. Relancer en local, sur le commit de `main` fusionné : `phpunit` complet, `vitest run`,
   `npm run build`.

## 2. Sauvegardes (deployment-versioning §4.1)

```bash
ssh surgicalhub-prod "/home/deploy/scripts/backup_mysql.sh"
ssh surgicalhub-prod "tar czf /home/deploy/backups/code/src_pre_deploy_\$(date +%Y%m%d_%H%M%S).tar.gz -C /opt/stack/apps/surgicalhub src"
ssh surgicalhub-prod "cp /opt/stack/apps/surgicalhub/.env /home/deploy/backups/surgicalhub_env_pre_d144_\$(date +%Y%m%d_%H%M%S) && chmod 600 /home/deploy/backups/surgicalhub_env_pre_d144_*"
# Images courantes conservées pour un retour arrière rapide
ssh surgicalhub-prod "docker tag surgicalhub-php:latest surgicalhub-php:rollback-12695c2 && docker tag surgicalhub-nginx:latest surgicalhub-nginx:rollback-12695c2"
```

## 3. Déploiement (ordre obligatoire)

```bash
git archive --format=tar.gz -o surgicalhub_deploy.tar.gz <commit main validé> -- backend frontend docker-compose.yml
scp surgicalhub_deploy.tar.gz surgicalhub-prod:/tmp/
ssh surgicalhub-prod "tar xzf /tmp/surgicalhub_deploy.tar.gz -C /opt/stack/apps/surgicalhub/src/ && rm /tmp/surgicalhub_deploy.tar.gz"
# Fichiers supprimés par la version (l'extraction ne supprime rien) :
ssh surgicalhub-prod "cd /opt/stack/apps/surgicalhub/src && rm -f backend/src/Service/EmailService.php \
  backend/src/Message/InstrumentistAbsenceOccurrenceImpactMessage.php backend/src/Message/SurgeonAbsenceOccurrencesNeutralizedMessage.php \
  backend/src/MessageHandler/InstrumentistAbsenceOccurrenceImpactMessageHandler.php backend/src/MessageHandler/SurgeonAbsenceOccurrencesNeutralizedMessageHandler.php \
  backend/templates/emails/absence_pool_missions_available.html.twig"
#   (liste vérifiée le 2026-10-11 avec : git diff --diff-filter=D --name-only 12695c2 HEAD -- backend frontend ;
#    les autres suppressions sont des tests, non déployés dans les images)
ssh surgicalhub-prod "cd /opt/stack/apps/surgicalhub && nohup bash -c 'docker compose build --no-cache > /tmp/build.log 2>&1; echo BUILD_EXIT=\$? >> /tmp/build.log' &"
# attendre BUILD_EXIT=0
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod"        # 76 exécutées, 6 nouvelles
ssh surgicalhub-prod "cd /opt/stack/apps/surgicalhub && docker compose up -d"
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:migrate --dry-run --env=prod" # exactement les 6
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:migrate --no-interaction --env=prod"
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console cache:clear --env=prod && docker restart surgicalhub-php"
ssh surgicalhub-prod "docker restart surgicalhub-worker"   # handlers et routage Messenger modifiés : obligatoire
```

Configuration : **aucune** nouvelle variable obligatoire. `ACTIVITY_JOURNAL_ENABLED` vaut `1`
par défaut (backend/.env) — interrupteur d'urgence seulement. `ENCODING_REMINDER_AUTO_ENABLED`
reste **absent (= 0)** au déploiement : les relances automatiques ne sont pas activées par le
déploiement lui-même (§5).

## 4. Vérifications après déploiement

Tests santé de `production-checklist.md` §6 (containers, 200 frontend, 400 login vide, 404
`.env`, JWT + `/api/me` avec compte jetable `@surgicalhub.internal`, logs php/worker,
`migrations:status` à jour), puis, avec `MAIL_SAFE_MODE=on` le temps de la recette et des
comptes jetables uniquement :

| Vérification | Attendu |
|---|---|
| `GET /api/me/notification-preferences` | pas de `ENCODING_REMINDER*`, `ENCODING_ESCALATION`, `MISSION_HOURS_REMINDER` |
| Mission jetable : soumission sans heures | `422 ENCODING_INCOMPLETE` |
| Début seul puis fin + pause | `200`, durée = fin − début − pause |
| Soumission complète → chirurgien jetable | in-app `ENCODING_SUBMITTED` |
| Relance manuelle ×2 | `200` (canal) puis `409` |
| `SELECT COUNT(*) FROM notification_dispatch` / `activity_event` | lignes créées par la recette, aucun doublon d'épisode |
| `app:notifications:send-encoding-reminders --at=…` | **refusé** (`kernel.environment = prod`) |

Nettoyer les comptes et missions jetables (requête vide en confirmation), retirer
`MAIL_SAFE_MODE=on`.

## 5. Activation des relances automatiques (geste séparé, après validation de §4)

Procédure détaillée : `docs/production.md` — « Tâche planifiée — relance progressive
d'encodage J+1/J+2/J+4 ». Résumé :

```bash
ssh surgicalhub-prod "crontab -l > /home/deploy/backups/cron/crontab_before_encoding_reminders_\$(date +%Y%m%d_%H%M%S).txt"
# créer /home/deploy/scripts/send_encoding_reminders.sh (modèle : missions_start_due.sh — set -uo pipefail,
# flock -n /home/deploy/locks/encoding-reminders.lock, docker inspect, log() direct dans le fichier)
#   commande : docker compose exec -T php php bin/console app:notifications:send-encoding-reminders --env=prod
# ajouter : */15 * * * * /home/deploy/scripts/send_encoding_reminders.sh >> /home/deploy/logs/encoding-reminders.log 2>&1
# dans le même geste : ENCODING_REMINDER_AUTO_ENABLED=1 dans /opt/stack/apps/surgicalhub/.env, puis recréer php
```

**Anti-rafale à la première activation** (garanti par le code, rien à faire) : seule une
échéance dont la fenêtre de 24 h contient l'instant présent est traitée. Les 94 missions
passées encore `ASSIGNED`/`IN_PROGRESS` (relevé du 2026-10-11) ne reçoivent rien ; elles
restent visibles dans « Suivi des encodages » (relance manuelle possible). Activer de
préférence un matin avant 09h00 ; vérifier le premier tick ≥ 09h00 :
`tail /home/deploy/logs/encoding-reminders.log` → une ligne `due=… sent=… skipped=… failed=0`.

## 6. Retour arrière

- Désactiver d'abord les relances si activées : retirer la ligne crontab,
  `ENCODING_REMINDER_AUTO_ENABLED=0`, recréer php.
- Code : images `surgicalhub-{php,nginx}:rollback-12695c2` (re-tag en `latest` + `up -d`) ou
  `src_pre_deploy_*.tar.gz` + rebuild.
- Base : les 6 migrations sont additives ; le code 12695c2 fonctionne avec ces tables en plus.
  Restaurer le dump uniquement si une donnée a été altérée (backup-and-restore.md) ;
  `migrations:migrate prev`/`down()` supprimerait l'historique créé depuis
  (activity_event, notification_dispatch, mission_encoding_reminder) — à éviter sauf décision.
- Aucun tag `*-prod` pour un état restauré ; documenter l'incident.

## 7. Risques résiduels

- **Volume d'e-mails managers à J+4** : chaque mission en retard à J+4 alerte les managers/
  admins actifs opérationnels (4 en prod au 2026-10-11 : comptes techniques
  `@surgicalhub.internal` exclus, voir D-144 complément). Aucun rattachement manager ↔ site
  n'existe pour cibler davantage — à surveiller la première semaine.
- **10 missions SUBMITTED avec durée seule** (anciennes) : validables (D-143), valorisables
  (D-144). Les **5 missions sans aucune heure** ne sont ni validables ni valorisables tant
  qu'elles ne sont pas régularisées (checklist dans
  `incidents/2026-10-11-missions-soumises-sans-horaires.md`).
- **Push** : seuls 6 utilisateurs ont un appareil enregistré ; les autres recevront les
  relances J+1/J+2 par e-mail jusqu'à l'activation (invitation D-143).
- **Journal H1** : une écriture du journal en échec annule la transaction métier (choix
  D-126) ; interrupteur `ACTIVITY_JOURNAL_ENABLED=0` documenté.
- **H6–H8 non livrés** : l'historique est écrit mais pas encore consultable dans l'interface.
