# SurgicalHub — Mise en production sur Hostinger Cloud Startup

> **⚠️ OBSOLÈTE depuis 2026-06-16** — Le serveur de production est maintenant
> un **VPS Docker** (`deploy@187.124.55.15`). Ce document est conservé à des
> fins d'archive uniquement.
> 
> **Document actuel** : [`docs/production.md`](production.md)

_Cible : `https://surgicalhub.be` (frontend) + `https://api.surgicalhub.be` (API Symfony)_

Ce document décrit la stratégie complète de mise en production sur un
hébergement **Hostinger Cloud Startup** (hPanel, sans Docker natif), la
configuration nécessaire, les procédures de déploiement initial, de mise à
jour, de rollback, et la checklist de validation.

Voir aussi : [`docs/production-checklist.md`](production-checklist.md) pour
la checklist imprimable.

---

## Règles de mise en production obligatoires

Ces règles s'appliquent à **chaque** déploiement, sans exception :

1. **Toujours faire un backup avant mise à jour** — base de données (dump SQL)
   et `backend/.env.prod.local` (hors dépôt). Voir [§14](#14-procédure-de-mise-à-jour-ultérieure).
2. **Toujours lancer les migrations Doctrine avec prudence** — relire le SQL
   généré (`doctrine:migrations:migrate --dry-run` ou consultation du fichier
   de migration) avant d'exécuter, et exécuter en dehors des heures d'usage
   si la migration touche de gros volumes.
3. **Toujours vérifier `APP_ENV=prod` et `APP_DEBUG=0`** sur le serveur de
   production avant et après chaque déploiement.
4. **Ne jamais commiter de secrets** — `.env`, `.env.local`, `.env.prod.local`,
   `config/jwt/*.pem`, clés VAPID, DSN Sentry de prod restent hors Git.
5. **Ne jamais exposer le dossier racine Symfony** — seul `backend/public/`
   doit être un document root. `vendor/`, `src/`, `config/`, `var/`, `.env*`,
   `.git` ne doivent jamais être accessibles via HTTP.
6. **Ne jamais modifier directement la production sans commit Git** — toute
   modification de code passe par un commit + push + `git pull` sur le
   serveur. Pas d'édition manuelle de fichiers PHP/TS en prod.
7. **Toujours tester le build frontend avant upload** (`npm run build` sans
   erreur, vérification locale avec `npm run preview`).
8. **Toujours vider le cache Symfony après modification**
   (`cache:clear --env=prod` puis `cache:warmup --env=prod`).
9. **Toujours vérifier les logs après déploiement** (`var/log/prod.log` côté
   Symfony, logs Apache/PHP via hPanel).

---

## 0. Environnement vérifié (SSH, 2026-06-13)

Connexion testée avec succès (`ssh -p 65002 u245913739@91.108.115.96`,
clé `samy.ftaita89@gmail.com` déjà autorisée — voir
[`docs/ssh-setup.md`](ssh-setup.md)).

| Élément | Constat |
|---|---|
| Home | `/home/u245913739` |
| PHP CLI | `8.2.30` (`/opt/alt/php82`) ✓ |
| Composer | `2.9.8` ✓ |
| Git | `2.47.3` ✓ |
| Node / npm | absents ✗ — confirme le build frontend **local** |
| Extensions PHP | `gd, gmp, intl, pcntl, pdo_mysql, zip, opcache, redis` toutes présentes ✓ |
| Redis serveur | non démarré (`redis-cli` absent) — sans impact, cache = filesystem |
| `~/domains/surgicalhub.be/public_html/` | déjà créé par Hostinger (placeholder `default.php` à remplacer) |
| `api.surgicalhub.be` | sous-domaine **pas encore créé** (à faire via hPanel) |

Aucun point bloquant détecté (§19 obsolète sur ce point).

> ⚠️ **Contrainte confirmée (mise à jour 2026-06-13)** : Hostinger **impose**
> le document root `~/domains/surgicalhub.be/public_html` (non modifiable),
> et le sous-domaine `api.surgicalhub.be` est forcé sur le sous-dossier
> `~/domains/surgicalhub.be/public_html/api`. §1 ci-dessous est adapté en
> conséquence : Symfony complet reste dans `~/domains/surgicalhub.be/backend/`
> (hors `public_html`), seul son `public/` est synchronisé vers
> `public_html/api/`.

---

## 1. Structure des dossiers sur Hostinger

**Contrainte imposée par Hostinger** : le document root de `surgicalhub.be`
est fixé à `~/domains/surgicalhub.be/public_html` et celui de
`api.surgicalhub.be` au sous-dossier `~/domains/surgicalhub.be/public_html/api`
— **aucun de ces chemins n'est modifiable**.

On s'adapte donc en gardant Symfony et les sources React **hors de
`public_html`**, et en ne plaçant dans `public_html/` que les fichiers
strictement publics, synchronisés à chaque déploiement :

```
/home/u245913739/domains/surgicalhub.be/
├── backend/                    ← Symfony COMPLET — JAMAIS public
│   ├── public/                 ←   source des fichiers publics (synchronisée vers public_html/api/)
│   │   ├── index.php            (dev/Docker — non utilisé en prod)
│   │   ├── index.prod.php       → copié en public_html/api/index.php
│   │   ├── .htaccess             → copié en public_html/api/.htaccess
│   │   └── uploads/              → cible du symlink public_html/api/uploads
│   ├── src/, config/, var/, vendor/   (jamais exposés)
│   ├── .env, .env.prod.local          (jamais exposés, jamais commités)
│   └── config/jwt/*.pem               (générés sur le serveur)
├── frontend/
│   ├── dist/                   ← build local, synchronisé vers public_html/
│   └── src/, node_modules/...  (jamais exposés — pas de build sur le serveur)
├── docs/, docker/, ...         ← reste du dépôt (hors public_html)
├── .git/                       ← dépôt cloné EN PLACE (voir §13.2)
└── public_html/                ← DOCROOT IMPOSÉ — non versionné (.gitignore)
    ├── index.html              ←   copié depuis frontend/dist/
    ├── assets/                 ←   "
    ├── .htaccess               ←   généré (fallback SPA, exclut /api)
    └── api/                    ←   DOCROOT IMPOSÉ du sous-domaine api.surgicalhub.be
        ├── index.php           ←   copié depuis backend/public/index.prod.php
        ├── .htaccess           ←   copié depuis backend/public/.htaccess
        └── uploads/            ←   SYMLINK -> ../../backend/public/uploads
```

Règles respectées :
- **Jamais** `public_html/backend` ni `public_html/frontend`.
- `public_html/` ne contient que le build React (`index.html`, `assets/`,
  `.htaccess`) + le sous-dossier `api/`.
- `public_html/api/` ne contient que l'entrée publique Symfony
  (`index.php`, `.htaccess`, `uploads/`) — jamais `vendor/`, `src/`,
  `config/`, `var/`, `.env*`.
- Le vrai backend Symfony reste dans `~/domains/surgicalhub.be/backend/`.

> `public_html/` est créé par Hostinger et contient déjà des fichiers
> (`default.php`, `DO_NOT_UPLOAD_HERE`) au moment du `git clone` — il est donc
> ajouté à `.gitignore` (racine du repo, déjà fait dans ce commit) et **n'est
> jamais versionné**. Son contenu est entièrement régénéré par
> `deploy-hostinger.sh` à partir de `backend/public/` et `frontend/dist/`.

---

## 2. Séparation frontend / backend

- **Frontend** : buildé **en local** (ou CI) avec `npm run build`, le
  contenu de `frontend/dist/` est synchronisé vers `public_html/` (racine,
  hors `api/`) par `deploy-hostinger.sh`.
- **Backend** : Symfony complet vit dans `~/domains/surgicalhub.be/backend/`
  (déployé via `git pull` + `composer install --no-dev`). Seul son
  `public/` (adapté) est synchronisé vers `public_html/api/`, qui est le
  document root **imposé** du sous-domaine `api.surgicalhub.be`.
- Aucune communication directe entre les deux : le frontend appelle l'API
  via `VITE_API_BASE_URL=https://api.surgicalhub.be` (CORS configuré côté
  Symfony pour n'autoriser que `https://surgicalhub.be`).

---

## 3. Configuration des domaines

Document roots **imposés par Hostinger** (non modifiables) :

| Domaine | Document root (imposé) | Contenu (généré par `deploy-hostinger.sh`) |
|---|---|---|
| `surgicalhub.be` (+ `www.surgicalhub.be` en redirection) | `~/domains/surgicalhub.be/public_html` | build React (`frontend/dist/`) |
| `api.surgicalhub.be` (sous-domaine) | `~/domains/surgicalhub.be/public_html/api` | entrée publique Symfony (`backend/public/`) |

Étapes (hPanel) :
1. `api.surgicalhub.be` est déjà créé et pointe sur `public_html/api` —
   rien à changer côté hPanel pour le routing.
2. Activer **SSL gratuit (Let's Encrypt)** sur `surgicalhub.be` et
   `api.surgicalhub.be` (hPanel > SSL) — obligatoire pour les cookies/Bearer
   JWT en HTTPS et pour CORS `https://`.
3. Le routing SPA (`public_html/.htaccess`) et l'entrée Symfony
   (`public_html/api/.htaccess` + `index.php`) sont générés par
   `deploy-hostinger.sh` — voir §9 et §17.

---

## 4. Configuration Symfony production

Fichiers concernés (déjà adaptés dans ce commit) :

- `backend/config/packages/cache.yaml` — adapter par défaut = **filesystem**
  (plus de dépendance Redis obligatoire ; Redis reste utilisé en dev/Docker
  via `when@dev`).
- `backend/config/packages/nelmio_cors.yaml` — origine CORS pilotée par la
  variable d'env `CORS_ALLOW_ORIGIN` (regex), au lieu de `localhost:5173`
  en dur.
- `backend/config/packages/messenger.yaml` — transport Doctrine
  (`doctrine://default`), pas de RabbitMQ/Redis requis.
- `backend/.env.prod.local.example` — template des variables de prod
  (voir [§7](#7-fichiers-env)).

Sur le serveur :

```bash
APP_ENV=prod
APP_DEBUG=0
```

doivent être effectifs via `backend/.env.prod.local` (jamais via
`backend/.env`, qui reste le fichier de **defaults dev**, commité).

### Clés JWT (Lexik)

Le repo ignore `config/jwt/*.pem` (clé privée/publique JWT). En prod, on
génère une paire **dédiée** :

```bash
cd ~/domains/surgicalhub.be/backend
php bin/console lexik:jwt:generate-keypair --env=prod --overwrite
```

La passphrase utilisée doit être renseignée dans `JWT_PASSPHRASE` de
`.env.prod.local` (cf template).

### Messenger (file d'attente async)

Le transport `async` est backé par la table Doctrine `messenger_messages`
(`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`). En l'absence de
process worker permanent (non disponible sur Cloud Startup), on consomme la
file via un **Cron Job Hostinger** (hPanel > Avancé > Cron Jobs) :

```
*/5 * * * * cd /home/u245913739/domains/surgicalhub.be/backend && php bin/console messenger:consume async --time-limit=240 --memory-limit=128M --env=prod >> var/log/messenger.log 2>&1
```

Toutes les 5 minutes, consomme pendant max 4 minutes — évite les overlaps et
les processus zombies.

---

## 5. Configuration React/Vite production

- `frontend/vite.config.ts` : aucun changement nécessaire (le proxy `/api`
  ne s'applique qu'à `vite dev`, pas au build).
- `frontend/.env.production` (créé, **commité** — ce sont des valeurs
  publiques, pas des secrets) :

  ```env
  VITE_API_BASE_URL=https://api.surgicalhub.be
  VITE_SENTRY_DSN="https://...@o.../...."
  ```

  Vite charge automatiquement `.env.production` quand `npm run build` est
  lancé (mode `production` par défaut).
- `apiClient.ts` utilise déjà `baseURL: import.meta.env.VITE_API_BASE_URL`
  (`frontend/src/app/api/apiClient.ts`) — aucune modification de code requise.
- Build :

  ```bash
  cd frontend
  npm ci
  npm run build        # -> frontend/dist/
  ```

  Vérifier en local avant upload :

  ```bash
  npm run preview
  ```

---

## 6. Base de données MySQL Hostinger

1. hPanel > Bases de données > MySQL : créer une base (ex. `u245913739_surgicalhub`)
   et un utilisateur dédié avec mot de passe fort.
2. Récupérer : nom d'hôte (généralement `localhost`), nom de la base,
   utilisateur, mot de passe, version MySQL affichée dans hPanel.
3. Renseigner `DATABASE_URL` dans `backend/.env.prod.local` :

   ```env
   DATABASE_URL="mysql://USER:PASSWORD@localhost:3306/DBNAME?serverVersion=8.0.32&charset=utf8mb4"
   ```

   Adapter `serverVersion` à la version réelle indiquée par Hostinger.

---

## 7. Fichiers `.env` / `.env.local` / `.env.prod.local`

| Fichier | Commité ? | Rôle |
|---|---|---|
| `backend/.env` | ✅ oui | defaults dev (committed) — **ne pas modifier pour la prod** |
| `backend/.env.local` | ❌ non | overrides locales (WAMP) — non utilisé en prod |
| `backend/.env.prod.local.example` | ✅ oui | **template** de prod, à copier sur le serveur |
| `backend/.env.prod.local` | ❌ jamais | vraies valeurs de prod, créé **uniquement sur le serveur** |
| `frontend/.env` | ✅ oui | defaults dev |
| `frontend/.env.production` | ✅ oui | valeurs publiques de build prod (URL API, DSN Sentry front) |

Sur le serveur, première installation :

```bash
cd ~/domains/surgicalhub.be/backend
cp .env.prod.local.example .env.prod.local
nano .env.prod.local   # remplir DATABASE_URL, APP_SECRET, JWT_PASSPHRASE, CORS_ALLOW_ORIGIN, ...
```

> ⚠️ **Constat sécurité actuel** : `backend/.env` et `frontend/.env` commités
> contiennent aujourd'hui de vraies valeurs sensibles (DSN Sentry, clés
> VAPID, `JWT_PASSPHRASE` de dev). Ce ne sont pas des secrets de **prod**
> (le `.env.prod.local` doit utiliser des valeurs **différentes**), mais il
> est recommandé de :
> - régénérer une paire de clés VAPID dédiée prod,
> - créer un projet Sentry dédié prod (DSN différent du DSN dev),
> - ne **jamais** copier `backend/.env` vers `.env.prod.local` — toujours
>   repartir du fichier `.example`.

---

## 8. Migrations Doctrine

```bash
cd ~/domains/surgicalhub.be/backend
# 1. Voir ce qui sera exécuté (lecture du SQL, AUCUNE écriture)
php bin/console doctrine:migrations:status --env=prod
php bin/console doctrine:migrations:migrate --dry-run --env=prod

# 2. Backup avant exécution réelle (voir §14)

# 3. Exécution réelle
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

Ne jamais exécuter `doctrine:schema:update` en prod (uniquement des
migrations versionnées).

---

## 9. Gestion des assets

Tous les fichiers ci-dessous sont (re)générés automatiquement dans
`public_html/` et `public_html/api/` par `deploy-hostinger.sh` (voir §17) —
aucune édition manuelle de `public_html/` n'est nécessaire ni souhaitable.

### `public_html/` (frontend, docroot `surgicalhub.be`)

- **Contenu** : `frontend/dist/` (HTML/JS/CSS/icônes/manifest), synchronisé
  par `rsync --delete` (en excluant `api/` et `.htaccess`).
- **`public_html/.htaccess`** (généré par le script) : fallback SPA pour
  React Router, **sans jamais réécrire `/api/...`** :

  ```apache
  <IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Ne jamais réécrire les requêtes destinées au sous-dossier /api
    RewriteCond %{REQUEST_URI} ^/api(/|$)
    RewriteRule ^ - [L]

    # Fichiers/dossiers réels (assets buildés) servis tels quels
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Fallback SPA : toute autre route -> index.html (React Router)
    RewriteRule . /index.html [L]
  </IfModule>
  ```

### `public_html/api/` (entrée publique Symfony, docroot `api.surgicalhub.be`)

- **`index.php`** : copie de `backend/public/index.prod.php`, qui charge le
  runtime Symfony depuis `../../backend/vendor/autoload_runtime.php` (voir
  [§1](#1-structure-des-dossiers-sur-hostinger) et le commentaire du fichier
  source).
- **`.htaccess`** : copie conforme de `backend/public/.htaccess` (règles
  Symfony Flex standard — front controller `index.php`, blocage de l'accès
  direct aux autres fichiers PHP).
- **`uploads/`** : **symlink** vers `../../backend/public/uploads` — permet
  de servir les fichiers uploadés (PDFs générés, documents...) sans les
  dupliquer ni les perdre lors d'un `rsync --delete` de `backend/public/` →
  `public_html/api/`. Le dossier réel `backend/public/uploads/` est
  **persistant** et n'est jamais supprimé par le script.

### Cache Symfony

- `backend/var/cache/prod` est régénéré par `cache:warmup --env=prod` à
  chaque déploiement (voir §13/§17) — n'est jamais synchronisé vers
  `public_html/`.

---

## 10. Permissions fichiers

```bash
cd ~/domains/surgicalhub.be/backend
chmod -R 755 var/cache var/log var/share public/uploads
# Le PHP-FPM Hostinger tourne sous votre utilisateur SSH : pas de chown nécessaire.
chmod 600 .env.prod.local config/jwt/*.pem
```

- `var/`, `public/uploads/` : writable par PHP (755 suffit car même
  utilisateur que PHP-FPM sur Hostinger).
- `.env.prod.local` et les clés JWT : `600` (lecture/écriture propriétaire
  uniquement).
- Aucun fichier PHP ne doit être en `777`.
- `public_html/api/uploads` est un **symlink** vers
  `../../backend/public/uploads` (voir §9) — les permissions du dossier
  réel (`backend/public/uploads`, `755`) s'appliquent ; le symlink lui-même
  n'a pas de permissions distinctes à gérer.

---

## 11. Règles de sécurité

- `public_html/api/.htaccess` (copie de `backend/public/.htaccess`, Symfony
  par défaut) bloque déjà l'accès direct aux fichiers PHP hors `index.php`
  — vérifié présent après déploiement.
- `public_html/api/index.php` (copie de `backend/public/index.prod.php`) ne
  fait que charger `backend/vendor/autoload_runtime.php` — aucune logique
  applicative n'est exposée dans `public_html/`.
- Le document root **imposé** de `api.surgicalhub.be` =
  `public_html/api/` (uniquement `index.php`, `.htaccess`, symlink
  `uploads/`) → `.env`, `vendor/`, `src/`, `config/`, `migrations/`, `var/`
  vivent dans `~/domains/surgicalhub.be/backend/` et ne sont **jamais**
  copiés dans `public_html/` par `deploy-hostinger.sh` (§17) : ils sont donc
  **physiquement hors** de tout document root, inaccessibles en HTTP (pas
  seulement bloqués par `.htaccess`).
- De même, `~/domains/surgicalhub.be/frontend/` (sources, `node_modules/`)
  n'est jamais copié dans `public_html/` — seul `frontend/dist/` l'est.
- `CORS_ALLOW_ORIGIN='^https://surgicalhub\.be$'` — seule l'origine front prod
  peut appeler l'API (pas `*`, pas `localhost`).
- HTTPS obligatoire (Let's Encrypt) sur les deux domaines.
- `APP_DEBUG=0` impérativement — sinon fuite de stack traces/secrets via les
  pages d'erreur Symfony.
- Sentry (`SENTRY_DSN`) : dédié prod, erreurs uniquement (`level: error`),
  pas de PII dans les events (déjà configuré via `monolog.yaml` `when@prod`).
- Rotation des secrets dev committés (`JWT_PASSPHRASE`, VAPID, Sentry DSN)
  recommandée à terme — voir avertissement §7.

---

## 12. Connexion SSH

```bash
ssh -p 65002 u245913739@91.108.115.96
```

> Conservez cette commande hors des dépôts/docs publics si possible. Elle
> est reproduite ici car fournie explicitement pour cette procédure.

Une fois connecté (vérifié le 2026-06-13, voir [§0](#0-environnement-vérifié-ssh-2026-06-13)) :

```bash
cd ~
pwd                 # /home/u245913739
php -v              # PHP 8.2.30 ✓
composer --version  # Composer 2.9.8 ✓
git --version       # git 2.47.3 ✓
```

`composer` et `git` sont directement disponibles dans le `$PATH` — aucun
fallback `composer.phar` nécessaire sur ce compte.

---

## 13. Procédure de déploiement initial

### 13.1 Préparation locale (frontend)

```bash
cd frontend
npm ci
npm run build
npm run preview        # vérification visuelle locale avant upload
```

### 13.2 Côté serveur — clone du dépôt « en place »

`~/domains/surgicalhub.be/public_html/` existe déjà (créé par Hostinger,
non vide : `default.php`, `DO_NOT_UPLOAD_HERE`), donc un `git clone` direct
dans `~/domains/surgicalhub.be/` échouerait (dossier non vide). On clone
dans un dossier temporaire puis on déplace le contenu du dépôt **vers le
haut**, en laissant `public_html/` intact (il est de toute façon dans
`.gitignore`) :

```bash
ssh -p 65002 u245913739@91.108.115.96
cd ~
git clone REPOSITORY_URL surgicalhub-tmp

# Déplacer tout le contenu du dépôt (y compris .git/) dans
# ~/domains/surgicalhub.be/, sans toucher à public_html/ existant
shopt -s dotglob
mv surgicalhub-tmp/* surgicalhub-tmp/.git ~/domains/surgicalhub.be/
shopt -u dotglob
rmdir surgicalhub-tmp

cd ~/domains/surgicalhub.be
git status   # vérifie que public_html/ reste ignoré et non suivi
```

Résultat : `~/domains/surgicalhub.be/` contient `backend/`, `frontend/`,
`docs/`, `.git/`, `deploy-hostinger.sh`, ... **et** `public_html/`
(pré-existant, non versionné) — exactement la structure du §1. Les mises à
jour suivantes se font avec un simple `git pull` (§14).

### 13.3 Backend — install & configuration

```bash
cd ~/domains/surgicalhub.be/backend
composer install --no-dev --optimize-autoloader

cp .env.prod.local.example .env.prod.local
nano .env.prod.local
# -> APP_SECRET, DATABASE_URL, CORS_ALLOW_ORIGIN, JWT_PASSPHRASE,
#    MAILER_DSN, SENTRY_DSN, VAPID_*, FRONTEND_URL

php bin/console lexik:jwt:generate-keypair --env=prod --overwrite

php bin/console doctrine:migrations:status --env=prod
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

chmod -R 755 var/cache var/log var/share public/uploads
chmod 600 .env.prod.local config/jwt/*.pem
```

### 13.4 Domaines (hPanel)

`surgicalhub.be` (→ `public_html`) et `api.surgicalhub.be` (→
`public_html/api`) existent déjà avec leurs document roots **imposés**
(§3) — rien à créer ni reconfigurer côté routing. Seule action restante :

1. Activer SSL (Let's Encrypt) sur `surgicalhub.be` et `api.surgicalhub.be`
   (hPanel > SSL).

### 13.5 Frontend — build local puis synchronisation

Le build React n'est **jamais** uploadé directement dans `public_html/` :
il est uploadé vers `frontend/dist/` (hors docroot), puis
`deploy-hostinger.sh` le synchronise vers `public_html/` (§17).

Depuis votre machine locale (après `npm run build`) :

```bash
cd frontend
npm ci
npm run build
npm run preview        # vérification visuelle locale avant upload

rsync -avz --delete \
  -e "ssh -p 65002" \
  dist/ u245913739@91.108.115.96:~/domains/surgicalhub.be/frontend/dist/
```

(Si `rsync` n'est pas dispo sous Windows : utiliser WinSCP/FileZilla en
SFTP, ou `scp -P 65002 -r dist/* u245913739@91.108.115.96:~/domains/surgicalhub.be/frontend/dist/`.)

Puis, côté serveur, lancer `./deploy-hostinger.sh` (§17) qui synchronise
`frontend/dist/` → `public_html/` et génère `public_html/.htaccess` (SPA,
§9).

### 13.6 Cron Messenger

hPanel > Avancé > Cron Jobs > ajouter :

```
*/5 * * * * cd /home/u245913739/domains/surgicalhub.be/backend && php bin/console messenger:consume async --time-limit=240 --memory-limit=128M --env=prod >> var/log/messenger.log 2>&1
```

### 13.7 Vérification

Voir [§15](#15-checklist-de-validation-après-mise-en-production).

---

## 14. Procédure de mise à jour ultérieure

```bash
# 1. BACKUP (toujours avant toute mise à jour)
ssh -p 65002 u245913739@91.108.115.96
cd ~/domains/surgicalhub.be/backend
mkdir -p ~/backups
mysqldump -u DBUSER -p DBNAME > ~/backups/surgicalhub_$(date +%Y%m%d_%H%M%S).sql
cp .env.prod.local ~/backups/env.prod.local.$(date +%Y%m%d_%H%M%S).bak

# 2. Pull du code (jamais d'édition manuelle en prod)
cd ~/domains/surgicalhub.be
git pull origin main

# 3. Backend + synchronisation public_html/api/ + public_html/
#    (composer install, migrations avec confirmation, cache, rsync, .htaccess)
./deploy-hostinger.sh

# 4. Vérifications
tail -n 50 backend/var/log/prod.log
```

```bash
# 5. Frontend (en local, AVANT ou APRÈS le backend selon compat API)
cd frontend
npm ci
npm run build
npm run preview   # vérification locale

rsync -avz --delete -e "ssh -p 65002" \
  dist/ u245913739@91.108.115.96:~/domains/surgicalhub.be/frontend/dist/

# Puis, côté serveur, relancer ./deploy-hostinger.sh pour synchroniser
# frontend/dist/ -> public_html/ (sauf si déjà fait à l'étape 3 avant
# l'upload du nouveau build — dans ce cas relancer une 2e fois).
```

---

## 15. Checklist de validation après mise en production

Voir [`docs/production-checklist.md`](production-checklist.md) — copie
rapide ici :

- [ ] `https://surgicalhub.be` charge le frontend (SSL valide, pas de mixed content)
- [ ] `https://api.surgicalhub.be/api/...` répond (ex. endpoint public/login)
- [ ] Login JWT fonctionne (génération + refresh token)
- [ ] CORS : pas d'erreur CORS dans la console navigateur depuis `surgicalhub.be`
- [ ] `APP_ENV=prod` et `APP_DEBUG=0` confirmés (`php bin/console debug:container --env=prod | head -1` ou page d'erreur 404 sans stack trace)
- [ ] Migrations à jour : `doctrine:migrations:status --env=prod` → "up to date"
- [ ] `var/log/prod.log` : pas d'erreurs critiques après les premières requêtes
- [ ] Routes SPA (refresh sur une route profonde, ex. `/app/m/missions/1`) fonctionnent (pas de 404 Apache)
- [ ] Upload/téléchargement de fichiers (`public/uploads/`) fonctionne
- [ ] Cron messenger actif : `var/log/messenger.log` montre des exécutions périodiques
- [ ] Sentry reçoit bien les erreurs prod (test avec une erreur volontaire puis suppression)
- [ ] Aucun fichier sensible accessible : `https://api.surgicalhub.be/.env` → 404, `https://api.surgicalhub.be/config/` → 404

---

## 16. Procédure de rollback simple

### Code (backend + frontend)

```bash
cd ~/domains/surgicalhub.be
git log --oneline -5          # identifier le dernier commit stable
git checkout <SHA_STABLE>      # ou: git reset --hard <SHA_STABLE> si déjà pull

cd backend
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
cd ..
./deploy-hostinger.sh   # resynchronise public_html/api/ et public_html/
```

Pour le frontend, si le build précédent a été conservé localement :

```bash
rsync -avz --delete -e "ssh -p 65002" \
  dist-previous/ u245913739@91.108.115.96:~/domains/surgicalhub.be/frontend/dist/
```

> Conseil : avant chaque upload, archiver l'ancien `dist/` côté serveur :
> `mv ~/domains/surgicalhub.be/frontend/dist ~/domains/surgicalhub.be/frontend/dist-previous`.

> ℹ️ Les fichiers uploadés (`backend/public/uploads/`, accessibles via le
> symlink `public_html/api/uploads`) ne sont **jamais** affectés par un
> rollback de code : ils survivent à `git checkout`/`reset` et aux
> resynchronisations de `deploy-hostinger.sh` (qui exclut `uploads/` du
> `rsync --delete`, voir §9/§17).

### Base de données

```bash
# Restauration depuis le dernier backup (§14)
mysql -u DBUSER -p DBNAME < ~/backups/surgicalhub_YYYYMMDD_HHMMSS.sql
```

> Si une migration a été appliquée et doit être annulée, utiliser
> `php bin/console doctrine:migrations:migrate prev --env=prod` **avant**
> de restaurer le dump si possible — sinon restaurer le dump règle l'état
> des données mais pas la table `doctrine_migration_versions` (à corriger
> manuellement si besoin).

---

## 17. Script `deploy-hostinger.sh`

Le script `deploy-hostinger.sh` (racine du repo) automatise, **côté
serveur**, à la fois la mise à jour du backend et la synchronisation vers
les document roots imposés par Hostinger :

```bash
ssh -p 65002 u245913739@91.108.115.96
cd ~/domains/surgicalhub.be
git pull origin main
./deploy-hostinger.sh
```

Étapes effectuées :

1. **Vérifications** : `backend/.env.prod.local` existe, `APP_ENV=prod`,
   pas de `APP_DEBUG=1` — sinon arrêt (`set -e`).
2. **Backend** : `composer install --no-dev --optimize-autoloader`, affiche
   le statut des migrations et demande confirmation avant
   `doctrine:migrations:migrate --env=prod`, puis
   `cache:clear`/`cache:warmup --env=prod` et permissions
   (`var/`, `public/uploads/`, `.env.prod.local`, `config/jwt/*.pem`).
3. **`public_html/api/`** : `rsync -a --delete` de `backend/public/` vers
   `public_html/api/` (en excluant `uploads/`, `index.php`,
   `index.prod.php`), copie de `index.prod.php` → `index.php`, et création/
   réparation du symlink `public_html/api/uploads -> ../../backend/public/uploads`.
4. **`public_html/`** : si `frontend/dist/` existe, `rsync -a --delete`
   (en excluant `api/` et `.htaccess`) vers `public_html/`, puis génération
   de `public_html/.htaccess` (fallback SPA, exclut `/api`, voir §9).
5. Affiche les 20 dernières lignes de `backend/var/log/prod.log`.

Le script ne fait **pas** le backup DB ni `npm run build` — voir §13/§14
pour la procédure complète (backup, migrations, build frontend).

---

## 18. Compatibilité vérifiée

| Composant | Requis | Constat sur le serveur (2026-06-13) |
|---|---|---|
| PHP | `>= 8.2` (composer.json) | **8.2.30** ✓ — `ctype, iconv, pdo_mysql, intl, zip, gd, gmp, opcache, pcntl` tous présents ✓ |
| Node (build local) | `>= 20.19` ou `>= 22.12` (requis par Vite 7 / TS 5.9) | absent sur le serveur ✓ (build **local** confirmé nécessaire) |
| Composer | 2.x, avec `--no-dev` | **2.9.8**, dans le `$PATH` ✓ |
| MySQL | 8.0.x (ou MariaDB 10.11+) | `mysql`/`mysqldump` présents ✓ — version DB à vérifier via hPanel |
| Redis | optionnel | extension `redis` chargée mais aucun serveur Redis actif — sans impact (cache filesystem) |
| SSH | oui | ✓ accès confirmé (`u245913739@91.108.115.96:65002`) |
| npm sur le serveur | non | absent — build frontend toujours local |

---

## 19. Points bloquants potentiels

- ~~Pas de Node/npm~~ → confirmé absent, résolu : build local + upload `dist/`.
- ~~Pas de Redis~~ → confirmé, résolu : cache filesystem par défaut en prod.
- ~~Extension `gmp`/`intl` absente~~ → **vérifié présentes**, non bloquant.
- **Pas de worker permanent pour Messenger** → résolu : cron
  `messenger:consume --time-limit`.
- **Document root imposé par Hostinger** (`public_html`, `public_html/api`,
  non modifiables) → architecture **définitive** (pas un repli) : Symfony et
  les sources React vivent hors `public_html/`, et `deploy-hostinger.sh`
  synchronise uniquement les fichiers publics — voir
  [§1](#1-structure-des-dossiers-sur-hostinger).
- **`composer install` mémoire/timeout sur shared hosting** → si timeout,
  augmenter `memory_limit` temporairement (`php -d memory_limit=512M
  composer install ...`) ou utiliser `--prefer-dist`.

---

## 20. Plan B — si Cloud Startup est insuffisant

Si une limitation bloquante apparaît (ex. extension PHP manquante de façon
définitive, CPU/mémoire insuffisants pour `composer install`, besoin d'un
worker Messenger permanent ou de Redis) :

1. **Hostinger VPS** (KVM) : accès root complet, Docker natif possible →
   réutilisation directe de `docker-compose.yml` existant (adapté prod :
   désactiver les volumes bind de dev, ajouter Traefik/Nginx + Let's
   Encrypt).
2. Le découpage **frontend statique / API séparée / DB MySQL** reste
   identique — seule la couche d'hébergement change. Les fichiers `.env.prod.local.example`,
   `nelmio_cors.yaml`, `cache.yaml` restent valides (le profil `when@dev`
   Redis peut alors être réactivé en prod si Redis est dispo sur le VPS).
3. Migration possible **sans interruption longue** : DNS `surgicalhub.be` /
   `api.surgicalhub.be` pointés vers le nouvel hôte une fois la DB migrée
   (export/import `mysqldump`) et le code déployé.
