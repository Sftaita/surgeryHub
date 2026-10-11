# H5a — Inventaire exhaustif des envois d’email (audit avant migration, puis état final)

Réalisé le 2026-10-05, **avant toute modification H5a**, à partir du code (`backend/src`) :
toute construction de `SendTemplatedEmailMessage` / `SendBillingEmailMessage`, tout appel
`EmailService::sendTemplatedEmail()`, `OutboundNotificationService::recordEmailQueued()` et
`MailerInterface::send()`. Aucun autre chemin d'envoi n'existe (`MailerInterface` n'est injecté
que dans les deux handlers d'envoi et `MailSafeModeListener`).

Classification (§7) :

- **A** — notification métier à un utilisateur → `NotificationDispatcher` obligatoire
  (Intent → Dispatcher → Resolver → NotificationDispatch → OutboundNotification → Attempt →
  ActivityEvent).
- **B** — document financier (facture firme, décompte) : infrastructure spécialisée admise,
  doit être auditable.
- **C** — identité / sécurité (invitation, activation, réactivation, réinitialisation de mot de
  passe, changement d'email) : jamais soumis aux préférences métier, mais même traçabilité
  (OutboundNotification QUEUED/SENT/FAILED, tentatives, correlationId, ActivityEvent).
- **D** — technique (transport, garde-fou) : hors dispatcher, justifié.

## Matrice

Légende « Traçabilité DB » : **Dispatcher** = NotificationDispatch + OutboundNotification par
canal + tentatives + ActivityEvent ; **Outbound seul** = OutboundNotification + tentatives
(D-083/D-084) sans NotificationDispatch ni décision de canal tracée ; **Journal D-114** =
SurgeonAbsenceCommunication/Delivery ; **Aucune** = rien en base (au mieux un log).

| # | Flux | Domaine | Déclencheur | Destinataire | Pièce jointe | Préférences applicables ? | Dispatcher actuel ? | Traçabilité DB ? | Classe | Action H5a |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Planning déployé — instrumentiste | Planning | `PlanningDeployPdfsMessageHandler` (déploiement) | instrumentiste | PDF personnel | oui (`PLANNING_DEPLOYED_INSTRUMENTIST`) | **oui** (H4) | Dispatcher | A | PDF par référence de document (§16) |
| 2 | Planning déployé — chirurgien | Planning | idem | chirurgien | PDF personnel | oui (`PLANNING_DEPLOYED_SURGEON`) | **oui** (H4) | Dispatcher | A | PDF par référence de document |
| 3 | Confirmation de déploiement — manager | Planning | idem (étape 8) | le manager qui déploie | PDF global | oui (`PLANNING_DEPLOYED_MANAGER`) | non — `SendBillingEmailMessage` | in-app seul ; email **aucune** | A | **migrer** ; PDF global rendu une fois, par référence |
| 4 | Récap Modification (`PLANNING_CHANGE_SUMMARY`) | Planning | lot Modification (H3) | personnes impactées | PDF personnel | oui | **oui** (H3) | Dispatcher | A | PDF par référence de document |
| 5 | Renvoi manuel du planning (`PLANNING_RESENT_MANUAL`) | Planning | `PlanningResendService` (action manager) | une personne | PDF personnel | **non** (action explicite du manager, D-090) | non — `SendBillingEmailMessage` | `NotificationEvent` canal EMAIL marqué « SENT » au dépôt en file (**statut mensonger**) | A | **migrer** en email obligatoire ; `mission_communication` source `RESEND` sur preuve |
| 6 | Pool `OPEN_MISSION_AVAILABLE` | Missions | `OpenMissionPoolNotifier` | instrumentistes éligibles | — | oui | **oui** (H3) | Dispatcher | A | — |
| 7 | Cycle de vie (libérée, annulée, réattribuée, prise, ajoutée…) | Missions | `MissionLifecycleChangedMessageHandler`, `PlanningChangeSummaryService` | instrumentiste / chirurgien | — | oui | **oui** (H2/H3) | Dispatcher | A | — |
| 8 | Attribution directe (`MISSION_ASSIGNED_DIRECTLY`) | Missions | lifecycle `ASSIGNED_DIRECTLY` | instrumentiste | — | oui | **oui** (H4) | Dispatcher | A | supprimer `NotificationService::missionAssignedDirectlyNotifyInstrumentist()` (**code mort**) |
| 9 | Demande nominative (`MISSION_OFFERED`) | Missions | lifecycle `OFFERED` (D-125) | instrumentiste ciblée | — | oui (push d'abord, email en repli) | non | Outbound seul | A | **migrer** (politique `FALLBACK_WHEN_PUSH_NOT_SENT`) |
| 10 | Demande refusée (`MISSION_OFFER_DECLINED`) | Missions | lifecycle `OFFER_DECLINED` | manager demandeur (repli : managers actifs) | — | oui | non | Outbound seul (in-app hors dispatcher) | A | **migrer** |
| 11 | Mission ouverte — chirurgien (`SURGEON_MISSION_OPEN_PUBLISHED`) | Missions | `MissionPublishedMessageHandler` | chirurgien | — | oui (push d'abord, email en repli) | non | Outbound seul | A | **migrer** |
| 12 | Escalade J-14 (`MISSION_UNCOVERED_ESCALATION`) | Missions | `MissionUncoveredEscalationMessageHandler` (cron) | chirurgien | — | oui | non — `SendBillingEmailMessage` | **aucune** pour l'email (push non tracé non plus) | A | **migrer** ; idempotence par épisode d'escalade |
| 13 | Alerte planning (`PLANNING_ALERT`, conflits) | Planning | `PlanningConflictDetectionService` → `PlanningAlertRaisedMessageHandler` | managers/admins actifs | — | oui | non — `SendBillingEmailMessage` | **aucune** pour l'email (push non tracé) | A | **migrer** ; idempotence par alerte |
| 14 | Absences N1/N2/N3, corrections, résumé manager, restaurations | Absences | coordinateur H4 | personnes impactées / managers | — | oui | **oui** (H4) | Dispatcher | A | — |
| 15 | Confirmation « absence enregistrée » | Absences | — | — | — | — | — | — | A | **n'existe pas** — non créé (décision A.2) |
| 16 | Demande des congés manquants (`request-missing`, D-051) | Absences | `AbsenceReminderController` (action manager) | chaque personne sélectionnée, individuellement | — | **non** (demande explicite du manager) | non — `SendTemplatedEmailMessage` brut | **aucune** (seul un compteur `UserAuditEvent`) | A | **migrer** en email obligatoire ; idempotence par lot de rappel ; `userIds` respectés |
| 17 | Récapitulatif des congés encodés (`confirm-encoded`, D-051) | Absences | idem | chaque personne, individuellement | — | **non** | non | **aucune** | A | **migrer** ; **le motif libre de l'absence était recopié dans l'email** → retiré (A.5) |
| 18 | Rappel d'encodage J+1 automatique + relance manuelle (D-083/D-120) | Encodage | `EncodingReminderService` (cron / action manager) | instrumentiste | — | **jamais consultées** (push toujours tenté) | non | Outbound seul (type libre `ENCODING_REMINDER_D1`, absent de `NotificationType`) | A | **migrer** ; `ENCODING_REMINDER_D1` devient un `NotificationType` (push + email en repli par défaut) |
| 19 | Proposition catalogue acceptée / écartée | Catalogue | `CatalogueRequestProcessedMessageHandler` | instrumentiste | — | oui (push d'abord) | non | Outbound seul | A | **migrer** |
| 20 | Nouvelle proposition catalogue | Catalogue | `CatalogueRequestCreatedMessageHandler` | managers/admins actifs | — | oui (canal indépendant) | non | Outbound seul | A | **migrer** |
| 21 | Demande de mission chirurgien acceptée / refusée | Missions | `SurgeonMissionRequestDecidedMessageHandler` | chirurgien | — | oui (push d'abord) | non | Outbound seul | A | **migrer** |
| 22 | Anomalie d'encodage résolue | Encodage | `EncodingAnomalyReportResolvedMessageHandler` | chirurgien | — | oui (push d'abord) | non | Outbound seul | A | **migrer** |
| 23 | Libération de salle — collègues (D-114 Lot A) | Absences chirurgiens | `RoomReleaseCommunicationService` | chirurgiens collègues du site | — | **non** (ADR D-114 : diffusion organisationnelle gouvernée par le réglage de site) | non | Journal D-114 (statuts honnêtes, échecs tracés) | A (spécialisé) | **conservé** (voir « Décisions à valider ») |
| 24 | Gestion du bloc — création / modification / annulation / programmée (D-114 Lot B) | Absences chirurgiens | `BlockManagementCommunicationService`, `app:absences:send-scheduled-communications` | **adresses externes** configurées par site (To + CC, Reply-To chirurgien) | — | non (pas un utilisateur) | non (impossible : pas de `User` destinataire) | Journal D-114 | D (externe, organisationnel) | **conservé**, justifié |
| 25 | Facture firme | Facturation | `FirmInvoiceController::send` | adresse de facturation de la firme (+ CC) | PDF facture | non | non — `SendBillingEmailMessage` | `FirmInvoice.sentAt` + `AuditEvent FIRM_INVOICE_ISSUED` ; **livraison email non tracée** | B | conservé ; écart signalé |
| 26 | Décompte instrumentiste | Facturation | `InstrumentistStatementController::send` | adresse saisie (défaut : snapshot de l'instrumentiste) | PDF décompte | non | non — `SendBillingEmailMessage` | `InstrumentistStatement.sentAt` ; **ni audit ni trace de livraison** | B | conservé ; écart signalé |
| 27 | Invitation (instrumentiste, chirurgien, utilisateur admin, renvoi) | Identité | `InstrumentistController`, `SurgeonController`, `UserAdministrationService` | la personne invitée | — | **non** | non — `EmailService` | **aucune** (`invitationLastSentAt` = date de dépôt en file) | C | **migrer** en email obligatoire tracé, adapté au rôle, jeton jamais persisté en clair |
| 28 | Changement d'email — ancienne adresse | Identité | `UserEmailChangeService` | **ancienne** adresse | — | **non** | non — `EmailService` | `UserAuditEvent` du changement ; email **aucune** | C | **migrer** (adresse explicite tracée) |
| 29 | Changement d'email — nouvelle adresse | Identité | idem | nouvelle adresse | — | **non** | non | idem | C | **migrer** |
| 30 | Réinitialisation de mot de passe | Identité | — | — | — | — | — | — | C | **n'existe pas** dans le code (login, Google OAuth, jeton d'invitation uniquement) — rien inventé |
| 31 | `SendTemplatedEmailMessageHandler`, `SendBillingEmailMessageHandler` | Transport | Messenger | — | — | — | — | — | D | seuls points d'appel de `MailerInterface::send()` |
| 32 | `MailSafeModeListener` (D-061) | Garde-fou | `MessageEvent` | — | — | — | — | — | D | inchangé |
| 33 | `EmailService::sendTemplatedEmail()` | Transport générique | — | — | — | — | — | — | D | **supprimé** une fois 27-29 migrés (plus aucun appelant) |

Hors email mais relevé pendant l'audit (non traité en H5a) : `ROOM_TAKEOVER_MISSION_OFFER`
(push via `WebPushService::sendToUser`, non tracé), push de `PLANNING_ALERT` et de
`MISSION_UNCOVERED_ESCALATION` (non tracés — **traités de fait** par la migration de ces deux
flux), in-app hérités (`MISSION_DECLARED*`, `PLANNING_ALERT_REASSIGNED_*`,
`PLANNING_MISSION_ASSIGNED`) créés directement en `NotificationEvent`.

## Constats transverses

1. **Pièces jointes** : les PDF voyagent en base64 dans le message Messenger
   (`messenger_messages`), jamais dans `notification_dispatch` / `outbound_notification` /
   `activity_event` — mais aucune référence de document n'est conservée : impossible de
   savoir après coup quel PDF a été envoyé.
2. **Statuts** : le renvoi manuel enregistre `status: SENT` au simple dépôt en file ;
   l'invitation horodate `invitationLastSentAt` au dépôt (documenté). Les flux `Outbound seul`
   sont honnêtes (QUEUED → SENT à l'acceptation par le relais, FAILED après épuisement).
3. **Adresse réellement utilisée** : `outbound_notification` ne la conserve pas (seulement
   l'utilisateur) — indispensable pour le changement d'email (ancienne adresse).
4. **Corps rendu** : `recordEmailAttempt()` recopie le HTML/texte rendu dans
   `outbound_notification` — inacceptable pour un email porteur d'un jeton (invitation).
5. **Motif d'absence** : seul le récapitulatif `confirm-encoded` le propageait encore.

---

# État final après H5a (2026-10-05)

Même méthode de recherche qu'à l'inventaire initial, relancée sur `backend/src` après H5a :
`new SendTemplatedEmailMessage(`, `new SendBillingEmailMessage(`, `sendTemplatedEmail(`,
`->recordEmailQueued(`, `$mailer->send(` (les occurrences en commentaire sont écartées).

## Avant / après — points d'envoi

| Primitive | Avant H5a | Après H5a | Où, après |
|---|---|---|---|
| `new SendTemplatedEmailMessage(` | 16 | **4** | `NotificationDispatcher` (couche autorisée) + 3 fichiers D-114 |
| `new SendBillingEmailMessage(` | 6 | **2** | `BillingDocumentMailer::sendFirmInvoiceEmail()` / `sendStatementEmail()` |
| `EmailService::sendTemplatedEmail(` | 3 | **0** | `EmailService` supprimé |
| `->recordEmailQueued(` | 10 | **1** | `NotificationDispatcher` |
| `$mailer->send(` | 2 | 2 | les deux handlers de transport (inchangé) |

## Avant / après — flux

- **Avant** : 33 lignes d'inventaire — 22 flux métier utilisateur (classe A) dont **8** déjà sur le
  dispatcher et **14** hors dispatcher (2 n'existaient pas : confirmation d'absence, reset de mot
  de passe), 2 flux D-114, 2 flux B, 3 flux C, 4 éléments techniques D.
- **Après** : **0 flux de classe A hors dispatcher.** Les 14 flux A restants ont été migrés
  (lignes 3, 5, 9, 10, 11, 12, 13, 16, 17, 18, 19, 20, 21, 22) ; les 3 flux C (invitation,
  changement d'email ×2) aussi, en email `MANDATORY` confidentiel.

## Chemins restants hors dispatcher (exhaustif) — état après H5b (D-132)

Plus aucun fichier **métier** n'utilise une primitive email. Les documents financiers et les
communications D-114 restent hors dispatcher (jamais de préférences, destinataires externes),
mais passent par la **couche basse commune** `TransactionalEmailSender` (même enregistrement
`OutboundNotification`, tentatives, corrélation et événements `NOTIFICATION_CHANNEL_*` que les
notifications utilisateur).

| Fichier | Méthode | Catégorie | Raison | Traçabilité |
|---|---|---|---|---|
| `src/Notification/NotificationDispatcher.php` | `dispatch()` | TECHNICAL | couche autorisée des notifications utilisateur (A / C) | `notification_dispatch` + `outbound_notification` + `activity_event` |
| `src/Notification/TransactionalEmailSender.php` | `queue()` | TECHNICAL | couche basse commune des communications non-utilisateur : facture / décompte (`BillingDocumentMailer`, BILLING) et D-114 (`AbsenceCommunicationDeliverySender`, D114_EXTERNAL_COMMUNICATION) | `outbound_notification` (destinataire nullable + libellé) + `activity_event` causé par l'acte métier (`FIRM_INVOICE_SENT`…, `SURGEON_ABSENCE_COMMUNICATION_RECORDED`) ; D-114 : `surgeon_absence_communication_delivery.outbound_notification_id` |
| `src/MessageHandler/SendTemplatedEmailMessageHandler.php` | `__invoke()` | TECHNICAL | transport (rendu + mailer + tentative), met à jour les deux journaux | `OutboundNotification` / journal D-114 |
| `src/MessageHandler/SendBillingEmailMessageHandler.php` | `__invoke()` | TECHNICAL | transport legacy, **sans producteur** depuis H5b — conservé pour les messages déjà en file | log seulement |
| `src/EventListener/MailSafeModeListener.php` | `__invoke()` | TECHNICAL | garde-fou D-061 (filtre, n'envoie jamais) | logs `MAIL_SAFE_MODE` |

Aucun autre fichier de `src/` n'utilise une primitive email : c'est vérifié par
`tests/Architecture/EmailThroughDispatcherTest.php`, avec des exceptions **nominatives**
(fichier + catégorie + raison, jamais un joker) et un contrôle des exceptions périmées.

## Terminologie

À la fin de H5a, **toutes les notifications métier utilisateur de classe A** passent par
`NotificationDispatcher` — pas « tous les emails de l'application » : les documents de facturation
(B) et les communications D-114 restent spécialisés, et les emails d'identité utilisent la
politique `MANDATORY` avec une livraison adaptée (confidentielle).
