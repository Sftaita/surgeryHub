<?php

namespace App\Controller\Api;

use App\Entity\AuditEvent;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningAlertActionService;
use App\Service\PlanningAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Manager/Admin-only read + acknowledge/resolve/ignore API for PlanningAlert (Batch 3's
 * post-publication absence-impact engine). No endpoint here ever mutates a Mission —
 * the action flags returned alongside each alert (canReassign/canOpenAsAvailable) are
 * advisory only, for a future batch that will add real reassignment endpoints.
 *
 * No frontend, no cutover, V1 (PAIR/IMPAIR/TOUTES) untouched — this exposes Batch 3's
 * alert system, nothing else.
 */
class PlanningAlertController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningAlertService $alertService,
        private readonly PlanningAlertActionService $actionService,
    ) {}

    // ── List ──────────────────────────────────────────────────────────────────

    #[Route('/api/planning/alerts', name: 'api_planning_alerts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $filters = [];

        if (($status = PlanningAlertStatus::tryFrom((string) $request->query->get('status'))) !== null) {
            $filters['status'] = $status;
        }
        if (($type = PlanningAlertType::tryFrom((string) $request->query->get('type'))) !== null) {
            $filters['type'] = $type;
        }
        if (($missionStatus = MissionStatus::tryFrom((string) $request->query->get('missionStatus'))) !== null) {
            $filters['missionStatus'] = $missionStatus;
        }
        if ($request->query->get('siteId') !== null) {
            $filters['siteId'] = (int) $request->query->get('siteId');
        }
        if ($request->query->get('surgeonId') !== null) {
            $filters['surgeonId'] = (int) $request->query->get('surgeonId');
        }
        if ($request->query->get('instrumentistId') !== null) {
            $filters['instrumentistId'] = (int) $request->query->get('instrumentistId');
        }
        if ($request->query->get('from') !== null) {
            try {
                $filters['from'] = new \DateTimeImmutable((string) $request->query->get('from'));
            } catch (\Exception) {}
        }
        if ($request->query->get('to') !== null) {
            try {
                $filters['to'] = new \DateTimeImmutable((string) $request->query->get('to'));
            } catch (\Exception) {}
        }

        $result = $this->alertService->search($filters, $page, $limit);

        return $this->json([
            'items' => array_map(fn (PlanningAlert $a) => $this->alertService->serialize($a), $result['items']),
            'total' => $result['total'],
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    // ── Detail ────────────────────────────────────────────────────────────────

    #[Route('/api/planning/alerts/{id}', name: 'api_planning_alert_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        return $this->json($this->alertService->serialize($this->findOrFail($id)));
    }

    // ── Transitions ───────────────────────────────────────────────────────────

    #[Route('/api/planning/alerts/{id}/acknowledge', name: 'api_planning_alert_acknowledge', methods: ['POST'])]
    public function acknowledge(int $id, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $alert   = $this->findOrFail($id);
        $changed = $this->alertService->acknowledge($alert, $currentUser);

        if ($changed) {
            $this->audit($alert, $currentUser, AuditEventType::PLANNING_ALERT_ACKNOWLEDGED);
        }
        $this->em->flush();

        return $this->json($this->alertService->serialize($alert));
    }

    #[Route('/api/planning/alerts/{id}/resolve', name: 'api_planning_alert_resolve', methods: ['POST'])]
    public function resolve(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $alert = $this->findOrFail($id);
        $note  = $this->extractNote($request, 'Résolu par le manager.');

        $changed = $this->alertService->resolve($alert, $currentUser, $note);

        if ($changed) {
            $this->audit($alert, $currentUser, AuditEventType::PLANNING_ALERT_RESOLVED, $note);
        }
        $this->em->flush();

        return $this->json($this->alertService->serialize($alert));
    }

    #[Route('/api/planning/alerts/{id}/ignore', name: 'api_planning_alert_ignore', methods: ['POST'])]
    public function ignore(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $alert = $this->findOrFail($id);
        $note  = $this->extractNote($request, 'Ignoré par le manager.');

        $changed = $this->alertService->ignore($alert, $currentUser, $note);

        if ($changed) {
            $this->audit($alert, $currentUser, AuditEventType::PLANNING_ALERT_IGNORED, $note);
        }
        $this->em->flush();

        return $this->json($this->alertService->serialize($alert));
    }

    // ── Actions (Batch 5) ────────────────────────────────────────────────────

    #[Route('/api/planning/alerts/{id}/reassign', name: 'api_planning_alert_reassign', methods: ['POST'])]
    public function reassign(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $alert = $this->findOrFail($id);
        $data  = json_decode($request->getContent() ?: '{}', true) ?? [];

        if (!isset($data['instrumentistId']) || !is_numeric($data['instrumentistId'])) {
            throw new BadRequestHttpException('instrumentistId est requis.');
        }
        $note = $this->extractOptionalNote($request);

        $this->actionService->reassign($alert, (int) $data['instrumentistId'], $currentUser, $note);

        $this->audit($alert, $currentUser, AuditEventType::PLANNING_ALERT_REASSIGNED, [
            'instrumentistId' => (int) $data['instrumentistId'],
            'note'            => $note,
        ]);
        $this->em->flush();

        return $this->json($this->alertService->serialize($alert));
    }

    #[Route('/api/planning/alerts/{id}/open-as-available', name: 'api_planning_alert_open_as_available', methods: ['POST'])]
    public function openAsAvailable(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $alert = $this->findOrFail($id);
        $note  = $this->extractOptionalNote($request);

        $this->actionService->openAsAvailable($alert, $currentUser, $note);

        $this->audit($alert, $currentUser, AuditEventType::PLANNING_ALERT_OPENED_AS_AVAILABLE, ['note' => $note]);
        $this->em->flush();

        return $this->json($this->alertService->serialize($alert));
    }

    #[Route('/api/planning/alerts/{id}/eligible-instrumentists', name: 'api_planning_alert_eligible_instrumentists', methods: ['GET'])]
    public function eligibleInstrumentists(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $alert      = $this->findOrFail($id);
        $candidates = $this->actionService->findEligibleInstrumentists($alert->getMission());

        return $this->json([
            'items' => array_map(static fn (User $u) => [
                'id'    => $u->getId(),
                'email' => $u->getEmail(),
                'name'  => trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? '')) ?: $u->getEmail(),
                'sites' => array_values(array_filter(array_map(
                    static fn ($sm) => $sm->getSite()?->getName(),
                    $u->getSiteMemberships()->toArray(),
                ))),
            ], $candidates),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function findOrFail(int $id): PlanningAlert
    {
        $alert = $this->em->find(PlanningAlert::class, $id);
        if ($alert === null) {
            throw $this->createNotFoundException('PlanningAlert introuvable.');
        }
        return $alert;
    }

    private function extractNote(Request $request, string $default): string
    {
        $data = json_decode($request->getContent() ?: '{}', true) ?? [];
        $note = is_string($data['note'] ?? null) ? trim($data['note']) : '';
        return $note !== '' ? $note : $default;
    }

    /** Unlike extractNote(), no default — the action services apply their own context-aware default note when null. */
    private function extractOptionalNote(Request $request): ?string
    {
        $data = json_decode($request->getContent() ?: '{}', true) ?? [];
        $note = is_string($data['note'] ?? null) ? trim($data['note']) : '';
        return $note !== '' ? $note : null;
    }

    /** Audit infra is available (AuditEvent requires a Mission, which every PlanningAlert has) — always write one on an actual transition. */
    private function audit(PlanningAlert $alert, User $actor, AuditEventType $eventType, array|string|null $extra = null): void
    {
        $event = new AuditEvent();
        $event->setActor($actor);
        $event->setMission($alert->getMission());
        $event->setEventType($eventType);
        $event->setPayload(array_merge(
            [
                'alertId'   => $alert->getId(),
                'alertType' => $alert->getType()->value,
                'newStatus' => $alert->getStatus()->value,
            ],
            is_array($extra) ? $extra : ['note' => $extra],
        ));
        $this->em->persist($event);
    }
}
