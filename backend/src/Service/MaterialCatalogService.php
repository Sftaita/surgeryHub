<?php

namespace App\Service;

use App\Dto\Request\MaterialItemFilter;
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
            ->orderBy('mi.manufacturer', 'ASC')
            ->addOrderBy('mi.label', 'ASC');

        if ($filter->active !== null) {
            $qb->andWhere('mi.active = :active')->setParameter('active', $filter->active);
        }

        if ($filter->implantOnly === true) {
            $qb->andWhere('mi.isImplant = :imp')->setParameter('imp', true);
        }

        if ($filter->manufacturer !== null) {
            $qb->andWhere('mi.manufacturer = :m')->setParameter('m', $filter->manufacturer);
        }

        if ($filter->referenceCode !== null) {
            $qb->andWhere('mi.referenceCode = :rc')->setParameter('rc', $filter->referenceCode);
        }

        if ($filter->search !== null) {
            $qb->andWhere(
                '(LOWER(mi.label) LIKE :q OR LOWER(mi.referenceCode) LIKE :q OR LOWER(COALESCE(mi.manufacturer, \'\')) LIKE :q)'
            )->setParameter('q', '%' . mb_strtolower($filter->search) . '%');
        }

        $page = max(1, (int) ($filter->page ?? 1));
        $limit = max(1, min(100, (int) ($filter->limit ?? 50)));

        $qb->setMaxResults($limit)->setFirstResult(($page - 1) * $limit);

        $paginator = new Paginator($qb, true);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
