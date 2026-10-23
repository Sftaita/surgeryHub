# SurgicalHub — Sauvegarde et restauration

_Serveur : `deploy@187.124.55.15` — Ubuntu 24.04.4 LTS — Docker VPS_
_Mis en place le : 2026-06-16_

---

## D-164 — Nouvelle chaîne de sauvegarde (versionnée, PAS ENCORE INSTALLÉE)

> État au 2026-10-23 : les scripts sont dans le dépôt (`deploy/server/backup/`) et testés, mais
> **la production utilise toujours les anciens scripts** décrits plus bas (`/home/deploy/scripts`).
> L'installation se fait en trois étapes, chacune après accord explicite.

### Ce qui change

| Sujet | Avant (scripts manuels) | D-164 |
|---|---|---|
| Dumps MySQL | `all_*.sql.gz` en clair | `all_*.sql.gz.gpg` chiffré AES-256 (+ `.sha256`), contrôlé après écriture |
| Volume `surgicalhub_var` (clés JWT, registre D-162) | jamais sauvegardé | archive chiffrée quotidienne, sans `cache/` ni `log/` |
| Uploads | `*.tar.gz` en clair | `*.tar.gz.gpg` chiffré |
| Hors serveur | `rclone sync` : chaque suppression locale efface le Drive | `rclone copy` des seuls fichiers chiffrés vers `…/surgicalhub-chiffre/`, vérifié, avec une rétention distante **indépendante** |
| Rotation | `find` mal parenthésé : les dumps n'étaient jamais supprimés | dry-run par défaut ; `--apply` refusé sans test de restauration réussi depuis moins de 35 jours |
| Code, `.env`, images, cache de build | jamais tournés | règles ci-dessous |

### Rétention locale (validée le 2026-10-23)

| Série | Règle |
|---|---|
| Dumps quotidiens `all_*` | Tous pendant 14 jours, puis le plus récent de chacune des 8 dernières semaines, puis le plus récent de chacun des 12 derniers mois |
| Dumps d'avant déploiement `surgicalhub_pre_deploy_*` | Les 10 derniers |
| Uploads, volume var | 30 jours, jamais moins des 3 plus récentes par volume |
| Archives de code `src_pre_deploy_*`, `pre_*` | Les 10 dernières |
| Copies de `.env` (`backups/code`) | Les 10 dernières, toujours en 600 |
| `deploy-backups/<horodatage>/` (D-163) | Les 10 derniers, plus celui nécessaire à `rollback legacy` tant que les images `rollback-legacy` existent |
| Images de release (`:<sha>`, `:rollback-*`) | Les 5 plus récentes ; toujours la release active, la précédente, `rollback-legacy`, `:local` et toute image utilisée par un conteneur |
| Cache de build | Élagué (entrées de plus de 7 jours) seulement au-delà de 8 Go, jamais pendant un build |

La plus récente sauvegarde de chaque série est toujours conservée. Seuls les fichiers au format
exact d'une série sont concernés : tout autre fichier est signalé « non géré » et conservé.

### Rétention hors serveur (validée le 2026-10-23, indépendante de la rétention locale)

Dumps quotidiens : 30 jours, 12 semaines, 24 mois. Uploads et var : 90 jours. Les anciens
dossiers en clair du Drive (`…/mysql`, `…/uploads`) ne sont pas touchés : leur sort est une
décision séparée.

### Scripts (`deploy/server/backup/`)

```bash
backup_mysql.sh                   # dump chiffré de toutes les bases ; --pre-deploy <étiquette>
backup_volumes.sh                 # uploads + surgicalhub_var, chiffrés
backup_offsite.sh                 # copie vérifiée vers le Drive ; rotation distante en dry-run
backup_offsite.sh --apply-remote-rotation
rotate_backups.sh                 # DRY-RUN : ce qui serait supprimé et l'espace récupéré
rotate_backups.sh --list          # … avec la liste détaillée
rotate_backups.sh --apply         # réel ; exige un test de restauration réussi récent
restore_test.sh                   # restauration RÉELLE dans un MySQL jetable + JWT + registre + uploads
restore_test.sh --from-remote     # idem, à partir des copies TÉLÉCHARGÉES depuis le Drive
install.sh [--apply-scripts|--apply-crontab]   # depuis le poste : diff, puis installation
```

