<?php

namespace App\Controller\Api;

use App\Dto\Request\Response\InstrumentistListItemResponse;
use App\Dto\Request\Response\InstrumentistWithRatesListItemResponse;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\InstrumentistVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/instrumentists')]
final class InstrumentistController extends AbstractController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * GET /api/instrumentists
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Retourne uniquement les instrumentistes actifs
     * - Aucun champ financier
     * - Tri: lastname, firstname, email
     */
    #[Route('', name: 'api_instrumentists_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST, User::class);

        $items = $this->users->findInstrumentists(true);

        $dtos = array_map(static function (User $u): InstrumentistListItemResponse {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();

            $name = trim((string)($firstname ?? '') . ' ' . (string)($lastname ?? ''));
            $displayName = $name !== '' ? $name : (string) $u->getEmail();

            $employmentType = $u->getEmploymentType();
            $employmentTypeValue = $employmentType ? $employmentType->value : null;

            return new InstrumentistListItemResponse(
                id: (int) $u->getId(),
                email: (string) $u->getEmail(),
                firstname: $firstname,
                lastname: $lastname,
                active: $u->isActive(),
                employmentType: $employmentTypeValue,
                defaultCurrency: $u->getDefaultCurrency(),
                displayName: $displayName,
            );
        }, $items);

        return $this->json([
            'items' => $dtos,
            'total' => count($dtos),
        ]);
    }

    /**
     * GET /api/instrumentists/with-rates
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Retourne tous les instrumentistes (actifs + inactifs)
     * - Inclut les champs financiers
     * - Tri: lastname, firstname, email
     */
    #[Route('/with-rates', name: 'api_instrumentists_list_with_rates', methods: ['GET'])]
    public function listWithRates(): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST_WITH_RATES, User::class);

        $items = $this->users->findInstrumentists(false);

        $dtos = array_map(static function (User $u): InstrumentistWithRatesListItemResponse {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();

            $name = trim((string)($firstname ?? '') . ' ' . (string)($lastname ?? ''));
            $displayName = $name !== '' ? $name : (string) $u->getEmail();

            $employmentType = $u->getEmploymentType();
            $employmentTypeValue = $employmentType ? $employmentType->value : null;

            return new InstrumentistWithRatesListItemResponse(
                id: (int) $u->getId(),
                email: (string) $u->getEmail(),
                firstname: $firstname,
                lastname: $lastname,
                active: $u->isActive(),
                employmentType: $employmentTypeValue,
                defaultCurrency: $u->getDefaultCurrency(),
                hourlyRate: $u->getHourlyRate(),
                consultationFee: $u->getConsultationFee(),
                displayName: $displayName,
            );
        }, $items);

        return $this->json([
            'items' => $dtos,
            'total' => count($dtos),
        ]);
    }
}
