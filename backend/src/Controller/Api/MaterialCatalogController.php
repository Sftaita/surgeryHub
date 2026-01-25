<?php

namespace App\Controller\Api;

use App\Dto\Request\MaterialItemFilter;
use App\Entity\MaterialItem;
use App\Service\MaterialCatalogService;
use App\Service\MaterialItemMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/material-items')]
final class MaterialCatalogController extends AbstractController
{
    public function __construct(
        private readonly MaterialCatalogService $catalog,
        private readonly MaterialItemMapper $mapper,
        private readonly ValidatorInterface $validator,
    ) {}

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

    private function validateObject(object $dto): void
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }
    }
}