Prérequis : `~/.surgicalhub-backup-passphrase` (600), conservée **aussi hors du serveur**. Sans
elle, aucune sauvegarde chiffrée n'est restaurable.

### Restaurer une sauvegarde chiffrée

```bash
P=~/.surgicalhub-backup-passphrase
sha256sum -c all_<horodatage>.sql.gz.gpg.sha256
gpg --batch --pinentry-mode loopback --passphrase-file $P -d all_<horodatage>.sql.gz.gpg > dump.sql.gz
# puis la procédure « Restaurer la base MySQL » ci-dessous (Cas 0, puis Cas 1)

gpg --batch --pinentry-mode loopback --passphrase-file $P -d surgicalhub_var_<horodatage>.tar.gz.gpg \
  | docker run --rm -i --network none --entrypoint tar -v surgicalhub_var:/d alpine:latest xzf - -C /d
```

### Mise en service (chaque étape après accord explicite)

L'ancien système (`/home/deploy/scripts/*.sh`, crontab actuelle) **reste actif** jusqu'à l'étape 4,
et ses scripts ne sont jamais supprimés : retour arrière = restaurer la crontab sauvegardée.

**Étape 1 — Passphrase (par l'utilisateur, jamais par l'agent).** Dans **son propre terminal** :
pas avec le préfixe `!` de Claude Code, dont la sortie arrive dans la conversation.

```bash
ssh surgicalhub-prod
umask 077
openssl rand -base64 48 > ~/.surgicalhub-backup-passphrase   # rien n'est affiché
chmod 600 ~/.surgicalhub-backup-passphrase
less ~/.surgicalhub-backup-passphrase                         # copier dans le gestionnaire de secrets, puis q
sha256sum ~/.surgicalhub-backup-passphrase | cut -c1-16       # empreinte courte, à noter avec le secret
```

