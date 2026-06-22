# SurgicalHub — Checklist de mise en production (VPS Docker)

Référence complète : [`docs/production.md`](production.md)
_(Ancienne version Hostinger : [`docs/production-hostinger.md`](production-hostinger.md))_

## Avant tout déploiement

- [ ] Tests PHP passés en local (`phpunit`)
- [ ] `npm run build` (frontend) sans erreur TypeScript
- [ ] Toutes les modifications commitées (`git status` clean)
- [ ] Backup DB : `/home/deploy/scripts/backup_mysql.sh` exécuté sur le serveur
- [ ] Migrations Doctrine relues (SQL inspecté avant exécution)

## Chaque mise à jour (VPS Docker)

- [ ] Archive des fichiers modifiés créée en local et transférée via `scp`
- [ ] Archive extraite dans `/opt/stack/apps/surgicalhub/src/`
- [ ] Build Docker : `docker compose build --no-cache` (dans `/opt/stack/apps/surgicalhub/`)
- [ ] Restart : `docker compose up -d`
- [ ] Migrations : `docker exec surgicalhub-php php bin/console doctrine:migrations:migrate --no-interaction --env=prod`
- [ ] Si routes 404 inattendus : `docker exec surgicalhub-php php bin/console cache:clear --env=prod && docker restart surgicalhub-php`

## Vérification post-déploiement

- [ ] `docker ps | grep surgicalhub` — 3 containers `Up`
- [ ] `https://surgicalhub.be` charge correctement (SSL valide)
- [ ] `https://api.surgicalhub.be/api/auth/login` répond (pas de 500)
- [ ] Login JWT fonctionnel (token JWT retourné)
- [ ] Login avec `rememberMe: false` → refresh token valable 1 jour
- [ ] Login avec `rememberMe: true` → refresh token valable 30 jours
- [ ] `/api/auth/refresh` renvoie un nouvel access token sans erreur CORS
- [ ] `/api/auth/logout` invalide bien le refresh token (un refresh ultérieur avec ce token échoue en 401)
- [ ] Pas de boucle 401 dans la console navigateur après expiration du refresh token
- [ ] `/api/me` retourne le bon rôle
- [ ] Routes admin fonctionnelles : `/api/admin/users`, `/api/admin/invitations`, `/api/admin/audit`
- [ ] `docker logs surgicalhub-php --tail 20` — pas d'erreur critique
- [ ] `docker logs surgicalhub-worker --tail 20` — worker actif
- [ ] `docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod` → « up to date »
- [ ] `https://api.surgicalhub.be/.env` → 404 (pas exposé)

## En cas de problème — rollback

- [ ] Restaurer le dump SQL (voir [`docs/backup-and-restore.md`](backup-and-restore.md))
- [ ] Remettre l'ancienne version du code dans `src/`
- [ ] Rebuild + restart : `docker compose build --no-cache && docker compose up -d`
- [ ] Vérifier les logs après restart
