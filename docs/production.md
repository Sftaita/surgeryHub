# SurgicalHub — Production (VPS Docker)

_Serveur actuel : VPS Ubuntu 24.04.4 LTS — `deploy@187.124.55.15`_
_Mis en service : 2026-06-16 (remplace l'hébergement Hostinger)_

> ⚠️ **Avant tout déploiement, suivre obligatoirement
> [`docs/deployment-versioning.md`](deployment-versioning.md)** (rapport
> d'écart, règle anti-cherry-pick, sauvegardes, tag de version). Ce fichier
> ne contient que les commandes mécaniques ; la procédure et ses règles de
> sécurité sont dans `deployment-versioning.md`.

Voir aussi : [`docs/backup-and-restore.md`](backup-and-restore.md) · [`docs/production-checklist.md`](production-checklist.md)

---

## Version actuelle de production

**Dernier tag déployé : voir `git tag -l 'v*-prod' --sort=-creatordate | head -1`.**
Ne jamais supposer la version sans vérifier — voir
[`docs/deployment-versioning.md`](deployment-versioning.md) §2.2 pour la
procédure de vérification réelle (le tag seul ne suffit pas, il faut un
marqueur de fichier confirmé sur le serveur).

### Historique des versions déployées

_Ajouter une ligne en haut après chaque déploiement validé. Ne jamais
réécrire une ligne existante — c'est un historique._

| Tag | Commit | Date | Notes |
|---|---|---|---|
| _(à créer)_ | `bae8ec1` | 2026-06-24 | Lot absences isolées + alertes chevauchantes + rattrapage Planning V2 launch (8296e70) et règles site-membership (eb1fa15). Déployé et validé par tests santé ; tag pas encore créé/poussé au moment de la rédaction — voir §7 de `deployment-versioning.md`. |

---

## Connexion au serveur

```bash
ssh deploy@187.124.55.15
```

Aucun port personnalisé, clé SSH standard. La clé publique autorisée est
`ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMDcVFk8oihxXDhVj+iKUTAytqwBhRXSuL/ZsFTL5rW5 samy.ftaita89@gmail.com`.

---

## Architecture Docker

Chaque service tourne dans son propre `docker-compose.yml` sous `/opt/stack/` :

```
/opt/stack/
├── traefik/          ← reverse proxy + TLS Let's Encrypt (port 443)
├── mysql/            ← MySQL 8.0 partagé (surgicalhub, medatwork, medclick)
├── redis/            ← Redis 7
├── apps/
│   └── surgicalhub/
│       ├── docker-compose.yml
│       ├── .env               ← secrets prod (jamais commités)
│       └── src/               ← code source (copie manuelle, pas de git)
├── portainer/        ← UI de gestion Docker
└── phpmyadmin/       ← interface MySQL
```

### Containers surgicalhub

| Container | Image | Rôle |
|---|---|---|
| `surgicalhub-php` | `surgicalhub-php:local` | PHP-FPM (Symfony) |
| `surgicalhub-nginx` | `surgicalhub-nginx:local` | Nginx (serveur static + proxy FPM) |
| `surgicalhub-worker` | `surgicalhub-php:local` | Messenger consumer async |

Les images sont buildées localement sur le serveur depuis `/opt/stack/apps/surgicalhub/src/`.

---

## Procédure de déploiement

**Ne jamais sauter le rapport d'écart pré-déploiement** (commit local vs
commit serveur vs dernier tag prod vs migrations en attente) — voir
[`docs/deployment-versioning.md`](deployment-versioning.md) §2. Les étapes
ci-dessous supposent que ce rapport a été produit et que la décision de
déployer a été validée.

### 0. Pré-requis : pas de cherry-pick partiel

> Si le serveur a plus d'un commit de retard : **interdiction de déployer
> un sous-ensemble de fichiers**. Voir
> [`docs/deployment-versioning.md`](deployment-versioning.md) §3 — on
> déploie `HEAD` en entier, ou on s'arrête et on demande validation.

### 1. Préparer l'archive en local — toujours depuis `git archive`

```bash
# Depuis la racine du repo, sur le commit HEAD exact qui sera déployé
git status   # doit être propre pour les chemins déployés
git log --oneline -1

git archive --format=tar.gz -o surgicalhub_deploy.tar.gz HEAD -- backend frontend docker-compose.yml
scp surgicalhub_deploy.tar.gz deploy@187.124.55.15:/tmp/
```

**Jamais `tar` sur le répertoire de travail.** `git archive` ne contient
que le contenu exactement committé sur `HEAD` — aucune modification non
commitée, aucun chantier en cours (ex: `planning-v2/*` non finalisé) ne
peut s'y glisser. C'est ce qui garantit que le tag créé en fin de
déploiement (§7 de `deployment-versioning.md`) correspond exactement à ce
qui tourne réellement.

### 2. Sauvegardes (toujours avant d'écraser quoi que ce soit)

```bash
ssh deploy@187.124.55.15

# Dump DB
/home/deploy/scripts/backup_mysql.sh

# Code source actuellement déployé (pour rollback — voir §"Rollback" plus bas)
tar czf /home/deploy/backups/code/src_pre_deploy_$(date +%Y%m%d_%H%M%S).tar.gz \
  -C /opt/stack/apps/surgicalhub src
```

Noter les deux chemins produits — ils vont dans le rapport final.

### 3. Extraire sur le serveur

```bash
tar xzf /tmp/surgicalhub_deploy.tar.gz -C /opt/stack/apps/surgicalhub/src/
rm /tmp/surgicalhub_deploy.tar.gz
```

### 4. Vérifier les migrations avant de les exécuter

```bash
docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod
```

Puis, une fois les nouvelles images buildées (étape 5) :

```bash
docker exec surgicalhub-php php bin/console doctrine:migrations:migrate --dry-run --env=prod
```

Relire le SQL produit (voir `docs/deployment-versioning.md` §4.2 pour les
critères de relecture) **avant** d'exécuter la vraie migration à l'étape 6.

### 5. Rebuild des images Docker

```bash
cd /opt/stack/apps/surgicalhub

# En tâche de fond (le build prend ~5-10 min)
nohup bash -c 'docker compose build --no-cache > /tmp/build.log 2>&1; echo "BUILD_EXIT=$?" >> /tmp/build.log' &
until grep -q 'BUILD_EXIT=' /tmp/build.log; do sleep 15; done
grep 'BUILD_EXIT=' /tmp/build.log
```

### 6. Redémarrer et migrer

```bash
cd /opt/stack/apps/surgicalhub

docker compose up -d

# Migrations Doctrine (réel, après relecture du dry-run à l'étape 4)
docker exec surgicalhub-php php bin/console doctrine:migrations:migrate \
  --no-interaction --env=prod

# Cache + restart (toujours après une migration ou un changement de code)
docker exec surgicalhub-php php bin/console cache:clear --env=prod
docker restart surgicalhub-php

# Si des handlers Messenger ont changé, le worker doit aussi relire le nouveau code
docker restart surgicalhub-worker
```

> **Note** : après `cache:clear`, un restart du container PHP est nécessaire
> pour que PHP-FPM prenne en compte le nouveau cache (le cache est dans le
> volume `surgicalhub_var`, non dans l'image).

### 7. Vérification

```bash
# Statut containers
docker ps | grep surgicalhub

# Logs PHP récents
docker logs surgicalhub-php --tail 20
docker logs surgicalhub-worker --tail 15

# Routes admin disponibles
docker exec surgicalhub-php php bin/console debug:router --env=prod | grep admin

# Migrations à jour — doit dire "Already at latest version"
docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod
```

Tests santé complets (HTTP public, login, `/api/me`, test ciblé fonction
modifiée) : voir [`docs/deployment-versioning.md`](deployment-versioning.md) §5.
Rapport final obligatoire : §6. Tag Git de fin de déploiement : §7.

---

## Gestion des secrets (`.env`)

Le fichier `/opt/stack/apps/surgicalhub/.env` contient tous les secrets de
production. **Ne jamais le commiter ni l'afficher dans les logs.**

Modifier une valeur sans exposer les secrets :

```bash
# Utiliser Python pour modifier une variable spécifique
python3 - << 'EOF'
import re
path = "/opt/stack/apps/surgicalhub/.env"
with open(path) as f:
    content = f.read()
content = re.sub(r"^MA_VARIABLE=.*$", "MA_VARIABLE=nouvelle_valeur", content, flags=re.MULTILINE)
with open(path, "w") as f:
    f.write(content)
print("OK")
EOF
```

Variables mailer actuelles :

| Variable | Valeur |
|---|---|
| `MAILER_DSN` | `smtp://notifications@surgicalhub.be:***@smtp.hostinger.com:587?encryption=tls` |
| `MAILER_FROM_ADDRESS` | `notifications@surgicalhub.be` |
| `MAILER_FROM_NAME` | `SurgicalHub` |

---

## Créer un compte utilisateur

```bash
docker exec surgicalhub-php php bin/console app:user:create \
  EMAIL 'MOT_DE_PASSE' ROLE_ADMIN --env=prod
```

Rôles disponibles : `ROLE_ADMIN`, `ROLE_MANAGER`, `ROLE_INSTRUMENTIST`.

---

## Commandes utiles

```bash
# Logs en temps réel
docker logs -f surgicalhub-php
docker logs -f surgicalhub-worker

# Console Symfony
docker exec surgicalhub-php php bin/console [commande] --env=prod

# Shell dans le container PHP
docker exec -it surgicalhub-php bash

# Restart d'un service
docker restart surgicalhub-php
docker restart surgicalhub-worker

# MySQL (mot de passe dans /opt/stack/mysql/.env)
ROOTPW=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/stack/mysql/.env | cut -d= -f2-)
docker exec mysql mysql -uroot -p"$ROOTPW" surgicalhub

# Rebuild une seule image (sans --no-cache si pas de changement de dépendances)
cd /opt/stack/apps/surgicalhub && docker compose build php
```

---

## Volumes Docker

| Volume | Contenu | Backup |
|---|---|---|
| `surgicalhub_uploads` | Fichiers uploadés (PDFs, docs) | ✓ quotidien |
| `surgicalhub_var` | Cache Symfony, logs, clés JWT | non (regénérable) |

Les uploads sont sauvegardés dans `/home/deploy/backups/uploads/` et
synchronisés sur Google Drive. Voir [`docs/backup-and-restore.md`](backup-and-restore.md).

---

## Rollback

Le tag `*-prod` précédent (voir "Historique des versions déployées"
ci-dessus) identifie le dernier commit connu-bon. L'archive de code créée à
l'étape 2 de chaque déploiement (`src_pre_deploy_*.tar.gz`) est le moyen le
plus rapide de revenir en arrière sans dépendre de Git sur le serveur.

```bash
# 1. Restaurer le backup DB (voir backup-and-restore.md)
ROOTPW=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/stack/mysql/.env | cut -d= -f2-)
zcat /home/deploy/backups/mysql/all_YYYYMMDD_HHMMSS.sql.gz \
  | docker exec -i mysql mysql -uroot -p"$ROOTPW"

# 2. Remettre l'ancienne version du code dans src/ depuis l'archive pré-déploiement
rm -rf /opt/stack/apps/surgicalhub/src/backend /opt/stack/apps/surgicalhub/src/frontend
tar xzf /home/deploy/backups/code/src_pre_deploy_YYYYMMDD_HHMMSS.tar.gz \
  -C /opt/stack/apps/surgicalhub --strip-components=1 src/backend src/frontend

# 3. Rebuild + restart
cd /opt/stack/apps/surgicalhub
docker compose build --no-cache && docker compose up -d
```

Après un rollback, ne pas créer de tag `*-prod` pour la version restaurée
(elle a déjà son tag) — documenter l'incident et le rollback dans le rapport,
mais le "dernier tag prod" reste celui d'avant le déploiement raté.

---

## Infrastructure complète

| Élément | Détail |
|---|---|
| OS | Ubuntu 24.04.4 LTS |
| IP | `187.124.55.15` |
| Utilisateur SSH | `deploy` (pas root) |
| Docker | 28.x |
| MySQL | 8.0.46 (container `mysql`) |
| Redis | 7 (container `redis`) |
| Traefik | v3.6 (TLS Let's Encrypt automatique) |
| Portainer | `portainer.surgicalhub.be` |
| phpMyAdmin | `pma.surgicalhub.be` |
| Sauvegardes | `/home/deploy/backups/` + Google Drive (quotidien 03h00) |
