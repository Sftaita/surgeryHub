<?php

namespace App\Controller\Api;

use App\Entity\Hospital;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\PlanningTemplateType;
use App\Enum\SlotPeriod;
use App\Security\Voter\PlanningVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/planning/templates')]
class PlanningTemplateController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'api_planning_templates_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $templates = $this->em->createQuery(
            'SELECT t FROM App\Entity\PlanningTemplate t ORDER BY t.dateStart DESC'
        )->getResult();

        return $this->json(array_map(fn($t) => $this->serializeTemplate($t), $templates));
    }

    #[Route('', name: 'api_planning_templates_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];

        $typeStr   = $data['type'] ?? null;
        $dateStart = $data['dateStart'] ?? null;
        $siteId    = $data['siteId'] ?? null;

        if (!$typeStr || !$dateStart) {
            return $this->json(['error' => ['message' => 'type et dateStart sont requis.']], 400);
        }

        $type = PlanningTemplateType::tryFrom($typeStr);
        if ($type === null) {
            return $this->json(['error' => ['message' => 'type invalide (PAIR ou IMPAIR).']], 400);
        }

        try {
            $dateStartImmutable = new \DateTimeImmutable($dateStart);
        } catch (\Exception) {
            return $this->json(['error' => ['message' => 'Format dateStart invalide.']], 400);
        }

        $dateEnd = null;
        if (!empty($data['dateEnd'])) {
            try {
                $dateEnd = new \DateTimeImmutable($data['dateEnd']);
            } catch (\Exception) {
                return $this->json(['error' => ['message' => 'Format dateEnd invalide.']], 400);
            }
        }

        $site = null;
        if ($siteId !== null) {
            $site = $this->em->find(Hospital::class, $siteId);
            if (!$site) {
                return $this->json(['error' => ['message' => 'Site introuvable.']], 404);
            }
        }

        // Auto-close previous active template of same type+site
        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(PlanningTemplate::class, 't')
            ->where('t.type = :type')
            ->andWhere('(t.dateEnd IS NULL OR t.dateEnd >= :dateStart)')
            ->setParameter('type', $type)
            ->setParameter('dateStart', $dateStartImmutable->format('Y-m-d'));

        if ($site !== null) {
            $qb->andWhere('t.site = :site')->setParameter('site', $site);
        } else {
            $qb->andWhere('t.site IS NULL');
        }

        /** @var PlanningTemplate[] $activeTemplates */
        $activeTemplates = $qb->getQuery()->getResult();

        $closeDate = $dateStartImmutable->modify('-1 day');
        foreach ($activeTemplates as $active) {
            $active->setDateEnd($closeDate);
        }

        $template = new PlanningTemplate();
        $template->setType($type);
        $template->setDateStart($dateStartImmutable);
        $template->setDateEnd($dateEnd);
        $template->setSite($site);
        $template->setCreatedBy($currentUser);

        $this->em->persist($template);
        $this->em->flush();

        return $this->json($this->serializeTemplate($template), 201);
    }

    #[Route('/{id}', name: 'api_planning_templates_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $template = $this->em->find(PlanningTemplate::class, $id);
        if (!$template) {
            return $this->json(['error' => ['message' => 'Template introuvable.']], 404);
        }

        return $this->json($this->serializeTemplate($template));
    }

    #[Route('/{id}', name: 'api_planning_templates_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $template = $this->em->find(PlanningTemplate::class, $id);
        if (!$template) {
            return $this->json(['error' => ['message' => 'Template introuvable.']], 404);
        }

        $this->em->remove($template);
        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{id}/slots', name: 'api_planning_templates_add_slot', methods: ['POST'])]
    public function addSlot(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $template = $this->em->find(PlanningTemplate::class, $id);
        if (!$template) {
            return $this->json(['error' => ['message' => 'Template introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $dayOfWeek  = $data['dayOfWeek'] ?? null;
        $periodStr  = $data['period'] ?? null;
        $startTime  = $data['startTime'] ?? null;
        $endTime    = $data['endTime'] ?? null;
        $surgeonId  = $data['surgeonId'] ?? null;
        $missionTypeStr = $data['missionType'] ?? null;

        if (!$dayOfWeek || !$periodStr || !$startTime || !$endTime || !$surgeonId || !$missionTypeStr) {
            return $this->json(['error' => ['message' => 'dayOfWeek, period, startTime, endTime, surgeonId et missionType sont requis.']], 400);
        }

        $period = SlotPeriod::tryFrom($periodStr);
        if ($period === null) {
            return $this->json(['error' => ['message' => 'period invalide (AM ou PM).']], 400);
        }

        $missionType = MissionType::tryFrom($missionTypeStr);
        if ($missionType === null) {
            return $this->json(['error' => ['message' => 'missionType invalide (BLOCK ou CONSULTATION).']], 400);
        }

        $surgeon = $this->em->find(User::class, $surgeonId);
        if (!$surgeon) {
            return $this->json(['error' => ['message' => 'Chirurgien introuvable.']], 404);
        }

        try {
            $startTimeImmutable = new \DateTimeImmutable($startTime);
            $endTimeImmutable   = new \DateTimeImmutable($endTime);
        } catch (\Exception) {
            return $this->json(['error' => ['message' => 'Format de temps invalide (HH:MM attendu).']], 400);
        }

        $site = null;
        if (!empty($data['siteId'])) {
            $site = $this->em->find(Hospital::class, $data['siteId']);
            if (!$site) {
                return $this->json(['error' => ['message' => 'Site introuvable.']], 404);
            }
        }

        $instrumentist = null;
        if (!empty($data['instrumentistId'])) {
            $instrumentist = $this->em->find(User::class, $data['instrumentistId']);
            if (!$instrumentist) {
                return $this->json(['error' => ['message' => 'Instrumentiste introuvable.']], 404);
            }
        }

        $slot = new PlanningSlot();
        $slot->setTemplate($template);
        $slot->setDayOfWeek((int) $dayOfWeek);
        $slot->setPeriod($period);
        $slot->setStartTime($startTimeImmutable);
        $slot->setEndTime($endTimeImmutable);
        $slot->setSurgeon($surgeon);
        $slot->setMissionType($missionType);
        $slot->setSite($site);
        $slot->setInstrumentist($instrumentist);

        $template->addSlot($slot);
        $this->em->flush();

        return $this->json($this->serializeSlot($slot), 201);
    }

    #[Route('/{id}/slots/{slotId}', name: 'api_planning_templates_delete_slot', methods: ['DELETE'])]
    public function deleteSlot(int $id, int $slotId): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $template = $this->em->find(PlanningTemplate::class, $id);
        if (!$template) {
            return $this->json(['error' => ['message' => 'Template introuvable.']], 404);
        }

        $slot = $this->em->find(PlanningSlot::class, $slotId);
        if (!$slot || $slot->getTemplate()?->getId() !== $id) {
            return $this->json(['error' => ['message' => 'Slot introuvable.']], 404);
        }

        $this->em->remove($slot);
        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{id}/slots/{slotId}', name: 'api_planning_templates_update_slot', methods: ['PUT'])]
    public function updateSlot(int $id, int $slotId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $template = $this->em->find(PlanningTemplate::class, $id);
        if (!$template) {
            return $this->json(['error' => ['message' => 'Template introuvable.']], 404);
        }

        $slot = $this->em->find(PlanningSlot::class, $slotId);
        if (!$slot || $slot->getTemplate()?->getId() !== $id) {
            return $this->json(['error' => ['message' => 'Slot introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['dayOfWeek'])) {
            $slot->setDayOfWeek((int) $data['dayOfWeek']);
        }

        if (isset($data['period'])) {
            $period = SlotPeriod::tryFrom($data['period']);
            if ($period === null) {
                return $this->json(['error' => ['message' => 'period invalide (AM ou PM).']], 400);
            }
            $slot->setPeriod($period);
        }

        if (isset($data['startTime'])) {
            try {
                $slot->setStartTime(new \DateTimeImmutable($data['startTime']));
            } catch (\Exception) {
                return $this->json(['error' => ['message' => 'Format startTime invalide.']], 400);
            }
        }

        if (isset($data['endTime'])) {
            try {
                $slot->setEndTime(new \DateTimeImmutable($data['endTime']));
            } catch (\Exception) {
                return $this->json(['error' => ['message' => 'Format endTime invalide.']], 400);
            }
        }

        if (isset($data['surgeonId'])) {
            $surgeon = $this->em->find(User::class, $data['surgeonId']);
            if (!$surgeon) {
                return $this->json(['error' => ['message' => 'Chirurgien introuvable.']], 404);
            }
            $slot->setSurgeon($surgeon);
        }

        if (isset($data['missionType'])) {
            $missionType = MissionType::tryFrom($data['missionType']);
            if ($missionType === null) {
                return $this->json(['error' => ['message' => 'missionType invalide.']], 400);
            }
            $slot->setMissionType($missionType);
        }

        if (array_key_exists('siteId', $data)) {
            if ($data['siteId'] === null) {
                $slot->setSite(null);
            } else {
                $site = $this->em->find(Hospital::class, $data['siteId']);
                if (!$site) {
                    return $this->json(['error' => ['message' => 'Site introuvable.']], 404);
                }
                $slot->setSite($site);
            }
        }

        if (array_key_exists('instrumentistId', $data)) {
            if ($data['instrumentistId'] === null) {
                $slot->setInstrumentist(null);
            } else {
                $instrumentist = $this->em->find(User::class, $data['instrumentistId']);
                if (!$instrumentist) {
                    return $this->json(['error' => ['message' => 'Instrumentiste introuvable.']], 404);
                }
                $slot->setInstrumentist($instrumentist);
            }
        }

        $this->em->flush();

        return $this->json($this->serializeSlot($slot));
    }

    // ── Serializers ───────────────────────────────────────────────────────────

    private function serializeTemplate(PlanningTemplate $t): array
    {
        return [
            'id'        => $t->getId(),
            'type'      => $t->getType()->value,
            'dateStart' => $t->getDateStart()->format('Y-m-d'),
            'dateEnd'   => $t->getDateEnd()?->format('Y-m-d'),
            'site'      => $t->getSite() ? ['id' => $t->getSite()->getId(), 'name' => $t->getSite()->getName()] : null,
            'createdBy' => $t->getCreatedBy() ? ['id' => $t->getCreatedBy()->getId()] : null,
            'createdAt' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'slots'     => array_map(fn($s) => $this->serializeSlot($s), $t->getSlots()->toArray()),
        ];
    }

    private function serializeSlot(PlanningSlot $s): array
    {
        return [
            'id'             => $s->getId(),
            'dayOfWeek'      => $s->getDayOfWeek(),
            'period'         => $s->getPeriod()->value,
            'startTime'      => $s->getStartTime()->format('H:i'),
            'endTime'        => $s->getEndTime()->format('H:i'),
            'missionType'    => $s->getMissionType()->value,
            'site'           => $s->getSite() ? ['id' => $s->getSite()->getId(), 'name' => $s->getSite()->getName()] : null,
            'surgeon'        => $s->getSurgeon() ? [
                'id'   => $s->getSurgeon()->getId(),
                'name' => trim(($s->getSurgeon()->getFirstname() ?? '') . ' ' . ($s->getSurgeon()->getLastname() ?? '')),
            ] : null,
            'instrumentist'  => $s->getInstrumentist() ? [
                'id'   => $s->getInstrumentist()->getId(),
                'name' => trim(($s->getInstrumentist()->getFirstname() ?? '') . ' ' . ($s->getInstrumentist()->getLastname() ?? '')),
            ] : null,
        ];
    }
}
