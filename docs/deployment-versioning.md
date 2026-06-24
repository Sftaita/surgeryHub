# SurgicalHub — Procédure officielle de déploiement et de versionning

**Ce document est obligatoire.** Aucun déploiement en production ne doit être
effectué sans suivre exactement les étapes décrites ici. `docs/production.md`
contient les commandes mécaniques (SSH, Docker, secrets) ; `docs/production-checklist.md`
est la checklist à cocher pendant l'exécution. Ce document-ci est la
**règle**, les deux autres sont l'**exécution**.

---

## 0. Pourquoi cette procédure existe (incident du 2026-06-24)

Lors du déploiement du lot "absences isolées", on a découvert que le serveur
de production était **3 commits en retard** sur `main`, et — plus grave —
que **la base de données avait déjà les migrations les plus récentes
appliquées alors que le code PHP déployé était ancien**. Autrement dit : la
base et le code n'étaient pas de la même version. Cause probable : un
déploiement antérieur a exécuté l'étape migrations sans terminer la copie du
code (ou un sous-ensemble de fichiers — dont `backend/migrations/` — a été
copié isolément).

Deux défauts structurels ont permis cet état :

1. **Aucune trace de "quelle version tourne en prod"** — ni tag Git, ni
   fichier de version, rien à comparer. Le seul moyen de savoir où en était
   le serveur a été d'inspecter le contenu de fichiers précis à la main.
2. **La procédure documentée autorisait explicitement le déploiement
   partiel** ("`tar czf ... # ... ajuster selon les fichiers modifiés`") —
   ce qui permet justement de copier `backend/migrations/` sans copier
   `backend/src/`, ou l'inverse.

Toutes les règles ci-dessous existent pour fermer ces deux trous : **on sait
toujours quelle version tourne**, et **on ne déploie jamais un sous-ensemble
de fichiers**.

---

## 1. Versioning de production

Après **chaque déploiement réussi et validé** (tests santé verts), la
production est identifiée par un tag Git annoté sur le commit exactement
déployé :

```text
vYYYY.MM.DD-prod
```

Exemple : `v2026.06.24-prod`.

- Un seul tag `-prod` par jour de déploiement. S'il y a plusieurs
  déploiements le même jour, suffixer `-2`, `-3`, etc. (`v2026.06.24-prod-2`).
- Le tag est créé **après** validation des tests santé, jamais avant.
- Le tag est poussé sur `origin` (GitHub) — voir §7. Un tag qui ne pointe pas
  vers un commit présent sur `origin` n'a aucune valeur de référence pour
  l'équipe.
- **Le dernier tag `*-prod` (par ordre chronologique) est, par définition,
  ce qui tourne actuellement sur le serveur.** C'est la seule source de
  vérité sur la version de prod — pas la mémoire de qui a déployé quoi.

### Tableau de référence (à tenir à jour dans `docs/production.md`)

Chaque déploiement ajoute une ligne en haut de la table "Historique des
versions déployées" de `docs/production.md` (tag, commit, date, qui/quoi).
Ne jamais réécrire une ligne existante — c'est un historique, pas un statut
mutable.

---

## 2. Rapport obligatoire AVANT déploiement

Avant de toucher au serveur, produire ce rapport et **l'afficher dans la
conversation/PR**, jamais en saut direct au déploiement.

### 2.1 Local

```bash
git status
git log --oneline --decorate -10
git tag -l 'v*-prod' --sort=-creatordate | head -5
```

Identifier :
- **Commit HEAD local** (celui qu'on s'apprête à déployer).
- **Dernier tag `*-prod` connu** (ce qui est censé tourner en prod).
- `git status` doit être propre (aucune modification non commitée dans les
  chemins qui seront déployés — `backend/`, `frontend/`, `docker-compose.yml`).
  Une modification non commitée présente dans le répertoire de travail ne
  doit **jamais** se retrouver dans l'archive de déploiement (voir §4.3 —
  on déploie depuis `git archive`, pas depuis le disque).

