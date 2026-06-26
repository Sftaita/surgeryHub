<?php

namespace App\Controller\Api;

use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Security\Voter\PlanningVoter;
use App\Service\SurgeonSchedulePostService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Manager/Admin-only CRUD for SurgeonSchedulePost. No mission generation, no mutation of existing missions. */
class SurgeonSchedulePostController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonSchedulePostService $service,
    ) {}

    #[Route('/api/planning/surgeon-posts', name: 'api_planning_surgeon_posts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $filters = [];
        if ($request->query->get('siteId') !== null) {
            $filters['siteId'] = (int) $request->query->get('siteId');
        }
        if ($request->query->get('siteGroupId') !== null) {
            $filters['siteGroupId'] = (int) $request->query->get('siteGroupId');
        }
        if ($request->query->get('surgeonId') !== null) {
            $filters['surgeonId'] = (int) $request->query->get('surgeonId');
        }
        if ($request->query->get('active') !== null) {
            $filters['active'] = filter_var($request->query->get('active'), FILTER_VALIDATE_BOOLEAN);
        }
        if (($type = MissionType::tryFrom((string) $request->query->get('type'))) !== null) {
            $filters['type'] = $type;
        }

        $posts = $this->service->search($filters);

        return $this->json(['items' => array_map(fn (SurgeonSchedulePost $p) => $this->serialize($p), $posts)]);
    }

    #[Route('/api/planning/surgeon-posts', name: 'api_planning_surgeon_posts_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $input = $this->parseInput($request, requireAll: true);
        $post  = $this->service->create($input, $currentUser);

        return $this->json($this->serialize($post), 201);
    }

    #[Route('/api/planning/surgeon-posts/{id}', name: 'api_planning_surgeon_post_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        return $this->json($this->serialize($this->findOrFail($id)));
    }

    #[Route('/api/planning/surgeon-posts/{id}', name: 'api_planning_surgeon_post_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $post  = $this->findOrFail($id);
        $input = $this->parseInput($request, requireAll: false);

        $this->service->update($post, $input);

        return $this->json($this->serialize($post));
    }

    #[Route('/api/planning/surgeon-posts/{id}', name: 'api_planning_surgeon_post_deactivate', methods: ['DELETE'])]
    public function deactivate(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $this->service->deactivate($this->findOrFail($id));

        return new JsonResponse(null, 204);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function findOrFail(int $id): SurgeonSchedulePost
    {
        $post = $this->em->find(SurgeonSchedulePost::class, $id);
        if ($post === null) {
            throw $this->createNotFoundException('SurgeonSchedulePost introuvable.');
        }
        return $post;
    }

    /** @return array<string, mixed> */
    private function parseInput(Request $request, bool $requireAll): array
    {
        $data = json_decode($request->getContent() ?: '{}', true) ?? [];
        $input = [];

        if ($requireAll || isset($data['surgeonId'])) {
            if (!isset($data['surgeonId']) || !is_numeric($data['surgeonId'])) {
                throw new BadRequestHttpException('surgeonId est requis.');
            }
            $input['surgeonId'] = (int) $data['surgeonId'];
        }

        if ($requireAll || isset($data['siteId'])) {
            if (!isset($data['siteId']) || !is_numeric($data['siteId'])) {
                throw new BadRequestHttpException('siteId est requis.');
            }
            $input['siteId'] = (int) $data['siteId'];
        }

        if ($requireAll || isset($data['type'])) {
            $type = MissionType::tryFrom((string) ($data['type'] ?? ''));
            if ($type === null) {
                throw new BadRequestHttpException('type est requis (BLOCK ou CONSULTATION).');
            }
            $input['type'] = $type;
        }

        if ($requireAll || isset($data['period'])) {
            $period = ShiftPeriod::tryFrom((string) ($data['period'] ?? ''));
            if ($period === null) {
                throw new BadRequestHttpException('period est requis (MATIN, APRES_MIDI ou JOURNEE).');
            }
            $input['period'] = $period;
        }

        if (array_key_exists('instrumentistId', $data)) {
            $input['instrumentistId'] = $data['instrumentistId'] !== null ? (int) $data['instrumentistId'] : null;
        }

        if ($requireAll || isset($data['startDate'])) {
            $input['startDate'] = $this->parseDate($data['startDate'] ?? null, 'startDate');
        }

        if (array_key_exists('endDate', $data)) {
            $input['endDate'] = $data['endDate'] !== null ? $this->parseDate($data['endDate'], 'endDate') : null;
        }

        if ($requireAll || isset($data['recurrence'])) {
            $input['recurrence'] = $this->parseRecurrence($data['recurrence'] ?? []);
        }

        if (array_key_exists('active', $data)) {
            if (!is_bool($data['active'])) {
                throw new BadRequestHttpException('active doit être un booléen (true ou false).');
            }
            $input['active'] = $data['active'];
        }

        return $input;
    }

    private function parseRecurrence(array $data): array
    {
        $recurrence = [];

        $frequency = RecurrenceFrequency::tryFrom((string) ($data['frequency'] ?? ''));
        if ($frequency !== null) {
            $recurrence['frequency'] = $frequency;
        } elseif (isset($data['frequency'])) {
            throw new BadRequestHttpException('recurrence.frequency invalide.');
        }

        if (isset($data['interval'])) {
            $recurrence['interval'] = (int) $data['interval'];
        }
        if (isset($data['weekdays']) && is_array($data['weekdays'])) {
            $recurrence['weekdays'] = array_map('intval', $data['weekdays']);
        }
        if (isset($data['anchorDate'])) {
            $recurrence['anchorDate'] = $this->parseDate($data['anchorDate'], 'recurrence.anchorDate');
        }
        if (isset($data['monthWeeks']) && is_array($data['monthWeeks'])) {
            $recurrence['monthWeeks'] = array_map('intval', $data['monthWeeks']);
        }

        return $recurrence;
    }

    private function parseDate(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new BadRequestHttpException(sprintf('%s est requis.', $field));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s doit être une date valide (YYYY-MM-DD).', $field));
        }
    }

    private function serialize(SurgeonSchedulePost $post): array
    {
        $recurrence = $post->getRecurrence();
        $instrumentist = $post->getInstrumentist();

        return [
            'id'         => $post->getId(),
            'surgeon'    => $this->serializeUserRef($post->getSurgeon()),
            'site'       => ['id' => $post->getSite()->getId(), 'name' => $post->getSite()->getName()],
            'type'       => $post->getType()->value,
            'period'     => $post->getPeriod()->value,
            'instrumentist' => $instrumentist !== null ? $this->serializeUserRef($instrumentist) : null,
            'startDate'  => $post->getStartDate()->format('Y-m-d'),
            'endDate'    => $post->getEndDate()?->format('Y-m-d'),
            'active'     => $post->isActive(),
            'recurrence' => [
                'frequency'   => $recurrence->getFrequency()->value,
                'interval'    => $recurrence->getInterval(),
                'weekdays'    => $recurrence->getWeekdays(),
                'anchorDate'  => $recurrence->getAnchorDate()->format('Y-m-d'),
                'monthWeeks'  => $recurrence->getMonthWeeks(),
            ],
        ];
    }

    /** Stable {id, email, name} shape — matches the convention already used by absences and eligible-instrumentists. */
    private function serializeUserRef(User $user): array
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return [
            'id'    => $user->getId(),
            'email' => $user->getEmail(),
            'name'  => $name !== '' ? $name : $user->getEmail(),
        ];
    }
}
