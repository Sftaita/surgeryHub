<?php

namespace App\Service;

use App\Dto\Request\MaterialItemFilter;
use App\Entity\Firm;
use App\Entity\MaterialItem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class MaterialCatalogService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @return array{items: list<MaterialItem>, total: int, page: int, limit: int}
     */
    public function list(MaterialItemFilter $filter): array
    {
        $qb = $this->em->getRepository(MaterialItem::class)->createQueryBuilder('mi')
            ->leftJoin('mi.firm', 'f')->addSelect('f')
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('mi.label', 'ASC');

        if ($filter->active !== null) {
            $qb->andWhere('mi.active = :active')->setParameter('active', $filter->active);
        }

        if ($filter->implantOnly === true) {
            $qb->andWhere('mi.isImplant = :imp')->setParameter('imp', true);
        }

        // Compat legacy : ?manufacturer=Smith... => f.name
        if ($filter->manufacturer !== null) {
            $qb->andWhere('f.name = :fname')->setParameter('fname', $filter->manufacturer);
        }

        if (property_exists($filter, 'firmId') && $filter->firmId !== null) {
            $qb->andWhere('f.id = :fid')->setParameter('fid', $filter->firmId);
        }

        if ($filter->referenceCode !== null) {
            $qb->andWhere('mi.referenceCode = :rc')->setParameter('rc', $filter->referenceCode);
        }

        if ($filter->search !== null) {
            $qb->andWhere(
                '(LOWER(mi.label) LIKE :q OR LOWER(mi.referenceCode) LIKE :q OR LOWER(COALESCE(f.name, \'\')) LIKE :q)'
            )->setParameter('q', '%' . mb_strtolower($filter->search) . '%');
        }

        $page = max(1, (int) ($filter->page ?? 1));
        $limit = max(1, min(200, (int) ($filter->limit ?? 50)));

        $qb->setMaxResults($limit)->setFirstResult(($page - 1) * $limit);

        $paginator = new Paginator($qb, true);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Catalog “embarqué” pour l’encodage : firms actives + items actifs.
     * Pas de logique métier côté frontend : tout est livré prêt à consommer.
     *
     * @return array{firms: list<Firm>, items: list<MaterialItem>}
     */
    public function getEncodingCatalog(): array
    {
        $firms = $this->em->getRepository(Firm::class)->createQueryBuilder('f')
            ->andWhere('f.active = :a')->setParameter('a', true)
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        $items = $this->em->getRepository(MaterialItem::class)->createQueryBuilder('mi')
            ->leftJoin('mi.firm', 'f')->addSelect('f')
            ->andWhere('mi.active = :a')->setParameter('a', true)
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('mi.label', 'ASC')
            ->getQuery()
            ->getResult();

        return ['firms' => $firms, 'items' => $items];
    }
}