### 2.2 Serveur

```bash
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod"
```

Identifier :
- **Commit actuellement déployé.** Il n'y a pas de `.git` dans
  `/opt/stack/apps/surgicalhub/src/` (copie manuelle) — la seule façon
  fiable est de comparer le dernier tag `*-prod` connu localement avec un ou
  plusieurs marqueurs de fichiers réels sur le serveur (grep d'une chaîne
  introduite par un commit précis). **Ne jamais supposer** que le tag
  correspond à la réalité sans vérifier au moins un marqueur — c'est
  exactement ce qui a été manqué le 2026-06-24.
- **Migrations Doctrine appliquées** (`Current`, `Executed`,
  `Executed Unavailable`, `New`).

### 2.3 Comparaison obligatoire

```bash
git diff --name-only <commit-serveur>..HEAD
git log --oneline <commit-serveur>..HEAD
```

Produire ce rapport, sous cette forme exacte, avant de continuer :

```text
Commit local       : <sha> (<message court>)
Commit serveur      : <sha ou "inconnu, déduit de marqueurs">
Dernier tag prod    : <vYYYY.MM.DD-prod ou "aucun">
Écart                : N commits (liste : ...)
Fichiers modifiés    : <résumé ou "voir liste complète">
Migrations en attente : <liste, ou "aucune (déjà à jour)">
Anomalie détectée    : <ex: "DB plus récente que le code serveur" ou "aucune">
Décision             : <déployer HEAD complet / s'arrêter et demander validation>
```

**Toujours mentionner explicitement** si la base de données est déjà à un
niveau de migration plus avancé que ce que les commits manquants laissent
supposer — c'est le signal exact de l'incident du 2026-06-24.

---

## 3. Règle de sécurité — pas de déploiement partiel

> **Si le serveur a plus d'un commit de retard sur `HEAD` local : INTERDIT
> de faire un cherry-pick partiel, INTERDIT de ne copier qu'une partie des
> fichiers (ex: juste `backend/migrations/`, juste un fichier modifié).**

Dans ce cas, obligatoirement :

1. **Arrêter.** Ne pas exécuter la moindre commande d'écriture sur le
   serveur (pas de backup non plus à ce stade — le backup vient après
   validation, voir §4.1).
2. **Présenter les commits manquants** à l'utilisateur (liste complète,
   `git log --oneline <commit-serveur>..HEAD`), avec les fichiers qu'ils
   touchent.
3. **Demander une validation explicite** avant de continuer — ne jamais
   supposer que "juste le dernier commit" est sans risque si d'autres
   commits non déployés touchent les mêmes fichiers (cas réel du 2026-06-24 :
   le lot absences et le lancement Planning V2 modifiaient les mêmes
   fichiers, rendant un cherry-pick incohérent).
4. **Le déploiement complet de `HEAD`** est l'option par défaut recommandée
   dès qu'un écart de plus d'un commit existe — c'est la seule option qui
   garantit que tous les fichiers du dépôt sont mutuellement cohérents.

Cette règle ne s'assouplit jamais "pour aller plus vite". Un déploiement
partiel est exactement le mécanisme qui a produit l'incident initial.

---

## 4. Déploiement — ordre obligatoire

### 4.1 Sauvegardes (toujours en premier, après validation du rapport §2)

```bash
# DB
ssh surgicalhub-prod "/home/deploy/scripts/backup_mysql.sh"

# Code source actuellement déployé (avant toute écrasement)
ssh surgicalhub-prod "tar czf /home/deploy/backups/code/src_pre_deploy_\$(date +%Y%m%d_%H%M%S).tar.gz -C /opt/stack/apps/surgicalhub src"
```

Noter les deux chemins exacts produits — ils vont dans le rapport final
(§6) et sont la base de tout rollback (§7 de `docs/production.md`).

