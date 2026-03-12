<?php

namespace App\Controller\Api;

use App\Dto\Request\Response\InstrumentistListItemResponse;
use App\Dto\Request\Response\InstrumentistWithRatesListItemResponse;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\InstrumentistVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
     * - Retourne la liste manager des instrumentistes
     * - Aucun champ financier
     * - Filtres supportés: search, active, siteId
     * - Tri: lastname, firstname, email
     */
    #[Route('', name: 'api_instrumentists_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST, User::class);

        $search = $request->query->get('search');
        $search = is_string($search) ? trim($search) : null;
        if ($search === '') {
            $search = null;
        }

        $active = $this->parseNullableBoolQuery($request, 'active');
        if ($active === null) {
            $active = true;
        }

        $siteId = $request->query->get('siteId');
        if ($siteId !== null && (!is_scalar($siteId) || !ctype_digit((string) $siteId))) {
            throw new BadRequestHttpException('Query parameter "siteId" must be a positive integer.');
        }
        $siteId = $siteId !== null ? (int) $siteId : null;

        $items = $this->users->findInstrumentists($search, $active, $siteId);

        $dtos = array_map(static function (User $u): InstrumentistListItemResponse {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();

            $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
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

        $items = $this->users->findInstrumentists(null, null, null);

        $dtos = array_map(static function (User $u): InstrumentistWithRatesListItemResponse {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();

            $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
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

    private function parseNullableBoolQuery(Request $request, string $key): ?bool
    {
        $value = $request->query->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            throw new BadRequestHttpException(sprintf('Query parameter "%s" must be a boolean.', $key));
        }

        return match (mb_strtolower((string) $value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new BadRequestHttpException(sprintf('Query parameter "%s" must be "true", "false", "1" or "0".', $key)),
        };
    }
}