Le secret est rangé dans le gestionnaire de mots de passe (entrée « SurgicalHub — passphrase des
sauvegardes D-164 », avec l'empreinte courte). Il n'apparaît jamais dans Git, dans un journal, dans
l'historique du shell ni dans une conversation. L'agent vérifie seulement la présence, les droits
600 et la taille du fichier. Sans cette passphrase, aucune sauvegarde chiffrée n'est restaurable :
la copie hors serveur est obligatoire avant l'étape 3.

**Étape 2 — Scripts.** `deploy/server/backup/install.sh` (différences, rien n'est écrit), puis
`install.sh --apply-scripts` → `/home/deploy/scripts/surgicalhub-backup/` (700). La crontab est
inchangée et l'ancien système continue.

**Étape 3 — Premier cycle chiffré, manuel, à côté de l'ancien.**
1. `backup_mysql.sh`, puis `backup_volumes.sh` : premiers `*.gpg`, et `backups/surgicalhub-var/` créé.
2. `restore_test.sh` : restauration réelle locale, marqueur `.restore-test-ok`.
3. `backup_offsite.sh` : copie vérifiée vers `…/surgicalhub-chiffre/` ; la rotation distante est
   en dry-run. Ensuite, contrôle des permissions du nouveau dossier (propriétaire seul).
4. `restore_test.sh --from-remote` : les dernières sauvegardes sont **téléchargées depuis le Drive**,
   puis restaurées dans un MySQL jetable sans réseau. Contrôles : lignes, clés JWT appariées,
   registre, uploads. Marqueur `.restore-test-remote-ok`.

**Étape 4 — Crontab.** `install.sh --apply-crontab` : la crontab est sauvegardée dans
`backups/cron/crontab_before_d164_*.txt`, puis les 4 anciennes lignes sont remplacées par
`crontab.proposed`. Le test de restauration devient hebdomadaire, et la rotation reste en dry-run.
`sync_gdrive.sh` n'est plus lancé : les anciens dossiers en clair du Drive sont **figés**, plus
rien ne les modifie. Contrôle après une nuit : journal, nouveaux fichiers, copie distante.

**Étape 5 — Rotation réelle.** Nouveau dry-run présenté, puis `--apply` en local, puis
`--apply-remote-rotation`. Ensuite seulement, la liste des anciens dossiers en clair du Drive
pouvant être supprimés est présentée, pour décision.

### Tests

- `deploy/server/backup/tests/run-in-docker.sh`, sous Linux :
  - `rotation.sh` (31 contrôles) : grand-père/père/fils, séries, liens symboliques, fichiers non
    gérés, images protégées, cache de build, refus sans test de restauration, passphrase ;
  - `offsite.sh` (18 contrôles, faux rclone) : seuls les `*.gpg` sortent (jamais un fichier en
    clair ni un `.env`), suppressions locales jamais répercutées, aucune rotation si la copie n'est
    pas vérifiée, rétention distante 30 j / 12 sem. / 24 mois et 90 j.
- `deploy/server/backup/tests/e2e-docker.sh` (21 contrôles, vrai Docker, tout jetable) : dumps et
  volumes chiffrés, restauration réelle locale **puis depuis la copie hors serveur sans aucune
  sauvegarde locale**, refus d'une archive altérée (locale ou distante) et d'une mauvaise passphrase.

---

## Architecture des sauvegardes

```
/home/deploy/
├── scripts/
│   ├── backup_mysql.sh       ← dump MySQL quotidien (03h00)
│   ├── backup_uploads.sh     ← archives volumes Docker (03h15)
│   ├── sync_gdrive.sh        ← synchronisation Google Drive (03h30)
│   └── rotate_backups.sh     ← rotation 30 jours (04h00)
└── backups/
    ├── mysql/                ← dumps SQL compressés (.sql.gz)
    ├── uploads/              ← archives volumes Docker (.tar.gz)
    └── backup.log            ← journal de toutes les opérations
```

### Flux complet

```
03h00  backup_mysql.sh    → /home/deploy/backups/mysql/all_YYYYMMDD_HHMMSS.sql.gz
03h15  backup_uploads.sh  → /home/deploy/backups/uploads/{app}_YYYYMMDD_HHMMSS.tar.gz
03h30  sync_gdrive.sh     → Google Drive : INFORMATIQUE/Base de donnée/mysql/
                                           INFORMATIQUE/Base de donnée/uploads/
04h00  rotate_backups.sh  → supprime les fichiers > 30 jours
```

---

## Emplacement des fichiers

| Fichier | Chemin |
|---|---|
| Script MySQL | `/home/deploy/scripts/backup_mysql.sh` |
| Script uploads | `/home/deploy/scripts/backup_uploads.sh` |
| Script Google Drive | `/home/deploy/scripts/sync_gdrive.sh` |
| Script rotation | `/home/deploy/scripts/rotate_backups.sh` |
| Dumps MySQL | `/home/deploy/backups/mysql/all_YYYYMMDD_HHMMSS.sql.gz` |
| Archives uploads | `/home/deploy/backups/uploads/{app}_YYYYMMDD_HHMMSS.tar.gz` |
| Journal | `/home/deploy/backups/backup.log` |
| Config rclone | `/home/deploy/.config/rclone/rclone.conf` |
| rclone binaire | `/home/deploy/bin/rclone` |

---

## Fréquence et rétention

| Type | Fréquence | Rétention locale | Copie hors-site |
|---|---|---|---|
| Dump MySQL (toutes bases) | Quotidienne 03h00 | 30 jours | Google Drive (sync) |
| Archives uploads | Quotidienne 03h15 | 30 jours | Google Drive (sync) |

---

## Bases et volumes sauvegardés

### MySQL
- `surgicalhub` — plateforme SurgicalHub
- `medatwork` — plateforme MedAtWork
- `medclick` — plateforme MedClick

Toutes les bases sont incluses via `mysqldump --all-databases`. Le mot de passe
MySQL est lu depuis `/opt/stack/mysql/.env` au moment de l'exécution — jamais
stocké en clair dans la crontab ou les scripts.

### Volumes Docker
- `surgicalhub_uploads` → `surgicalhub_YYYYMMDD_HHMMSS.tar.gz`
- `medatwork_uploads` → `medatwork_YYYYMMDD_HHMMSS.tar.gz` _(dès déploiement)_
- `medclick_uploads` → `medclick_YYYYMMDD_HHMMSS.tar.gz` _(dès déploiement)_

Les volumes absents sont ignorés silencieusement (app non encore déployée).

---

## Configuration rclone — Google Drive

### Installation
rclone v1.74.3 installé dans `/home/deploy/bin/rclone`.
Config : `/home/deploy/.config/rclone/rclone.conf` — remote `gdrive` configuré et actif.

### Dossiers Google Drive utilisés

```
Google Drive (compte samy.ftaita89@gmail.com)
└── INFORMATIQUE/
    └── Base de donnée/
        ├── mysql/      ← dumps SQL (.sql.gz)
        └── uploads/    ← archives volumes (.tar.gz)
```

> **Note technique** : le chemin contient un espace (`Base de donnée`). Dans les
> scripts shell, ce chemin doit **toujours être entre guillemets** pour éviter
> que bash le découpe en mots séparés. Le script `sync_gdrive.sh` utilise des
> variables pour garantir ce comportement :
> ```bash
> GDRIVE_MYSQL="gdrive:INFORMATIQUE/Base de donnée/mysql"
> rclone sync /home/deploy/backups/mysql "${GDRIVE_MYSQL}" ...
> ```

### Reconfigurer l'authentification (si nécessaire)

**Méthode headless (serveur sans navigateur) :**

**1. Sur le serveur** (connexion SSH interactive) :
```bash
ssh deploy@187.124.55.15
export PATH=/home/deploy/bin:$PATH
rclone config
```
Répondre aux questions :
- `n` → new remote (ou `e` pour éditer `gdrive` existant)
- Nom : `gdrive`
- Type : `drive` (Google Drive)
- `client_id` : laisser vide (Entrée)
- `client_secret` : laisser vide (Entrée)
- `scope` : `1` (drive — accès complet)
- `root_folder_id` : laisser vide (Entrée)
- `service_account_file` : laisser vide (Entrée)
- `Edit advanced config?` : `n`
- **`Use auto config?` : `n`** (serveur headless)

**2. Sur ta machine locale** (avec navigateur) :
```bash
rclone authorize "drive"
```
Cela ouvre un navigateur → autoriser → copier le token affiché.

**3. Sur le serveur** : coller le token quand demandé, puis confirmer.

**4. Vérifier :**
```bash
export PATH=/home/deploy/bin:$PATH
rclone lsf "gdrive:INFORMATIQUE/Base de donnée/"
```

---

## Procédures de restauration

### Restaurer la base MySQL

> **Règle : une restauration SurgicalHub ne touche que la base `surgicalhub`.**
> Le dump nocturne `all_*.sql.gz` est un `mysqldump --all-databases` : il contient, dans
> l'ordre, la base système `mysql` (comptes, mots de passe, droits), `medatwork`, `medclick`
> et `surgicalhub`. **Ne jamais le rejouer en entier** (`zcat all_*.sql.gz | docker exec -i
> mysql mysql …`) : `medatwork` et `medclick` reviendraient à l'état de la nuit (tout ce qui a
> été écrit depuis serait perdu) et les comptes et droits MySQL seraient réécrits (un compte
> créé depuis le dump serait supprimé) — constaté sur conteneurs de test le 2026-10-17. Le
> rejeu complet est réservé à la reconstruction d'un serveur vide, sur décision explicite.

Choisir d'abord l'opération (détail : [`production.md`](production.md) §Rollback) :

| Situation | Opération | Base touchée |
|---|---|---|
| Le code déployé pose problème, le schéma est sain | A. retour du code seul | aucune |
| Une migration pose problème | B. `down()` des migrations du déploiement | schéma de `surgicalhub` |
| Les données de `surgicalhub` sont corrompues | C. Cas 1 ci-dessous | `surgicalhub` seule |

**Cas 0 — Instantané de sécurité de `surgicalhub` (avant tout Cas 1 ou tout `down()`)**

```bash
ROOTPW=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/stack/mysql/.env | cut -d= -f2-)
STAMP=$(date +%Y%m%d_%H%M%S)
docker exec -e MYSQL_PWD="$ROOTPW" mysql mysqldump -uroot --single-transaction --routines --triggers \
  --databases surgicalhub | gzip > /home/deploy/backups/mysql/surgicalhub_pre_restore_${STAMP}.sql.gz
gzip -t /home/deploy/backups/mysql/surgicalhub_pre_restore_${STAMP}.sql.gz && echo OK
```

`--databases surgicalhub` limite le dump à cette base ; le mot de passe passe par
`MYSQL_PWD`, jamais par la ligne de commande. L'instantané conserve tout ce qui a été écrit
depuis le dump nocturne (missions, encodages, calculs, factures, relevés, absences, comptes).
Après une restauration, il se relit dans une base séparée (extraction du Cas 1 avec une
autre cible) pour décider, **au cas par cas**, de ce qui doit être repris. Rien n'est
réinjecté automatiquement : les identifiants entrent en collision et les notifications déjà
envoyées ne se rejouent pas.

**Cas 1 — Restaurer la seule base `surgicalhub` depuis un dump `--all-databases`**

Fenêtre de maintenance : `php` et `worker` arrêtés (`cd /opt/stack/apps/surgicalhub &&
docker compose stop php worker`), aucune tâche planifiée SurgicalHub pendant l'opération
(voir les sections « Tâche planifiée » de [`production.md`](production.md)).

```bash
ROOTPW=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/stack/mysql/.env | cut -d= -f2-)
DUMP=/home/deploy/backups/mysql/all_YYYYMMDD_HHMMSS.sql.gz
WORK=$(mktemp -d)
# dumps disponibles : ls -lt /home/deploy/backups/mysql/ | head

# 0. Instantané de sécurité de l'état courant (Cas 0)

# 1. Le dump doit être complet
gzip -dc "$DUMP" | tail -n 1 | grep -q '^-- Dump completed' && echo "dump complet" || echo "DUMP INCOMPLET : arrêter"

# 2. Extraire l'en-tête, la seule section `surgicalhub` et le pied ; la base visée devient __CIBLE__
gzip -dc "$DUMP" | awk '
  BEGIN { s = "header" }
  /^-- Current Database: `/ { n = $0; sub(/^-- Current Database: `/, "", n); sub(/`.*$/, "", n)
                              s = (n == "surgicalhub") ? "keep" : "skip"; next }
  /^\/\*!40103 SET TIME_ZONE=@OLD_TIME_ZONE \*\/;/ { s = "footer" }
  s == "skip" { next }
  s == "keep" && /^CREATE DATABASE / { next }
  s == "keep" && /^USE `/ { print "USE `__CIBLE__`;"; next }
  { print }' > "$WORK/extrait.sql"

# 3. Garde-fous : une seule instruction USE, aucune instruction visant une autre base
grep -cE '^(USE|CREATE DATABASE|DROP DATABASE) ' "$WORK/extrait.sql"              # attendu : 1
grep -cE '^(INSERT INTO|CREATE TABLE|DROP TABLE IF EXISTS|LOCK TABLES) `[^`]+`\.`' "$WORK/extrait.sql"  # attendu : 0

# 4. Vérifier dans une base temporaire
sed 's/^USE `__CIBLE__`;$/USE `surgicalhub_restore_check`;/' "$WORK/extrait.sql" > "$WORK/check.sql"
docker exec -e MYSQL_PWD="$ROOTPW" mysql mysql -uroot \
  -e 'CREATE DATABASE surgicalhub_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
docker exec -i -e MYSQL_PWD="$ROOTPW" mysql mysql -uroot < "$WORK/check.sql"
docker exec -e MYSQL_PWD="$ROOTPW" mysql mysql -uroot surgicalhub_restore_check \
  -e 'SELECT COUNT(*) FROM doctrine_migration_versions; SELECT COUNT(*) FROM mission; SELECT COUNT(*) FROM user;'

# 5. Remplacer `surgicalhub`, et elle seule
sed 's/^USE `__CIBLE__`;$/USE `surgicalhub`;/' "$WORK/extrait.sql" > "$WORK/surgicalhub.sql"
docker exec -e MYSQL_PWD="$ROOTPW" mysql mysql -uroot \
  -e 'DROP DATABASE surgicalhub; CREATE DATABASE surgicalhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
docker exec -i -e MYSQL_PWD="$ROOTPW" mysql mysql -uroot < "$WORK/surgicalhub.sql"

# 6. Nettoyage
docker exec -e MYSQL_PWD="$ROOTPW" mysql mysql -uroot -e 'DROP DATABASE surgicalhub_restore_check'
rm -rf "$WORK"
```

Les comptes et droits MySQL sont stockés dans la base système `mysql`, qui n'est pas touchée :
le `DROP` / `CREATE` de `surgicalhub` ne modifie aucun compte ni aucun droit. Remettre ensuite
le code qui correspond au nombre de migrations restaurées (`production.md` §Rollback A),
redémarrer `php` et `worker`, **puis `nginx`**, et faire les contrôles de santé.

**Cas 2 — Restauration dans une base temporaire (vérification avant écrasement)**

```bash
ROOTPW=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/stack/mysql/.env | cut -d= -f2-)

# 1. Dump ciblé d'une seule base
docker exec mysql mysqldump -uroot -p"$ROOTPW" surgicalhub --single-transaction \
  | gzip > /tmp/surgicalhub_check.sql.gz

# 2. Créer base temporaire
docker exec mysql mysql -uroot -p"$ROOTPW" \
  -e 'CREATE DATABASE restore_test CHARACTER SET utf8mb4;'

# 3. Restaurer et vérifier
zcat /tmp/surgicalhub_check.sql.gz \
  | docker exec -i mysql mysql -uroot -p"$ROOTPW" restore_test

docker exec mysql mysql -uroot -p"$ROOTPW" restore_test -e 'SHOW TABLES;'
docker exec mysql mysql -uroot -p"$ROOTPW" restore_test -e 'SELECT COUNT(*) FROM user;'

# 4. Nettoyer
docker exec mysql mysql -uroot -p"$ROOTPW" -e 'DROP DATABASE restore_test;'
rm /tmp/surgicalhub_check.sql.gz
```

**Cas 3 — Restaurer depuis Google Drive**

```bash
export PATH=/home/deploy/bin:$PATH

# Lister les fichiers disponibles sur Drive
rclone lsf "gdrive:INFORMATIQUE/Base de donnée/mysql/"

# Télécharger un dump spécifique
rclone copy "gdrive:INFORMATIQUE/Base de donnée/mysql/all_YYYYMMDD_HHMMSS.sql.gz" /tmp/

# Puis restaurer la seule base surgicalhub (Cas 0 puis Cas 1) ; jamais de rejeu complet
```

---

### Restaurer les uploads

**Cas 1 — Restauration dans un dossier temporaire (vérification)**

```bash
ARCHIVE=/home/deploy/backups/uploads/surgicalhub_YYYYMMDD_HHMMSS.tar.gz
RESTORE_DIR=/tmp/uploads_restore

mkdir -p "$RESTORE_DIR"
tar xzf "$ARCHIVE" -C "$RESTORE_DIR"
ls -lh "$RESTORE_DIR/data/"
```

**Cas 2 — Restauration dans le volume Docker**

```bash
ARCHIVE=/home/deploy/backups/uploads/surgicalhub_YYYYMMDD_HHMMSS.tar.gz

# Restaurer dans le volume surgicalhub_uploads
docker run --rm \
  -v surgicalhub_uploads:/data \
  -v "$(dirname $ARCHIVE)":/backup:ro \
  alpine \
  sh -c "tar xzf /backup/$(basename $ARCHIVE) -C / --strip-components=1"
```

---

## Commandes utiles

```bash
# Voir les dernières lignes du journal
tail -50 /home/deploy/backups/backup.log

# Lancer un backup MySQL manuellement
/home/deploy/scripts/backup_mysql.sh

# Lancer un backup uploads manuellement
/home/deploy/scripts/backup_uploads.sh

# Lancer la synchronisation Google Drive manuellement
/home/deploy/scripts/sync_gdrive.sh

# Lister les backups MySQL disponibles
ls -lht /home/deploy/backups/mysql/

# Lister les backups uploads disponibles
ls -lht /home/deploy/backups/uploads/

# Taille totale des sauvegardes locales
du -sh /home/deploy/backups/

# Voir ce qui est sur Google Drive
export PATH=/home/deploy/bin:$PATH
rclone lsf "gdrive:INFORMATIQUE/Base de donnée/mysql/"
rclone lsf "gdrive:INFORMATIQUE/Base de donnée/uploads/"

# Vérifier le crontab actif
crontab -l
```

---

## Check-list de vérification mensuelle

À effectuer le premier lundi de chaque mois :

- [ ] **Logs** : `tail -100 /home/deploy/backups/backup.log` — aucune ligne `ERREUR`
- [ ] **Backups MySQL** : `ls -lht /home/deploy/backups/mysql/ | head -5` — fichier récent (< 24h)
- [ ] **Backups uploads** : `ls -lht /home/deploy/backups/uploads/ | head -5` — fichier récent
- [ ] **Google Drive** : `rclone lsf "gdrive:INFORMATIQUE/Base de donnée/mysql/" | head -5` — synchronisé
- [ ] **Taille** : `du -sh /home/deploy/backups/` — espace disque suffisant (`df -h /`)
- [ ] **Test restauration MySQL** : restaurer dans `restore_test`, compter les tables, supprimer
- [ ] **Rotation** : vérifier qu'aucun fichier dépasse 30 jours (`find /home/deploy/backups -mtime +30`)
- [ ] **Crontab** : `crontab -l` — 4 entrées présentes
- [ ] **rclone** : `rclone version` — version à jour

---

## Résultat des tests de restauration (2026-06-16)

| Test | Résultat |
|---|---|
| Dump MySQL `--all-databases` | ✓ 864 Ko, exit 0 |
| Restauration `surgicalhub` → `restore_test` | ✓ 35/35 tables, cohérent |
| Archive uploads `surgicalhub_uploads` | ✓ 3,8 Mo, 5/5 fichiers |
| Restauration uploads `/tmp` | ✓ 5 fichiers, structure intacte |
| Sync Google Drive (2026-06-17) | ✓ 3 dumps + 2 archives présents sur Drive |

## Correctif appliqué (2026-06-17) — chemins Google Drive avec espaces

**Problème** : `sync_gdrive.sh` échouait silencieusement avec `ERREUR sync mysql`.

**Cause** : le chemin `gdrive:INFORMATIQUE/Base de donnée/mysql` n'était pas
entre guillemets. Bash le découpait en 3 arguments distincts :
`gdrive:INFORMATIQUE/Base`, `de`, `donnée/mysql` — rclone recevait une syntaxe
invalide.

**Corrections** :
1. Les chemins GDrive sont définis dans des variables puis passés entre
   `"${...}"` — les espaces sont transmis correctement à rclone.
2. `2>/dev/null` remplacé par `2>>"$LOG"` — les erreurs rclone sont désormais
   visibles dans le journal au lieu d'être silencieusement ignorées.

**Règle générale** : tout chemin rclone contenant un espace doit être entre
guillemets, y compris dans les commandes manuelles :
```bash
# ✗ Incorrect (bash découpe le chemin)
rclone lsf gdrive:INFORMATIQUE/Base de donnée/mysql

# ✓ Correct
rclone lsf "gdrive:INFORMATIQUE/Base de donnée/mysql"
```
