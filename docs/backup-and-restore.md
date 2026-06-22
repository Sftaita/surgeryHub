# SurgicalHub — Sauvegarde et restauration

_Serveur : `deploy@187.124.55.15` — Ubuntu 24.04.4 LTS — Docker VPS_
_Mis en place le : 2026-06-16_

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

**Cas 1 — Restauration d'urgence (écraser la base existante)**

```bash
ssh deploy@187.124.55.15

# Identifier le dump à restaurer
ls -lt /home/deploy/backups/mysql/

# Exemple : restaurer le dernier dump
ROOTPW=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/stack/mysql/.env | cut -d= -f2-)
DUMP=/home/deploy/backups/mysql/all_YYYYMMDD_HHMMSS.sql.gz

zcat "$DUMP" | docker exec -i mysql mysql -uroot -p"$ROOTPW"
```

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

# Puis restaurer comme Cas 1 ou 2
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
