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

**Dernier tag déployé : `v2026.07.31-prod` → commit `196642c`.**
Vérifié le 2026-07-31 par marqueur de fichier réel sur le serveur (pas
seulement par le tag — voir [`docs/deployment-versioning.md`](deployment-versioning.md)
§2.2). Pour confirmer à tout moment :

```bash
git tag -l 'v*-prod' --sort=-creatordate | head -1
ssh surgicalhub-prod "test -f /opt/stack/apps/surgicalhub/src/backend/src/Controller/Api/NotificationController.php && echo PRESENT"
```

### Historique des versions déployées

_Ajouter une ligne en haut après chaque déploiement validé. Ne jamais
réécrire une ligne existante — c'est un historique._

| Tag | Commit | Date | Notes |
|---|---|---|---|
| `v2026.07.31-prod` | `196642c` | 2026-07-31 | **Lot PWA/mobile/notifications/offres/administration** (audit complet 2026-07-29, révisé lors d'une revue post-rapport le même jour). 2 commits depuis `v2026.07.28-prod-2` (`c6d1de3`) : `d6c9f06` (docs-only, hors périmètre de l'archive déployée) et `196642c`. **Notifications internes** (`GET/POST /api/notifications*`, D-086) : `NotificationEvent.seenAt` enfin lu/écrit, cloche+historique+préférences par catégorie pour manager/admin **et** instrumentiste (auparavant réservé à l'instrumentiste via un cache `localStorage` non synchronisé — retiré intégralement, `notifications.store.ts`/`useNotifications.ts` supprimés). **Badge "offres non lues"** (`User.offersLastSeenAt`, D-087) : remplace un badge cumulatif par un checkpoint serveur (`GET /api/missions/offers/unread-count`, `POST /api/me/offers-seen`). **Administration** (D-088) : `change-role` accepte `ROLE_ADMIN` comme cible (+ commande console `app:user:promote-to-admin`, non exécutée sur `samy.ftaita89@gmail.com` — décision utilisateur explicite de ne pas promouvoir ce compte pour l'instant) ; `resend-invitation` gagne un anti-spam sous verrou pessimiste MySQL réel (`SELECT ... FOR UPDATE`, pas un simple check applicatif) et une résilience email (échec de dispatch loggué, jamais bloquant). **PWA** : icônes maskable régénérées à partir du vrai logo, safe-areas iOS (`env(safe-area-inset-*)`, `100dvh` avec repli `@supports`), service worker désormais avec un vrai handler `fetch` (network-first sur icônes/manifest), `landing.html` (HTML statique obsolète) supprimé au profit de `LandingPage.tsx` retravaillée (responsive, nav mobile, cibles tactiles ≥44px). 1 migration additive : `Version20260729140000` (`user.offers_last_seen_at`, nullable, `down()` symétrique). **Bugs trouvés et corrigés avant ce commit** (revue hunk-par-hunk du diff, requise par la présence d'un chantier Planning V2 non commité dans le même répertoire de travail) : (1) une passe antérieure de la même session avait écrasé un vrai enregistrement de décision déjà commité (`D-085` — rotation des clés VAPID compromises, sans rapport avec ce lot) en réutilisant son numéro pour une nouvelle décision ; restauré à l'identique depuis `HEAD`, les 3 nouvelles décisions renumérotées `D-086`/`D-087`/`D-088` ; (2) un patch hunk construit à la main pour `OffersPage.test.tsx` avait une erreur de comptage de lignes laissant un bloc `describe()` non fermé — invisible dans le répertoire de travail (masqué par du contenu non indexé), aurait cassé le build une fois committé ; détecté en construisant l'index en commit temporaire jetable et en le vérifiant dans un `git worktree` isolé (`tsc --noEmit` + `php -l` + suite complète) avant le commit réel. Commit final scrupuleusement scopé : fichiers du chantier Planning V2 (suppression des pages/contrôleurs V1) et de la réorganisation de navigation manager D-079 préexistants dans le même répertoire de travail, **tous exclus** (vérifié explicitement, `git diff --cached --name-only \| grep -i planning` vide) ; 5 fichiers mixtes (`docs/api.md`, `docs/architecture.md`, `docs/decisions.md`, `OffersPage.tsx`, `OffersPage.test.tsx`) scindés au niveau du hunk via patches construits à la main plutôt que `git add` entier. Suite complète avant déploiement : 1621/1621 backend (1 test *risky* préexistant sans rapport, handler d'erreur non nettoyé dans `AbsenceControllerTest`), 823/823 frontend (répertoire de travail) + 887/887 (snapshot indexé isolé, incluant les fichiers de test hérités du chantier Planning V2 non touchés par ce lot), `tsc --noEmit` clean, `php -l` clean sur tous les fichiers PHP indexés. Migration dry-run relue avant exécution réelle (1 migration, SQL confirmé additif uniquement). Rebuild complet `--no-cache`, 3/3 containers recréés. Sauvegardes : dump MySQL `/home/deploy/backups/mysql/all_20260731_050246.sql.gz` (904 Ko), archive code `/home/deploy/backups/code/src_pre_deploy_20260731_050253.tar.gz` (10 420 189 octets). **Tests fonctionnels réels en prod** : 3 comptes jetables `@surgicalhub.internal` (MANAGER/INSTRUMENTIST/ADMIN) créés via `app:user:create` (aucun email envoyé) ; login JWT réel → `200` pour les 3 ; `/api/me` → rôle correct pour les 3 ; côté manager : `GET/POST /api/notifications*` (liste vide, `unreadCount:0`, `mark-all-seen` idempotent), `GET`+`PATCH /api/me/notification-preferences` (écriture confirmée persistée) ; côté instrumentiste : `/api/missions/offers/unread-count` (`0`), `POST /api/me/offers-seen` (checkpoint réel horodaté `200`) ; côté admin jetable : `POST /api/admin/users/{id}/resend-invitation` sur un compte déjà activé → `409` (chemin d'erreur choisi plutôt qu'un vrai renvoi d'invitation, aucun email déclenché) ; `POST /api/admin/users/{id}/change-role` `ROLE_ADMIN` sur le compte manager jetable → `200`, `SiteMembership` (vide) inchangées, `UserAuditEvent USER_ROLE_CHANGED` réel confirmé via `GET /api/admin/audit` — valide en conditions réelles le chemin API partagé par la commande `app:user:promote-to-admin`, sans jamais l'utiliser sur un compte réel. PWA vérifiée : `manifest.json` (200, icônes maskable référencées), icônes maskable/standard (200), `sw.js` (200), `theme-color` mis à jour dans le HTML servi (`#1F6B4F`). Nettoyage : les 3 comptes de test ont nécessité un nettoyage SQL explicite (pas seulement une suppression best-effort) car l'un d'eux avait été temporairement promu `ROLE_ADMIN` pendant le test ci-dessus — `refresh_tokens`/`user_audit_event`/`notification_preference` purgés dans l'ordre FK avant suppression des 3 lignes `user` ; 0 résidu confirmé (`SELECT COUNT(*) FROM user WHERE email LIKE 'test-%@surgicalhub.internal'` → 0), liste `ROLE_ADMIN` reconfirmée strictement identique à avant le déploiement (2 comptes : `admin@surgicalhub.be`, `deploy.test.1782201617@surgicalhub.be` — ce dernier un résidu d'un déploiement antérieur, non touché, signalé mais hors périmètre de ce lot). Tests santé génériques : frontend `200`, login `400` (jamais `500`), `.env` `404`, 3/3 containers `Up`, logs PHP/worker sans `CRITICAL`/`exception` hors dépréciations Doctrine/Symfony bénignes préexistantes (implicit-commit sur `ALTER TABLE`, `report_fields_where_declared`, nœuds `firewall`/`user_provider` du bundle JWT refresh), migrations `Already at latest version`. **Limite assumée** : correctifs safe-area/`dvh` iOS déployés mais **non validés sur un appareil ou simulateur iOS réel** — à vérifier manuellement après ce déploiement, ne bloque pas la mise en production. **Décision utilisateur** : promotion `ROLE_ADMIN` de `samy.ftaita89@gmail.com` explicitement annulée pour ce déploiement (à traiter séparément, plus tard, si toujours souhaitée). |
| `v2026.07.28-prod-2` | `c6d1de3` | 2026-07-28 | **Onboarding première connexion instrumentiste** (4 écrans Bienvenue/Installer/Missions/Encodage, `SheetModal` réutilisé, aucune donnée financière/patient affichée) + **correction minimale d'une référence orpheline** dans `AppRouter.tsx` (`PlanningVersionDetailPage`, supprimée par le chantier Planning en cours, non commité — corrigé uniquement le point d'entrée nécessaire au build, aucun autre fichier du chantier Planning touché). 2 commits depuis `v2026.07.28-prod` (`690d30d`) : `428a40d` (docs-only, hors périmètre de l'archive déployée) et `c6d1de3`. Backend : nouvelle colonne nullable `user.instrumentist_onboarding_completed_at` (migration `Version20260728165034`, additive uniquement, `down()` symétrique), nouvel endpoint `POST /api/me/onboarding/complete` (même convention que `/api/me/profile-picture` — pas de Voter dédié, ressource propre uniquement, idempotent, réservé INSTRUMENTIST), exposé sur `/api/me` (`instrumentistOnboardingCompleted`). Rejouable depuis Paramètres ("Revoir la présentation de SurgicalHub") sans jamais toucher l'état serveur. Tests avant déploiement : 33/33 tests frontend ciblés (4 fichiers, individuels puis combinés), `npm run build` clean (`tsc -b && vite build`, exit 0, après correction de la référence Planning orpheline), `MeControllerTest` 7/7 (18 assertions — code de sortie 1 dû uniquement au flag PHPUnit "risky" sur 2 dépréciations Doctrine/Symfony préexistantes, 0 échec/0 erreur), `BusinessDateTimeColumnConventionTest` 2/2. Migration dry-run relue avant exécution réelle (1 migration, 3 requêtes en dry-run/1 en réel, SQL confirmé additif : `ALTER TABLE user ADD instrumentist_onboarding_completed_at DATETIME DEFAULT NULL`). Rebuild complet `--no-cache`, 3/3 containers recréés. Sauvegardes : dump MySQL `/home/deploy/backups/mysql/all_20260728_205322.sql.gz` (904 Ko), archive code `/home/deploy/backups/code/src_pre_deploy_20260728_205330.tar.gz` (10 408 128 octets). **Tests fonctionnels réels en prod** : compte jetable `onboarding-test-1785272415@surgicalhub.internal` (`ROLE_INSTRUMENTIST`) créé via `app:user:create` ; login JWT réel → `200` ; `/api/me` avant complétion → `instrumentistOnboardingCompleted:false` ; `POST /api/me/onboarding/complete` → `200` ; `/api/me` après → `true` ; second appel → `200`/`true` inchangé (idempotence confirmée en conditions réelles) ; compte et refresh token supprimés, 0 résidu confirmé. Tests santé génériques : frontend `200`, login `401` (comportement pré-existant de l'endpoint, jamais `500` — cf. note `v2026.07.11-prod`), `.env` `404`, 3/3 containers `Up`, migrations `Already at latest version`, logs PHP/worker sans `CRITICAL`/`exception` hors dépréciations bénignes préexistantes. **Anomalie relevée avant déploiement (non bloquante)** : `git status` non propre dans `backend/`/`frontend/` au moment du rapport d'écart — entièrement le chantier Planning préexistant non commité, sans rapport avec ce lot ; sans impact car le déploiement utilise `git archive HEAD` (jamais le disque de travail), qui ne peut par construction inclure du contenu non committé. **Limite assumée** : `docs/api.md`/`docs/architecture.md`/`docs/decisions.md` non mis à jour pour ce lot (à faire séparément). |
| `v2026.07.28-prod` | `690d30d` | 2026-07-28 | **15 commits depuis `v2026.07.27-prod` (`0fe10f0`)** — écart réel de 16 commits en comptant `fc09ba6` (docs-only, jamais déployé, exclu de `git archive` par construction). Rapport d'écart produit et validé avant toute action serveur (`docs/deployment-versioning.md` §2). **D-079 — navigation manager** : sidebar réorganisée en groupes (Dashboard/Missions/Instrumentistes/Chirurgiens/Établissements, Catalogue, Planning, Facturation, Administration ADMIN-only), badges de demandes (`useNavBadgeCount`, générique). **Dashboard manager** (nouvel écran, endpoints déjà existants — aucun nouveau contrat backend). **Page Prestations** remplace `BillingConfigPage` (tarification par firme, `RateVersionManager`). **Menu compte desktop** (Push/PWA/déconnexion) puis **accès Profil** ajouté. **Profil manager/admin** (`/app/m/profile`) : identité lecture seule + photo modifiable via le pipeline déjà existant (`/api/me`, `POST /api/me/profile-picture`) — aucun `PATCH /api/me`, aucun changement self-service d'e-mail ou de mot de passe (gaps produit documentés, pas comblés). **Navigation mobile instrumentiste simplifiée** : retrait d'items redondants/factices (Messages n'avait aucune destination réelle ; Notifications/Profil restent accessibles via la bande de marque partagée). **Catalogue** : unification des demandes matériel et types d'intervention. **Encodage** : justification obligatoire en l'absence de matériel encodé (mission `BLOCK` uniquement). **Permissions** : `ac2082e` étend la gestion des firmes de `ROLE_MANAGER` à `BillingVoter::MANAGE` (MANAGER ou ADMIN). **Nettoyage** : suppression de `MissionEncodingController` (mal placé dans `src/Entity/`, jamais autoloadé — anomalie relevée dans la ligne `v2026.07.27-prod` ci-dessous, désormais corrigée). Historique Git localement réécrit avant push (`git rebase -i`) pour corriger une dépendance d'un ancien commit vers un commit futur (assertion de test anticipant D-079) — 15 commits, mêmes messages, même contenu cumulé, uniquement des SHA différents à partir du point corrigé ; validé par `range-diff` et comparaison stricte des arbres avant push (branche de sauvegarde locale `backup/pre-push-history-fix` conservée). Aucune migration. 806/806 tests frontend, 1602/1602 tests backend, `tsc --noEmit` clean, build clean — tous exécutés sur `HEAD` isolé (46 lignes hors périmètre d'un autre chantier mises de côté via `git stash`, restaurées à l'identique, jamais incluses dans l'archive de déploiement). Sauvegardes : dump MySQL `/home/deploy/backups/mysql/all_20260728_134551.sql.gz` (901 Ko), archive code `/home/deploy/backups/code/src_pre_deploy_20260728_134553.tar.gz` (9,9 Mo). Espace disque avant déploiement : 68 Go libres / 96 Go (30 % utilisé). Rebuild complet `--no-cache`, 3/3 containers recréés (`BUILD_EXIT=0`). **Tests fonctionnels réels en prod** : 3 comptes jetables `@surgicalhub.internal` (un par rôle MANAGER/ADMIN/INSTRUMENTIST) créés via `app:user:create` ; login JWT réel → `200` pour les 3 ; `/api/me` → rôle correct pour les 3 (contrat exact utilisé par la page Profil) ; endpoints Dashboard/Prestations/Demandes (`/api/missions`, `/api/sites`, `/api/instrumentists`, `/api/surgeons`, `/api/material-item-requests`, `/api/intervention-type-requests`, `/api/financial-statistics/overview`+`pipeline`, `/api/firms`, `/api/intervention-types`) → `200` avec données réelles ; permission firmes vérifiée par le chemin d'erreur (`POST /api/firms` corps vide : ADMIN → `422`, preuve que la permission est désormais accordée avant l'échec de validation ; INSTRUMENTIST → `403`, confirmant qu'elle reste refusée) ; règle de justification d'encodage vérifiée par marqueur du code réellement chargé dans le conteneur (mutation réelle jugée disproportionnée au vu de la couverture de tests déjà exhaustive — 1602/1602 avant déploiement) ; bundle frontend déployé vérifié contenant les chaînes `"Construire"`, `"Mon profil"`, `"Activer les notifications"` (nouveau hash de build, `index-6AISoXoq.js`). Compte et refresh tokens de test supprimés, 0 résidu confirmé sur les deux tables. Logs PHP/worker (fenêtre de 10 min couvrant tout le déploiement et les tests) : aucune occurrence de `ERROR`/`CRITICAL`/`exception` hors dépréciations Symfony/Doctrine bénignes et préexistantes. **Anomalie de procédure trouvée et corrigée pendant ce déploiement** : `tar xzf` (étape d'extraction, `deployment-versioning.md` §4.4) ne supprime jamais les fichiers absents de la nouvelle archive — purement additif/écrasant. Le fichier mort `backend/src/Entity/MissionEncodingController.php` (supprimé par ce lot) est resté orphelin sur l'hôte après extraction ; retiré manuellement du répertoire source de l'hôte après le build (aucun impact fonctionnel : ce fichier n'a jamais été chargé par l'autoloader PSR-4, ni avant ni après — donc aucun rebuild déclenché pour ce seul motif ; l'image déjà construite contient encore ce fichier mort de façon inoffensive, sera absent au prochain rebuild puisque l'hôte est maintenant propre). **À corriger dans `deployment-versioning.md` §4.4** : ajouter une étape de nettoyage explicite de `src/` avant extraction pour tout déploiement contenant des suppressions de fichiers — hors périmètre de ce déploiement, à traiter séparément. **Limite assumée** : documentation D-079/Profil manager-admin non mise à jour dans `docs/decisions.md`/`docs/architecture.md` avant ce déploiement (recommandé, non bloquant — à faire dans un commit séparé). |
| `v2026.07.27-prod` | `0fe10f0` | 2026-07-27 | **60 commits depuis `v2026.07.17-prod` (`224893f`)** — écart de 10 jours, rapport de gap complet produit et validé explicitement par l'utilisateur avant toute action serveur (`docs/deployment-versioning.md` §3, écart > 1 commit). **EPIC Exécution & Valorisation (D-071→D-077)** : `InstrumentistService` renommé `MissionExecution` (le RÉALISÉ, distinct du planifié et de la valorisation), colonnes financières mortes supprimées après vérification qu'aucun chemin de production n'y accède (`service_type`, `employment_type_snapshot`, `consultation_fee_applied`, `status`, `computed_amount` — table `instrumentist_service` à 0 ligne au moment de la migration, vérifié en prod juste avant exécution) ; `InstrumentistRate` (versioning tarifaire append-only, miroir de `PricingRule`) ; `FinancialCalculation`/`FinancialCalculationLine` (moteur de valorisation append-only) ; `Payment` (paiements append-only sur factures/décomptes) ; corrections additives (notes de crédit/débit, `FinancialCorrectionService`) ; statistiques financières manager (`FinancialStatisticsQueryService`/`DrilldownService`/`RankingService`). **Encodage Lot 5→7** : catalogue `InterventionType`/`InterventionTypeRequest`, workflow manager de résolution/ignorance des demandes, `MissionInterventionDraft` (création atomique), modèle de lecture unifié `entries`, cycle complet start/complete/validate/reject/reopen avec verrouillage (`encodingLockedAt`). **D-083** : rappel d'encodage D+1 déployé (migration `mission.encoding_reminder_sent_at`), **cron non activé** — hors périmètre de ce déploiement, activation séparée à planifier (voir procédure prête dans ce document). **D-084** : historique des notifications sortantes (`OutboundNotification`/`OutboundNotificationAttempt`) + UI admin. **Push/PWA** : abonnements Web Push, installation Android/iOS. **Sécurité (D-085)** : clés VAPID de développement retirées des fichiers versionnés (trouvées commitées en clair, considérées compromises ; paire de production distincte et déjà hors dépôt, non modifiée). 14 migrations exécutées (`Version20260717210000`→`Version20260726150000`) : renommage `instrumentist_service`→`mission_execution` et `service_hours_dispute`→`mission_execution_dispute` avec conversion `hours`→`actual_duration_minutes` avant suppression de colonne (down() vérifié réversible), 5 tables additives (`instrumentist_rate`, `financial_calculation`, `financial_calculation_line`, `payment`, `mission_intervention_draft`, `outbound_notification`, `outbound_notification_attempt`), colonnes additives sur `mission`/`audit_event`/lignes de facturation — **0 perte de données** (`instrumentist_service`/`service_hours_dispute` vérifiées à 0 ligne juste avant migration réelle, cohérent avec l'absence de mission exécutée en production à ce jour ; `mission` 168→168 inchangé). Rebuild complet `--no-cache`, 3/3 containers recréés. 1567/1567 tests backend (1 test *risky* préexistant sans rapport — handler d'erreur non nettoyé dans `AbsenceControllerTest`, non lié à ce lot), `tsc --noEmit` frontend clean — tous deux exécutés sur `HEAD` propre (chantier de refonte Planning en cours mis de côté via `git stash` le temps des tests, restauré ensuite à l'identique, jamais inclus dans l'archive de déploiement car `git archive` ne prend que `HEAD` committé). Sauvegardes : dump MySQL `/home/deploy/backups/mysql/all_20260727_062414.sql.gz` (896 Ko), archive code `/home/deploy/backups/code/src_pre_deploy_20260727_062416.tar.gz` (9 955 971 octets). **Tests fonctionnels réels en prod** : compte manager jetable `deploy-test-20260727@surgicalhub.internal` créé via `app:user:create` (aucun email) ; login JWT réel → `200` ; `/api/me` → rôle `MANAGER` correct ; `/api/financial-statistics/overview` (nouveau) → `200` avec données réelles (`missionCount: 168`, cohérent) ; `/api/missions`, `/api/intervention-types`, `/api/firms` → `200` ; `/api/admin/outbound-notifications` (nouveau, D-084) → `403` pour un manager, confirmant que le RBAC Voter protège bien l'endpoint ADMIN-only. Compte et refresh tokens supprimés directement en base (pas de commande `app:user:delete` disponible), 0 résidu confirmé sur les deux tables. Logs PHP/worker (fenêtre couvrant tout le déploiement) : aucune occurrence de `ERROR`/`CRITICAL`/`exception` hors dépréciations Symfony/Doctrine bénignes et préexistantes. **Anomalie préexistante relevée, non corrigée (hors périmètre)** : `App\Controller\Api\MissionEncodingController` (route `GET /api/missions/{id}/encoding`) est physiquement situé dans `backend/src/Entity/` au lieu de `backend/src/Controller/Api/`, ce qui l'exclut de l'autoloading PSR-4 (warning au build : "does not comply with psr-4 autoloading standard. Skipping."). Confirmé présent à l'identique dans le tag prod précédent (`224893f`, ancêtre du commit qui l'a introduit) et non touché par aucun des 60 commits de ce lot — bug préexistant, pas une régression de ce déploiement, à corriger dans un lot séparé (déplacer le fichier). **Limite assumée** : cron D-083 non activé (décision opérationnelle séparée, cf. procédure documentée plus bas dans ce fichier). |
| `v2026.07.17-prod` | `224893f` | 2026-07-17 | **Catalogue financier Lot 1 (D-067) + D-064/D-065/D-066 + 4 correctifs indépendants** (`docs/decisions.md`). 16 commits depuis `v2026.07.13-prod-2` (`cd77520`) : 6 commits Lot 1 (`82aba01`→`2823e42` — `InterventionType`/`FirmServiceOffering`/`SuggestedMaterial`, `PricingRule` évolué vers `interventionType`/`currency`/`validFrom`/`validTo`/`MATERIAL_FEE`, `PricingRuleWriteService` avec verrouillage pessimiste déterministe Firme→InterventionType|MaterialItem anti-chevauchement concurrent, adaptation de `FirmInvoiceService`, écrans manager Interventions/Facturation), puis 9 commits de sécurisation du travail antérieur non commité (`f819d70`→`efe3464` — correctif réel d'un bug de production : le commentaire de déclaration de mission était silencieusement perdu côté serveur depuis le dernier déploiement, DTO attendait `declaredComment` alors que le frontend envoie `comment` ; D-064 démarrage automatique `ASSIGNED`→`IN_PROGRESS` via nouvelle commande `app:missions:start-due`, **non encore planifiée automatiquement** — aucun cron/scheduler existant sur ce serveur, à mettre en place séparément ; D-066 correction structurelle du timezone à l'hydratation Doctrine (`business_datetime_immutable`, D-065 superseded) ; consolidation `resolveApiAssetUrl` avec correctif réel d'un bug d'URL relative sur les photos d'hôpital ; réécriture de l'encodage instrumentiste sur un système `ui/sheet` ; exposition des photos de site et spécialités chirurgien dans les payloads mission ; polish toast/app-shell), puis 2 commits de stabilisation de tests (`bd61cfa`, `224893f` — un test backend dépendait de la pagination par défaut d'une liste non filtrée sur une base de test polluée par des exécutions antérieures, sans rapport avec D-064/D-066 ; un test frontend nécessitait le même traitement de timeout déjà établi pour un test voisin dans le même fichier, sous contention CPU plus sévère que précédemment profilée). 2 migrations exécutées : `Version20260715064809` (seed utilisateur technique `system@surgicalhub.internal`, mot de passe `NULL`, rôles vides) et `Version20260716120000` (nouvelles tables + colonnes du catalogue financier — 0 donnée réelle à l'époque de sa conception, resserrement de contraintes sans perte). 975/975 tests backend, 468/468 tests frontend, `tsc --noEmit` clean avant déploiement. Sauvegardes : dump MySQL `/home/deploy/backups/mysql/all_20260717_144351.sql.gz` (912 542 octets, gzip validé), archive code `/home/deploy/backups/code/src_pre_deploy_20260717_144409.tar.gz` (9 864 342 octets, 897 fichiers, tar validé) — toutes deux prises avant tout changement. Déploiement via `git archive` (jamais le disque de travail) → `scp` → extraction, conformément à `docs/deployment-versioning.md` (pas de `.git` sur le serveur). Build complet `--no-cache` (images `php`/`nginx` reconstruites), 3/3 containers recréés et `Up`. Migrations vérifiées avant (`Version20260623120000`, 37 exécutées) et après (`Version20260716120000`, 39 exécutées, `Already at latest version`) : utilisateur système présent, tables `intervention_type`/`firm_service_offering`/`suggested_material` présentes, colonnes `pricing_rule.{intervention_type_id,currency,valid_from,valid_to}` présentes, **données existantes intégralement préservées** — comparaison exacte dump-avant vs base-après : `firm` 2→2, `material_item` 0→0, `mission` 168→168 (aucune perte). **Tests fonctionnels réels en prod** : login JWT réel via l'API publique avec un compte manager jetable créé par `app:user:create` (pas d'email envoyé) ; `/api/me`, `/api/intervention-types`, `/api/firms`, `/api/material-items`, `/api/firms/{id}/pricing-rules`, `/api/firms/{id}/service-offerings`, `/api/sites`, `/api/planning/versions`, `/api/missions` tous `200` avec données réelles (2 firmes réelles : Medacta, Zimmer Biomet) ; correctif du champ `comment` non re-testé en prod séparément (déjà couvert par le test de caractérisation ajouté avant déploiement, aucune mission de test créée en prod pour ce point précis — limite assumée). Compte et refresh tokens de test supprimés, 0 résidu confirmé. Logs PHP/nginx/worker (fenêtre de 25 min couvrant tout le déploiement) : aucune occurrence de `ERROR`/`CRITICAL`/`Unknown column`/`IMPLANT_FEE`/`interventionCode` — uniquement des dépréciations Symfony/Doctrine bénignes et préexistantes. **Limite assumée, documentée, non corrigée pendant ce déploiement** : `app:missions:start-due` (D-064) n'a aucun mécanisme de planification automatique sur ce serveur (ni cron, ni service scheduler dans `docker-compose.yml`) — créer un cron aurait constitué une décision opérationnelle nouvelle (fréquence, utilisateur, journalisation) hors du périmètre strict de cette procédure de déploiement ; à traiter dans un lot séparé. |
| `v2026.07.13-prod-2` | `cd77520` | 2026-07-13 | **D-063 — modification sécurisée de l'adresse email par un manager/admin + photos de profil dans les listes** (`docs/decisions.md`). 2 commits depuis `v2026.07.13-prod` (`c1d58bd` fonctionnel, `cd77520` design final des emails). Nouvel endpoint `PATCH /api/users/{id}/email` (`UserController`, générique, jamais dupliqué par rôle), RBAC via nouvel attribut `UserAdministrationVoter::UPDATE_EMAIL` (MANAGER ou ADMIN, distinct d'`UPDATE` resté ADMIN-only). Logique dans `UserEmailChangeService` : validation → mutation → audit (`UserAuditEventType::USER_EMAIL_CHANGED`) → `flush()` → dispatch de 2 `SendTemplatedEmailMessage` indépendants (ancienne puis nouvelle adresse), chacun catché séparément (`warnings[]`, jamais un échec de la requête), envoyés même à un compte suspendu. Templates `user_email_changed_{old,new}_address.{html,txt}.twig` — design final appliqué (handoff `design_handoff_email_recap_planning`), date/heure du changement volontairement absente du corps (absente du design, restant tracée dans `UserAuditEvent.createdAt`). Risques documentés et assumés (non corrigés) : session JWT/refresh token de la personne cible cesse de fonctionner après changement (provider par email, comportement structurel voulu) ; compte dupliqué possible si la nouvelle adresse diverge de l'email Google réel (`googleId` jamais renseigné en pratique). Frontend : `UserEmailEditor` (partagé, intégré dans `InstrumentistDrawer`/`SurgeonDrawer`), avatars (`PersonAvatar`, `buildProfilePictureUrl` étendu) dans les 2 `DataGrid` et les 2 drawers. Aucune nouvelle migration. Worker + PHP recréés (rebuild complet `--no-cache`). 860/860 tests backend, 393-394/394 tests frontend (flakiness de timing préexistante sur des fichiers non touchés, confirmée non liée) verts avant déploiement. Tests santé : frontend 200, API login 400, `.env` 404, containers 3/3 Up, logs propres, migrations à jour, transport `failed` vide ; bundle frontend déployé vérifié contenant le chunk `UserEmailEditor` (hash différent du build local, preuve d'un rebuild réel). **Tests fonctionnels réels en prod** (`MAIL_SAFE_MODE=on` activé avant tout test car flux email, retiré après) : 3 comptes jetables `@surgicalhub.internal` (manager, instrumentiste, chirurgien) ; photos vérifiées de bout en bout (`null` puis chemin simulé) sur les 4 endpoints (liste + fiche instrumentiste, liste + fiche chirurgien) ; changement d'email réel via l'API publique (JWT réel) sur le compte instrumentiste — `200`, `warnings: []`, `UserAuditEvent USER_EMAIL_CHANGED` persisté, les 2 emails réellement dispatchés (sujets et templates exacts confirmés dans les logs worker, `MAIL_SAFE_MODE` résolu en `allowlist` sur le vrai transport Hostinger). Nettoyage : comptes, refresh tokens, `user_audit_event` — 0 résidu confirmé sur chaque table. |
| `v2026.07.13-prod` | `cfc9d530` | 2026-07-13 | **D-062 — réaction automatique aux absences sur les missions déjà déployées** (`docs/decisions.md`). Nouveau `App\Service\AbsenceMissionReactionService`, appelé par `AbsenceController` en plus de (jamais à la place de) `AbsenceImpactService`, dont le contrat "jamais de mutation" reste inchangé : absence instrumentiste sur une mission `ASSIGNED` → libérée (`OPEN`, instrumentiste retiré) ; absence chirurgien sur une mission `OPEN`/`ASSIGNED` → annulée (instrumentiste retiré le cas échéant). `MissionPostDeployService::cancel()` étendu à `OPEN\|ASSIGNED → CANCELLED`. Nouveau message asynchrone `AbsenceMissionsReactedMessage`/`AbsenceMissionsReactedMessageHandler` : un seul email récapitulatif groupé par destinataire réellement concerné (3 nouveaux `NotificationType` : `ABSENCE_INSTRUMENTIST_RELEASED`, `ABSENCE_SURGEON_MISSION_OPENED`, `ABSENCE_MISSION_CANCELLED`), 3 nouveaux templates. `AuditEvent`/`NotificationEvent` conservés pour chaque mutation. `SurgeonSchedulePost` (définition récurrente) jamais touché — seules les occurrences `Mission` déjà matérialisées le sont. Aucune nouvelle migration (déjà à `Version20260623120000`). Worker recréé (nouveau message/handler Messenger). 831/831 tests backend verts avant déploiement. **Tests fonctionnels réels en prod** (`MAIL_SAFE_MODE=on` activé avant tout test car flux email, retiré après) : 5 comptes jetables `@surgicalhub.internal` + 1 site + 2 missions jetables créés via l'API publique (JWT réel) ; scénario absence instrumentiste (mission `ASSIGNED`→`OPEN`) et scénario absence chirurgien (mission `ASSIGNED`→`CANCELLED`) tous deux confirmés en base + par les emails réellement dispatchés (`MAIL_SAFE_MODE` résolu en `allowlist` sur le vrai transport Hostinger, `isRecognizedLocalSink=false`) + par les `NotificationEvent`/`AuditEvent` persistés. Nettoyage : absences supprimées **directement en base** (pas via l'API, pour éviter que `onAbsenceDeleted()` notifie tous les vrais managers/admins de production avec une référence à un compte jetable) ; missions, site, comptes, refresh tokens, `notification_event`, `audit_event` — 0 résidu confirmé sur chaque table après coup. Anomalie relevée avant déploiement : le commit n'était pas encore poussé sur `origin` au moment du rapport pré-déploiement — corrigé avant toute action serveur. |
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

