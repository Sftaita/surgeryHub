<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialItemFilter;
use App\Entity\Firm;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Security\Voter\BillingVoter;
use App\Service\MaterialCatalogService;
use App\Service\MaterialItemMapper;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * GET /api/material-items
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
            'page'  => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    /**
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
    #[Route('/{id}', name: 'api_material_items_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id): JsonResponse
    {
        $mi = $this->em->getRepository(MaterialItem::class)->find($id);

        if (!$mi instanceof MaterialItem) {
            throw new NotFoundHttpException('Material item not found');
        }

        return $this->json($this->mapper->toSlim($mi));
    }

    /**
     * POST /api/material-items
     */
    #[Route(name: 'api_material_items_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $body   = json_decode($request->getContent(), true) ?? [];
        $firmId = $body['firmId'] ?? null;
        $label  = trim((string) ($body['label'] ?? ''));
        $unit   = trim((string) ($body['unit'] ?? ''));

        if (!$firmId || $label === '' || $unit === '') {
            return $this->json(
                ['message' => 'firmId, label and unit are required'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $firm = $this->em->getRepository(Firm::class)->find((int) $firmId);
        if (!$firm instanceof Firm) {
            return $this->json(['message' => 'Firm not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel($label);
        $mi->setUnit($unit);
        $mi->setReferenceCode(trim((string) ($body['referenceCode'] ?? '')));
        $mi->setIsImplant((bool) ($body['isImplant'] ?? false));

        $this->em->persist($mi);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(
                ['message' => 'Une référence identique existe déjà pour cette firme.'],
                Response::HTTP_CONFLICT
            );
        }

        return $this->json($this->mapper->toSlim($mi), Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/material-items/{id}
     */
    #[Route('/{id}', name: 'api_material_items_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $mi = $this->em->getRepository(MaterialItem::class)->find($id);
        if (!$mi instanceof MaterialItem) {
            throw new NotFoundHttpException('Material item not found');
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('firmId', $body)) {
            // La firme d'un matériel devient immuable dès qu'une ligne de mission réelle
            // le référence — sinon une ré-affectation silencieuse fausserait rétroactivement
            // la firme d'un matériel déjà encodé mais pas encore facturé (docs/decisions.md).
            $usageCount = (int) $this->em->getRepository(MaterialLine::class)
                ->createQueryBuilder('ml')
                ->select('COUNT(ml.id)')
                ->andWhere('ml.item = :item')->setParameter('item', $mi)
                ->getQuery()->getSingleScalarResult();

            if ($usageCount > 0) {
                return $this->json(
                    ['message' => 'La firme de ce matériel ne peut plus être modifiée : il est déjà utilisé dans au moins une mission.'],
                    Response::HTTP_CONFLICT
                );
            }

            $firm = $this->em->getRepository(Firm::class)->find((int) $body['firmId']);
            if (!$firm instanceof Firm) {
                return $this->json(['message' => 'Firm not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $mi->setFirm($firm);
        }

        if (array_key_exists('label', $body)) {
            $label = trim((string) $body['label']);
            if ($label === '') {
                return $this->json(['message' => 'label cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $mi->setLabel($label);
        }

        if (array_key_exists('unit', $body)) {
            $unit = trim((string) $body['unit']);
            if ($unit === '') {
                return $this->json(['message' => 'unit cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $mi->setUnit($unit);
        }

        if (array_key_exists('referenceCode', $body)) {
            $mi->setReferenceCode(trim((string) $body['referenceCode']));
        }

        if (array_key_exists('isImplant', $body)) {
            $mi->setIsImplant((bool) $body['isImplant']);
        }

        if (array_key_exists('active', $body)) {
            $mi->setActive((bool) $body['active']);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(
                ['message' => 'Une référence identique existe déjà pour cette firme.'],
                Response::HTTP_CONFLICT
            );
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
