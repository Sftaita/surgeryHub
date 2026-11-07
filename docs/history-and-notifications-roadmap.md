# Chantier « Historique global & fiabilisation des notifications »

Origine : diagnostic du cas Philippe Schiepers / Françoise Detrembleur (2026-10-03) — voir
`docs/decisions.md` D-126 pour le socle. Ordre validé :

**H1 → H2 → H3 → H4 → H5a → H5b → H6 → H8 → H7** (H6 peut avancer en parallèle après H1).

| Lot | Objet | Statut |
|---|---|---|
| **H1** | Socle `activity_event` + corrélation + premiers événements (absences, génération, déploiement, suppression de brouillon, lot Modification, miroir des audits) | **Validé (2026-10-04)**, non commité, non déployé — D-126 |
| **H2** | `NotificationDispatcher` + traçabilité par canal (`notification_dispatch`, `OutboundNotification` étendue, IN_APP / SKIPPED / idempotence) ; snapshot de `cancel()` pour notifier l'instrumentiste ; `PLANNING_MISSION_RELEASED` sur `POST /release` ; branches du cycle de vie via le dispatcher (le flag email devient effectif) | **Validé (2026-10-04)**, non commité, non déployé — D-128 |
| **H3** | `OpenMissionPoolNotifier` unique (déploiement, RELEASED, Modification post-commit, publication POOL, reprise de salle, alerte « ouvrir au pool ») ; `findEligibleByMission()` (missionId → éligibles) ; défauts `OPEN_MISSION_AVAILABLE` inApp/email/push = true ; email groupé par destinataire et opération ; exclusion contextuelle de la personne retirée ; suppression du doublon `SURGEON_POST_UNCOVERED` / `ABSENCE_SURGEON_MISSION_OPENED` | **Validé (2026-10-05)**, non commité, non déployé — D-129 |
| **H4** | Règles d'absence D-127 : `AbsenceImpactResolver` (N1/N2/N3, N3 > N2 > N1), `AbsenceImpactCoordinator` (séquence unique des deux contrôleurs), `AbsenceImpactNotifier` (N1/N2 via dispatcher, `level` dans le payload), N3 via dispatcher, résumé manager enrichi, `ABSENCE_IMPACT_EVALUATED` / `USER_PLANNING_IMPACTED`, registre `mission_communication` | **Validé (2026-10-05)** + décisions de finalisation (amendement D-130), non commité, non déployé |
| **H5a** | Toutes les notifications métier utilisateur de classe A via le dispatcher (inventaire avant/après `docs/h5a-email-inventory.md`) : politique `MANDATORY`, identité confidentielle et tracée, PDF par référence (`generated_document`), renvoi manuel → `RESEND`, rappels d'absence idempotents par lot, rappel automatique soumis aux préférences / relance manuelle `MANDATORY`, text/plain DOM, garde-fou d'architecture nominatif | **Validé (2026-10-05)** + complément (§20–28), non commité, non déployé — D-131 |
| **H5b** | Couverture `activity_event` des actions encore silencieuses (audit avant modification : `docs/h5b-activity-inventory.md`) : entités gouvernées (listener Doctrine + describers, diff avant → après), audits complétés (mission manuelle / brouillon, comptes depuis les écrans instrumentiste / chirurgien), envoi / renvoi des factures et décomptes (acte métier + PDF figé + livraison tracée), D-114 corrélé et livré par la couche basse commune `TransactionalEmailSender`, in-app / push hérités via le dispatcher, garde-fous de couverture | **Implémenté (2026-10-05)**, en attente de validation, non commité, non déployé — D-132 |
| H6 | API Historique (curseur, filtres, chaîne de corrélation, historiques contextuels, voters) | à faire |
| H8 | Reprise de l'historique existant (`source=BACKFILL`) | à faire |
| H7 | UI Manager « Historique » + historiques contextuels | à faire |

## H1 — notes de mise en production

- Migration `Version20261003120000` (création de table, purement additive).
- `ACTIVITY_JOURNAL_ENABLED=1` par défaut (`backend/.env`) ; `0` = interrupteur de rollback
  opérationnel (le stack prod lit `/opt/stack/apps/surgicalhub/.env`, `docker compose up -d`).
