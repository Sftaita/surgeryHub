<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-178 — raccourcis de saisie de l'encodage, calculés uniquement à partir des interventions
 * réellement enregistrées. Jamais une règle métier : une suggestion se sélectionne exactement
 * comme la même entrée du catalogue complet (matériel suggéré, choix obligatoire et délégué
 * restent résolus par le flux existant). Une requête agrégée par appel (GROUP BY + LIMIT),
 * aucun historique complet renvoyé.
 *
 * Exclusions communes : interventions des missions annulées ou refusées (une intervention
 * supprimée n'existe plus en base), firmes et types désactivés.
 */
class EncodingSuggestionService
{
    public const LIMIT = 2;

    private const EXCLUDED_MISSION_STATUSES = [MissionStatus::CANCELLED, MissionStatus::REJECTED];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Les deux dernières firmes distinctes des interventions encodées par `$user`, de la plus
     * récente à la plus ancienne. Seul l'auteur réellement enregistré compte : une intervention
     * antérieure à D-178 (auteur inconnu) n'est attribuée à personne.
     *
     * @return list<array{id: int, name: string}>
     */
    public function recentFirmsFor(User $user): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('f.id AS id', 'f.name AS name', 'MAX(mi.createdAt) AS HIDDEN lastUsedAt', 'MAX(mi.id) AS HIDDEN lastId')
            ->from(MissionIntervention::class, 'mi')
            ->innerJoin('mi.primaryFirm', 'f')
            ->innerJoin('mi.mission', 'm')
            ->andWhere('mi.createdBy = :user')
            ->andWhere('f.active = true')
            ->andWhere('m.status NOT IN (:excluded)')
            ->setParameter('user', $user)
            ->setParameter('excluded', array_map(static fn (MissionStatus $s) => $s->value, self::EXCLUDED_MISSION_STATUSES))
            ->groupBy('f.id, f.name')
            ->orderBy('lastUsedAt', 'DESC')
            ->addOrderBy('lastId', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * Les deux derniers types d'intervention distincts encodés avec `$firm` sur les missions du
     * chirurgien `$surgeon`, quel que soit l'instrumentiste. Un type n'est retenu que s'il est
     * actif et proposé par la firme : parmi ses prestations actives quand elle en a, sinon
     * (aucune prestation) dans le catalogue actif — même liste que la sélection complète.
     *
     * @return list<array{id: int, code: string, label: string}>
     */
    public function recentInterventionTypesFor(Firm $firm, User $surgeon): array
    {
        if (!$firm->isActive()) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('it.id AS id', 'it.code AS code', 'it.label AS label', 'MAX(mi.createdAt) AS HIDDEN lastUsedAt', 'MAX(mi.id) AS HIDDEN lastId')
            ->from(MissionIntervention::class, 'mi')
            ->innerJoin('mi.interventionType', 'it')
            ->innerJoin('mi.mission', 'm')
            ->andWhere('mi.primaryFirm = :firm')
            ->andWhere('m.surgeon = :surgeon')
            ->andWhere('it.active = true')
            ->andWhere('m.status NOT IN (:excluded)')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT o1.id FROM %1$s o1 WHERE o1.firm = :firm AND o1.active = true)'
                . ' OR EXISTS (SELECT o2.id FROM %1$s o2 WHERE o2.firm = :firm AND o2.interventionType = it AND o2.active = true)',
                FirmServiceOffering::class,
            ))
            ->setParameter('firm', $firm)
            ->setParameter('surgeon', $surgeon)
            ->setParameter('excluded', array_map(static fn (MissionStatus $s) => $s->value, self::EXCLUDED_MISSION_STATUSES))
            ->groupBy('it.id, it.code, it.label')
            ->orderBy('lastUsedAt', 'DESC')
            ->addOrderBy('lastId', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'label' => (string) $r['label']], $rows);
    }
}
