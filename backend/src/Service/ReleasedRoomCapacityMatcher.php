<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-159 — « Salles libérées » : reconnaître le remplacement d'un poste (une seule capacité
 * opératoire) sans jamais fusionner deux salles simultanées légitimes (D-035).
 *
 * Le modèle ne connaît aucune salle physique : un créneau libéré est identifié par
 * (site, postId, date). Quand un manager termine ou désactive un poste et en crée un autre pour
 * la même plage (prod : Willemart, mercredi matin, poste #2 terminé le 2026-09-30 → poste #35
 * dès le 2026-10-01), le créneau déjà publié sur l'ancien poste reste (non-rétractation) et le
 * nouveau poste produit la même occurrence sous un autre postId : l'unicité
 * (site, postId, date) ne la voit pas.
 *
 * Seules données fiables : un créneau existant est « orphelin » quand son propre poste ne
 * produit plus cette occurrence avec cette période (poste supprimé, désactivé, terminé,
 * récurrence ou période modifiée). Deux postes qui produisent tous deux l'occurrence le même
 * jour sont deux capacités, jamais rapprochés — quel que soit leur instrumentiste ou leur
 * période. Pour une occurrence sans créneau propre :
 *  - aucun orphelin du même chirurgien, même site, même date, dont l'horaire chevauche → NEW ;
 *  - un seul orphelin chevauchant, de même période, et ce poste est le seul poste courant sans
 *    créneau propre qui chevauche cette période → REPLACED (la capacité existe déjà, disponible
 *    ou déjà reprise : aucune création) ;
 *  - sinon → REVIEW : jamais de création automatique, signalé pour validation métier.
 *
 * Lecture seule. Utilisé par la création temps réel, le rattrapage et la garde de réconciliation
 * D-104/D-124 (jamais restaurer une occurrence sous une reprise de l'ancien poste).
 */
class ReleasedRoomCapacityMatcher
{
    public const NEW = 'NEW';
    public const REPLACED = 'REPLACED';
    public const REVIEW = 'REVIEW';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonAbsenceBlockOccurrenceResolver $occurrences,
    ) {
    }

    /**
     * Occurrence ($post, $date) qui n'a pas encore de créneau propre.
     *
     * @return array{decision: string, slot: ?ReleasedOperatingRoomSlot, reason: ?string}
     */
    public function classify(SurgeonSchedulePost $post, \DateTimeImmutable $date): array
    {
        $overlapping = array_values(array_filter(
            $this->orphanSlots($post->getSurgeon(), $post->getSite(), $date, $post->getId()),
            static fn (ReleasedOperatingRoomSlot $s) => self::overlaps($s->getPeriod(), $post->getPeriod()),
        ));

        if ($overlapping === []) {
            return ['decision' => self::NEW, 'slot' => null, 'reason' => null];
        }

        $orphan = $overlapping[0];
        if (count($overlapping) === 1 && $orphan->getPeriod() === $post->getPeriod()) {
            $competitors = array_values(array_filter(
                $this->currentPostsWithoutOwnSlot($post->getSurgeon(), $post->getSite(), $date),
                static fn (SurgeonSchedulePost $p) => self::overlaps($p->getPeriod(), $orphan->getPeriod()),
            ));
            if (count($competitors) === 1 && $competitors[0]->getId() === $post->getId()) {
                return ['decision' => self::REPLACED, 'slot' => $orphan, 'reason' => null];
            }

            return ['decision' => self::REVIEW, 'slot' => $orphan, 'reason' => sprintf(
                'créneau #%d (ancien poste #%d) et %d postes actuels sans créneau sur la même plage',
                $orphan->getId(), $orphan->getPostId(), count($competitors),
            )];
        }

        return ['decision' => self::REVIEW, 'slot' => $orphan, 'reason' => sprintf(
            'créneau(x) %s d\'un ancien poste chevauchant la période %s sans correspondre à un seul remplacement',
            implode(', ', array_map(static fn (ReleasedOperatingRoomSlot $s) => sprintf('#%d (poste #%d, %s)', $s->getId(), $s->getPostId(), $s->getPeriod()->value), $overlapping)),
            $post->getPeriod()->value,
        )];
    }

    /**
     * Poste qui porte aujourd'hui la capacité de ce créneau : le sien s'il produit toujours
     * l'occurrence, sinon l'unique poste qui l'a remplacé (REPLACED), sinon null.
     */
    public function effectivePost(ReleasedOperatingRoomSlot $slot): ?SurgeonSchedulePost
    {
        $surgeon = $slot->getSurgeon();
        $site = $slot->getSite();
        if ($surgeon === null || $site === null) {
            return null;
        }
        $date = $slot->getOccurrenceDate();

        if ($this->isCarriedByOwnPost($slot, $surgeon, $site, $date)) {
            return $slot->getSchedulePost();
        }

        foreach ($this->currentPostsWithoutOwnSlot($surgeon, $site, $date) as $post) {
            $match = $this->classify($post, $date);
            if ($match['decision'] === self::REPLACED && $match['slot']?->getId() === $slot->getId()) {
                return $post;
            }
        }

        return null;
    }

    /**
     * D-104/D-124 — l'occurrence ($post, $date) est-elle déjà occupée par une reprise ? Son
     * propre créneau CLAIMED, ou un créneau orphelin chevauchant CLAIMED (la reprise a été faite
     * sur l'ancien poste) : jamais restaurer le chirurgien absent sous un repreneur.
     */
    public function isCapacityTakenOver(SurgeonSchedulePost $post, \DateTimeImmutable $date): bool
    {
        $site = $post->getSite();
        if ($site === null) {
            return false;
        }

        $own = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.postId = :postId')
            ->andWhere('s.occurrenceDate = :date')
            ->andWhere('s.status = :claimed')
            ->setParameter('postId', $post->getId())
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setParameter('claimed', ReleasedRoomSlotStatus::CLAIMED)
            ->getQuery()
            ->getSingleScalarResult();
        if ($own > 0) {
            return true;
        }

        foreach ($this->orphanSlots($post->getSurgeon(), $site, $date, $post->getId()) as $orphan) {
            if ($orphan->getStatus() === ReleasedRoomSlotStatus::CLAIMED && self::overlaps($orphan->getPeriod(), $post->getPeriod())) {
                return true;
            }
        }

        return false;
    }

    /** JOURNEE chevauche MATIN et APRES_MIDI ; MATIN et APRES_MIDI ne se chevauchent pas. */
    public static function overlaps(ShiftPeriod $a, ShiftPeriod $b): bool
    {
        return $a === $b || $a === ShiftPeriod::JOURNEE || $b === ShiftPeriod::JOURNEE;
    }

    /** @return list<ReleasedOperatingRoomSlot> créneaux du même chirurgien/site/date dont le poste ne porte plus l'occurrence */
    private function orphanSlots(?User $surgeon, ?Hospital $site, \DateTimeImmutable $date, ?int $excludingPostId): array
    {
        if ($surgeon === null || $site === null) {
            return [];
        }

        /** @var list<ReleasedOperatingRoomSlot> $slots */
        $slots = $this->em->createQueryBuilder()
            ->select('s')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.site = :site')
            ->andWhere('s.surgeon = :surgeon')
            ->andWhere('s.occurrenceDate = :date')
            ->andWhere('s.postId <> :postId')
            ->setParameter('site', $site)
            ->setParameter('surgeon', $surgeon)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setParameter('postId', $excludingPostId ?? 0)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($slots, fn (ReleasedOperatingRoomSlot $s) => !$this->isCarriedByOwnPost($s, $surgeon, $site, $date)));
    }

    private function isCarriedByOwnPost(ReleasedOperatingRoomSlot $slot, User $surgeon, Hospital $site, \DateTimeImmutable $date): bool
    {
        $post = $this->em->find(SurgeonSchedulePost::class, $slot->getPostId());

        return $post !== null
            && $post->isActive()
            && $post->getType() === MissionType::BLOCK
            && $post->getSurgeon()?->getId() === $surgeon->getId()
            && $post->getSite()?->getId() === $site->getId()
            && $post->getPeriod() === $slot->getPeriod()
            && $this->occurrences->postOccursOn($post, $date);
    }

    /** @return list<SurgeonSchedulePost> postes BLOCK qui produisent l'occurrence ce jour-là et n'ont pas de créneau propre */
    private function currentPostsWithoutOwnSlot(?User $surgeon, ?Hospital $site, \DateTimeImmutable $date): array
    {
        if ($surgeon === null || $site === null) {
            return [];
        }

        /** @var list<SurgeonSchedulePost> $posts */
        $posts = $this->em->createQuery(
            'SELECT p FROM App\Entity\SurgeonSchedulePost p
             WHERE p.surgeon = :surgeon AND p.site = :site AND p.active = true AND p.type = :type
               AND p.startDate <= :date AND (p.endDate IS NULL OR p.endDate >= :date)
               AND NOT EXISTS (
                   SELECT s.id FROM App\Entity\ReleasedOperatingRoomSlot s
                   WHERE s.site = :site AND s.postId = p.id AND s.occurrenceDate = :date
               )
             ORDER BY p.id ASC'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('site', $site)
            ->setParameter('type', MissionType::BLOCK)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getResult();

        return array_values(array_filter($posts, fn (SurgeonSchedulePost $p) => $this->occurrences->postOccursOn($p, $date)));
    }
}