### Rotation des clés VAPID (D-085)

La paire VAPID de développement a été retirée des fichiers versionnés après avoir
été trouvée commitée en clair dans `backend/.env` (voir `docs/decisions.md` D-085) —
considérée compromise. La paire de production, distincte, vit uniquement dans
`/opt/stack/apps/surgicalhub/.env` et n'a **pas** été modifiée par ce lot.

Procédure de rotation (préparée, non exécutée) :

```bash
# 1. Générer une nouvelle paire (depuis le container php, ou tout outil web-push VAPID)
docker exec surgicalhub-php php -r '
require "/var/www/backend/vendor/autoload.php";
print_r(\Minishlink\WebPush\VAPID::createVapidKeys());
'

# 2. Sauvegarder la config actuelle avant modification
cp /opt/stack/apps/surgicalhub/.env /home/deploy/backups/env/surgicalhub.env.bak_$(date +%Y%m%d_%H%M%S)

# 3. Mettre à jour VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (script Python ci-dessus,
#    répété pour chaque variable — confirmer VAPID_SUBJECT=mailto:notifications@surgicalhub.be
#    reste inchangé)

# 4. Recréer les containers pour charger le nouveau .env
cd /opt/stack/apps/surgicalhub && docker compose up -d
```

**Une rotation invalide toutes les subscriptions Push existantes des utilisateurs
réels** (la nouvelle clé publique ne correspond plus à ce que le navigateur a
enregistré) — après activation, tester iOS et Android avec un compte jetable, puis
confirmer via l'historique ADMIN (`/app/admin/outbound-notifications`) que les
nouveaux envois apparaissent normalement. Les utilisateurs réels devront réactiver
les notifications sur leurs appareils.

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

