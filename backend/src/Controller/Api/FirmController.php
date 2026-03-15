<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/firms')]
final class FirmController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * GET /api/firms
     * Liste toutes les firmes actives, triées par nom.
     */
    #[Route('', name: 'api_firms_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $firms = $this->em->getRepository(Firm::class)->findBy(
            ['active' => true],
            ['name' => 'ASC'],
        );

        return $this->json(array_map(
            fn (Firm $f) => ['id' => $f->getId(), 'name' => $f->getName()],
            $firms,
        ));
    }
}
