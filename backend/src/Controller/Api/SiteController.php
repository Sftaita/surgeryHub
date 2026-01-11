<?php

namespace App\Controller\Api;

use App\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sites')]
class SiteController extends AbstractController
{
    #[Route('', name: 'api_sites_list', methods: ['GET'])]
    public function list(EntityManagerInterface $em): JsonResponse
    {
        // /api/* est déjà protégé par access_control (IS_AUTHENTICATED_FULLY)
        $sites = $em->getRepository(Hospital::class)->findBy([], ['name' => 'ASC']);

        return $this->json($sites, JsonResponse::HTTP_OK, [], [
            'groups' => ['site:list'],
        ]);
    }
}
