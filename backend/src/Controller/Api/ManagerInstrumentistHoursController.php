<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use App\Security\Voter\InstrumentistHoursVoter;
use App\Service\InstrumentistHoursService;
use App\Service\MyHoursService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-180 — suivi manager des heures instrumentistes. Lecture seule, MANAGER / ADMIN
 * (InstrumentistHoursVoter). Le serveur calcule tout : le front n'affiche que ces valeurs.
 */
#[Route('/api/manager')]
final class ManagerInstrumentistHoursController extends AbstractController
{
    private const TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly InstrumentistHoursService $hours,
        private readonly MyHoursService $myHours,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/instrumentist-hours', name: 'api_manager_instrumentist_hours', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistHoursVoter::VIEW);

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $month = $this->month($request, $now);
        if ($month instanceof JsonResponse) {
            return $month;
        }
        $siteId = $this->positiveInt($request, 'siteId');
        if ($siteId instanceof JsonResponse) {
            return $siteId;
        }
        $limit = $this->positiveInt($request, 'limit');
        if ($limit instanceof JsonResponse) {
            return $limit;
        }

        $sort = (string) $request->query->get('sort', 'validatedMinutes');
        if (!in_array($sort, InstrumentistHoursService::SORTS, true)) {
            return $this->invalid('Query parameter "sort" must be one of: ' . implode(', ', InstrumentistHoursService::SORTS));
        }
        $direction = (string) $request->query->get('direction', 'desc');
        if (!in_array($direction, ['asc', 'desc'], true)) {
            return $this->invalid('Query parameter "direction" must be "asc" or "desc"');
        }
        $q = $request->query->get('q');
        if ($q !== null && (!is_string($q) || mb_strlen($q) > 100)) {
            return $this->invalid('Query parameter "q" must be a string of at most 100 characters');
        }

        return $this->json($this->hours->overview($month[0], $month[1], $siteId, $q, $sort, $direction, $limit, $now));
    }

    #[Route('/instrumentists/{id}/hours', name: 'api_manager_instrumentist_hours_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistHoursVoter::VIEW);

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $month = $this->month($request, $now);
        if ($month instanceof JsonResponse) {
            return $month;
        }
        $siteId = $this->positiveInt($request, 'siteId');
        if ($siteId instanceof JsonResponse) {
            return $siteId;
        }

        $instrumentist = $this->users->findInstrumentistById($id);
        if ($instrumentist === null) {
            return $this->json(['message' => 'Instrumentist not found'], Response::HTTP_NOT_FOUND);
        }

        // Relevé identique à « Mes heures » (D-179), estimation comprise, + comparaisons.
        $statement = $this->myHours->statement($instrumentist, $month[0], $month[1], $siteId, true);
        $comparison = $this->hours->comparisonFor($instrumentist, $month[0], $month[1], $siteId, $now);

        return $this->json([
            'month' => $statement['month'],
            'provisional' => $comparison['provisional'],
            'asOfDay' => $comparison['asOfDay'],
            'siteId' => $statement['siteId'],
            'instrumentist' => [
                'id' => $instrumentist->getId(),
                'firstname' => $instrumentist->getFirstname(),
                'lastname' => $instrumentist->getLastname(),
                'active' => $instrumentist->isActive(),
            ],
            'sites' => $statement['sites'],
            'summary' => $statement['summary'],
            'estimate' => $statement['estimate'],
            'comparison' => $comparison['comparison'],
            'items' => $statement['items'],
        ]);
    }

    /** @return array{0: int, 1: int}|JsonResponse */
    private function month(Request $request, \DateTimeImmutable $now): array|JsonResponse
    {
        $month = $request->query->get('month');
        if (!is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1) {
            return $this->invalid('Query parameter "month" is required (YYYY-MM)');
        }
        if ($month > $now->format('Y-m')) {
            return $this->invalid('Query parameter "month" cannot be in the future');
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function positiveInt(Request $request, string $name): int|null|JsonResponse
    {
        $value = $request->query->get($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            return $this->invalid(sprintf('Query parameter "%s" must be a positive integer', $name));
        }

        return (int) $value;
    }

    private function invalid(string $message): JsonResponse
    {
        return $this->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
