<?php

namespace App\Service;

use App\Entity\Absence;
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
