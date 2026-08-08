<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot 4 (D-098) — agrégats d'activité personnelle chirurgien : source de vérité
 * `MissionIntervention` rattachée à une `Mission` dont `surgeon` est l'utilisateur courant,
 * jamais `MissionInterventionDraft` (provisoire, pré-matériel — voir docblock de cette classe).
 *
 * Règle de comptage (§3) — unique source de vérité, jamais recopiée ailleurs : seules les
 * missions VALIDATED/CLOSED comptent comme activité réalisée.
 *
 * Groupement (§6) : une `MissionIntervention` réelle référence un `InterventionType` via FK
 * depuis le Lot 5 catalogue (D-068) — on groupe par `interventionType.id`, avec le libellé
 * COURANT du référentiel (`it.label`, toujours à jour — contrairement au snapshot
 * `mi.code`/`mi.label` figé à la création, voir docblock `MissionIntervention`). Les lignes
 * historiques pré-Lot 5 (`interventionType = null`, legacy) sont groupées séparément par
 * `mi.code` — seul identifiant stable disponible pour elles — avec `mi.label` (snapshot)
 * comme libellé affiché faute de mieux. Les deux groupes ne sont jamais fusionnés entre eux,
 * même si un label se ressemble : aucune résolution par similarité de texte.
 *
 * 3 requêtes DQL agrégées, aucun N+1 : `missionCount` (COUNT sur Mission seule, utilise l'index
 * existant `idx_mission_start_status`), le groupement "réel" (JOIN INNER sur interventionType)
 * et le groupement "legacy" (interventionType IS NULL) — toutes deux via `idx_intervention_mission`
 * pour la jointure Mission. `interventionCount` est dérivé en PHP (somme des deux groupes),
 * jamais une 4e requête.
 */
final class SurgeonActivityService
{
    /** Unique règle métier "quels statuts comptent comme activité réalisée" (§3). */
    public const COUNTED_STATUSES = [MissionStatus::VALIDATED, MissionStatus::CLOSED];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{missionCount: int, interventionCount: int, interventions: array<int, array{interventionTypeId: ?int, label: string, count: int}>}
     */
    public function getActivity(User $surgeon, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $missionCount = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m
             WHERE m.surgeon = :surgeon AND m.status IN (:statuses)
               AND m.startAt >= :from AND m.startAt < :to'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('statuses', self::COUNTED_STATUSES)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->getSingleScalarResult();

        $typed = $this->em->createQuery(
            'SELECT IDENTITY(mi.interventionType) AS interventionTypeId, it.label AS label, COUNT(mi.id) AS cnt
             FROM App\Entity\MissionIntervention mi
             JOIN mi.mission m
             JOIN mi.interventionType it
             WHERE m.surgeon = :surgeon AND m.status IN (:statuses)
               AND m.startAt >= :from AND m.startAt < :to
             GROUP BY it.id, it.label'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('statuses', self::COUNTED_STATUSES)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->getResult();

        $legacy = $this->em->createQuery(
            'SELECT mi.code AS code, mi.label AS label, COUNT(mi.id) AS cnt
             FROM App\Entity\MissionIntervention mi
             JOIN mi.mission m
             WHERE mi.interventionType IS NULL
               AND m.surgeon = :surgeon AND m.status IN (:statuses)
               AND m.startAt >= :from AND m.startAt < :to
             GROUP BY mi.code, mi.label'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('statuses', self::COUNTED_STATUSES)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->getResult();

        $rows = [];
        foreach ($typed as $r) {
            $rows[] = [
                'interventionTypeId' => (int) $r['interventionTypeId'],
                'label' => $r['label'],
                'count' => (int) $r['cnt'],
            ];
        }
        foreach ($legacy as $r) {
            $rows[] = [
                'interventionTypeId' => null,
                'label' => $r['label'],
                'count' => (int) $r['cnt'],
            ];
        }

        // Tri count DESC puis tie-break déterministe label ASC (§6).
        usort($rows, static function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return strcmp($a['label'], $b['label']);
        });

        $interventionCount = array_sum(array_column($rows, 'count'));

        return [
            'missionCount' => $missionCount,
            'interventionCount' => $interventionCount,
            'interventions' => $rows,
        ];
    }
}
