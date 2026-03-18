<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

class PlanningScoreService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Returns a scored and sorted list of available instrumentists for a given mission.
     * Score: specialtyMatch(0-40) + historyScore(0-35) + typeExperience(0-25)
     *
     * @return array<int, array{id: int, name: string, email: string, score: int, hasHistory: bool, specialtyMatch: bool}>
     */
    public function suggestForMission(Mission $mission): array
    {
        $missionDate = $mission->getStartAt();
        $missionSite = $mission->getSite();

        // 1. Get all active instrumentists on the same site
        $conn = $this->em->getConnection();

        $instrumentistRows = $conn->fetchAllAssociative(
            'SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.specialties
             FROM `user` u
             INNER JOIN site_membership sm ON sm.user_id = u.id AND sm.site_id = :siteId
             WHERE u.active = 1
               AND JSON_CONTAINS(u.roles, \'["ROLE_INSTRUMENTIST"]\') = 1
               OR (u.active = 1 AND u.id IN (
                   SELECT user_id FROM site_membership WHERE site_id = :siteId2
               ) AND u.roles LIKE \'%ROLE_INSTRUMENTIST%\')',
            ['siteId' => $missionSite?->getId(), 'siteId2' => $missionSite?->getId()]
        );

        // Fallback: use DQL for instrumentists
        $candidates = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u
             JOIN u.siteMemberships sm
             WHERE sm.site = :site
               AND u.active = true'
        )
            ->setParameter('site', $missionSite)
            ->getResult();

        // Filter to only ROLE_INSTRUMENTIST
        $candidates = array_filter($candidates, static fn(User $u) => in_array('ROLE_INSTRUMENTIST', $u->getRoles(), true));

        // 2. Filter out unavailable candidates (absence or conflicting mission)
        $dayStart = $missionDate->setTime(0, 0, 0);
        $dayEnd   = $missionDate->setTime(23, 59, 59);
        $dateStr  = $missionDate->format('Y-m-d');

        $available = [];
        foreach ($candidates as $candidate) {
            // Check absence
            $absence = $this->em->createQuery(
                'SELECT a FROM App\Entity\Absence a
                 WHERE a.user = :user
                   AND a.dateStart <= :day
                   AND a.dateEnd >= :day'
            )
                ->setParameter('user', $candidate)
                ->setParameter('day', $missionDate->format('Y-m-d'))
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($absence !== null) {
                continue; // absent
            }

            // Check conflicting mission (overlapping time same day)
            $conflict = $this->em->createQuery(
                'SELECT m FROM App\Entity\Mission m
                 WHERE m.instrumentist = :user
                   AND m.startAt < :end
                   AND m.endAt > :start
                   AND m.status NOT IN (:excluded)'
            )
                ->setParameter('user', $candidate)
                ->setParameter('start', $mission->getStartAt())
                ->setParameter('end', $mission->getEndAt())
                ->setParameter('excluded', [MissionStatus::DRAFT, MissionStatus::REJECTED])
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($conflict !== null) {
                continue; // conflict
            }

            $available[] = $candidate;
        }

        // 3. Score each candidate
        $surgeonSpecialty = $mission->getSurgeon()?->getSpecialties()[0] ?? null;
        $surgeon = $mission->getSurgeon();
        $missionType = $mission->getType();

        $scored = [];
        foreach ($available as $candidate) {
            // Specialty match
            $specialtyMatch = $surgeonSpecialty !== null && in_array($surgeonSpecialty, $candidate->getSpecialties(), true);
            $specialtyScore = $specialtyMatch ? 40 : 0;

            // History: past VALIDATED missions with this surgeon
            $historyCount = (int) $this->em->createQuery(
                'SELECT COUNT(m.id) FROM App\Entity\Mission m
                 WHERE m.instrumentist = :candidate
                   AND m.surgeon = :surgeon
                   AND m.status = :status'
            )
                ->setParameter('candidate', $candidate)
                ->setParameter('surgeon', $surgeon)
                ->setParameter('status', MissionStatus::VALIDATED)
                ->getSingleScalarResult();

            $historyScore = min($historyCount / 10.0, 1.0) * 35;

            // Type experience
            $totalCount = (int) $this->em->createQuery(
                'SELECT COUNT(m.id) FROM App\Entity\Mission m
                 WHERE m.instrumentist = :candidate
                   AND m.status = :status'
            )
                ->setParameter('candidate', $candidate)
                ->setParameter('status', MissionStatus::VALIDATED)
                ->getSingleScalarResult();

            $typeCount = 0;
            if ($missionType !== null) {
                $typeCount = (int) $this->em->createQuery(
                    'SELECT COUNT(m.id) FROM App\Entity\Mission m
                     WHERE m.instrumentist = :candidate
                       AND m.type = :type
                       AND m.status = :status'
                )
                    ->setParameter('candidate', $candidate)
                    ->setParameter('type', $missionType)
                    ->setParameter('status', MissionStatus::VALIDATED)
                    ->getSingleScalarResult();
            }

            $typeExperience = $totalCount > 0 ? ($typeCount / $totalCount) * 25 : 0;

            $score = (int) round($specialtyScore + $historyScore + $typeExperience);
            $hasHistory = $historyCount > 0;

            $name = trim(($candidate->getFirstname() ?? '') . ' ' . ($candidate->getLastname() ?? ''));
            if ($name === '') {
                $name = $candidate->getEmail() ?? '';
            }

            $scored[] = [
                'id'            => $candidate->getId(),
                'name'          => $name,
                'email'         => $candidate->getEmail(),
                'score'         => $score,
                'hasHistory'    => $hasHistory,
                'specialtyMatch'=> $specialtyMatch,
            ];
        }

        // 4. Sort: history+specialty first, then specialty only, then score desc
        usort($scored, static function (array $a, array $b): int {
            $aTop = ($a['hasHistory'] && $a['specialtyMatch']) ? 2 : ($a['specialtyMatch'] ? 1 : 0);
            $bTop = ($b['hasHistory'] && $b['specialtyMatch']) ? 2 : ($b['specialtyMatch'] ? 1 : 0);

            if ($aTop !== $bTop) {
                return $bTop <=> $aTop;
            }

            return $b['score'] <=> $a['score'];
        });

        return $scored;
    }
}
