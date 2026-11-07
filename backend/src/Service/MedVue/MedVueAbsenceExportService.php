<?php

namespace App\Service\MedVue;

use App\Entity\Absence;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-140 — instantané complet des congés d'un titulaire lié, pour MedVue (contrat v1).
 *
 * Minimisation par construction : la requête ne sélectionne que `id`, `dateStart`, `dateEnd`
 * (jamais l'entité `Absence`) — `reason`, `createdBy` et le reste ne sont même pas lus.
 *
 * Toutes les absences qui intersectent [from, to] (bornes incluses, comme `Absence`), en une
 * seule requête : la réponse est complète ou n'existe pas (plafond dépassé → exception, jamais
 * une liste tronquée), condition nécessaire pour que MedVue puisse supprimer les congés disparus.
 *
 * Statut : `Absence` n'en a pas (D-140, décision D1) — toute absence enregistrée est effective,
 * exposée `CONFIRMED`. Une suppression dans SurgicalHub = absence absente de l'instantané.
 */
class MedVueAbsenceExportService
{
    public const MAX_WINDOW_DAYS = 850;
    public const MAX_ABSENCES = 1000;

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @return list<array{id: string, startDate: string, endDate: string, status: string, updatedAt: null}>
     *
     * @throws \OverflowException plus de MAX_ABSENCES absences dans la fenêtre
     */
    public function snapshot(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('a.id AS id', 'a.dateStart AS dateStart', 'a.dateEnd AS dateEnd')
            ->from(Absence::class, 'a')
            ->where('a.user = :owner')
            ->andWhere('a.dateEnd >= :from')
            ->andWhere('a.dateStart <= :to')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(self::MAX_ABSENCES + 1)
            ->getQuery()
            ->getArrayResult();

        if (count($rows) > self::MAX_ABSENCES) {
            throw new \OverflowException('Too many absences in window.');
        }

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'startDate' => $row['dateStart']->format('Y-m-d'),
            'endDate' => $row['dateEnd']->format('Y-m-d'),
            'status' => 'CONFIRMED',
            'updatedAt' => null,
        ], $rows);
    }
}
