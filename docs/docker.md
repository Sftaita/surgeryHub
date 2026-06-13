# Environnement Docker — SurgicalHub

Ce document décrit comment installer, lancer et utiliser l'environnement de
développement local de SurgicalHub via Docker Compose.

> Périmètre : développement local uniquement. Aucune donnée patiente ne doit
> transiter par cet environnement (base de données, fixtures, emails de test).

## 1. Prérequis

- **Docker Desktop** (avec Docker Compose v2 intégré — commande `docker compose`, sans tiret)
- **Git Bash** ou **WSL** recommandé sous Windows pour exécuter le `Makefile`
  (PowerShell / cmd.exe ne sont pas supportés par `make`)
- **make** disponible dans le shell utilisé (inclus avec Git Bash via
  `make` ou installable séparément, ex. `choco install make`)
- Ports hôte libres : `8080`, `5173`, `3308`, `6380`, `1026`, `8026`, `8081`

## 2. Premier lancement

```bash
make build      # build des images Docker (php, frontend node)
make up         # démarre tous les services en arrière-plan
make ps         # vérifie que les containers sont "running"/"healthy"
make migrate    # applique les migrations Doctrine (jamais automatique)
make doctor     # diagnostic complet de la stack
```

Au premier démarrage du container `php`, l'entrypoint :
- exécute `composer install` si `vendor/autoload.php` est absent (volume vide) ;
- génère le couple de clés JWT (`config/jwt/*.pem`) si absent.

Le container `frontend` exécute `npm install` puis `npm run dev -- --host`
au démarrage (peut prendre quelques minutes la première fois).

## 3. Accès aux services

| Service     | URL / Adresse                          | Notes |
|-------------|------------------------------------------|-------|
| API (Symfony via nginx) | http://localhost:8080            | Sert `backend/public/` |
| Frontend (React/Vite)   | https://localhost:5173 (ou http://, selon `vite.config.ts`) | Certificat auto-signé (plugin `basicSsl`) |
| Mailpit (UI emails)     | http://localhost:8026             | Capture tous les emails sortants |
| phpMyAdmin              | http://localhost:8081             | Démarrage manuel — `make tools-up` |
| MySQL                   | `localhost:3308`                  | Côté hôte. Côté containers : `mysql:3306` |
| Redis                   | `localhost:6380`                  | Côté hôte. Côté containers : `redis:6379` |

## 4. Makefile — commandes principales

Toutes les commandes sont listées avec `make help`.

| Commande | Description |
|---|---|
| `make help` | Affiche la liste des commandes disponibles et les ports |
| `make up` | Démarre tous les services en arrière-plan |
| `make down` | Arrête et supprime les containers (les volumes sont conservés) |
| `make restart` | `down` puis `up` |
| `make logs` | Suit les logs de tous les services (Ctrl+C pour arrêter) |
| `make ps` | Affiche l'état des containers |
| `make doctor` | Diagnostic complet (services, extensions PHP, vendor, npm, Redis…) |
| `make console cmd="about"` | Exécute une commande Symfony console |
| `make composer cmd="install"` | Exécute une commande Composer dans le container `php` |
| `make npm cmd="run build"` | Exécute une commande npm dans le container `frontend` |
| `make migrate` | Applique les migrations Doctrine en attente |
| `make migration-status` | Affiche le statut des migrations |
| `make test-backend` | Lance les tests PHPUnit |
| `make test-frontend` | Lance les tests Vitest (mode non interactif) |
| `make messenger` | Lance le consumer Messenger en interactif (verbose) |
| `make messenger-logs` | Suit les logs du worker Messenger en arrière-plan |
| `make redis-ping` | Vérifie que Redis répond (`PONG`) |
| `make tools-up` | Démarre les outils optionnels (phpMyAdmin) — profile `tools` |
| `make phpmyadmin-url` | Affiche l'URL de phpMyAdmin (`http://localhost:8081`) |
| `make dev-user` | Crée/met à jour le compte de connexion local de développement |
| `make reset-volumes CONFIRM=yes` | **[DANGER]** Supprime tous les volumes Docker (DB, caches…) |

## 5. Variables d'environnement

- **`DATABASE_URL`** : dans Docker, doit pointer vers le service `mysql` —
  `mysql://root:root@mysql:3306/surgicalhub?serverVersion=8.0.32&charset=utf8mb4`
  (défini dans `docker-compose.yml`, override la valeur de `backend/.env`
  qui pointe vers `127.0.0.1:3306` pour un usage WAMP hors Docker).
- **`REDIS_URL`** : dans Docker, doit pointer vers `redis://redis:6379`
  (le port `6380` n'est utilisé que pour les accès depuis l'hôte, ex. un
  client Redis local).
