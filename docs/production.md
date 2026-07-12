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

**Dernier tag déployé : `v2026.07.12-prod-2` → commit `d5bce87`.**
Vérifié le 2026-07-12 par marqueur de fichier réel sur le serveur (pas
seulement par le tag — voir [`docs/deployment-versioning.md`](deployment-versioning.md)
§2.2). Pour confirmer à tout moment :

```bash
git tag -l 'v*-prod' --sort=-creatordate | head -1
ssh surgicalhub-prod "grep -n 'does NOT key on' /opt/stack/apps/surgicalhub/src/backend/src/Service/PlanningAlertService.php"
```

### Historique des versions déployées

_Ajouter une ligne en haut après chaque déploiement validé. Ne jamais
réécrire une ligne existante — c'est un historique._

| Tag | Commit | Date | Notes |
|---|---|---|---|
| `v2026.07.12-prod-2` | `d5bce87` | 2026-07-12 | 3 commits depuis `v2026.07.12-prod` (`6b86305` docs-only, `23cac54`, `d5bce87`). **Lot 1 — refonte emails de premier déploiement** (`23cac54`) : nouveau design (header/pill/tuiles de stats) appliqué à `emails/planning_instrumentist.html.twig` et `emails/planning_surgeon.html.twig` ; ajout d'une section "Missions disponibles" **exclusive à l'email instrumentiste**, listant les missions `OPEN` de la période déployée pour lesquelles le destinataire est réellement éligible (`MissionEligibilityService::evaluate()`, jamais recalculé côté Twig), avec CTA vers `/app/i/offers` ; PDF et pipeline de modification (`planning_change_summary_*`) strictement inchangés (confirmé par diff et par tests). **Lot 2 — MAIL_SAFE_MODE mode `capture`** (`d5bce87`) : un incident survenu le jour même pendant la préparation de ce déploiement (test local contre une copie de données de production) a révélé que le garde-fou D-061 bloquait aussi les emails de test en local/Mailpit, alors que ce transport ne peut techniquement rien délivrer à l'extérieur. Ajout d'un second mode de délivrance, `capture` (nouvelle variable `MAIL_SAFE_DELIVERY_MODE`, défaut `auto`) : recipients laissés intacts + en-tête `X-SurgicalHub-Mail-Safe-Mode: captured-locally` quand `MAILER_DSN` correspond à un sink local vérifié (`MAIL_SAFE_LOCAL_SINKS`) ; repli automatique et loggué en `critical` sur `allowlist` (comportement pré-existant) si `capture` est demandé contre un transport non reconnu comme sink local — jamais de désactivation accidentelle du filtrage contre un vrai relais. Aucune nouvelle migration. Worker + PHP redémarrés (rebuild complet `--no-cache`). 8 nouveaux tests MAIL_SAFE_MODE (capture/allowlist/auto/refus-et-repli/`null://`), 2 nouveaux tests templates email, 795/795 tests backend verts avant déploiement. Tests santé : frontend 200, API login 400, `.env` 404, containers 3/3 Up, logs propres, migrations à jour. **Tests fonctionnels réels en prod** : (a) résolution de configuration testée sans envoi réel (script de diagnostic éphémère, kernel réel, aucun `MessageEvent`) — confirmé `enabled=false`/`deliveryMode=allowlist` en fonctionnement normal, et `isRecognizedLocalSink=false` contre le vrai `MAILER_DSN` Hostinger (le mode `capture` ne peut donc pas s'y activer, même par erreur) ; (b) `MAIL_SAFE_MODE=on` activé temporairement, 4 comptes jetables `@surgicalhub.internal` + 1 site + 2 missions jetables créés, **déploiement réel déclenché via l'API HTTP publique** (`POST /api/planning/deploy`, JWT réel) — 4 emails dispatchés avec succès (instrumentiste avec "Missions disponibles (1)" attendu, 2 chirurgiens, manager), aucun `MAIL_SAFE_MODE: rejected/stripped` loggué (tous les destinataires jetables passent l'allowlist), `deliveryMode=allowlist` confirmé par les logs worker (transport réel Hostinger, pas un sink local) ; (c) pipeline de modification vérifié par marqueurs sur le code réellement chargé (sujet `Modification de votre planning – ...` présent, `Missions disponibles` absent des templates de modification) plutôt que par mutation réelle, pour ne prendre aucun risque sur des données de production — jugé suffisant au vu du diff (aucun fichier de ce lot ne touche ce pipeline) et de la couverture de tests existante. Toutes les données de test (4 comptes, 1 site, 2 missions, 1 `PlanningDeployment`, `notification_event`, `refresh_tokens`) supprimées et confirmées absentes en fin de déploiement. `MAIL_SAFE_MODE` retiré du `.env` du stack après test (`printenv` confirme `UNSET`, résolution `auto` → inactif en prod normale, reconfirmé par un nouveau test de résolution de configuration post-nettoyage). **Anomalie relevée avant déploiement** : les 2 commits n'étaient pas encore poussés sur `origin` au moment du rapport pré-déploiement — corrigé avant toute action serveur. |
| `v2026.07.12-prod` | `8d7bcc9` | 2026-07-12 | 3 commits depuis `v2026.07.11-prod-3` (`90e7c07`, `ddb8fea`, `8d7bcc9`) — réponse directe à l'incident du 2026-07-12 ci-dessous (voir "Historique des incidents"). Nouveau `App\EventListener\MailSafeModeListener` (D-061, `docs/mail-safe-mode.md`) sur `Symfony\Component\Mailer\Event\MessageEvent` : garde-fou centralisé bloquant tout email vers un destinataire non explicitement autorisé (`MAIL_SAFE_ALLOWED_DOMAINS`/`MAIL_SAFE_ALLOWED_RECIPIENTS`), actif par défaut hors production (`MAIL_SAFE_MODE=auto`), activable manuellement en prod pour une session de test (`MAIL_SAFE_MODE=on`) puis obligatoirement redésactivé ensuite. Filtre `To`/`Cc`/`Bcc` à la fois sur les en-têtes du message et sur l'`Envelope` SMTP réel ; rejette l'envoi entier si plus aucun destinataire autorisé ne reste. Audit exhaustif confirmé : un seul point d'interception suffit, tout le mailer de l'app transite par exactement 2 `MessageHandler` (`SendTemplatedEmailMessageHandler`, `SendBillingEmailMessageHandler`), tous deux couverts sans exception. Correctif en cours de route (`8d7bcc9`) : les logs `Billing email dispatched`/`Email dispatched` de ces deux handlers ont été renommés et leur commentaire corrigé après avoir vérifié dans le code source de Symfony que `Mailer::send()` clone le message avant tout `MessageEvent` (le handler ne voit jamais si le listener a filtré/rejeté) — seules les lignes de log `MAIL_SAFE_MODE: ...` émises par le listener lui-même font foi de ce qui a été réellement bloqué ; ceci ne change rien à l'efficacité du blocage lui-même, uniquement à l'endroit où en lire la preuve. Aucune nouvelle migration. Worker + PHP redémarrés (rebuild complet). 13 nouveaux tests (`MailSafeModeListenerTest` : 11, `MailSafeModeIntegrationTest` : 2), 796/796 tests backend verts avant déploiement. **Test fonctionnel réel en prod** : `MAIL_SAFE_MODE=on` activé temporairement (`.env` du stack, `docker compose up -d` pour relecture), comptes jetables `@surgicalhub.internal` + mission réelle existante (id=257) réaffectée temporairement à un instrumentiste jetable pour déclencher un email réel côté chirurgien réel — confirmé bloqué (log `MAIL_SAFE_MODE: rejected an email with no allow-listed recipient left` sur le worker, aucune livraison), puis email vers compte jetable confirmé passant sans filtrage. Mission 257 restaurée à son état d'origine (`instrumentist_id=19`) et vérifiée par relecture SQL. `MAIL_SAFE_MODE` retiré du `.env` de prod après test (`printenv` confirme `UNSET`, résolution `auto` → inactif en prod normale). Tous les comptes/données de test (2 comptes `@surgicalhub.internal`, `refresh_tokens`, `notification_event`, `audit_event` associés) supprimés et confirmés absents en fin de déploiement (`SELECT COUNT(*) ... LIKE 'deploy-test-%@surgicalhub.internal'` → 0). **Limite documentée non corrigée** : `backend/.env.prod.local.example` et `docs/deployment-versioning.md` §5 décrivent encore `.env.prod.local` comme fichier à modifier pour activer `MAIL_SAFE_MODE=on` en prod ; le mécanisme réel découvert pendant ce déploiement est que `docker-compose.yml` de prod lit `/opt/stack/apps/surgicalhub/.env` (`env_file: .env`), pas `.env.prod.local` — à corriger dans un prochain lot de documentation. |
| `v2026.07.11-prod-3` | `0036906` | 2026-07-12 | 6 commits depuis `v2026.07.11-prod-2` (`11287c8`, `50df03e`, `cbb025f`, `8377d04`, `b4cbeb7`, `0036906`). Refonte visuelle des emails de récap (design table-based/inline-CSS, liste unifiée "Modifications (N)") + sujet distinct `Modification de votre planning – {Mois} {Année}` (jamais confondu avec le sujet de déploiement initial `Planning du {from} au {to}`, chemin de code totalement séparé). Nouveau `POST /api/planning/versions/{id}/cancel-all` ("Supprimer ce mois" côté UI) — annule en lot les missions `ASSIGNED`/`OPEN` d'une version `ACTIVE`, jamais de suppression physique, historique `AuditEvent` conservé. Suppression (pas annulation) d'un brouillon de mission fraîchement ajouté en mode Modification avant tout redéploiement. Mode Modification restreint aux versions `ACTIVE` (DRAFT/ARCHIVED rejetés en 400 côté serveur, plus offerts côté UI). Gestion explicite de l'expiration de session : `apiClient` émet un événement `surgicalhub:session-expired` sur tout 401 définitif (refresh déjà tenté et échoué), `AuthContext` bascule immédiatement en anonyme → redirection `/login`, message clair au lieu d'un toast générique, aucune donnée locale non sauvegardée traitée comme sauvegardée. Aucune nouvelle migration. Worker Messenger obligatoirement redémarré (recréé par le rebuild d'image — comportement différent du dev local où le worker est un process long-running sur source montée, documenté dans `docs/docker.md` §9 avec nouvelle cible `make messenger-restart`). Tests santé : frontend 200, API login 401, `.env` 404, containers 3/3 Up, logs propres, transport Messenger `failed` vide. 770/770 tests backend, 369/369 tests frontend, tous verts avant déploiement. **Tests fonctionnels réels en prod** (comptes `ROLE_MANAGER`/`ROLE_SURGEON`/`ROLE_INSTRUMENTIST` jetables `@surgicalhub.internal`, jamais de vraie personne après incident ci-dessous) : ajout de mission → email `Modification de votre planning – Mars 2027` (2 destinataires jetables) ; modification d'horaire → SQL brut + relecture API confirment la persistance (10:00–15:00), email ciblé identique ; `cancel-all` → mission `CANCELLED` (pas supprimée), version `ACTIVE` conservée, 4 `AuditEvent` intacts (`MISSION_ADDED_POST_DEPLOY`/`MISSION_TIME_CHANGED_POST_DEPLOY`/`MISSION_RELEASED_TO_POOL`/`MISSION_CANCELLED_POST_DEPLOY`), email ciblé confirmé ; bundle frontend déployé vérifié contenant `surgicalhub:session-expired` et le message de session expirée (`index-B8gbuXNj.js`) et "Supprimer ce mois" (`PlanningV2Page-DQAHFlY8.js`). **Incident pendant ce déploiement** : le tout premier test (déploiement initial, période 2027-02, site réel) a été exécuté par erreur avec de vrais chirurgiens/instrumentistes plutôt que des comptes jetables — 16 emails réels envoyés (sujet `Planning du 01/02/2027 au 28/02/2027`, liste complète dans le rapport de session). Le pipeline testé était fonctionnellement correct (c'est la méthode de test, pas le code, qui était en cause) ; toutes les données créées ont été supprimées de la base immédiatement après détection, aucun email correctif renvoyé (décision utilisateur), tous les tests suivants basculés sur comptes 100% jetables. Toutes les données de test (3 comptes, 2 `PlanningVersion`, missions, `AuditEvent`, `notification_event`) nettoyées et confirmées absentes en fin de déploiement. |
| `v2026.07.11-prod-2` | `eaf71c0` | 2026-07-11 | Planning V2 — mode Modification : édite un planning déjà déployé dans le même éditeur que "Générer" (réaffecter/libérer/annuler/reprogrammer/ajouter une mission), appliqué en un batch via `POST /api/planning/versions/{id}/apply-modifications`. Backend : `PlanningModificationService` (nouveau) diffe un snapshot avant/après de la version (`PlanningDiffService::computeDiffFromSnapshots`, nouveau) et envoie exactement un email récap ciblé par personne réellement affectée via `PlanningChangeSummaryService` (jusqu'ici câblé nulle part — maintenant piloté par le diff, avec PDF joint par destinataire et logging d'erreur au lieu d'un échec silencieux). `MissionPostDeployService` gagne `notify=false` sur ses mutateurs + `updateSchedule()`/`createPostDeploy()`. Frontend : nouveau `Inspector.tsx` (panneau latéral permanent, remplace l'ancien popover) ; `GeneratePlanningTab` bascule entre mode Génération (bleu) et Modification (ambre), sourçant les lignes depuis les vraies Missions (`missionToPreviewLine`) en mode édition. Aucune nouvelle migration. Tests santé : frontend 200, API login 401, `.env` 404, containers 3/3 Up, logs propres. 93/93 tests backend (64 unitaires + 9 fonctionnels + 20 intégration email), 40/40 tests frontend, tous verts avant déploiement. Test ciblé réel en prod : compte `ROLE_MANAGER` jetable, login JWT réel, `POST /api/planning/versions/5/apply-modifications` avec `lines:[]` sur une version ACTIVE réelle (id=5, 68 missions) → 200, no-op garanti (`created/updated/cancelled/released` tous à 0, aucune mutation, aucun email) — chemin de succès sûr choisi plutôt que le chemin de mutation réelle (irréversible sur des missions réelles) ; compte et refresh token supprimés après coup (confirmé : 0 restant). |
| `v2026.07.11-prod` | `d2f3b54` | 2026-07-11 | Richesse visuelle du picker de réaffectation d'instrumentiste (popover de recherche dans "Générer planning") : photo de profil réelle (repli sur pastille d'initiales) via un nouveau champ `profilePicturePath` sur `InstrumentistListItemResponse`/`GET /api/instrumentists` (existait déjà sur l'entité `User` et sur l'endpoint mono-instrumentiste, jamais exposé sur le listing) ; badge "En congé" + style grisé pour les instrumentistes absents ce jour précis (`GET /api/absences?from=X&to=X`, refetch à chaque ouverture du popover) ; badge "Déjà affecté ailleurs" pour les instrumentistes déjà sur un autre poste actif le même jour dans cette prévisualisation — non bloquant : les sélectionner libère automatiquement leur autre créneau au lieu de créer un double-booking silencieux (`findSameDayAssignmentElsewhere`). `SearchableSelect` gagne des props optionnelles `avatarUrl`/`muted`/`badge` par option (usage site/groupe non affecté). Aucune nouvelle migration (déjà à `Version20260623120000`). Tests santé : frontend 200, API login 401 (pas 400 — comportement pré-existant de l'endpoint, jamais 500), `.env` 404, containers 3/3 Up, logs PHP/worker propres. Test ciblé réel : compte `ROLE_MANAGER` jetable créé via `app:user:create`, login JWT réel (`POST /api/auth/login` → 200), `GET /api/instrumentists` → 200 avec `profilePicturePath` bien présent dans la réponse ; compte et refresh token supprimés après coup (confirmé : 0 restant). |
| `v2026.07.09-prod-2` | `926d980` | 2026-07-09 | Correctif : restaure la réaffectation d'instrumentiste dans "Générer planning" (`GeneratePlanningTab.tsx`). Cause de la régression : l'éditeur de réaffectation (sélection par ligne, suggestions "libérés", réaffectation en masse) avait été codé dans `PlanningGeneratePage.tsx` (commit `6f7c3af`) **après** que sa route ait déjà été retirée en faveur de V2 (commit `26d66ef`) — la fonctionnalité existait dans le code déployé (dev et prod) mais était inaccessible depuis l'interface. Porté dans `GeneratePlanningTab.tsx`, l'écran réellement monté sur `/app/m/planning/v2`. `generateMutation` envoie désormais les lignes éditées + le `previewVersion` propre à chaque mois au backend, qui savait déjà les recevoir (`PlanningGeneratorServiceV2::generate` `$overrideLines`, jamais branché côté frontend). Aucune nouvelle migration. Tests santé : frontend 200, API login 400, `.env` 404, containers 3/3 Up, logs propres. 69/69 tests planning-v2, 347/347 suite frontend complète, vérifié en direct dans le navigateur (réaffectation Sophie Collette → Salve Decorte confirmée visuellement). |
| `v2026.07.09-prod` | `7bf7989` | 2026-07-09 | 17 commits depuis `v2026.06.30-prod` (259 fichiers). Backend : `MissionEligibilityService`/`EligibilityReason` (garde d'éligibilité pré-verrouillage sur `claim()`), `MissionLifecycleChangedMessage`/`Handler` (notifications chirurgien CLAIMED/RELEASED/REASSIGNED/CANCELLED, isolation des échecs par destinataire), `UncoveredReason`/`UncoveredReasonResolver` + refonte `PlanningDeployPdfsMessageHandler` (payloads de notification enrichis, filtrage par préférences), `PlanningCoverageService`/`PlanningVersionHistoryService`/`CoverageSummary` (KPI de couverture + historique de versions). Frontend : actions de cycle de vie mission côté manager (réassigner/annuler/historique/couverture) ; reconstruction visuelle Login, Aujourd'hui, Encodage, Offres, Planning pour coller au prototype `docs/design/` (logique métier inchangée, rendu uniquement) ; ajout `docs/design/` comme référence de vérité design. Aucune nouvelle migration (DB déjà à `Version20260623120000`, identique à la dernière migration locale). Tests santé : frontend 200, API login 400, `.env` 404, containers 3/3 Up, logs PHP/worker propres, 523/523 tests unitaires backend verts avant déploiement. **Limite assumée** : test de connexion JWT réel avec compte jetable non exécuté (création de compte bloquée par le classifieur de permissions autonomes — non couvert explicitement par l'autorisation "tests santé") ; checks génériques (frontend/API/.env/containers/logs) tous validés à la place. |
| `v2026.06.30-prod` | `11bbc0e` | 2026-06-26 | Planning V2 — implémentation du handoff design (MODIFICATIONS-Generer.md) : (1) sélection multi-mois par chips toggle ; (2) prévisualisation groupée jour → chirurgien au lieu d'un tableau plat ; (3) avatars initiales hashées chirurgien + instrumentiste, "À pourvoir" en ambre si aucun ; (4) filtres cliquables sur les états (Tout/OK/Missions ouvertes/À surveiller/Conflits) ; (5) bande ambre inline dans PostCard pour fin de poste proche + suppression des bandeaux INFORMATION pleine largeur (`EndingSoonAlertCard` deleted). Backend : récurrences mensuelles `MONTHLY_NTH_WEEKDAY` avec `monthWeeks[]` (Batch 14A/B/C), migration `Version20260623120000` (`recurrence_month_weeks` colonne JSON). Vérifié via Playwright : chip "Planning V2 — nouveau module", 4 onglets, 6 chips mois sur "Générer", 0 erreur JS. |
| `v2026.06.29-prod` | `0e1116b` | 2026-06-25 | **Fix critique** : la création d'absence était impossible en production ("Cannot read properties of null (reading 'id')" sur chaque clic Enregistrer, déterministe, pas une course de double-clic). Cause : `mutationFn`/`onMutate` lisaient `selectedPerson` via fermeture sur l'état du composant ; `onMutate` appelait lui-même `resetCreateForm()` (→ `selectedPerson=null`) avant que `mutationFn` ne s'exécute, et React Query relit `mutationFn` en direct au moment de l'appel — la fermeture utilisée était donc celle du re-rendu post-reset. Aucun appel `POST /api/absences` n'était jamais émis (confirmé). Cause root-causée par instrumentation temporaire en conditions réelles (Chromium/Playwright contre la prod, logs retirés avant commit). Corrigé : variables de mutation snapshotées une fois dans `submitCreate()`, plus aucune lecture d'état live dans `mutationFn`/`onMutate`. Vérifié en double après déploiement : création réelle pour un chirurgien (Arnaud Deltour, id=8) et un instrumentiste (compte de test), `POST /api/absences` → 201 dans les deux cas, absence visible dans la liste, supprimée après vérification. Aucune erreur logs. Aucune migration. |
| `v2026.06.28-prod` | `0251ebf` | 2026-06-25 | Fix : `PersonSearchSelect` ne comparait la recherche qu'à chaque champ séparément (`firstname`/`lastname`/`email`/`rôle`), jamais au nom complet — un nom à deux mots ("Arnaud Deltour") ne matchait jamais ("Aucune personne ne correspond" malgré l'existence réelle, cas réel : surgeon id=8). Corrigé : recherche sur le nom complet dans les deux ordres ("Prénom Nom" et "Nom Prénom"), insensible accents/casse/espaces, `firstname`/`lastname` trimmés à la source (anomalie de donnée réelle : espace finale sur `firstname` en prod). **Affichage Prénom Nom conservé volontairement** — une inversion Nom Prénom serait un lot UX séparé. Aucune migration. |
| `v2026.06.27-prod` | `e4edb43` | 2026-06-25 | Fix : garde anti-double-soumission (`submittingRef`, synchrone, indépendante de `createMutation.isPending`) sur "Enregistrer" dans `AbsencesPage` — empêchait la création de 2 absences sur double-clic rapide (régression rendue plus probable depuis que la sélection de personne est instantanée, sans débounce). Garde libérée dans `onSettled` (succès et erreur), couvre les deux modes (période/jours isolés). Ajoute aussi une garde défensive non invasive sur `selectedPerson` (toast clair au lieu d'un crash). **N'est pas la résolution du signalement "Cannot read properties of null (reading 'id')"** — cause racine toujours non confirmée, en attente de stack trace complète. Aucune migration. |
| `v2026.06.26-prod` | `424af94` | 2026-06-25 | `PersonSearchSelect` rendu générique (prop `scope`: `all`/`instrumentists`/`surgeons`, défaut `all`), recherche serveur débouncée remplacée par un chargement unique + filtrage 100% client (retour terrain défavorable sur l'ancienne UX), documenté dans `docs/architecture.md`. Aucune migration. Tests santé génériques + fonctionnels (listes actives, création/suppression d'absence) validés. |
| `v2026.06.25-prod` | `67446df` | 2026-06-25 | Lot relances manager Absences ("Demander les congés" / "Confirmer les congés encodés", emails individuels, D-051) + fix sécurité : un body JSON malformé sur `request-missing`/`confirm-encoded` retombait silencieusement sur "envoyer à tout le monde" — bug découvert pendant les tests santé post-déploiement (19 emails non prévus envoyés à de vrais utilisateurs par un test à moi, cause : accents corrompus en transit → JSON invalide). Corrigé en `67446df` (retourne 400 sur JSON invalide), re-testé restreint à des comptes jetables, aucune autre régression. Tag vérifié par grep sur `decodeJsonBody` dans le fichier réellement chargé par le conteneur `surgicalhub-php`. |
| `v2026.06.24-prod` | `bae8ec1` | 2026-06-24 | Lot absences isolées + alertes chevauchantes + rattrapage Planning V2 launch (8296e70) et règles site-membership (eb1fa15). Tag annoté créé et poussé sur `origin` après validation des tests santé. Le commit doc `9e926f6` (ajout de `deployment-versioning.md`) a été poussé sur `main` ensuite mais n'est **pas** déployé sur le serveur — le tag pointe volontairement sur `bae8ec1`, pas sur `HEAD`. |

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

## Historique des incidents

_Traçabilité complète — ne jamais réécrire une entrée existante. Chaque
incident documente : date, version déployée, description, cause racine,
impact, actions correctives, actions préventives._

### 2026-07-12 — Envoi de 16 emails réels lors d'un test post-déploiement

**Version déployée** : `v2026.07.11-prod-3` (commit `0036906`).

**Description** : lors du tout premier test fonctionnel post-déploiement
(validation du flux "déploiement initial de planning"), le test a été exécuté
directement contre la base de production avec de **vraies données** —
chirurgiens et instrumentistes réels d'un site réel — au lieu de comptes
jetables dédiés aux tests. Le `MAILER_DSN` de production pointant vers un
vrai relais SMTP (Hostinger, contrairement au `MAILER_DSN` local qui pointe
vers un catcher Mailpit), le déploiement de ce planning fictif (période
février 2027, jamais réelle) a réellement envoyé **16 emails** aux adresses
suivantes, avec le sujet `Planning du 01/02/2027 au 28/02/2027` :

```text
salvedecorte@gmail.com, cvanmess@gmail.com, dianedemoor@gmail.com,
sophie.gillard@surgeryhub.be, sophie@hospiathome.be,
fdetrembleur@surgery-supports-solutions.be, perrine.pineux@gmail.com,
ewillemart@yahoo.fr, lejeuneetienne@yahoo.fr, jdemuylder23@hotmail.com,
seapetronilia@hotmail.com, berger.yorick@gmail.com,
philippe.schiepers@hotmail.com, arnauddeltour@hotmail.com,
urgyanstephane@gmail.com, samy.ftaita@hotmail.com
```

**Cause racine** : purement procédurale — aucun défaut du code déployé. La
procédure de test post-déploiement (`docs/deployment-versioning.md` §5)
demandait déjà des "comptes et données de test jetables", mais rien ne
l'imposait techniquement : rien n'empêchait un test de réutiliser des
missions/comptes réels par erreur, dans un environnement (la production) où
le mailer envoie réellement.

**Impact** : 16 personnes réelles (chirurgiens et instrumentistes) ont reçu
un email annonçant un planning fictif pour février 2027. Aucune donnée
personnelle exposée au-delà de ce qui figure normalement dans un email de
planning légitime (nom, créneaux). Aucun email correctif renvoyé (décision
explicite de l'équipe) — la date manifestement future (2027) rend l'email
identifiable comme anomalie par les destinataires.

**Actions correctives réalisées** :
- Toutes les données créées pour ce test (`PlanningVersion`, missions,
  `PlanningDeployment`) supprimées de la base immédiatement après détection.
- Tous les tests fonctionnels restants de cette session basculés sur des
  comptes 100 % jetables (`@surgicalhub.internal`), jamais de données réelles.
- Toutes les données de test (comptes, `PlanningVersion`, missions,
  `AuditEvent`, `notification_event`) nettoyées et confirmées absentes en fin
  de déploiement.

**Actions préventives** :
- **`MAIL_SAFE_MODE`** (D-061, `docs/mail-safe-mode.md`) — garde-fou
  technique centralisé (`App\EventListener\MailSafeModeListener`, sur
  `Symfony\Component\Mailer\Event\MessageEvent`, le point par lequel *tout*
  email sortant transite) bloquant tout destinataire non explicitement
  autorisé, actif par défaut dans tout environnement non-production, et
  activable temporairement en production (`MAIL_SAFE_MODE=on`) pour toute
  session de test manuel — c'est précisément le mécanisme qui aurait empêché
  cet incident.
- `docs/deployment-versioning.md` §5 mis à jour : activer `MAIL_SAFE_MODE=on`
  est désormais une étape **obligatoire**, non optionnelle, avant tout test
  fonctionnel touchant un flux email en production.

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
