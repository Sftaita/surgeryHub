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

### Version visible dans l'application (D-162)

La version qui tourne est aussi affichée en bas de « Mon profil », dans les trois espaces
(manager, chirurgien, instrumentiste). Elle est lue par `GET /api/version` et ne s'écrit
jamais à la main. Elle repose sur deux sources, chacune alimentée par cette procédure :

- **Le commit exécuté** : `backend/REVISION` (et `frontend/src/app/features/app-version/buildRevision.ts`)
  contient `$Format:%H$`. `git archive` (§4.3) le remplace par le SHA exact du commit archivé
  (attribut `export-subst`, `.gitattributes`). L'image construite connaît donc son commit sans
  aucune étape manuelle. Dans un clone de travail, la valeur reste littérale : l'application
  affiche « développement ».
- **Le registre des versions officielles** : `var/release/releases.json`, sur le volume
  persistant `surgicalhub_var` (il survit aux rebuilds et aux rollbacks). Une ligne n'y est
  ajoutée **qu'après** les tests de santé et la création du tag (§7), par
  `app:release:mark <tag> --commit=<sha>`.

Une version n'est donc affichée comme officielle (`SurgicalHub · vYYYY.MM.DD-prod · <sha>`)
que si le commit exécuté figure dans le registre :

| Situation | Affichage |
|---|---|
| Déploiement en cours, tag pas encore créé | « version en cours de validation · `<sha>` » — jamais un tag |
| Tag créé et enregistré | `SurgicalHub · vYYYY.MM.DD-prod · <sha>`, sans redéployer |
| Rollback | La version restaurée (son tag, si elle a été enregistrée), jamais celle abandonnée |
| Métadonnées illisibles | Jamais un tag supposé (« en cours de validation », ou « version indisponible » si l'API ne répond pas) |

Ce mécanisme ne remplace pas le tag Git : le dernier tag `*-prod` reste la source de vérité.
Le registre n'en est que le reflet dans le serveur, vérifié à chaque déploiement (§5 et §7).

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

### 2.4 Rapport des développements non déployés (D-169) — obligatoire

Une release ne doit pas faire oublier un correctif déjà développé (cas de D-157 : testé le
2026-10-20, resté en PR pendant que cinq autres versions étaient déployées). Avant toute mise en
production, produire et **afficher** :

```bash
deploy/deploy.sh pending <SHA>     # = node deploy/work-inventory/inventory.mjs --fetch --server --release <SHA>
```

Le rapport est calculé (Git, PR GitHub, serveur en lecture seule), puis croisé avec le registre des
décisions humaines `docs/work-registry.json`. Il liste, dans cet ordre :

1. les **correctifs P0/P1 absents de la production**, avec « ABSENT DE LA RELEASE PRÉVUE » s'ils ne
   sont pas dans `<SHA>`, et « OUBLIÉ » s'ils sont prêts depuis plus de `staleDays` jours ;
2. les **travaux non suivis** (branche, PR, worktree, stash, commit de main, script ou ligne de
   crontab sans entrée de registre) ;
3. le code fusionné non déployé, en séparant l'applicatif de ce qui est hors archive (docs, scripts
   serveur), et les migrations fusionnées non déployées ;
4. les PR ouvertes, les branches non intégrées (conflits, migrations, jamais poussées), les
   modifications locales absentes de Git ;
5. le serveur : release active, migrations non appliquées, scripts versionnés non installés ou
   différents, scripts hors dépôt modifiés, crontab différente de la référence versionnée ;
6. les opérations hors code en attente (installation, reprise de données, configuration, validation) ;
   une opération n'est close que marquée `fait` avec `confirmedAt` et `evidence` (preuve de
   l'exécution réelle), sinon elle reste affichée ;
7. les incohérences du registre et ce qui est nouveau depuis le dernier audit ;
8. les branches qui modifient les mêmes fichiers que la release (risque de conflit).

