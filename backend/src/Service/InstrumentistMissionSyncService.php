<?php

namespace App\Service;

use App\Dto\Request\Response\InstrumentistMissionSyncResponse;
use App\Entity\Mission;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\PublicationScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * V1 "polling intelligent" — synchronisation des missions instrumentiste
 * sans Mercure/WebSocket (compatible hébergement mutualisé).
 *
 * Retourne uniquement les missions créées/modifiées depuis `since` :
 *  - missions OPEN éligibles à l'instrumentiste (offres nouvelles/mises à jour)
 *  - missions assignées à l'instrumentiste courant (Mes missions / Planning)
 *  - missions précédemment éligibles (OPEN) qui ne le sont plus
 *    (claimées par quelqu'un d'autre, republiées ailleurs, etc.) -> removedMissionIds
 *
 * Aucune donnée patiente n'est jamais incluse (MissionListDto ne référence aucun champ patient).
 */
class InstrumentistMissionSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionMapper $mapper,
    ) {}

    /**
     * @return array{serverTime: \DateTimeImmutable, changed: bool, missions: Mission[], removedMissionIds: int[]}
     */
    public function sync(User $user, ?\DateTimeImmutable $since): array
    {
        $serverTime = new \DateTimeImmutable();

        // "since" absent => premier sync : on remonte tout l'état pertinent.
        $sinceEffective = $since ?? new \DateTimeImmutable('@0');

        // 1) Offres OPEN éligibles, créées/modifiées depuis `since`
        $qbOffers = $this->baseQueryBuilder()
            ->andWhere('m.status = :open')->setParameter('open', MissionStatus::OPEN)
            ->andWhere('m.updatedAt > :since')->setParameter('since', $sinceEffective);
        $this->applyEligibilityJoin($qbOffers, $user);
        $offers = $qbOffers->getQuery()->getResult();

        // 2) Missions assignées à l'instrumentiste courant, modifiées depuis `since`
        $qbMine = $this->baseQueryBuilder()
            ->andWhere('m.instrumentist = :me')->setParameter('me', $user)
            ->andWhere('m.status != :draft')->setParameter('draft', MissionStatus::DRAFT)
            ->andWhere('m.updatedAt > :since')->setParameter('since', $sinceEffective);
        $mine = $qbMine->getQuery()->getResult();

        // 3) Anciennes offres OPEN éligibles, désormais non éligibles (claim par un autre, etc.)
        $qbRemoved = $this->baseQueryBuilder()
            ->andWhere('m.status != :open')->setParameter('open', MissionStatus::OPEN)
            ->andWhere('m.updatedAt > :since')->setParameter('since', $sinceEffective)
            ->andWhere('(m.instrumentist IS NULL OR m.instrumentist != :me)')->setParameter('me', $user);
        $this->applyEligibilityJoin($qbRemoved, $user);
        $removed = $qbRemoved->getQuery()->getResult();

        // Fusion offers + mine, dédoublonnage par id
        $missions = [];
        $seen = [];
        foreach ([...$offers, ...$mine] as $mission) {
            /** @var Mission $mission */
            $id = (int) $mission->getId();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $missions[] = $this->mapper->toListDto($mission, $user);
        }

        $removedMissionIds = [];
        foreach ($removed as $mission) {
            /** @var Mission $mission */
            $removedMissionIds[] = (int) $mission->getId();
        }

        return [
            'serverTime' => $serverTime,
            'changed' => count($missions) > 0 || count($removedMissionIds) > 0,
            'missions' => $missions,
            'removedMissionIds' => $removedMissionIds,
        ];
    }

    public function toResponse(array $result): InstrumentistMissionSyncResponse
    {
        return new InstrumentistMissionSyncResponse(
            serverTime: $result['serverTime']->format(\DateTimeInterface::ATOM),
            changed: $result['changed'],
            missions: $result['missions'],
            removedMissionIds: $result['removedMissionIds'],
        );
    }

    private function baseQueryBuilder(): QueryBuilder
    {
        return $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.site', 's')->addSelect('s')
            ->leftJoin('m.surgeon', 'surgeon')->addSelect('surgeon')
            ->leftJoin('m.instrumentist', 'instr')->addSelect('instr')
            ->distinct();
    }

    /**
     * Restreint le QueryBuilder aux missions publiées (MissionPublication) éligibles
     * pour cet instrumentiste : TARGETED vers lui, ou POOL (freelance partout,
     * employé seulement sur ses sites de rattachement).
     *
     * Même règle que MissionService::list(eligibleToMe=true) / MissionVoter.
     */
    private function applyEligibilityJoin(QueryBuilder $qb, User $user): void
    {
        $isFreelancer = $user->getEmploymentType() === EmploymentType::FREELANCER;

        $qb->innerJoin('m.publications', 'p');

        $qb->leftJoin(
            SiteMembership::class,
            'sm',
            'WITH',
            'sm.user = :elig_me AND sm.site = m.site'
        );

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->andX(
                    'p.scope = :elig_scopeTargeted',
                    'p.targetInstrumentist = :elig_me'
                ),
                $qb->expr()->andX(
                    'p.scope = :elig_scopePool',
                    $qb->expr()->orX(
                        ':elig_isFreelancer = true',
                        'sm.id IS NOT NULL'
                    )
                )
            )
        );

        $qb->setParameter('elig_me', $user);
        $qb->setParameter('elig_isFreelancer', $isFreelancer);
        $qb->setParameter('elig_scopeTargeted', PublicationScope::TARGETED);
        $qb->setParameter('elig_scopePool', PublicationScope::POOL);
    }
}