### 4.2 Vérification des migrations (avant toute exécution)

```bash
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:status --env=prod"
```

Puis, **après** le build mais **avant** `doctrine:migrations:migrate` réel :

```bash
ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:migrate --dry-run --env=prod"
```

Relire le SQL produit. Pour chaque migration nouvelle, vérifier dans le code
source (`backend/migrations/VersionXXXXXXXXXXXX.php`) que les instructions
destructrices (`DROP`, `DELETE`, `TRUNCATE`, `ALTER ... DROP COLUMN`) ne sont
présentes que dans `down()`, jamais dans `up()`, sauf si le ticket l'exige
explicitement et que c'est documenté. **Ne jamais exécuter une migration non
relue.**

### 4.3 Construction de l'archive — depuis `git archive`, jamais depuis le disque

```bash
# Depuis la racine du repo, sur le commit HEAD exact validé en §2
git archive --format=tar.gz -o surgicalhub_deploy.tar.gz HEAD -- backend frontend docker-compose.yml
scp surgicalhub_deploy.tar.gz surgicalhub-prod:/tmp/
```

**Toujours `git archive`, jamais `tar` sur le répertoire de travail.**
`git archive` ne contient que le contenu exactement committé sur le commit
ciblé — aucune modification non commitée, aucun fichier non suivi, aucun
chantier en cours ne peut s'y glisser. C'est ce qui garantit que "ce qui est
déployé" == "ce qui est dans `git log`" == "ce qui sera tagué" (§1).

### 4.4 Extraction

```bash
ssh surgicalhub-prod "tar xzf /tmp/surgicalhub_deploy.tar.gz -C /opt/stack/apps/surgicalhub/src/ && rm /tmp/surgicalhub_deploy.tar.gz"
```

### 4.5 Build, restart, migration réelle, cache

```bash
# Build (en tâche de fond, ~5-10 min)
ssh surgicalhub-prod "cd /opt/stack/apps/surgicalhub && nohup bash -c 'docker compose build --no-cache > /tmp/build.log 2>&1; echo BUILD_EXIT=\$? >> /tmp/build.log' &"
# attendre BUILD_EXIT=0 dans /tmp/build.log avant de continuer

ssh surgicalhub-prod "cd /opt/stack/apps/surgicalhub && docker compose up -d"

ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console doctrine:migrations:migrate --no-interaction --env=prod"

ssh surgicalhub-prod "docker exec surgicalhub-php php bin/console cache:clear --env=prod && docker restart surgicalhub-php"

# Si le worker doit reprendre une nouvelle version du code des handlers Messenger :
ssh surgicalhub-prod "docker restart surgicalhub-worker"
```

---

## 5. Tests santé obligatoires

Toujours, sans exception :

| Vérification | Commande |
|---|---|
| Frontend charge | `curl -s -o /dev/null -w "%{http_code}" https://surgicalhub.be` (200) |
| API login répond | `curl -s -o /dev/null -w "%{http_code}" -X POST https://api.surgicalhub.be/api/auth/login -d '{}'` (400, jamais 500) |
| `.env` non exposé | `curl -s -o /dev/null -w "%{http_code}" https://api.surgicalhub.be/.env` (404) |
| Login JWT réel | login avec un compte de test → token reçu |
| `/api/me` | rôle correct retourné |
| Containers | `docker ps \| grep surgicalhub` → 3/3 `Up` |
| Logs PHP | `docker logs surgicalhub-php --tail 30` → pas de `CRITICAL`/`exception` lié au déploiement |
| Logs worker | `docker logs surgicalhub-worker --tail 15` → consumer actif |
| Migrations | `doctrine:migrations:status` → `Already at latest version` |

**Si une fonctionnalité majeure a été modifiée par ce déploiement**, ajouter
un test réel ciblé sur cette fonctionnalité (pas seulement les checks
génériques ci-dessus) :