**Le rapport signale, il ne décide pas** : il ne bloque pas le déploiement et ne fusionne rien. Le
périmètre de la release reste une décision explicite, consignée dans le rapport §2.3 (« Décision »).
Tout correctif P0/P1 absent de la release y est mentionné avec la raison de son exclusion. Ce qui
n'a pas pu être vérifié (serveur injoignable, API GitHub indisponible) est écrit « Non vérifié »,
jamais supposé conforme. `deploy/deploy.sh prepare` affiche aussi un résumé non bloquant.

Le registre est mis à jour dans la PR qui fait évoluer un chantier (statut, prochaine action,
opérations), et `deploy/production/server-reference/` dans la PR de toute installation ou
modification serveur. Le même rapport (sans le serveur) tourne chaque lundi et sur chaque PR
(`.github/workflows/work-inventory.yml`).

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

### 4.3 Archive, transfert, release, build, activation — `deploy/deploy.sh` (D-163)

Depuis D-163, le déploiement passe **exclusivement** par `deploy/deploy.sh` (commandes
détaillées : `docs/production.md` §1-6). Le script rend mécanique ce que cette procédure exige,
et chaque étape s'arrête avant toute modification du serveur dès qu'un contrôle échoue.

| Exigence | Mécanisme |
|---|---|
| Ce qui est déployé = un commit validé | SHA complet, fusionné sur `origin/main` ; `git archive` de ce commit, jamais le disque |
| Rien d'oublié (incident de `docker/`, figé depuis le 2026-08-02) | Périmètre dérivé du compose versionné (`deploy/production/docker-compose.yml`) et des `COPY` de ses Dockerfiles (`deploy.sh paths`) |
| Jamais une autre archive (incident du 2026-10-21) | Nom = `surgicalhub-<SHA>.tar.gz` ; SHA-256 calculée localement, vérifiée sur le serveur avant tout renommage ou extraction ; `backend/REVISION` = SHA |
| Aucun secret | Refus de `.env.local`, `.env.prod`, `*.pem`, `*.key`, `var/`, `vendor/`, `node_modules/` ; `.env` reste hors de l'archive |
| Fichiers supprimés de Git | Chaque release est un arbre neuf (`releases/<SHA>/`) : rien de périmé n'est construit, rien n'est supprimé sur place ; `stage` liste les fichiers retirés |
| Pas de déploiement partiel | Une release contient tout le périmètre ; le build part de cette release, jamais de `src/` |
| Échec de build | Images `:<SHA>` uniquement ; rien n'est activé tant que le build n'est pas vérifié |
| Rollback complet | `deploy.sh rollback <SHA>` : release, compose et images restaurés ensemble |

**Contrôle après extraction** : `releases/<SHA>/backend/REVISION` = SHA, vérifié par `stage`.
L'ancienne vérification manuelle de `src/backend/REVISION` (D-162) n'a plus lieu d'être.

