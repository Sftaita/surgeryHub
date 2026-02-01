<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialItemFilter;
use App\Entity\MaterialItem;
use App\Service\MaterialCatalogService;
use App\Service\MaterialItemMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/material-items')]
final class MaterialCatalogController extends AbstractController
{
    public function __construct(
        private readonly MaterialCatalogService $catalog,
        private readonly MaterialItemMapper $mapper,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * Catalogue standard (admin / recherche avancée)
     */
    #[Route(name: 'api_material_items_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $dto = MaterialItemFilter::fromQuery($request->query->all());
        $this->validateObject($dto);

        $result = $this->catalog->list($dto);

        $items = array_map(
            fn (MaterialItem $mi) => $this->mapper->toSlim($mi),
            $result['items']
        );

        return $this->json([
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    /**
     * 🔥 MICRO-ÉTAPE — Autocomplete ultra-rapide (mobile-first)
     *
     * GET /api/material-items/quick-search?q=abc
     */
    #[Route('/quick-search', name: 'api_material_items_quick_search', methods: ['GET'])]
    public function quickSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->json(['items' => []]);
        }

        $limit = min(20, max(1, (int) $request->query->get('limit', 20)));

        $qb = $this->em->getRepository(MaterialItem::class)->createQueryBuilder('mi');

        // Priorité absolue :
        // 1) referenceCode exact
        // 2) referenceCode LIKE
        // 3) label LIKE
        $qb
            ->andWhere('mi.active = true')
            ->andWhere(
                '(mi.referenceCode = :exact
                  OR LOWER(mi.referenceCode) LIKE :like
                  OR LOWER(mi.label) LIKE :like)'
            )
            ->setParameter('exact', $q)
            ->setParameter('like', '%' . mb_strtolower($q) . '%')
            ->addOrderBy('CASE WHEN mi.referenceCode = :exact THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('mi.label', 'ASC')
            ->setMaxResults($limit);

        $items = $qb->getQuery()->getResult();

        return $this->json([
            'items' => array_map(
                fn (MaterialItem $mi) => $this->mapper->toSlim($mi),
                $items
            ),
        ]);
    }

    /**
     * GET /api/material-items/{id}
     */
    #[Route('/{id}', name: 'api_material_items_get', methods: ['GET'])]
    public function getOne(int $id): JsonResponse
    {
        $mi = $this->em->getRepository(MaterialItem::class)->find($id);

        if (!$mi instanceof MaterialItem) {
            throw new NotFoundHttpException('Material item not found');
        }

        return $this->json($this->mapper->toSlim($mi));
    }

    private function validateObject(object $dto): void
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }
    }
}
