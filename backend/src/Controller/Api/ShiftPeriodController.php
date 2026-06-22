<?php

namespace App\Controller\Api;

use App\Entity\Hospital;
use App\Entity\ShiftPeriodConfig;
use App\Enum\ShiftPeriod;
use App\Security\Voter\PlanningVoter;
use App\Service\ShiftPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Manager/Admin-only CRUD for ShiftPeriodConfig (site-level MATIN/APRES_MIDI/JOURNEE hours). */
class ShiftPeriodController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftPeriodService $service,
    ) {}

    #[Route('/api/planning/shift-periods', name: 'api_planning_shift_periods_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $site = null;
        if ($request->query->get('siteId') !== null) {
            $site = $this->findSiteOrFail((int) $request->query->get('siteId'));
        }

        return $this->json(['items' => array_map(fn (ShiftPeriodConfig $c) => $this->serialize($c), $this->service->list($site))]);
    }

    #[Route('/api/planning/shift-periods', name: 'api_planning_shift_periods_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (!isset($data['siteId']) || !is_numeric($data['siteId'])) {
            throw new BadRequestHttpException('siteId est requis.');
        }
        $site = $this->findSiteOrFail((int) $data['siteId']);

        $period = ShiftPeriod::tryFrom((string) ($data['period'] ?? ''));
        if ($period === null) {
            throw new BadRequestHttpException('period est requis (MATIN, APRES_MIDI ou JOURNEE).');
        }

        $startTime = $this->parseTime($data['startTime'] ?? null, 'startTime');
        $endTime   = $this->parseTime($data['endTime'] ?? null, 'endTime');

        $config = $this->service->create($site, $period, $startTime, $endTime);

        return $this->json($this->serialize($config), 201);
    }

    #[Route('/api/planning/shift-periods/{id}', name: 'api_planning_shift_period_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $config = $this->findOrFail($id);
        $data   = json_decode($request->getContent() ?: '{}', true) ?? [];

        $period = isset($data['period']) ? ShiftPeriod::tryFrom((string) $data['period']) : null;
        if (isset($data['period']) && $period === null) {
            throw new BadRequestHttpException('period invalide.');
        }

        $startTime = isset($data['startTime']) ? $this->parseTime($data['startTime'], 'startTime') : null;
        $endTime   = isset($data['endTime']) ? $this->parseTime($data['endTime'], 'endTime') : null;

        $this->service->update($config, $period, $startTime, $endTime);

        if (isset($data['active'])) {
            $data['active'] ? $this->service->reactivate($config) : $this->service->deactivate($config);
        }

        return $this->json($this->serialize($config));
    }

    #[Route('/api/planning/shift-periods/{id}', name: 'api_planning_shift_period_deactivate', methods: ['DELETE'])]
    public function deactivate(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $this->service->deactivate($this->findOrFail($id));

        return new JsonResponse(null, 204);
    }

    private function findOrFail(int $id): ShiftPeriodConfig
    {
        $config = $this->em->find(ShiftPeriodConfig::class, $id);
        if ($config === null) {
            throw $this->createNotFoundException('ShiftPeriodConfig introuvable.');
        }
        return $config;
    }

    private function findSiteOrFail(int $siteId): Hospital
    {
        $site = $this->em->find(Hospital::class, $siteId);
        if ($site === null) {
            throw $this->createNotFoundException('Site introuvable.');
        }
        return $site;
    }

    private function parseTime(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new BadRequestHttpException(sprintf('%s est requis.', $field));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s doit être une heure valide (HH:MM ou HH:MM:SS).', $field));
        }
    }

    private function serialize(ShiftPeriodConfig $config): array
    {
        return [
            'id'        => $config->getId(),
            'site'      => ['id' => $config->getSite()->getId(), 'name' => $config->getSite()->getName()],
            'period'    => $config->getPeriod()->value,
            'startTime' => $config->getStartTime()->format('H:i'),
            'endTime'   => $config->getEndTime()->format('H:i'),
            'active'    => $config->isActive(),
        ];
    }
}