- La base de dev locale `surgicalhub` n'a pas l'historique de migrations : y exécuter
  `doctrine:migrations:execute --up 'DoctrineMigrations\Version20261003120000'` (ou mettre
  l'interrupteur à 0) avant d'utiliser la stack locale.
- Aucun changement visible pour les utilisateurs, aucune API nouvelle.

## Règles métier de référence (D-127) — à couvrir par les tests de H3/H4

Trois niveaux : **N1** organisation habituelle (template), **N2** mission préparée (DRAFT),
**N3** mission publiée/communiquée. Vocabulaire et destinataires détaillés dans D-127.

| # | Situation | Attendu | Lot |
|---|---|---|---|
| 1 | Schiepers absent, mission publiée avec Françoise | mission annulée ; Françoise informée que **sa mission est annulée** (absence chirurgien) ; manager voit l'impact ; journal `ABSENCE_CREATED → MISSION_CANCELLED_POST_DEPLOY → USER_PLANNING_IMPACTED → dispatch` | H4 (existe en partie, D-062) |
| 2 | Schiepers absent, mission DRAFT avec Françoise | DRAFT impactée/neutralisée ; Françoise informée que le chirurgien est absent et que **le créneau prévu** est impacté ; aucun « mission publiée annulée » ; trace qui survit à la suppression du DRAFT | H4 |
| 3 | Schiepers absent, aucune mission, Françoise habituelle | Françoise informée de l'absence sur **son créneau habituel** | H4 (existe, D-103 — à tracer `USUAL_SLOT_IMPACTED`) |
| 4 | Françoise absente, mission publiée avec Schiepers | Schiepers informé ; `ASSIGNED → OPEN` ; manager informé ; instrumentistes réellement éligibles sollicités | H3 (pool) + H4 (journal) |
| 5 | Françoise absente, mission DRAFT avec Schiepers | Schiepers informé ; occurrence signalée comme à couvrir | H4 |
| 6 | Françoise absente, aucune mission, template Françoise + Schiepers | Schiepers informé que son instrumentiste habituelle sera absente | H4 (existe — à tracer) |
| 7 | Mission publiée libérée manuellement par le manager | chirurgien informé (à recouvrir) ; ancienne instrumentiste informée de son retrait (`PLANNING_MISSION_RELEASED`) ; exclue de la proposition pour cet épisode ; autres éligibles reçoivent `OPEN_MISSION_AVAILABLE` | H2 (retrait via `POST /release`) + H3 (pool, exclusion, mode Modification) |
| 8 | Instrumentiste du site déjà occupée aux mêmes horaires | aucune proposition pour la mission OPEN | H3 |

Chaque scénario doit être reconstructible depuis `activity_event` (ex. `ABSENCE_CREATED →
USUAL_SLOT_IMPACTED → MISSION_RELEASED_TO_POOL → NOTIFICATION_DISPATCHED(…) → livraisons`).

## H2 — notes de mise en production

- Migration `Version20261004100000` (additive : `notification_dispatch`,
  `notification_dispatch_delivery`, 4 colonnes nullables sur `outbound_notification`).
- Changement visible : les emails demandés par les préférences / défauts sur les transitions de
  mission partent désormais (en particulier `PLANNING_MISSION_CANCELLED`, email par défaut) ; la
  personne retirée d'une mission par un manager est prévenue (`PLANNING_MISSION_RELEASED`) ;
  l'instrumentiste d'une mission annulée hors absence est prévenue. Validation prod à faire avec
  `MAIL_SAFE_MODE=on` et des comptes jetables.
- Encore à faire en H3 pour le scénario 7 : exclusion contextuelle de la personne retirée de
  l'offre pool (aujourd'hui elle reçoit encore `OPEN_MISSION_AVAILABLE` par l'ancien chemin).

## H3 — notes de mise en production

- Aucune migration.
- Changements visibles :
  - `OPEN_MISSION_AVAILABLE` : email et push activés par défaut (in-app inchangé) ; seules les
    instrumentistes réellement éligibles à la mission sont sollicitées (la publication POOL
    poussait auparavant à tout le site) ; un seul email par personne pour un déploiement ou un
    lot Modification.
  - Mode Modification : un retrait par le manager prévient la personne (récapitulatif) et le pool ;
    un lot refusé est entièrement annulé (plus d'application partielle).
  - L'email de planning du déploiement ne liste plus les « missions disponibles » (l'email pool
    s'en charge).
  - `ABSENCE_POOL_MISSION_AVAILABLE` disparaît des préférences (type retiré, historique conservé).
- Validation prod : `MAIL_SAFE_MODE=on` + comptes jetables ; vérifier `OPEN_POOL_EVALUATED` et
  l'historique des notifications après une libération manager et un lot Modification.
- Scénarios D-127 couverts par H3 : 4 (pool, côté sollicitation), 7 (complet : retrait manager
  via `POST /release` et via le mode Modification, exclusion pour l'épisode), 8 (conflit horaire).

## H4 — notes de mise en production

- Migration `Version20261005100000` (additive : `mission_communication`, `notification_dispatch.communicates`).
- Changements visibles :
  - instrumentiste prévue sur un DRAFT : information « planning en préparation » (N2) au lieu
    d'une « Alerte planning » générique ; chirurgien d'un DRAFT désormais prévenu ;
  - managers : plus d'email par alerte d'absence, un seul résumé consolidé enrichi
    (DRAFT à recouvrir, décisions requises, créneaux habituels à anticiper) ;
  - toutes les notifications d'absence passent par le dispatcher (traçabilité par canal) ;
    une absence de plusieurs jours = un email par destinataire et par type ;
  - le planning du déploiement et l'attribution directe passent par le dispatcher.
- `mission_communication` démarre vide (pas de backfill).
- Scénarios D-127 couverts : 1, 2, 3, 4 (journal), 5, 6, 7 (H2/H3), 8 (H3).

## H5a — notes de mise en production

- Migration `Version20261006100000` (additive : `generated_document`,
  `outbound_notification.recipient_address`). Appliquée en local sur `surgicalhub_test` ;
  à appliquer sur `surgicalhub` (dev) et en production avec le lot.
- Messages déjà en file au déploiement : les `SendTemplatedEmailMessage` à pièces jointes
  base64 (H3) restent envoyables (champ conservé) ; les `SendBillingEmailMessage` en file pour
  les flux migrés partent une dernière fois sans trace (comportement d'avant).
- Changements visibles : voir D-131 « Conséquences » (alerte planning in-app typée
  `PLANNING_ALERT`, invitation formulée selon le rôle, plus de motif dans le récapitulatif des
  congés, dialogue de rappels « en cours d'envoi » + personnes non jointes, cinq types à email
  obligatoire retirés des préférences, préférences désormais appliquées au rappel d'encodage).
- À décider : rétention de `generated_document` ; traçabilité de la livraison des emails de
  classe B (facture, décompte) et audit de l'envoi d'un décompte.
- **Point bloquant avant tout déploiement H1–H5** : la base de dev `surgicalhub` contient le schéma
  d'une migration non enregistrée (`Version20260107053952`, table `export_log` déjà présente) —
  `doctrine:migrations:migrate` y échoue ; les migrations H1–H5a y ont été jouées une par une
  (`migrations:execute --up`). Non corrigé, non diagnostiqué : voir
  `docs/production-checklist.md` §4. Aucun déploiement tant que l'écart n'est pas compris.

## H5b — notes de mise en production

- Migration `Version20261007100000` (additive : colonnes nullables / avec défaut, une FK
  `SET NULL`, deux index). Appliquée en local sur `surgicalhub_test`, `surgicalhub_repro` et
  `surgicalhub` (`migrations:execute --up`, la base de dev n'ayant pas l'historique).
- **Bloquant avant tout déploiement** : la vérification de l'état des migrations décrite dans
  `docs/production-checklist.md` (section 4, diagnostic `Version20260107053952`).
- Aucun backfill : l'historique antérieur reste celui des audits miroités (H8).
- `SendBillingEmailMessageHandler` doit rester déployé tant que des messages legacy peuvent être
  en file (vérifier `messenger_messages` avant de le retirer dans un lot ultérieur).
- Défaut métier relevé, non corrigé (D-132, « Conséquences ») : D-114 silencieux pour une
  occurrence habituelle non matérialisée sur un site avec `ShiftPeriodConfig`.