- **`MAILER_DSN`** : dans Docker, doit pointer vers `smtp://mailer:1025`
  (le port hôte `1026` permet d'envoyer des emails de test depuis l'hôte
  vers Mailpit si besoin).
- **`FRONTEND_URL`** : doit pointer vers l'URL du frontend tel
  qu'accessible depuis le navigateur (ex. `https://localhost:5173`), utilisé
  par le backend pour générer des liens (invitations, emails…).
- **Secrets** (`APP_SECRET`, `JWT_PASSPHRASE`, `SENTRY_DSN`, clés VAPID…) :
  ne doivent **jamais** être committés. Utiliser `.env.local` /
  `.env.local.php` (non versionnés) pour les valeurs réelles en local.
- **Clés JWT** (`config/jwt/private.pem` / `public.pem`) : générées
  automatiquement par l'entrypoint du container `php` si absentes — ne pas
  les committer.

## 6. Migrations

Les migrations Doctrine **ne sont jamais exécutées automatiquement** par
l'entrypoint ou au démarrage des containers. Elles doivent être lancées
explicitement :

```bash
make migrate            # applique les migrations en attente
make migration-status   # affiche le statut courant
```

> Sur une base fraîche, voir aussi la [section 15](#15-compte-de-connexion-local-dev)
> pour créer un compte de connexion une fois les migrations appliquées.

## 7. Emails locaux (Mailpit)

Mailpit capture tous les emails envoyés par l'application en développement —
aucun email n'est réellement délivré.

- SMTP interne (utilisé par `php` et `messenger`) : `mailer:1025`
- Interface web : http://localhost:8026

Utile pour visualiser les emails d'invitation instrumentiste, les
notifications, et pour tester les flux d'emailing sans dépendance externe.

## 8. Redis

Redis est utilisé comme **backend de cache Symfony**
(`cache.adapter.redis`, voir `backend/config/packages/cache.yaml`).

- **Messenger reste sur le transport Doctrine** (`doctrine://default`) — Redis
  ne sert pas de file de messages.
- Ping depuis l'hôte ou via Make :
  ```bash
  make redis-ping
  ```
- Adresses :
  - Côté hôte (debug avec `redis-cli`, RedisInsight, TablePlus…) : `localhost:6380`
  - Côté containers (`REDIS_URL`) : `redis:6379`

## 9. Messenger

Un container `messenger` dédié exécute en continu le consumer asynchrone :

```yaml
command: php bin/console messenger:consume async --limit=50 -vv
restart: unless-stopped
```

- Démarré **par défaut** avec `make up` / `docker compose up -d` (aucun
  profile Docker associé, pour rester simple à démarrer).
- Le transport reste **Doctrine** (`doctrine://default?auto_setup=0`),
  conformément à `backend/config/packages/messenger.yaml` (transport
  `failed` également sur Doctrine).
- Logs en continu :
  ```bash
  make messenger-logs
  ```
- Lancement interactif (debug, en plus du worker en arrière-plan) :
  ```bash
  make messenger
  ```

## 10. phpMyAdmin

phpMyAdmin **n'est pas démarré par défaut** — il est placé derrière le
profile Docker `tools` pour ne pas alourdir le démarrage standard.

```bash
make tools-up        # démarre phpMyAdmin (docker compose --profile tools up -d)
make phpmyadmin-url  # affiche http://localhost:8081
```

Connexion :
- **Serveur** : `mysql`
- **Utilisateur** : `root`
- **Mot de passe** : `root`
  (valeurs définies par `MYSQL_ROOT_PASSWORD` / `MYSQL_DATABASE` dans
  `docker-compose.yml`)

## 11. Volumes

| Volume | Contenu |
|---|---|
| `mysql_data` | Données de la base MySQL (`surgicalhub`) |
| `redis_data` | Données Redis (cache) |
| `vendor_data` | Dépendances Composer (`backend/vendor`) — évite la lenteur NTFS sous Windows |
| `node_modules_data` | Dépendances npm (`frontend/node_modules`) — idem |
| `composer_cache` | Cache de téléchargement Composer |
| `npm_cache` | Cache de téléchargement npm |

⚠️ `docker compose down -v` ou `make reset-volumes CONFIRM=yes` **supprime
tous ces volumes**, y compris `mysql_data` (la base de données complète) et
`redis_data`. À utiliser uniquement pour repartir d'un environnement vierge.
Après un reset, `composer install` et `npm install` sont relancés
automatiquement au prochain démarrage, mais les migrations doivent être
réappliquées manuellement (`make migrate`).

## 12. Tests

```bash
make test-backend    # PHPUnit (backend/)
make test-frontend   # Vitest, mode non interactif (frontend/)
make lint            # TypeScript type-check (tsc --noEmit)
```

Pour un build de production frontend (vérification) :

```bash
make npm cmd="run build"
```

## 13. Dépannage

- **Port déjà utilisé** (`8080`, `5173`, `3308`, `6380`, `1026`, `8026`, `8081`) :
  un autre service de l'hôte (WAMP, MySQL local, Redis local…) occupe le
  port. Arrêter ce service ou adapter le mapping de port dans
  `docker-compose.yml` (côté hôte uniquement, ex. `"3309:3306"`).
- **Conflit Redis sur le port 6379** : c'est volontaire — le port hôte est
  `6380` précisément pour éviter tout conflit avec un Redis local sur
  `6379`. Le port `6379` côté container reste interne au réseau Docker.
- **Mailpit SMTP `1026`/`1025`** : le SMTP Mailpit est exposé sur le port
  hôte `1026` (et non `1025`) pour éviter un conflit avec un serveur SMTP
  local. Côté containers, `php`/`messenger` utilisent toujours `mailer:1025`.
- **`vendor/autoload.php` manquant** : l'entrypoint du container `php`
  exécute `composer install` automatiquement si ce fichier est absent. Si le
  problème persiste : `make composer cmd="install"`.
- **`node_modules` manquant / vide** : le container `frontend` exécute
  `npm install` au démarrage. Si besoin de forcer : `make npm cmd="install"`.
- **Permissions Windows** : les répertoires `vendor/` et `node_modules/`
  sont stockés dans des volumes Docker nommés (et non en bind mount) pour
  éviter les problèmes de permissions et de lenteur NTFS. Le code source
  (`backend/`, `frontend/`) reste en bind mount pour le hot-reload.
- **phpMyAdmin inaccessible** : il n'est pas démarré par défaut. Lancer
  `make tools-up`, puis vérifier `make ps` (le service `phpmyadmin` doit
  apparaître).
- **Migrations non exécutées** : symptôme typique = erreurs SQL "table
  inconnue" au premier lancement. Lancer `make migrate` (jamais automatique).
- **Cache Symfony Redis indisponible hors Docker (WAMP)** : si Redis n'est
  pas lancé en local, soit démarrer un Redis local sur `6379`, soit
  commenter les lignes `app: cache.adapter.redis` /
  `default_redis_provider` dans `backend/config/packages/cache.yaml` pour
  retomber sur le cache filesystem (ne pas modifier ce fichier sans besoin —
  cette section est informative).

## 14. Sécurité / bonnes pratiques

- Aucune donnée patiente dans les fixtures, les seeds ou les jeux de tests —
  utiliser uniquement des données fictives.
- Ne jamais committer de secrets : `APP_SECRET`, `JWT_PASSPHRASE`,
  `SENTRY_DSN`, clés VAPID, etc. (utiliser `.env.local`, non versionné).
- Ne jamais committer les clés JWT générées (`config/jwt/private.pem`,
  `config/jwt/public.pem`).
- phpMyAdmin est un outil de **développement local uniquement** — ne jamais
  le déployer en production.
- Les ports MySQL et Redis exposés sur l'hôte (`3308`, `6380`) sont une
  commodité de développement. En production, ces services ne doivent pas
  être exposés publiquement de la même manière.
- La commande `app:create-dev-user` (section 15) est réservée au
  développement/tests : elle refuse de s'exécuter si `APP_ENV=prod`.

## 15. Compte de connexion local (dev)

Sur une base **fraîche** (après `make migrate`), la table `user` est vide —
aucun login n'est possible tant qu'aucun compte n'existe.

La commande `app:create-dev-user` crée (ou met à jour, si l'email existe déjà)
un compte de développement avec un mot de passe haché. Elle est **idempotente** :
la relancer ne crée jamais de doublon, elle met simplement à jour le mot de
passe/rôle de l'utilisateur existant.

```bash
make dev-user
# équivalent à :
docker compose exec php php bin/console app:create-dev-user
```

**Identifiants par défaut** :

| Champ | Valeur |
|---|---|
| Email | `admin@surgicalhub.local` |
| Mot de passe | `ChangeMe123!` |
| Rôle | `ROLE_MANAGER` (accès au dashboard manager) |
| Actif | oui |

Pour personnaliser (ex. créer un compte `ROLE_ADMIN` ou un autre email) :

```bash
docker compose exec php php bin/console app:create-dev-user \
  --email="autre@surgicalhub.local" \
  --password="AutreMotDePasse1!" \
  --role="ROLE_ADMIN"
```

⚠️ **Environnement local/dev uniquement** :
- la commande refuse de s'exécuter si `APP_ENV=prod` ;
- ne jamais utiliser `ChangeMe123!` ou ce compte en dehors d'un environnement
  de développement local ;
- aucune donnée patiente n'est créée par cette commande (uniquement un
  compte utilisateur applicatif).

Une fois le compte créé, connecte-toi sur https://localhost:5173 avec ces
identifiants.
