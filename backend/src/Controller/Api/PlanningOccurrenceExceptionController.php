<?php

namespace App\Controller\Api;

use App\Entity\PlanningOccurrenceException;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\OccurrenceExceptionType;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningOccurrenceExceptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Manager/Admin-only CRUD for PlanningOccurrenceException. Always keyed by (post, date) —
 * never touches SurgeonSchedulePost or its RecurrenceRule.
 */
class PlanningOccurrenceExceptionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningOccurrenceExceptionService $service,
    ) {}

    #[Route('/api/planning/surgeon-posts/{postId}/exceptions', name: 'api_planning_post_exceptions_list', methods: ['GET'])]
    public function listForPost(int $postId): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $post = $this->findPostOrFail($postId);

        return $this->json(['items' => array_map(fn (PlanningOccurrenceException $e) => $this->serialize($e), $this->service->listForPost($post))]);
    }

    #[Route('/api/planning/surgeon-posts/{postId}/exceptions', name: 'api_planning_post_exceptions_create', methods: ['POST'])]
    public function create(int $postId, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $post = $this->findPostOrFail($postId);
        $data = json_decode($request->getContent() ?: '{}', true) ?? [];

        $type = OccurrenceExceptionType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            throw new BadRequestHttpException('type est requis (CANCELLED, MOVED, TIME_OVERRIDE ou INSTRUMENTIST_OVERRIDE).');
        }
        $occurrenceDate = $this->parseDate($data['occurrenceDate'] ?? null, 'occurrenceDate');

        [$overrideDate, $overrideInstrumentist, $overrideStartTime, $overrideEndTime] = $this->parseOverrides($data);

        $exception = $this->service->createException(
            $post, $type, $occurrenceDate, $currentUser,
            $overrideDate, $overrideInstrumentist, $overrideStartTime, $overrideEndTime,
        );

        return $this->json($this->serialize($exception), 201);
    }

    #[Route('/api/planning/exceptions/{id}', name: 'api_planning_exception_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $exception = $this->findExceptionOrFail($id);
        $data      = json_decode($request->getContent() ?: '{}', true) ?? [];

        $input = [];
        if (isset($data['type'])) {
            $type = OccurrenceExceptionType::tryFrom((string) $data['type']);
            if ($type === null) {
                throw new BadRequestHttpException('type invalide.');
            }
            $input['type'] = $type;
        }
        if (array_key_exists('overrideDate', $data)) {
            $input['overrideDate'] = $data['overrideDate'] !== null ? $this->parseDate($data['overrideDate'], 'overrideDate') : null;
        }
        if (array_key_exists('overrideInstrumentistId', $data)) {
            $input['overrideInstrumentist'] = $data['overrideInstrumentistId'] !== null
                ? $this->findUserOrFail((int) $data['overrideInstrumentistId'])
                : null;
        }
        if (array_key_exists('overrideStartTime', $data)) {
            $input['overrideStartTime'] = $data['overrideStartTime'] !== null ? $this->parseTime($data['overrideStartTime'], 'overrideStartTime') : null;
        }
        if (array_key_exists('overrideEndTime', $data)) {
            $input['overrideEndTime'] = $data['overrideEndTime'] !== null ? $this->parseTime($data['overrideEndTime'], 'overrideEndTime') : null;
        }

        $this->service->updateException($exception, $input);

        return $this->json($this->serialize($exception));
    }

    #[Route('/api/planning/exceptions/{id}', name: 'api_planning_exception_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $this->service->deleteException($this->findExceptionOrFail($id));

        return new JsonResponse(null, 204);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @return array{0: ?\DateTimeImmutable, 1: ?User, 2: ?\DateTimeImmutable, 3: ?\DateTimeImmutable} */
    private function parseOverrides(array $data): array
    {
        $overrideDate = isset($data['overrideDate']) ? $this->parseDate($data['overrideDate'], 'overrideDate') : null;
        $overrideInstrumentist = isset($data['overrideInstrumentistId']) ? $this->findUserOrFail((int) $data['overrideInstrumentistId']) : null;
        $overrideStartTime = isset($data['overrideStartTime']) ? $this->parseTime($data['overrideStartTime'], 'overrideStartTime') : null;
        $overrideEndTime   = isset($data['overrideEndTime']) ? $this->parseTime($data['overrideEndTime'], 'overrideEndTime') : null;

        return [$overrideDate, $overrideInstrumentist, $overrideStartTime, $overrideEndTime];
    }

    private function findPostOrFail(int $postId): SurgeonSchedulePost
    {
        $post = $this->em->find(SurgeonSchedulePost::class, $postId);
        if ($post === null) {
            throw $this->createNotFoundException('SurgeonSchedulePost introuvable.');
        }
        return $post;
    }

    private function findExceptionOrFail(int $id): PlanningOccurrenceException
    {
        $exception = $this->em->find(PlanningOccurrenceException::class, $id);
        if ($exception === null) {
            throw $this->createNotFoundException('PlanningOccurrenceException introuvable.');
        }
        return $exception;
    }

    private function findUserOrFail(int $id): User
    {
        $user = $this->em->find(User::class, $id);
        if ($user === null) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }
        return $user;
    }

    private function parseDate(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new BadRequestHttpException(sprintf('%s est requis.', $field));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s doit être une date valide.', $field));
        }
    }

    private function parseTime(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new BadRequestHttpException(sprintf('%s doit être une heure valide.', $field));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s doit être une heure valide.', $field));
        }
    }

    private function serialize(PlanningOccurrenceException $exception): array
    {
        $overrideInstrumentist = $exception->getOverrideInstrumentist();

        return [
            'id'                     => $exception->getId(),
            'postId'                 => $exception->getPost()->getId(),
            'occurrenceDate'         => $exception->getOccurrenceDate()->format('Y-m-d'),
            'type'                   => $exception->getType()->value,
            'overrideDate'           => $exception->getOverrideDate()?->format('Y-m-d'),
            'overrideInstrumentist'  => $overrideInstrumentist !== null ? ['id' => $overrideInstrumentist->getId(), 'email' => $overrideInstrumentist->getEmail()] : null,
            'overrideStartTime'      => $exception->getOverrideStartTime()?->format('H:i'),
            'overrideEndTime'        => $exception->getOverrideEndTime()?->format('H:i'),
            'createdAt'              => $exception->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