## Tâche planifiée — démarrage automatique des missions (D-064)

`app:missions:start-due` (`MissionStartDueCommand`) transitionne les missions
`ASSIGNED` en `IN_PROGRESS` une fois leur `startAt` passé. Déployée avec
`v2026.07.17-prod` mais sans planification (voir la limite documentée sur
cette ligne du tableau de version) — activée le 2026-07-17 via **cron
utilisateur `deploy`** (mécanisme déjà en place pour les sauvegardes, aucun
timer systemd ni scheduler applicatif n'existait sur ce serveur).

### Mécanisme

| | |
|---|---|
| Planificateur | cron utilisateur `deploy` (pas de service dédié) |
| Fréquence | toutes les 5 minutes (`*/5 * * * *`) |
| Script | `/home/deploy/scripts/missions_start_due.sh` |
| Commande exécutée | `docker compose exec -T php php bin/console app:missions:start-due --env=prod` |
| Répertoire d'exécution | `/opt/stack/apps/surgicalhub` |
| Journal | `/home/deploy/logs/missions-start-due.log` |
| Verrou anti-chevauchement (shell) | `flock -n` sur `/home/deploy/locks/missions-start-due.lock` |
| Verrou anti-concurrence (applicatif) | `MissionPostDeployService::start()` — verrou pessimiste Doctrine (`LockMode::PESSIMISTIC_WRITE`), même schéma que `claim()` |

Entrée crontab (ajoutée après les 4 jobs de sauvegarde existants, jamais réécrits) :

```
# D-064 — Démarrage automatique des missions ASSIGNED échues (toutes les 5 min)
*/5 * * * * /home/deploy/scripts/missions_start_due.sh >> /home/deploy/logs/missions-start-due.log 2>&1
```

Le script suit le style déjà établi par `backup_mysql.sh`
(`docs/backup-and-restore.md`) : `set -uo pipefail` (pas de `-e` — le code de
sortie de la commande Symfony est capturé et loggué explicitement plutôt que
de faire quitter le script avant d'avoir pu logguer un échec), helper `log()`,
vérification `docker inspect` que `surgicalhub-php` tourne avant d'agir, garde
`flock -n` (`exec 200>"$LOCK"; flock -n 200 || exit 0`) qui fait sortir
proprement (code 0, ligne `SKIP` logguée) une exécution qui chevaucherait la
précédente encore en cours.

### Double protection anti-concurrence

Deux couches indépendantes, pour deux risques différents :

1. **Shell (`flock`)** — empêche deux instances du *script cron* de tourner
   en même temps (ex : un run lent encore actif quand le tick suivant arrive).
2. **Applicatif (verrou pessimiste Doctrine)** — empêche deux *transitions
   métier* concurrentes sur la même mission, même si le verrou shell était
   contourné (exécution manuelle en parallèle du cron, par exemple). Avant
   cette activation, `start()` n'avait aucune protection (contrairement à
   `claim()`, déjà protégé après une course au double-claim déjà connue) —
   corrigé par le commit `62898e9` (`fix(missions): prevent concurrent
   automatic mission starts`), avec test d'intégration dédié
   (`MissionStartDueConcurrencyTest`, preuve par timeout MySQL déterministe
   `innodb_lock_wait_timeout`, jamais une course sur le timing). Sur conflit,
   `start()` lève `ConflictHttpException` ; `MissionStartDueCommand` la
   catche par mission et continue le lot plutôt que d'abandonner
   l'exécution entière.

### Anomalie trouvée et corrigée pendant l'activation — doublons de log sous cron

Le premier tick automatique (18:00 UTC) a produit chaque ligne de log en
double. Cause : le helper `log()` utilisait `tee -a "$LOG"` (écrit dans le
fichier **et** sur stdout), et l'entrée crontab redirige elle-même
stdout+stderr du script vers ce même fichier (`>> ... 2>&1`) — la sortie déjà
tee-ée était donc réinjectée une seconde fois par cron. **Ce n'est pas une
double exécution** : aucun chevauchement de process, un seul appel à la
commande Symfony par tick, un seul événement métier. Confirmé pré-existant et
non spécifique à ce script : le même motif produit déjà des lignes dupliquées
dans `backup.log` pour les jobs de sauvegarde déclenchés par cron (ex.
`rotate_backups.sh` à 04h00) — non corrigé ici, hors périmètre (scripts déjà
déployés, non touchés par cette activation).

**Correctif appliqué** (uniquement dans `missions_start_due.sh`, créé pendant
cette activation — pas un script pré-existant) : `log()` écrit désormais
directement dans le fichier (`>> "$LOG"`, sans `tee`), donc plus rien ne fuit
sur stdout en fonctionnement normal ; la redirection `2>&1` de la crontab
reste comme filet de sécurité pour un crash survenant avant que `log()` ne
soit disponible. Revérifié par une invocation manuelle avec la redirection
exacte de la crontab (`... >> missions-start-due.log 2>&1`) puis par deux
cycles automatiques réels (18:05 et 18:10 UTC) — une seule ligne `Démarrage`
et une seule ligne `OK — exit=0` par tick.

### Vérification

```bash
# Dernières lignes du journal dédié
tail -50 /home/deploy/logs/missions-start-due.log

# Aucun chevauchement de process
ps aux | grep missions:start-due

# Erreurs Docker/Symfony récentes
cd /opt/stack/apps/surgicalhub && docker compose logs --since=15m php worker | grep -iE 'error|critical|exception'

# Entrée crontab active
crontab -l | grep missions_start_due
```

### Désactivation

```bash
crontab -l | grep -v 'missions_start_due.sh' | crontab -
crontab -l   # confirmer l'absence de la ligne, les 4 jobs de sauvegarde intacts
```

### Rollback de la planification

Sauvegarde de la crontab prise avant modification :
`/home/deploy/backups/cron/crontab_before_mission_start_20260717_175553.txt`
(4 jobs de sauvegarde, aucune entrée D-064 — état d'avant activation).

```bash
crontab /home/deploy/backups/cron/crontab_before_mission_start_20260717_175553.txt
crontab -l   # confirmer le retour à l'état d'origine
```

Le fix de concurrence applicatif (`62898e9`) n'est **pas** concerné par ce
rollback — il reste en place indépendamment de la planification (protection
utile même en exécution manuelle).

---

## Tâche planifiée — rappel d'encodage D+1 (D-083) — PAS ENCORE ACTIVÉE

`app:notifications:send-encoding-reminders` (`SendEncodingRemindersCommand`)
envoie le rappel d'encodage D+1 (Push prioritaire, repli email) aux missions
éligibles. Développée et testée en local (voir D-083), **non déployée et non
planifiée en production** au moment de la rédaction — instructions ci-dessous
prêtes pour l'activation, à exécuter seulement avec une autorisation explicite
de déploiement/configuration prod.

### Mécanisme prévu (même schéma que D-064)

| | |
|---|---|
| Planificateur | cron utilisateur `deploy` |
| Fréquence recommandée | toutes les 15-30 minutes (`*/15 * * * *`) — **pas** un tick unique à 08h00 pile |
| Script | `/home/deploy/scripts/send_encoding_reminders.sh` (à créer, même style que `missions_start_due.sh` : `set -uo pipefail`, `flock -n`, vérification `docker inspect`) |
| Commande exécutée | `docker compose exec -T php php bin/console app:notifications:send-encoding-reminders --env=prod` |
| Répertoire d'exécution | `/opt/stack/apps/surgicalhub` |
| Journal | `/home/deploy/logs/encoding-reminders.log` |
| Verrou anti-chevauchement (shell) | `flock -n` sur `/home/deploy/locks/encoding-reminders.lock` |
| Garde métier (applicatif) | `SendEncodingRemindersCommand` refuse d'agir avant 08h00 Europe/Brussels — voir ci-dessous |

**Pourquoi une fréquence de 15-30 min plutôt qu'un tick unique à 08h00** : le
serveur de production n'a pas de garantie documentée d'être en `Europe/Brussels`
(le cron `deploy` existant pour D-064 tourne en UTC — voir le tick "18:00 UTC"
loggué plus haut). Un cron fixé à `0 8 * * *` dériverait d'une heure entre
heure d'été et heure d'hiver si le serveur est en UTC. Plutôt que de dépendre
de `CRON_TZ=Europe/Brussels` (à vérifier/configurer explicitement si retenu),
la commande elle-même refuse d'envoyer quoi que ce soit avant 08h00 heure de
Bruxelles (`SendEncodingRemindersCommand::now()`, `EARLIEST_HOUR = 8`) — une
exécution plus fréquente que nécessaire est donc sans risque : la majorité des
ticks avant 08h00 locale ne font rien (sortie "Trop tôt", aucun appel au
service), et le premier tick après 08h00 locale traite le lot du jour.
L'idempotence stricte (`Mission.encodingReminderSentAt`) garantit par
ailleurs qu'un tick supplémentaire ne renvoie jamais un rappel déjà envoyé.

Entrée crontab prévue (à ajouter après les jobs existants, jamais réécrits) :

```
# D-083 — Rappel d'encodage D+1 (garde 08h00 Europe/Brussels interne à la commande)
*/15 * * * * /home/deploy/scripts/send_encoding_reminders.sh >> /home/deploy/logs/encoding-reminders.log 2>&1
```

### Avant activation

1. Confirmer la timezone réelle du serveur (`docker exec surgicalhub-php-1 date -u`
   et `date` côté hôte) — documenter l'écart avec Europe/Brussels s'il existe,
   la garde interne fonctionne quel que soit cet écart tant que la commande
   calcule elle-même l'heure Bruxelles (elle le fait, indépendamment de
   l'horloge système du conteneur).
2. Appliquer la migration `Version20260726090000` en prod
   (`doctrine:migrations:migrate --env=prod`) avant tout déploiement du code
   applicatif qui la suppose.
3. Créer `/home/deploy/scripts/send_encoding_reminders.sh` sur le modèle exact
   de `missions_start_due.sh` (même garde `flock`, même vérification
   `docker inspect`, `log()` écrivant directement dans le fichier — pas de
   `tee`, pour éviter le doublon déjà rencontré et documenté pour D-064).
4. Sauvegarder la crontab avant modification
   (`crontab -l > /home/deploy/backups/cron/crontab_before_encoding_reminders_<horodatage>.txt`).

### Vérification (une fois activée)

```bash
tail -50 /home/deploy/logs/encoding-reminders.log
ps aux | grep send-encoding-reminders
crontab -l | grep send_encoding_reminders
```

### Désactivation

```bash
crontab -l | grep -v 'send_encoding_reminders.sh' | crontab -
crontab -l   # confirmer l'absence de la ligne, les autres jobs intacts
```

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
