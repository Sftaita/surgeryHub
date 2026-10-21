# SurgicalHub — Checklist de mise en production (VPS Docker)

Procédure et règles complètes : [`docs/deployment-versioning.md`](deployment-versioning.md)
(**obligatoire, lire avant tout déploiement**). Commandes mécaniques :
[`docs/production.md`](production.md). _(Ancienne version Hostinger :
[`docs/production-hostinger.md`](production-hostinger.md))_

---

## 0. Rapport d'écart — AVANT toute action sur le serveur

- [ ] `git status` propre (rien de non commité dans `backend/`, `frontend/`, `docker-compose.yml`)
- [ ] `git log --oneline --decorate -10` → commit HEAD local identifié
- [ ] `git tag -l 'v*-prod' --sort=-creatordate | head -5` → dernier tag prod connu
- [ ] Commit actuellement déployé sur le serveur identifié (marqueur de fichier réel vérifié, pas une supposition)
- [ ] `doctrine:migrations:status --env=prod` exécuté sur le serveur → noter `Current`/`Executed Unavailable`/`New`
- [ ] Écart calculé (`git log --oneline <commit-serveur>..HEAD`) et rapport produit (gabarit §2.3 de `deployment-versioning.md`)
- [ ] **Anomalie DB-vs-code vérifiée explicitement** (la base est-elle plus avancée que ce que laisse supposer le commit déployé ?)

## 1. Règle anti-cherry-pick

- [ ] Si écart > 1 commit : commits manquants présentés à l'utilisateur, validation explicite obtenue avant de continuer
- [ ] Décision actée : déploiement de `HEAD` complet (jamais un sous-ensemble de fichiers)

## 2. Avant tout déploiement (qualité du code)

- [ ] Tests PHP passés en local (`phpunit`)
- [ ] `npm run build` / `vitest run` (frontend) sans erreur
- [ ] Toutes les modifications commitées

## 3. Sauvegardes

- [ ] Dump DB : `/home/deploy/scripts/backup_mysql.sh` exécuté — chemin noté
- [ ] Archive du code serveur actuel créée (`src_pre_deploy_*.tar.gz`) — chemin noté

## 4. Migrations — relecture avant exécution

- [ ] `doctrine:migrations:status --env=prod` (avant build)
- [ ] `doctrine:migrations:migrate --dry-run --env=prod` (après build, avant exécution réelle)
- [ ] SQL de chaque migration relu — `DROP`/`DELETE`/`TRUNCATE` confirmés présents uniquement dans `down()`, jamais `up()` (sauf cas documenté explicitement)
- [ ] Aucune migration exécutée sans avoir été relue
- [ ] **BLOQUANT (H1–H5, 2026-10-05)** — avant tout déploiement, comparer
  `doctrine_migration_versions`, les migrations du repository et le **schéma réel**. Écart connu
  en local : la base de dev contient le schéma de `Version20260107053952` (table `export_log`)
  sans que la version soit enregistrée. **Ne jamais lancer `doctrine:migrations:migrate` en
  aveugle si une divergence est détectée** ; aucun déploiement tant que l'écart n'est pas compris.
- [ ] **Diagnostic `Version20260107053952` / `export_log` (H5b, 2026-10-05 — lecture seule, rien
  modifié)** :
  - *La migration* : baseline complète (20 tables dont `export_log`, `user`, `mission`…), ajoutée
    le 2026-01-11 (commit `278864f`) **en remplacement** des baselines `Version20251225075014`,
    `Version20251230053638` / `060706` supprimées le même jour. Une base créée avant cette date
    contenait déjà ces tables : migration *recréée* (historique, documenté aussi par `752b83e`
    qui a neutralisé un doublon de cette baseline).
  - *Prod* : cohérente — 2026-09-29, `migrations:status` 72/72 avant, 0 nouvelle, 0
    indisponible, puis 2 migrations jouées (74). La baseline y est enregistrée.
  - *Tests (`surgicalhub_test`) et repro (`surgicalhub_repro`)* : cohérents — 68 versions
    enregistrées au même instant (2026-09-07 05:36:45, import / `version --add` en bloc), puis
    les suivantes une à une ; schémas identiques au dépôt.
  - *Dev (`surgicalhub`)* : **métadonnées perdues** — toutes les tables créées en une passe le
    2026-09-09 16:34:57 (reconstruction hors migrations : `schema:create` ou import), et
    `doctrine_migration_versions` ne contient que les versions jouées ensuite une par une
    (6 aujourd'hui, 73 « nouvelles »). Le schéma dev est identique à repro (colonnes + index)
    **sauf** `mission_publication.declined_at` : `Version20260926100000` n'a jamais été jouée en
    dev. `migrate` y échoue sur la première version non enregistrée (`export_log` existe déjà).
  - *Cause* : base locale reconstruite hors migrations (métadonnées perdues) — ni défaut de la
    migration, ni divergence de la prod.
  - *Procédure proposée (non exécutée, décision attendue)* — **prod**, avant le déploiement :
    sauvegarde ; `migrations:status` doit montrer exactement 74 exécutées, 0 indisponible et les
    5 nouvelles `Version20261003120000`, `Version20261004100000`, `Version20261005100000`,
    `Version20261006100000`, `Version20261007100000` ; `migrate --dry-run` ne doit lister
    qu'elles ; toute autre différence = arrêt. **Dev** : sauvegarde ;
    `migrations:execute --up Version20260926100000` ; puis `migrations:version --add` pour
    chacune des 73 autres versions, après vérification que son schéma est présent (comparaison
    `information_schema` avec repro) ; `migrations:status` doit alors être à jour. Ne jamais
    supprimer `export_log`, ni marquer une version exécutée sans cette comparaison.

## 5. Déploiement

- [ ] Archive créée via `git archive --format=tar.gz HEAD -- backend frontend docker-compose.yml` (**jamais** `tar` sur le répertoire de travail)
- [ ] Archive transférée (`scp`) et extraite dans `/opt/stack/apps/surgicalhub/src/`
- [ ] Fichiers supprimés par l'écart (`git diff --name-status --diff-filter=D <commit-serveur> HEAD`) retirés de `src/` (l'extraction ne les supprime pas)
- [ ] D-162 : `src/backend/REVISION` contient le SHA complet du commit validé (jamais `$Format:%H$`)
- [ ] Build Docker : `docker compose build --no-cache` → `BUILD_EXIT=0` confirmé
- [ ] Restart : `docker compose up -d` → 3 containers recréés
- [ ] Migrations exécutées : `doctrine:migrations:migrate --no-interaction --env=prod`
- [ ] `cache:clear --env=prod` puis `docker restart surgicalhub-php`
- [ ] `docker restart surgicalhub-worker` si des handlers Messenger ont changé

