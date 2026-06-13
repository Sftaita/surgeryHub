# SurgicalHub — Checklist de mise en production (Hostinger)

Référence complète : [`docs/production-hostinger.md`](production-hostinger.md)

## Avant tout déploiement

- [ ] Code review / tests passés en local (`phpunit`, `vitest`)
- [ ] `npm run build` (frontend) sans erreur
- [ ] `npm run preview` testé localement
- [ ] Toutes les modifications sont commitées et pushées (`git status` clean)
- [ ] Backup DB : `mysqldump` réalisé et stocké dans `~/backups/`
- [ ] Backup `backend/.env.prod.local` réalisé
- [ ] Migrations Doctrine relues (`doctrine:migrations:status --env=prod` + lecture du SQL des nouvelles migrations)

## Déploiement initial uniquement

- [ ] Dépôt cloné « en place » dans `~/domains/surgicalhub.be/` (clone dans
      un dossier temporaire puis déplacement du contenu, sans toucher à
      `public_html/` pré-existant — voir §13.2)
- [ ] `backend/.env.prod.local` créé depuis `.env.prod.local.example` et rempli
- [ ] `php bin/console lexik:jwt:generate-keypair --env=prod --overwrite` exécuté
- [ ] Base MySQL créée dans hPanel + `DATABASE_URL` renseignée
- [ ] SSL Let's Encrypt activé sur `surgicalhub.be` et `api.surgicalhub.be`
      (document roots `public_html` / `public_html/api` déjà imposés par
      Hostinger — rien à configurer côté routing)
- [ ] Cron Job `messenger:consume` configuré (chemin
      `~/domains/surgicalhub.be/backend`)

## Chaque mise à jour

- [ ] `git pull origin main` sur le serveur (`~/domains/surgicalhub.be`)
- [ ] `./deploy-hostinger.sh` exécuté (composer install, migrations,
      cache, permissions, synchronisation `public_html/api/` et
      `public_html/`)
- [ ] Build frontend local + `rsync` vers `frontend/dist/` sur le serveur
      (hors docroot, voir §13.5), puis `./deploy-hostinger.sh` pour
      synchroniser vers `public_html/`

## Vérification post-déploiement

- [ ] `https://surgicalhub.be` charge correctement (SSL valide)
- [ ] `https://api.surgicalhub.be` répond (pas de 500)
- [ ] `APP_ENV=prod` et `APP_DEBUG=0` confirmés
- [ ] Login + refresh token JWT fonctionnels
- [ ] Pas d'erreur CORS dans la console navigateur
- [ ] Routes SPA profondes (refresh navigateur) fonctionnent sans 404
- [ ] Upload/téléchargement fichiers (via `public_html/api/uploads`)
      fonctionnel
- [ ] `backend/var/log/prod.log` sans erreur critique
- [ ] `backend/var/log/messenger.log` montre des exécutions cron périodiques
- [ ] Sentry reçoit les erreurs prod (test ponctuel)
- [ ] `https://api.surgicalhub.be/.env` → 404 (pas exposé)
- [ ] `https://api.surgicalhub.be/config/` et `/src/` → 404 (pas exposés)
- [ ] `public_html/api/uploads` est bien un **symlink** vers
      `../../backend/public/uploads` (`ls -la public_html/api/`)
- [ ] `public_html/api/index.php` correspond à
      `backend/public/index.prod.php` (même contenu)
- [ ] `.htaccess` présents dans `public_html/` (fallback SPA) **et**
      `public_html/api/` (front controller Symfony)
- [ ] Aucun dossier `public_html/backend` ni `public_html/frontend`
      n'existe

## En cas de problème — rollback

- [ ] `git checkout <SHA_STABLE>` (`~/domains/surgicalhub.be`) +
      `composer install --no-dev --optimize-autoloader` (dans `backend/`)
- [ ] `cache:clear` + `cache:warmup --env=prod`
- [ ] `./deploy-hostinger.sh` pour resynchroniser `public_html/api/` et
      `public_html/`
- [ ] Restaurer `frontend/dist-previous/` si build problématique
- [ ] Restaurer le dump SQL si migration problématique (`mysql -u USER -p DBNAME < backup.sql`)
- [ ] Vérifier que `public_html/api/uploads` (symlink) et
      `backend/public/uploads/` (données réelles) sont intacts après le
      rollback