- Utiliser des comptes de test jetables créés via
  `app:user:create` (console, pas l'API admin — pas d'email envoyé), jamais
  des comptes réels d'utilisateurs.
- Exécuter le scénario réel via l'API publique (`https://api.surgicalhub.be`),
  pas seulement `docker exec` à l'intérieur du container — pour vérifier
  aussi Nginx/Traefik/CORS, pas que Symfony.
- **Toujours nettoyer** les comptes/données de test créés à la fin
  (utilisateurs, refresh tokens associés, ressources métier créées) et
  vérifier que le nettoyage a réussi (requête de confirmation vide).
- Si le test de référence implique un envoi d'email réel ou une action non
  réversible (ex: création de compte via `POST /api/admin/users`, qui ne
  peut pas être supprimée — aucun endpoint `DELETE` n'existe), **ne pas
  l'exécuter en chemin de succès** : valider le chemin d'erreur/validation
  à la place (ex: vérifier qu'une règle métier renvoie bien 400) et le
  documenter comme limite assumée dans le rapport final.

---

## 6. Rapport final obligatoire

Toujours produire ce rapport, dans cet ordre, après un déploiement (succès
ou échec) :

```markdown
## Rapport de déploiement — vYYYY.MM.DD-prod

### Version
- Tag prod : vYYYY.MM.DD-prod
- Commit déployé : <sha> (<message>)
- Date : <date+heure>

### Écart constaté avant déploiement
- Commit serveur avant : <sha ou "inconnu, déduit de marqueurs">
- Commits rattrapés : <liste>

### Sauvegardes
- Dump DB : <chemin exact>
- Archive code pré-déploiement : <chemin exact>

### Migrations
- <liste des migrations appliquées> OU "Already at latest version" (avec confirmation que c'était déjà le cas avant, si pertinent)

### Containers
- <état docker ps>

### Tests santé
| Vérification | Résultat |
|---|---|
| ... | ... |

### Tests ciblés (fonctionnalité modifiée)
- <scénario, résultat, nettoyage confirmé>

### Logs
- PHP : <confirmation absence d'erreur, ou liste des erreurs trouvées>
- Worker : <idem>

### Limites assumées
- <ex: chemin de succès d'une création de compte non testé pour raison X>
```

---

## 7. Tag Git — uniquement après validation complète

```bash
git tag -a v2026.06.24-prod -m "Déploiement validé : <résumé commit(s)>"
git push origin v2026.06.24-prod
```

- Le tag est **annoté** (`-a`), pas léger — le message documente ce qui a
  été déployé, pour qu'un `git show v2026.06.24-prod` suffise à comprendre.
- **Avant de pousser le tag, vérifier que le commit ciblé est bien présent
  sur `origin`** (`git log origin/main` ou équivalent). Un tag qui pointe
  vers un commit absent du remote est un mensonge pour le reste de
  l'équipe — si `main` local est en avance sur `origin/main`, pousser
  `main` (ou la branche/PR appropriée) **avant** de pousser le tag.
- Mettre à jour la table "Historique des versions déployées" dans
  `docs/production.md` avec une nouvelle ligne (jamais réécrire une ligne
  existante).

---

## 8. Principe fondamental

La production ne doit **jamais** se retrouver dans un état intermédiaire :

- base de données plus récente que le code déployé (ou inversement) ;
- code plus récent que les migrations appliquées ;
- déploiement partiel / cherry-pick de quelques fichiers ;
- copie manuelle de fichiers individuels en dehors de la procédure `git archive` ;
- version en prod inconnue ou non vérifiable.

**Si l'un de ces cas est détecté à n'importe quelle étape (avant, pendant,
après un déploiement) : arrêter, documenter l'état exact constaté, et
demander une décision explicite avant de continuer.** Ne jamais "réparer en
silence" un état incohérent sans le signaler — c'est ce signalement qui a
permis de détecter et corriger l'incident du 2026-06-24 au lieu de
l'aggraver.