## 6. Tests santé obligatoires

- [ ] `docker ps | grep surgicalhub` — 3 containers `Up`
- [ ] `https://surgicalhub.be` charge correctement (200, SSL valide)
- [ ] `https://api.surgicalhub.be/api/auth/login` répond (400 attendu sans creds, jamais 500)
- [ ] `https://api.surgicalhub.be/.env` → 404 (pas exposé)
- [ ] Login JWT fonctionnel avec un compte de test jetable (créé via `app:user:create`, jamais un compte réel)
- [ ] `/api/me` retourne le bon rôle
- [ ] `docker logs surgicalhub-php --tail 30` — pas d'erreur/exception/critique liée au déploiement
- [ ] `docker logs surgicalhub-worker --tail 15` — worker actif, consumer démarré
- [ ] `doctrine:migrations:status --env=prod` → « Already at latest version »
- [ ] D-162 : `app:release:show` → commit exécuté = commit déployé, statut `PENDING_VALIDATION`

## 7. Test ciblé — si une fonctionnalité majeure a été modifiée

- [ ] Scénario réel exécuté via l'API publique (pas seulement `docker exec` interne)
- [ ] Comptes/données de test jetables nettoyés à la fin, nettoyage confirmé (requête vide)
- [ ] Chemins non testés (ex: action irréversible, email réel) documentés explicitement comme limite assumée

## 8. Rapport final obligatoire

- [ ] Version (tag prod + commit + date)
- [ ] Écart constaté avant déploiement (commit serveur avant, commits rattrapés)
- [ ] Sauvegardes (chemins exacts)
- [ ] Migrations (liste appliquée ou "Already at latest version")
- [ ] Containers (état)
- [ ] Tableau détaillé des tests santé
- [ ] Confirmation absence d'erreur dans les logs
- [ ] Limites assumées

## 9. Tag Git — après validation complète

- [ ] Commit déployé déjà présent sur `origin` (vérifié — sinon push `main`/branche d'abord)
- [ ] `git tag -a vYYYY.MM.DD-prod -m "..."` créé
- [ ] `git push origin vYYYY.MM.DD-prod`
- [ ] D-162 : `app:release:mark vYYYY.MM.DD-prod --commit=<sha>` exécuté sur le serveur
- [ ] D-162 : cohérence vérifiée — commit du tag = commit exécuté (`app:release:show`, statut `OFFICIAL`) = version affichée en bas de « Mon profil » / `GET /api/version`
- [ ] Après un rollback : `app:release:show` affiche la version restaurée
- [ ] Table "Historique des versions déployées" de `docs/production.md` mise à jour (nouvelle ligne ajoutée, aucune ligne existante modifiée)

## En cas de problème — rollback

- [ ] Choisir l'opération ([`docs/production.md`](production.md) §Rollback) : A. retour du code (par défaut, base inchangée), B. `down()` des migrations, C. restauration de la **seule** base `surgicalhub`
- [ ] Jamais de rejeu complet du dump `all_*.sql.gz` : MedAtWork, MedClick et les comptes MySQL ne sont jamais touchés (voir [`docs/backup-and-restore.md`](backup-and-restore.md))
- [ ] B ou C : instantané de sécurité de `surgicalhub` d'abord (Cas 0)
- [ ] Remettre les images `surgicalhub-{php,nginx}:rollback-<commit>` (sinon code depuis `src_pre_deploy_*.tar.gz` + rebuild), restart php et worker, **puis nginx**
- [ ] Vérifier les logs après restart
- [ ] Ne pas créer de tag `*-prod` pour l'état restauré — documenter l'incident
