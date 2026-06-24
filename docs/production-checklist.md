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

## 5. Déploiement

- [ ] Archive créée via `git archive --format=tar.gz HEAD -- backend frontend docker-compose.yml` (**jamais** `tar` sur le répertoire de travail)
- [ ] Archive transférée (`scp`) et extraite dans `/opt/stack/apps/surgicalhub/src/`
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
- [ ] Table "Historique des versions déployées" de `docs/production.md` mise à jour (nouvelle ligne ajoutée, aucune ligne existante modifiée)

## En cas de problème — rollback

- [ ] Restaurer le dump SQL (voir [`docs/backup-and-restore.md`](backup-and-restore.md))
- [ ] Remettre l'ancienne version du code depuis `src_pre_deploy_*.tar.gz`
- [ ] Rebuild + restart : `docker compose build --no-cache && docker compose up -d`
- [ ] Vérifier les logs après restart
- [ ] Ne pas créer de tag `*-prod` pour l'état restauré — documenter l'incident