> Avant D-163 : `git archive … -- backend frontend docker-compose.yml`, extrait sur place dans
> `src/`. Ce mode n'incluait pas `docker/` et laissait sur place les fichiers supprimés de Git :
> 4 tests retirés de Git étaient encore copiés dans l'image le 2026-10-22. Il ne doit plus être
> utilisé.

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
| Version exécutée (D-162) | `docker exec surgicalhub-php php bin/console app:release:show` → `Commit exécuté` = SHA du commit déployé, `Statut` = `PENDING_VALIDATION` (le tag n'existe pas encore) |
| Release active (D-163) | `deploy/deploy.sh status` → release active = SHA déployé |
| Cache frontend (D-163) | `curl -sI https://surgicalhub.be/` → `Cache-Control: no-cache` ; un asset `/assets/<nom>.js` → `immutable` ; `/assets/inexistant.js` → 404 |

**Si une fonctionnalité majeure a été modifiée par ce déploiement**, ajouter
un test réel ciblé sur cette fonctionnalité (pas seulement les checks
génériques ci-dessus) :

- Utiliser des comptes de test jetables créés via
  `app:user:create` (console, pas l'API admin — pas d'email envoyé), jamais
  des comptes réels d'utilisateurs. Domaine `@surgicalhub.internal`
  obligatoire (autorisé par défaut par `MAIL_SAFE_MODE`, voir point suivant).
- **Obligatoire dès que le test touche, même indirectement, un flux qui
  envoie un email** (déploiement de planning, modification, facturation,
  invitations, relances…) : activer `MAIL_SAFE_MODE=on` (ajouter la ligne à
  `/opt/stack/apps/surgicalhub/.env` — **pas** `.env.prod.local`, qui n'est
  pas lu par ce stack ; c'est `docker-compose.yml` qui injecte ce `.env`
  au niveau du stack via `env_file:`, voir `backend/.env.prod.local.example`
  pour le détail — puis `docker compose up -d` — un simple `docker restart`
  ne relit pas le fichier) **avant** d'exécuter le moindre test, et le
  retirer (`docker compose up -d` à nouveau) juste après. Voir
  `docs/mail-safe-mode.md` §3.3 pour la procédure complète. **Ne jamais
  supposer** qu'utiliser des comptes jetables suffit à elle seule — c'est
  exactement l'hypothèse qui a échoué lors de l'incident du 2026-07-12
  (`docs/production.md`, historique des incidents) : une manipulation
  utilisant par erreur de vraies missions/personnes reste possible malgré la
  discipline, `MAIL_SAFE_MODE=on` la rend inoffensive même dans ce cas.
- Exécuter le scénario réel via l'API publique (`https://api.surgicalhub.be`),
  pas seulement `docker exec` à l'intérieur du container — pour vérifier
  aussi Nginx/Traefik/CORS, pas que Symfony.
- **Toujours nettoyer** les comptes/données de test créés à la fin
  (utilisateurs, refresh tokens associés, ressources métier créées) et
  vérifier que le nettoyage a réussi (requête de confirmation vide).
  Un compte de test qui ne peut pas être supprimé (missions, audit) est
  **désactivé** (`active = 0`) : un compte manager/admin actif reçoit toutes
  les notifications « tous les managers » (D-149 — incident du compte
  `…+testmgr1782332852@gmail.com`, resté actif de juin à octobre 2026).
  Contrôle final obligatoire, résultat attendu vide :

  ```sql
  SELECT id, email, roles, created_at FROM user
  WHERE active = 1 AND (email LIKE '%@surgicalhub.internal'
        OR email LIKE '%+test%' OR email LIKE '%deploy%test%' OR email LIKE '%smoke%')
    AND email <> 'system@surgicalhub.internal';
  ```
- Pour une action non réversible et indépendante des emails (ex: création de
  compte via `POST /api/admin/users`, qui ne peut pas être supprimée — aucun
  endpoint `DELETE` n'existe), **ne pas l'exécuter en chemin de succès** :
  valider le chemin d'erreur/validation à la place (ex: vérifier qu'une
  règle métier renvoie bien 400) et le documenter comme limite assumée dans
  le rapport final.

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

### 7.1 Enregistrer la version officielle sur le serveur (D-162) — obligatoire

Juste après avoir poussé le tag, et seulement alors :

```bash
deploy/deploy.sh mark <SHA> vYYYY.MM.DD-prod   # D-163 : refuse si le tag n'est pas sur origin ou ne pointe pas sur SHA,
                                              # ou si SHA n'est pas la release active ; puis app:release:show
```

La commande refuse un tag mal formé, un SHA différent du code exécuté, et un tag déjà
enregistré pour un autre commit. Elle est idempotente. Aucun redémarrage n'est nécessaire.

**Contrôle de cohérence obligatoire.** Ces trois valeurs doivent être identiques :
1. le commit pointé par le tag Git ;
2. le `Commit exécuté` de `app:release:show` (`Statut` = `OFFICIAL`, `Version officielle` = le tag) ;
3. la version affichée en bas de « Mon profil » (`SurgicalHub · vYYYY.MM.DD-prod · <sha>`),
   lue avec un compte de test via l'API publique (`GET /api/version`).

Tout écart est une anomalie à signaler (§8). Après un **rollback**, `app:release:show` doit
afficher le commit et le tag de la version restaurée. Un rollback vers une version antérieure
à D-162 restaure aussi l'ancien frontend, sans affichage de version.

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
