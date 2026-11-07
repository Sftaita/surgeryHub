<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\PlanningOccurrenceException;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
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
 *
 * D-158 — sauf la neutralisation posée par CETTE absence elle-même : le coordinateur (H4)
 * neutralise les occurrences non encore générées (SurgeonAbsenceOccurrenceImpactService,
 * CANCELLED / SURGEON_ABSENCE / sourceAbsence = l'absence) AVANT les communications D-114.
 * Les exclure revenait à ne jamais libérer de salle, ni envoyer de Room Release ou de
 * gestion du bloc, pour un mois dont le planning n'était pas encore généré (prod : absences
 * #79–#82, décembre 2026). Une exception d'une AUTRE absence reste exclue (déjà annoncée par
 * celle-ci : jamais deux annonces pour la même occurrence).
 */
class SurgeonAbsenceBlockOccurrenceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
    ) {
    }

    /**
     * @param Absence|null $absence l'absence dont on calcule les occurrences : ses propres
     *        neutralisations (D-103) ne masquent pas l'occurrence (D-158)
     * @return array<int, array{site: Hospital, occurrences: array<int, array{post: SurgeonSchedulePost, date: \DateTimeImmutable}>}>
     *         Groupé par id de site, dans l'ordre de première rencontre.
     */
    public function resolveForWindow(User $surgeon, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd, ?Absence $absence = null): array
    {
        $posts = $this->loadActiveBlockPosts($surgeon, $windowStart, $windowEnd);
        if (empty($posts)) {
            return [];
        }

        $bySite = [];

        foreach ($posts as $post) {
            $dates = $this->generator->theoreticalOccurrenceDates($post, $windowStart, $windowEnd);

            foreach ($dates as $date) {
                if ($this->isExplainedByAnotherException($post, $date, $absence)) {
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

    /** D-159 — le poste produit-il cette occurrence (récurrence et période de validité, hors exceptions) ? */
    public function postOccursOn(SurgeonSchedulePost $post, \DateTimeImmutable $date): bool
    {
        return $this->generator->theoreticalOccurrenceDates($post, $date, $date) !== [];
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
    private function isExplainedByAnotherException(SurgeonSchedulePost $post, \DateTimeImmutable $date, ?Absence $absence): bool
    {
        $exception = $this->em->getRepository(PlanningOccurrenceException::class)
            ->findOneBy(['post' => $post, 'occurrenceDate' => $date]);
        if ($exception === null) {
            return false;
        }

        // D-158 — the neutralisation of THIS absence is the absence itself, not another reason.
        return !($absence !== null
            && $absence->getId() !== null
            && $exception->getType() === OccurrenceExceptionType::CANCELLED
            && $exception->getSource() === OccurrenceExceptionSource::SURGEON_ABSENCE
            && $exception->getSourceAbsence()?->getId() === $absence->getId());
    }
}
