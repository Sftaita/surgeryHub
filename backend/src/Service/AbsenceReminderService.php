<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure read/query logic behind the "Demander les congés" / "Confirmer les congés encodés"
 * manager actions. Only INSTRUMENTIST/SURGEON, active users are ever in scope.
 *
 * The two actions use DIFFERENT windows, deliberately:
 * - "missing" selection (who gets asked) is bounded to the next 3 months — `defaultPeriod()`.
 * - "encoded" confirmation (what gets confirmed) is ALL future absences, uncapped —
 *   `findAllFutureEncodedAbsencesGrouped()` only takes a lower bound. Each computed exactly
 *   once here and reused by both the preview endpoints (what the dialog shows) and the send
 *   endpoints (what actually gets emailed) — see D-051.
 */
class AbsenceReminderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {}

    public function defaultPeriod(): array
    {
        $from = new \DateTimeImmutable('today');
        $to   = $from->modify('+3 months');
        return [$from, $to];
    }

    /**
     * Active instrumentists/surgeons with NO Absence row overlapping [$from, $to].
     *
     * @return User[] sorted role (instrumentists first) then lastname then firstname
     */
    public function findUsersWithoutAbsenceInPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $candidates = [
            ...$this->userRepository->findInstrumentists(null, true),
            ...$this->userRepository->findSurgeons(null, true),
        ];

        if ($candidates === []) {
            return $candidates;
        }

        $overlappingUserIds = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(a.user) AS userId
             FROM App\Entity\Absence a
             WHERE a.dateEnd >= :from AND a.dateStart <= :to'
        )
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getSingleColumnResult();

        $overlappingIds = array_map('intval', $overlappingUserIds);

        return array_values(array_filter(
            $candidates,
            static fn (User $u) => !in_array($u->getId(), $overlappingIds, true),
        ));
    }

    /**
     * Every FUTURE Absence (dateEnd >= $from, no upper bound — deliberately not capped to 3
     * months, unlike the "missing absence" selection criteria) for active
     * instrumentists/surgeons, grouped by person.
     *
     * @return list<array{user: User, absences: list<Absence>}> sorted role then lastname then firstname
     */
    public function findAllFutureEncodedAbsencesGrouped(\DateTimeImmutable $from): array
    {
        /** @var Absence[] $absences */
        $absences = $this->em->createQueryBuilder()
            ->select('a', 'u')
            ->from(Absence::class, 'a')
            ->join('a.user', 'u')
            ->where('a.dateEnd >= :from')
            ->andWhere('(u.roles LIKE :instr OR u.roles LIKE :surg)')
            ->andWhere('u.active = :active')
            ->orderBy('a.dateStart', 'ASC')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('instr', '%"ROLE_INSTRUMENTIST"%')
            ->setParameter('surg', '%"ROLE_SURGEON"%')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($absences as $absence) {
            $user = $absence->getUser();
            if ($user === null) {
                continue;
            }
            $userId = $user->getId();
            $grouped[$userId] ??= ['user' => $user, 'absences' => []];
            $grouped[$userId]['absences'][] = $absence;
        }

        $result = array_values($grouped);
        usort($result, static function (array $a, array $b): int {
            $roleOrder = static fn (User $u) => in_array('ROLE_INSTRUMENTIST', $u->getRoles(), true) ? 0 : 1;
            return [$roleOrder($a['user']), $a['user']->getLastname(), $a['user']->getFirstname()]
                <=> [$roleOrder($b['user']), $b['user']->getLastname(), $b['user']->getFirstname()];
        });

        return $result;
    }
}
