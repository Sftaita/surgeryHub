<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\PlanningOccurrenceException;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Communication des absences chirurgiens — Lot A (D-114), §2 de la demande. Calcule, pour un
 * chirurgien et une fenêtre de dates données, les occurrences théoriques de type `BLOCK`
 * (jamais `CONSULTATION`) issues du planning habituel Planning V2 (SurgeonSchedulePost +
 * RecurrenceRule), groupées par site.
 *
 * Lecture seule strict — ne modifie jamais SurgeonSchedulePost/RecurrenceRule (invariant
 * R-02 du freeze Planning V2). Réutilise `PlanningGeneratorServiceV2::theoreticalOccurrenceDates()`
 * comme unique moteur de récurrence (jamais réimplémenté) et le pattern de requête de
 * `SurgeonAbsenceOccurrenceImpactService::loadActivePosts()` (Lot 3/D-103), avec un filtre
 * de type en plus.
 *
 * Une occurrence portant déjà une PlanningOccurrenceException (quel qu'en soit le type ou
 * la provenance — absence antérieure, action manuelle du manager) est exclue, même
 * convention que SurgeonAbsenceOccurrenceImpactService::hasExistingException().
 */
class SurgeonAbsenceBlockOccurrenceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
    ) {
    }

    /**
     * @return array<int, array{site: Hospital, occurrences: array<int, array{post: SurgeonSchedulePost, date: \DateTimeImmutable}>}>
     *         Groupé par id de site, dans l'ordre de première rencontre.
     */
    public function resolveForWindow(User $surgeon, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $posts = $this->loadActiveBlockPosts($surgeon, $windowStart, $windowEnd);
        if (empty($posts)) {
            return [];
        }

        $bySite = [];

        foreach ($posts as $post) {
            $dates = $this->generator->theoreticalOccurrenceDates($post, $windowStart, $windowEnd);

            foreach ($dates as $date) {
                if ($this->hasExistingException($post, $date)) {
                    continue;
                }

                $site = $post->getSite();
                if ($site === null) {
                    continue;
                }

                $siteId = $site->getId();
                if (!isset($bySite[$siteId])) {
                    $bySite[$siteId] = ['site' => $site, 'occurrences' => []];
                }

                $bySite[$siteId]['occurrences'][] = ['post' => $post, 'date' => $date];
            }
        }

        return $bySite;
    }

    /** @return SurgeonSchedulePost[] */
    private function loadActiveBlockPosts(User $surgeon, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        return $this->em->createQuery(
            'SELECT p FROM App\Entity\SurgeonSchedulePost p
             WHERE p.surgeon = :surgeon
               AND p.active = true
               AND p.type = :type
               AND p.startDate <= :windowEnd
               AND (p.endDate IS NULL OR p.endDate >= :windowStart)'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('type', MissionType::BLOCK)
            ->setParameter('windowStart', $windowStart, Types::DATE_IMMUTABLE)
            ->setParameter('windowEnd', $windowEnd, Types::DATE_IMMUTABLE)
            ->getResult();
    }

    /**
     * Même convention que SurgeonAbsenceOccurrenceImpactService::hasExistingException() :
     * dès qu'une exception existe pour ce (post, date), quel qu'en soit le type ou la
     * provenance, on ne recalcule/réinterprète jamais — un manager peut avoir MOVED ou
     * TIME_OVERRIDE cette occurrence pour une raison qui n'a rien à voir avec cette absence.
     */
    private function hasExistingException(SurgeonSchedulePost $post, \DateTimeImmutable $date): bool
    {
        return $this->em->getRepository(PlanningOccurrenceException::class)
            ->findOneBy(['post' => $post, 'occurrenceDate' => $date]) !== null;
    }
}
