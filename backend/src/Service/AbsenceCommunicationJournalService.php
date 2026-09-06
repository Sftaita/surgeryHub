<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Communication des absences chirurgiens — Lot A (D-114). Écriture du journal
 * (SurgeonAbsenceCommunication + SurgeonAbsenceCommunicationDelivery) et calcul du
 * `revisionNumber` sous verrou pessimiste réel (transaction Doctrine), avec la contrainte
 * unique DB `(absence, site, type, revisionNumber)` comme dernier garde-fou en cas de course
 * concurrente — jamais un simple bouton désactivé côté frontend.
 *
 * Ne dispatche jamais de message lui-même : la création de la ligne (transactionnelle) et le
 * dispatch des emails (strictement après commit) sont deux responsabilités séparées, portées
 * par l'appelant (RoomReleaseCommunicationService) — même discipline que partout ailleurs
 * dans ce domaine (ex. CheckUncoveredEscalationsCommand).
 */
class AbsenceCommunicationJournalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Crée la communication logique (ROOM_RELEASE) et une SurgeonAbsenceCommunicationDelivery
     * par destinataire (statut SCHEDULED — pas encore réellement envoyée), sous verrou
     * pessimiste sur l'Absence pour que le calcul du revisionNumber soit atomique vis-à-vis
     * d'une autre requête/retry concurrent sur la même absence.
     *
     * @param array<int, array{postId: int, date: string, period: string}> $occurrences
     * @param User[] $recipients
     *
     * @return array{communication: SurgeonAbsenceCommunication, deliveries: SurgeonAbsenceCommunicationDelivery[]}
     */
    public function recordRoomRelease(
        Absence $absence,
        Hospital $site,
        User $surgeon,
        array $occurrences,
        array $recipients,
        string $subject,
        string $body,
    ): array {
        $communication = null;
        $deliveries = [];

        $this->em->wrapInTransaction(function () use (
            $absence, $site, $surgeon, $occurrences, $recipients, $subject, $body,
            &$communication, &$deliveries,
        ): void {
            $this->em->lock($absence, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($absence);

            $revision = $this->nextRevisionNumberLocked($absence, $site, AbsenceCommunicationType::ROOM_RELEASE);

            $communication = new SurgeonAbsenceCommunication();
            $communication->setAbsence($absence);
            $communication->setSurgeon($surgeon);
            $communication->setSite($site);
            $communication->setType(AbsenceCommunicationType::ROOM_RELEASE);
            $communication->setRevisionNumber($revision);
            $communication->setSubjectSnapshot($subject);
            $communication->setBodySnapshot($body);
            $communication->setOccurrencesSnapshot($occurrences);
            $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
            $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
            $this->em->persist($communication);

            foreach ($recipients as $recipient) {
                $delivery = new SurgeonAbsenceCommunicationDelivery();
                $delivery->setRecipient($recipient);
                $delivery->setRecipientEmailSnapshot((string) $recipient->getEmail());
                $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
                $communication->addDelivery($delivery);
                $this->em->persist($delivery);
                $deliveries[] = $delivery;
            }

            $this->em->flush();
        });

        return ['communication' => $communication, 'deliveries' => $deliveries];
    }

    /**
     * Revue finale Lot C (§2) — variante de recordRoomRelease() pour le chemin "mise à jour"
     * (allongement de congé, ou rattrapage). Recalcule le delta d'occurrences jamais
     * annoncées SOUS LE MÊME VERROU PESSIMISTE que le calcul du `revisionNumber`, jamais
     * avant : sans cela, deux exécutions concurrentes de ce chemin pour la même absence
     * (deux managers relançant le même rattrapage, ou un rattrapage concurrent d'une vraie
     * modification) pourraient toutes deux lire "occurrence jamais annoncée" avant qu'aucune
     * n'ait committé, puis créer chacune une révision distincte annonçant deux fois les
     * mêmes dates aux mêmes collègues. Retourne `null` si, une fois recalculé sous verrou,
     * le delta est vide — jamais de communication vide créée, jamais de correction inutile.
     *
     * @param array<int, array{postId: int, date: string, period: string}> $candidateOccurrences
     *        Occurrences futures candidates, PAS ENCORE filtrées par "déjà annoncées" — ce
     *        filtrage a lieu ici, sous verrou, jamais chez l'appelant.
     * @param User[] $recipients
     * @param \Closure(array<int, array{postId: int, date: string, period: string}>): string $renderBody
     *        Rendu du corps de l'email à partir du delta FINAL (recalculé sous verrou) —
     *        jamais du delta pré-calculé par l'appelant, qui pourrait être obsolète face à
     *        une exécution concurrente déjà committée.
     *
     * @return array{communication: SurgeonAbsenceCommunication, deliveries: SurgeonAbsenceCommunicationDelivery[]}|null
     */
    public function recordRoomReleaseDelta(
        Absence $absence,
        Hospital $site,
        User $surgeon,
        array $candidateOccurrences,
        array $recipients,
        string $subject,
        \Closure $renderBody,
    ): ?array {
        $communication = null;
        $deliveries = [];
        $emptyAfterLockedFilter = false;

        $this->em->wrapInTransaction(function () use (
            $absence, $site, $surgeon, $candidateOccurrences, $recipients, $subject, $renderBody,
            &$communication, &$deliveries, &$emptyAfterLockedFilter,
        ): void {
            $this->em->lock($absence, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($absence);

            // Relu SOUS VERROU — jamais la valeur pré-calculée par l'appelant avant
            // l'acquisition du verrou, qui pourrait être obsolète face à une exécution
            // concurrente déjà committée entretemps.
            $alreadyAnnounced = $this->alreadyAnnouncedOccurrenceKeys($absence, $site);
            $snapshot = array_values(array_filter(
                $candidateOccurrences,
                static fn (array $o) => !isset($alreadyAnnounced[self::occurrenceKey((int) $o['postId'], (string) $o['date'])]),
            ));

            if (empty($snapshot)) {
                $emptyAfterLockedFilter = true;
                return;
            }

            $body = $renderBody($snapshot);
            $revision = $this->nextRevisionNumberLocked($absence, $site, AbsenceCommunicationType::ROOM_RELEASE);

            $communication = new SurgeonAbsenceCommunication();
            $communication->setAbsence($absence);
            $communication->setSurgeon($surgeon);
            $communication->setSite($site);
            $communication->setType(AbsenceCommunicationType::ROOM_RELEASE);
            $communication->setRevisionNumber($revision);
            $communication->setSubjectSnapshot($subject);
            $communication->setBodySnapshot($body);
            $communication->setOccurrencesSnapshot($snapshot);
            $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
            $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
            $this->em->persist($communication);

            foreach ($recipients as $recipient) {
                $delivery = new SurgeonAbsenceCommunicationDelivery();
                $delivery->setRecipient($recipient);
                $delivery->setRecipientEmailSnapshot((string) $recipient->getEmail());
                $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
                $communication->addDelivery($delivery);
                $this->em->persist($delivery);
                $deliveries[] = $delivery;
            }

            $this->em->flush();
        });

        if ($emptyAfterLockedFilter) {
            return null;
        }

        return ['communication' => $communication, 'deliveries' => $deliveries];
    }

    /**
     * Union des clés `postId|date` déjà annoncées pour ce couple (absence, site), toutes
     * révisions ROOM_RELEASE confondues — sert au calcul du delta lors d'un allongement de
     * congé (RoomReleaseCommunicationService::onAbsenceUpdated()).
     *
     * @return array<string, true>
     */
    public function alreadyAnnouncedOccurrenceKeys(Absence $absence, Hospital $site): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('c')
            ->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :absence')
            ->andWhere('c.site = :site')
            ->andWhere('c.type = :type')
            ->setParameter('absence', $absence)
            ->setParameter('site', $site)
            ->setParameter('type', AbsenceCommunicationType::ROOM_RELEASE)
            ->getQuery()
            ->getResult();

        $keys = [];
        foreach ($rows as $row) {
            /** @var SurgeonAbsenceCommunication $row */
            foreach ($row->getOccurrencesSnapshot() as $occurrence) {
                $keys[self::occurrenceKey((int) $occurrence['postId'], (string) $occurrence['date'])] = true;
            }
        }

        return $keys;
    }

    /**
     * `period` est délibérément absent de cette clé : pour un `postId` donné, `period` est un
     * champ invariant du `SurgeonSchedulePost` (jamais différent d'une occurrence à l'autre du
     * même post), donc `postId|date` identifie déjà une occurrence de façon univoque —
     * l'inclure serait redondant, jamais un correctif nécessaire.
     *
     * Stabilité si le post est modifié après un premier envoi (audité, D-114) :
     * - changer `period`/l'horaire du post ne change jamais la clé (toujours `postId|date`) —
     *   la ré-exécution retrouve toujours la même occurrence comme "déjà annoncée", jamais de
     *   doublon. Le snapshot déjà journalisé garde intentionnellement le `period` tel qu'il
     *   était AU MOMENT de l'envoi (immuable, comme tout autre snapshot de ce journal) même si
     *   le post affiche depuis une autre valeur — c'est un enregistrement d'audit, pas une vue
     *   live.
     * - changer la récurrence (jours/intervalle) du même post ne peut jamais réintroduire une
     *   ancienne date déjà annoncée sous un id de post différent : `postId` ne change pas
     *   quand on édite un post existant, donc `postId|date` reste stable pour toute date déjà
     *   vue. Verrouillé par
     *   RoomReleaseCommunicationFunctionalTest::test_modifying_the_same_post_recurrence_never_reannounces_already_sent_dates().
     * - seul un cas non couvert et volontairement non traité ici : un manager qui SUPPRIME/
     *   DÉSACTIVE ce post et en CRÉE un nouveau (id différent) pour représenter le même
     *   créneau réel pourrait, en théorie, faire réapparaître la même date calendaire sous un
     *   nouveau `postId` non présent dans les clés déjà annoncées. Accepté comme limite
     *   assumée : une reconstruction de poste est une action manager délibérée et rare, hors
     *   du cycle de vie normal d'une absence ; dédupliquer par date seule (en ignorant
     *   `postId`) créerait le risque inverse — supprimer à tort une annonce légitime pour un
     *   autre poste/salle qui partage la même date par coïncidence.
     */
    public static function occurrenceKey(int $postId, string $date): string
    {
        return $postId . '|' . $date;
    }

    /**
     * Appelé par SendTemplatedEmailMessageHandler une fois $mailer->send() confirmé sans
     * exception. `lastError` est explicitement vidé : un envoi qui a échoué une ou plusieurs
     * fois puis réussi lors d'un retry Messenger doit finir dans un état cohérent — SENT ET
     * sans trace d'une erreur transitoire qui ne décrit plus l'état réel de la livraison.
     * `attemptCount` continue de compter chaque tentative réelle (échecs inclus), lui, n'est
     * jamais réinitialisé.
     */
    public function recordDeliverySuccess(int $deliveryId): void
    {
        $delivery = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryId);
        if ($delivery === null) {
            return;
        }

        $delivery->setAttemptCount($delivery->getAttemptCount() + 1);
        $delivery->setStatus(AbsenceCommunicationStatus::SENT);
        $delivery->setSentAt(new \DateTimeImmutable());
        $delivery->setLastError(null);
        $this->em->flush();
    }

    /** Appelé par OutboundNotificationEmailFailureListener — FAILED seulement une fois les retries Messenger épuisés. */
    public function recordDeliveryFailure(int $deliveryId, string $reason, bool $final): void
    {
        $delivery = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryId);
        if ($delivery === null) {
            return;
        }

        $delivery->setAttemptCount($delivery->getAttemptCount() + 1);
        $delivery->setLastError($reason);
        if ($final) {
            $delivery->setStatus(AbsenceCommunicationStatus::FAILED);
        }
        $this->em->flush();
    }

    // ── Lot B (D-114) — gestion du bloc ─────────────────────────────────────────

    /**
     * Résout To/CC "live" — jamais figé à la programmation (décision actée, Lot B) :
     * appelée aussi bien pour l'aperçu au moment de la programmation que juste avant
     * l'envoi réel (immédiat ou par la commande cron), afin que les valeurs utilisées
     * reflètent toujours l'état ACTUEL du réglage de site et de l'adresse du chirurgien —
     * jamais ce qui était vrai au moment de la décision initiale.
     *
     * $requireEnabled=false pour MODIFICATION/CANCELLATION (§23) : le toggle OFF empêche
     * les nouveaux préavis automatiques, jamais la correction/annulation d'un message déjà
     * envoyé — mais une config entièrement absente ou sans adresse principale reste 'invalid'
     * dans tous les cas, il n'y a alors rien à corriger.
     *
     * @return array{status: 'ready'|'disabled'|'invalid', to: ?string, cc: list<string>}
     */
    public function resolveLiveBlockManagementRecipients(Hospital $site, User $surgeon, bool $requireEnabled = true): array
    {
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        if ($config === null || ($requireEnabled && !$config->isNotifyBlockManagementEnabled())) {
            return ['status' => 'disabled', 'to' => null, 'cc' => []];
        }

        $to = trim((string) $config->getBlockManagementEmailTo());
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => 'invalid', 'to' => null, 'cc' => []];
        }

        $cc = [];
        $seen = [mb_strtolower($to)];
        foreach ($config->getBlockManagementEmailCc() as $address) {
            $address = trim((string) $address);
            $key = mb_strtolower($address);
            if ($address === '' || in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;
            $cc[] = $address;
        }

        $surgeonEmail = trim((string) $surgeon->getEmail());
        if ($surgeonEmail !== '' && !in_array(mb_strtolower($surgeonEmail), $seen, true)) {
            $cc[] = $surgeonEmail;
        }

        return ['status' => 'ready', 'to' => $to, 'cc' => $cc];
    }

    /**
     * Crée OU met à jour EN PLACE la communication `BLOCK_MANAGEMENT_ABSENCE` (revision 0,
     * unique par absence+site) tant qu'elle n'a jamais été réellement envoyée — décision
     * actée (Lot B) : rien n'a encore été communiqué tant que `SCHEDULED`/`CANCELLED`-avant-
     * envoi, donc muter en place (dates, aperçu de destinataires, `scheduledAt`) est sûr,
     * jamais une "correction". Ne touche jamais une ligne déjà `SENT`/`FAILED` — le caller
     * doit alors utiliser recordBlockManagementFollowUp() (nouvelle révision MODIFICATION).
     *
     * `recipientEmailSnapshot`/`recipientCcSnapshot` ne sont ici qu'un APERÇU (résolu live
     * au moment de la programmation) — ils seront réécrits avec les valeurs réellement
     * utilisées juste avant le vrai dispatch (immédiat ou cron), jamais figés.
     *
     * @return array{communication: SurgeonAbsenceCommunication, delivery: SurgeonAbsenceCommunicationDelivery, alreadyFinal: bool}
     */
    public function upsertPendingBlockManagementNotice(
        Absence $absence,
        Hospital $site,
        User $surgeon,
        string $subject,
        string $body,
        \DateTimeImmutable $scheduledAt,
    ): array {
        $communication = null;
        $delivery = null;
        $alreadyFinal = false;

        $this->em->wrapInTransaction(function () use (
            $absence, $site, $surgeon, $subject, $body, $scheduledAt,
            &$communication, &$delivery, &$alreadyFinal,
        ): void {
            $this->em->lock($absence, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($absence);

            $communication = $this->em->createQueryBuilder()
                ->select('c')
                ->from(SurgeonAbsenceCommunication::class, 'c')
                ->where('c.absence = :absence')
                ->andWhere('c.site = :site')
                ->andWhere('c.type = :type')
                ->andWhere('c.revisionNumber = 0')
                ->setParameter('absence', $absence)
                ->setParameter('site', $site)
                ->setParameter('type', AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE)
                ->getQuery()
                ->getOneOrNullResult();

            $preview = $this->resolveLiveBlockManagementRecipients($site, $surgeon);

            if ($communication === null) {
                $communication = new SurgeonAbsenceCommunication();
                $communication->setAbsence($absence);
                $communication->setSurgeon($surgeon);
                $communication->setSite($site);
                $communication->setType(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE);
                $communication->setRevisionNumber(0);
                $this->em->persist($communication);

                $delivery = new SurgeonAbsenceCommunicationDelivery();
                $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
                $communication->addDelivery($delivery);
                $this->em->persist($delivery);
            } else {
                $delivery = $communication->getDeliveries()->first();

                if (!$delivery instanceof SurgeonAbsenceCommunicationDelivery
                    || !in_array($delivery->getStatus(), [AbsenceCommunicationStatus::SCHEDULED, AbsenceCommunicationStatus::CANCELLED], true)
                ) {
                    // Déjà SENT/FAILED — plus rien à muter ici, le caller doit passer par
                    // recordBlockManagementFollowUp() (nouvelle révision MODIFICATION).
                    $alreadyFinal = true;
                    return;
                }

                $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
                $delivery->setCancelledAt(null);
                // Réactivation (ou simple reprogrammation) — jamais considérée "déjà
                // dispatchée" tant qu'aucun nouvel envoi réel n'a eu lieu depuis.
                $delivery->setDispatchClaimedAt(null);
            }

            $communication->setSubjectSnapshot($subject);
            $communication->setBodySnapshot($body);
            $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
            $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
            // Gestion du bloc : période complète du congé, jamais de liste d'occurrences BLOCK (§9).
            $communication->setOccurrencesSnapshot([]);

            $delivery->setScheduledAt($scheduledAt);
            $delivery->setRecipientEmailSnapshot($preview['to'] ?? '');
            $delivery->setRecipientCcSnapshot($preview['cc']);

            $this->em->flush();
        });

        return ['communication' => $communication, 'delivery' => $delivery, 'alreadyFinal' => $alreadyFinal];
    }

    /**
     * Annule une delivery encore `SCHEDULED` (jamais envoyée) — site devenu non concerné
     * (§14 cas B) ou absence supprimée avant l'échéance (§17).
     *
     * Revue finale Lot B (§10) — verrou pessimiste + `refresh()` obligatoires, comme partout
     * ailleurs dans ce service : l'appelant (`BlockManagementCommunicationService::react()`/
     * `onAbsenceDeleted()`) charge la delivery en mémoire potentiellement AVANT qu'un run
     * concurrent du cron ne l'ait réellement claim en base — sans ce verrou+refresh, le
     * `getStatus() !== SCHEDULED` ci-dessous lirait l'état PHP en mémoire (encore
     * `SCHEDULED`, jamais rafraîchi) au lieu de l'état réel en base, et une suppression
     * pourrait alors marquer `CANCELLED` une ligne que le scheduler vient tout juste de
     * confier au pipeline d'envoi réel — la suppression ne doit jamais "gagner" après coup
     * sur un envoi déjà réellement claim. Le check `dispatchClaimedAt !== null` est
     * nécessaire en plus de `status` : un claim `ready` laisse volontairement `status` à
     * `SCHEDULED` jusqu'à confirmation du handler (voir `claimScheduledBlockManagementDelivery()`),
     * donc `status` seul ne suffit pas à détecter qu'un envoi est déjà en cours.
     */
    public function cancelPendingDelivery(SurgeonAbsenceCommunicationDelivery $delivery, string $reason): void
    {
        $this->em->wrapInTransaction(function () use ($delivery, $reason): void {
            $this->em->lock($delivery, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($delivery);

            if ($delivery->getStatus() !== AbsenceCommunicationStatus::SCHEDULED || $delivery->getDispatchClaimedAt() !== null) {
                return;
            }

            $delivery->setStatus(AbsenceCommunicationStatus::CANCELLED);
            $delivery->setCancelledAt(new \DateTimeImmutable());
            $delivery->setLastError($reason);
            $this->em->flush();
        });
    }

    /**
     * Crée une NOUVELLE communication `BLOCK_MANAGEMENT_MODIFICATION`/`BLOCK_MANAGEMENT_CANCELLATION`
     * (jamais `BLOCK_MANAGEMENT_ABSENCE`, qui passe par upsertPendingBlockManagementNotice()) —
     * toujours un envoi immédiat, jamais programmé. `revisionNumber` propre à ce type,
     * calculé sous le même verrou pessimiste que Lot A.
     *
     * `requireEnabled=false` pour ces deux types (§23) : le toggle OFF n'empêche jamais la
     * correction/annulation d'un message déjà envoyé — seule une config réellement absente
     * ou sans adresse principale valide produit 'invalid' (→ FAILED, jamais de fallback inventé).
     *
     * Revue finale Lot B (§12) — `$dedupeIfExists` : pour `MODIFICATION`, l'idempotence d'un
     * double PATCH/retry est déjà garantie EN AMONT par la comparaison de dates dans
     * `BlockManagementCommunicationService::react()` (aucun appel du tout si les dates n'ont
     * pas réellement changé) — le verrou pessimiste ici ne fait que sérialiser le calcul du
     * `revisionNumber`, jamais dédupliquer un contenu. `CANCELLATION` n'a en revanche AUCUNE
     * comparaison de contenu équivalente en amont (une suppression est un événement binaire,
     * pas une valeur à comparer) : deux requêtes DELETE concurrentes/retry pour la même
     * absence appelleraient sinon toutes les deux cette méthode et créeraient chacune une
     * révision distincte (le verrou garantit des numéros non-collisionnants, jamais l'absence
     * de doublon) — d'où `$dedupeIfExists=true` pour ce type, qui vérifie sous le MÊME verrou
     * qu'aucune communication de ce type n'existe déjà pour (absence, site) avant d'en créer
     * une, rendant l'opération réellement idempotente plutôt que simplement non-collisionnante.
     *
     * @return array{communication: SurgeonAbsenceCommunication, delivery: SurgeonAbsenceCommunicationDelivery, alreadyExisted: bool}
     */
    public function recordBlockManagementFollowUp(
        Absence $absence,
        Hospital $site,
        User $surgeon,
        AbsenceCommunicationType $type,
        string $subject,
        string $body,
        bool $dedupeIfExists = false,
    ): array {
        $communication = null;
        $delivery = null;
        $alreadyExisted = false;

        $this->em->wrapInTransaction(function () use (
            $absence, $site, $surgeon, $type, $subject, $body, $dedupeIfExists,
            &$communication, &$delivery, &$alreadyExisted,
        ): void {
            $this->em->lock($absence, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($absence);

            if ($dedupeIfExists) {
                $existing = $this->em->createQueryBuilder()
                    ->select('c')
                    ->from(SurgeonAbsenceCommunication::class, 'c')
                    ->where('c.absence = :absence')
                    ->andWhere('c.site = :site')
                    ->andWhere('c.type = :type')
                    ->setParameter('absence', $absence)
                    ->setParameter('site', $site)
                    ->setParameter('type', $type)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($existing !== null) {
                    $alreadyExisted = true;
                    $communication = $existing;
                    $delivery = $existing->getDeliveries()->first();
                    return;
                }
            }

            $revision = $this->nextRevisionNumberLocked($absence, $site, $type);

            $communication = new SurgeonAbsenceCommunication();
            $communication->setAbsence($absence);
            $communication->setSurgeon($surgeon);
            $communication->setSite($site);
            $communication->setType($type);
            $communication->setRevisionNumber($revision);
            $communication->setSubjectSnapshot($subject);
            $communication->setBodySnapshot($body);
            $communication->setOccurrencesSnapshot([]);
            $communication->setAbsenceDateStartSnapshot($absence->getDateStart());
            $communication->setAbsenceDateEndSnapshot($absence->getDateEnd());
            $this->em->persist($communication);

            $preview = $this->resolveLiveBlockManagementRecipients($site, $surgeon, requireEnabled: false);

            $delivery = new SurgeonAbsenceCommunicationDelivery();
            $delivery->setStatus(AbsenceCommunicationStatus::SCHEDULED);
            $delivery->setScheduledAt(null);
            $delivery->setRecipientEmailSnapshot($preview['to'] ?? '');
            $delivery->setRecipientCcSnapshot($preview['cc']);
            $communication->addDelivery($delivery);
            $this->em->persist($delivery);

            $this->em->flush();
        });

        return ['communication' => $communication, 'delivery' => $delivery, 'alreadyExisted' => $alreadyExisted];
    }

    /**
     * Toutes les communications `BLOCK_MANAGEMENT_ABSENCE`/`_MODIFICATION` de cette absence
     * (jamais `_CANCELLATION`, qui n'existe qu'après suppression), groupées par site,
     * triées par révision croissante — permet au caller de déterminer l'état courant par
     * site (dernière révision connue + statut de sa delivery).
     *
     * @return array<int, SurgeonAbsenceCommunication[]> indexé par id de site
     */
    public function blockManagementCommunicationsBySite(Absence $absence): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('c', 'd')
            ->from(SurgeonAbsenceCommunication::class, 'c')
            ->leftJoin('c.deliveries', 'd')
            ->where('c.absence = :absence')
            ->andWhere('c.type IN (:types)')
            ->setParameter('absence', $absence)
            ->setParameter('types', [
                AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE,
                AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION,
            ])
            ->orderBy('c.revisionNumber', 'ASC')
            ->getQuery()
            ->getResult();

        $bySite = [];
        foreach ($rows as $row) {
            /** @var SurgeonAbsenceCommunication $row */
            $siteId = $row->getSite()?->getId();
            if ($siteId === null) {
                continue;
            }
            $bySite[$siteId][] = $row;
        }

        return $bySite;
    }

    /**
     * Marque une delivery comme "confiée au pipeline d'envoi" (jamais réinitialisé) — appelé
     * juste avant le dispatch Messenger, aussi bien par le chemin immédiat
     * (`BlockManagementCommunicationService::dispatchNow()`) que par le cron (via
     * `claimScheduledBlockManagementDelivery()`, qui le pose lui-même sous verrou). Ne
     * remplace jamais `status` : `dispatchClaimedAt` répond à "a-t-on déjà tenté ?",
     * `status` répond à "quel est le résultat confirmé ?" — deux questions différentes.
     */
    public function markDispatchClaimed(int $deliveryId): void
    {
        $delivery = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryId);
        if ($delivery === null || $delivery->getDispatchClaimedAt() !== null) {
            return;
        }

        $delivery->setDispatchClaimedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Revue finale Lot B (§1) — libère un claim posé juste avant un dispatch Messenger qui a
     * lui-même échoué (jamais un échec SMTP ultérieur dans le worker, qui doit au contraire
     * conserver le claim — c'est précisément ce que markDispatchClaimed() protège). Sans ce
     * filet, une exception levée par `$bus->dispatch()` entre le claim (déjà committé) et
     * l'acceptation réelle du message par le transport laisserait la ligne bloquée
     * indéfiniment : `dispatchClaimedAt` non-null l'exclut à la fois du scan du cron
     * (`dispatchClaimedAt IS NULL`) et d'un futur re-claim
     * (`claimScheduledBlockManagementDelivery()` traite tout `dispatchClaimedAt !== null`
     * comme "déjà traité par un run concurrent"), alors qu'aucun email n'a en réalité été
     * confié au pipeline.
     *
     * No-op défensif si le statut n'est plus `SCHEDULED` : le message a pu malgré tout être
     * accepté par le transport avant que l'exception ne remonte (jamais rien à libérer dans
     * ce cas), ou une autre opération a déjà traité la ligne entretemps (annulation
     * concurrente notamment) — ne jamais réinitialiser un claim qui ne correspond plus à
     * l'état courant.
     */
    public function releaseDispatchClaim(int $deliveryId, string $reason): void
    {
        $delivery = $this->em->find(SurgeonAbsenceCommunicationDelivery::class, $deliveryId);
        if ($delivery === null || $delivery->getStatus() !== AbsenceCommunicationStatus::SCHEDULED) {
            return;
        }

        $delivery->setDispatchClaimedAt(null);
        $delivery->setLastError($reason);
        $this->em->flush();
    }

    /**
     * Appelé par SendScheduledAbsenceCommunicationsCommand — sous verrou pessimiste sur la
     * DELIVERY (pas l'absence : plusieurs deliveries de sites différents pour la même
     * absence peuvent être traitées indépendamment par le même run) : re-vérifie
     * `SCHEDULED` (une exécution concurrente ou une suppression peut avoir déjà traité
     * cette ligne entre le SELECT du scan et ce claim), résout To/CC "live" (jamais figé à
     * la programmation, décision actée). `disabled`/`invalid` sont déjà entièrement traités
     * ici (CANCELLED/FAILED persistés) ; seul `ready` laisse le statut `SCHEDULED` — c'est
     * l'appelant qui dispatche l'email strictement après le commit de cette transaction, et
     * `SendTemplatedEmailMessageHandler` qui passera réellement à `SENT`.
     *
     * @return array{outcome: 'ready'|'disabled'|'invalid'|'already-handled', to: ?string, cc: list<string>}
     */
    public function claimScheduledBlockManagementDelivery(SurgeonAbsenceCommunicationDelivery $delivery): array
    {
        $outcome = 'already-handled';
        $to = null;
        $cc = [];

        $this->em->wrapInTransaction(function () use ($delivery, &$outcome, &$to, &$cc): void {
            $this->em->lock($delivery, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($delivery);

            if ($delivery->getStatus() !== AbsenceCommunicationStatus::SCHEDULED || $delivery->getDispatchClaimedAt() !== null) {
                // Statut plus SCHEDULED (annulé/déjà résolu entretemps), OU déjà claim par
                // CE run ou un run concurrent/rapproché précédent — jamais un second dispatch
                // pour la même delivery avant confirmation réelle (§11/§12, garde-fou
                // dispatchClaimedAt, voir docblock de l'entité).
                return;
            }

            $delivery->setDispatchClaimedAt(new \DateTimeImmutable());

            $communication = $delivery->getCommunication();
            $site = $communication->getSite();
            $surgeon = $communication->getSurgeon();

            $resolved = $this->resolveLiveBlockManagementRecipients($site, $surgeon);

            if ($resolved['status'] === 'disabled') {
                $delivery->setStatus(AbsenceCommunicationStatus::CANCELLED);
                $delivery->setCancelledAt(new \DateTimeImmutable());
                $delivery->setLastError('Gestion du bloc désactivée avant l\'échéance programmée.');
                $this->em->flush();
                $outcome = 'disabled';
                return;
            }

            if ($resolved['status'] === 'invalid') {
                $delivery->setAttemptCount($delivery->getAttemptCount() + 1);
                $delivery->setStatus(AbsenceCommunicationStatus::FAILED);
                $delivery->setLastError('Adresse de gestion du bloc invalide ou manquante à l\'échéance programmée.');
                $this->em->flush();
                $outcome = 'invalid';
                return;
            }

            $delivery->setRecipientEmailSnapshot((string) $resolved['to']);
            $delivery->setRecipientCcSnapshot($resolved['cc']);
            $this->em->flush();

            $outcome = 'ready';
            $to = $resolved['to'];
            $cc = $resolved['cc'];
        });

        return ['outcome' => $outcome, 'to' => $to, 'cc' => $cc];
    }

    private function nextRevisionNumberLocked(Absence $absence, Hospital $site, AbsenceCommunicationType $type): int
    {
        $max = $this->em->createQueryBuilder()
            ->select('MAX(c.revisionNumber)')
            ->from(SurgeonAbsenceCommunication::class, 'c')
            ->where('c.absence = :absence')
            ->andWhere('c.site = :site')
            ->andWhere('c.type = :type')
            ->setParameter('absence', $absence)
            ->setParameter('site', $site)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
