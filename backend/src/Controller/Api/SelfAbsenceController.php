<?php

namespace App\Controller\Api;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\AbsenceVoter;
use App\Service\AbsenceImpactReconciliationService;
use App\Service\AbsenceImpactService;
use App\Service\AbsenceMissionReactionService;
use App\Service\SurgeonAbsenceOccurrenceImpactService;
use App\Message\AbsenceSelfDeclaredMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Self-service absence CRUD for SURGEON/INSTRUMENTIST (Lot 3, D-097) — reuses the existing
 * `Absence` entity and `AbsenceImpactService`/`AbsenceMissionReactionService` as-is, never a
 * second entity, never a duplicated engine. Entirely separate from AbsenceController (manager
 * `PLANNING_MANAGE`-only), which is untouched by this lot.
 *
 * Absolute security invariant: the client NEVER chooses the owner. Every write here uses
 * `$currentUser` exclusively — a client-supplied `userId` in the request body (if present) is
 * never read, not even to reject it explicitly; there is simply no code path that looks at it.
 * Ownership on read/update/delete is enforced by `AbsenceVoter::SELF_MANAGE`
 * (`absence.user === current user`), never by trusting a route/body-supplied id alone.
 */
#[Route('/api/absences/mine')]
class SelfAbsenceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceImpactService $absenceImpactService,
        private readonly AbsenceMissionReactionService $absenceMissionReactionService,
        private readonly SurgeonAbsenceOccurrenceImpactService $surgeonAbsenceOccurrenceImpactService,
        private readonly AbsenceImpactReconciliationService $reconciliationService,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('', name: 'api_self_absences_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(AbsenceVoter::SELF_ACCESS);

        $absences = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Absence::class, 'a')
            ->where('a.user = :user')
            ->setParameter('user', $currentUser)
            ->orderBy('a.dateStart', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json(array_map(fn (Absence $a) => $this->serialize($a), $absences));
    }

    #[Route('/impact-preview', name: 'api_self_absences_impact_preview', methods: ['GET'])]
    public function impactPreview(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(AbsenceVoter::SELF_ACCESS);

        [$start, $end, $error] = $this->parseRange(
            $request->query->get('dateStart'),
            $request->query->get('dateEnd'),
        );
        if ($error !== null) {
            return $this->json(['error' => ['message' => $error]], 400);
        }

        $missions = $this->absenceImpactService->previewOverlappingMissions($currentUser, $start, $end);

        return $this->json(array_map(fn (Mission $m) => $this->serializeImpactedMission($m, $currentUser), $missions));
    }

    #[Route('', name: 'api_self_absences_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(AbsenceVoter::SELF_ACCESS);

        $data = json_decode($request->getContent(), true) ?? [];

        if (!($data['dateStart'] ?? null) || !($data['dateEnd'] ?? null)) {
            return $this->json(['error' => ['message' => 'dateStart et dateEnd sont requis.']], 400);
        }

        [$start, $end, $error] = $this->parseRange($data['dateStart'], $data['dateEnd']);
        if ($error !== null) {
            return $this->json(['error' => ['message' => $error]], 400);
        }

        // Snapshot BEFORE any mutation — AbsenceMissionReactionService may auto-release/cancel
        // some of these, which would otherwise fall out of AbsenceImpactService's own overlap
        // query by the time it runs. This count is what the "N missions concernées" confirmation
        // to the user reports (§10) — never recomputed after the fact.
        $impactedMissions = $this->absenceImpactService->previewOverlappingMissions($currentUser, $start, $end);

        $absence = new Absence();
        // Never $data['userId'] — self-service always means "for myself", never negotiable.
        $absence->setUser($currentUser);
        $absence->setDateStart($start);
        $absence->setDateEnd($end);
        $absence->setReason(isset($data['reason']) && $data['reason'] !== '' ? $data['reason'] : null);
        $absence->setCreatedBy($currentUser);

        $this->em->persist($absence);
        $this->em->flush();

        $this->reactAndSync($absence, $currentUser);

        return $this->json(array_merge(
            $this->serialize($absence),
            ['missionsImpactedCount' => count($impactedMissions)],
        ), 201);
    }

    #[Route('/{id}', name: 'api_self_absences_update', methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $absence = $this->em->find(Absence::class, $id);
        if (!$absence) {
            return $this->json(['error' => ['message' => 'Absence introuvable.']], 404);
        }
        $this->denyAccessUnlessGranted(AbsenceVoter::SELF_MANAGE, $absence);

        $data = json_decode($request->getContent(), true) ?? [];

        // Captured BEFORE any mutation — see AbsenceController::update() for the same
        // reasoning (D-104, Lot 4).
        $previousDateStart = $absence->getDateStart();
        $previousDateEnd   = $absence->getDateEnd();

        $dateStart = $absence->getDateStart();
        $dateEnd   = $absence->getDateEnd();

        if (array_key_exists('dateStart', $data) || array_key_exists('dateEnd', $data)) {
            [$parsedStart, $parsedEnd, $error] = $this->parseRange(
                array_key_exists('dateStart', $data) && $data['dateStart'] !== null ? $data['dateStart'] : $dateStart->format('Y-m-d'),
                array_key_exists('dateEnd', $data) && $data['dateEnd'] !== null ? $data['dateEnd'] : $dateEnd->format('Y-m-d'),
            );
            if ($error !== null) {
                return $this->json(['error' => ['message' => $error]], 400);
            }
            $dateStart = $parsedStart;
            $dateEnd   = $parsedEnd;
        }

        $absence->setDateStart($dateStart);
        $absence->setDateEnd($dateEnd);

        if (array_key_exists('reason', $data)) {
            $absence->setReason($data['reason'] !== '' ? $data['reason'] : null);
        }

        $impactedMissions = $this->absenceImpactService->previewOverlappingMissions($currentUser, $dateStart, $dateEnd);

        $this->em->flush();

        $this->reactAndSync($absence, $currentUser, $previousDateStart, $previousDateEnd);

        return $this->json(array_merge(
            $this->serialize($absence),
            ['missionsImpactedCount' => count($impactedMissions)],
        ));
    }

    #[Route('/{id}', name: 'api_self_absences_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $currentUser): JsonResponse
    {
        $absence = $this->em->find(Absence::class, $id);
        if (!$absence) {
            return $this->json(['error' => ['message' => 'Absence introuvable.']], 404);
        }
        $this->denyAccessUnlessGranted(AbsenceVoter::SELF_MANAGE, $absence);

        // Same ordering/two-phase split as AbsenceController::delete() (D-104, Lot 4) —
        // see AbsenceImpactReconciliationService's class docblock.
        $absenceId = $absence->getId();
        $this->absenceImpactService->onAbsenceDeleted($absence);
        $restoredOccurrences = $this->reconciliationService->beginDeletion($absence, $currentUser);
        $this->absenceMissionReactionService->onAbsenceDeleted($absence, $currentUser);

        $this->em->remove($absence);
        $this->em->flush();

        $this->reconciliationService->completeDeletion($absence, $absenceId, $currentUser, $restoredOccurrences);

        return $this->json(null, 204);
    }

    /**
     * Same call order as AbsenceController::create()/update() (reaction service before impact
     * service — see AbsenceMissionReactionService's class docblock for why the order matters).
     * Lot 3 (D-103) — surgeonAbsenceOccurrenceImpactService runs after both (independent of
     * either; it only ever acts on Post occurrences with no Mission at all).
     *
     * $previousDateStart/$previousDateEnd — passed only from update() (D-104, Lot 4): when
     * present, reconciliation runs last (restoring whatever fell out of a shortened range).
     * null from create() — a brand-new absence has nothing to reconcile.
     *
     * If NEITHER the impact sync raised a new alert, NOR Lot 3 neutralized a future
     * occurrence, NOR Lot 4 restored anything, the manager would otherwise learn nothing
     * about this self-declared absence at all — dispatches ABSENCE_SELF_DECLARED for that
     * case only, never when any of the three already covers it (no duplication, §14).
     */
    private function reactAndSync(
        Absence $absence,
        User $currentUser,
        ?\DateTimeImmutable $previousDateStart = null,
        ?\DateTimeImmutable $previousDateEnd = null,
    ): void {
        $this->absenceMissionReactionService->onAbsenceCreated($absence, $currentUser);
        $result = $this->absenceImpactService->onAbsenceCreated($absence);
        $occurrenceResult = $this->surgeonAbsenceOccurrenceImpactService->onSurgeonAbsenceCreated($absence, $currentUser);

        $reconciliationResult = ['restoredOccurrences' => 0, 'restoredMissions' => 0];
        if ($previousDateStart !== null && $previousDateEnd !== null) {
            $reconciliationResult = $this->reconciliationService->reconcileForUpdate($absence, $previousDateStart, $previousDateEnd, $currentUser);
        }

        if (!empty($result['created']) || !empty($occurrenceResult['created'])
            || !empty($reconciliationResult['restoredOccurrences']) || !empty($reconciliationResult['restoredMissions'])
        ) {
            return;
        }

        $managers = $this->userRepository->findManagersAndAdmins(true);
        if (empty($managers)) {
            return;
        }

        $this->bus->dispatch(new AbsenceSelfDeclaredMessage(
            absenceId: $absence->getId(),
            absentUserId: $currentUser->getId(),
            absentUserName: self::displayName($currentUser),
            absentUserRole: self::personRole($currentUser) ?? 'INSTRUMENTIST',
            dateStart: $absence->getDateStart()->format('Y-m-d'),
            dateEnd: $absence->getDateEnd()->format('Y-m-d'),
            reason: $absence->getReason(),
            recipientUserIds: array_map(static fn (User $m) => $m->getId(), $managers),
        ));
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: ?string} */
    private function parseRange(?string $rawStart, ?string $rawEnd): array
    {
        try {
            $start = new \DateTimeImmutable((string) $rawStart);
            $end   = new \DateTimeImmutable((string) $rawEnd);
        } catch (\Exception) {
            return [new \DateTimeImmutable(), new \DateTimeImmutable(), 'Format de date invalide.'];
        }

        if ($end < $start) {
            return [$start, $end, 'dateEnd doit être >= dateStart.'];
        }

        return [$start, $end, null];
    }

    private function serialize(Absence $a): array
    {
        return [
            'id'        => $a->getId(),
            'dateStart' => $a->getDateStart()->format('Y-m-d'),
            'dateEnd'   => $a->getDateEnd()->format('Y-m-d'),
            'reason'    => $a->getReason(),
            'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'editable'  => $a->getDateEnd() >= new \DateTimeImmutable('today'),
        ];
    }

    /**
     * "Counterpart" is resolved server-side relative to the current viewer — the absent user
     * is necessarily either the mission's surgeon or its instrumentist (that's why it
     * overlaps), so the frontend never needs to branch on role to know which field to read.
     * `null` only happens when the viewer is the surgeon and the mission has no instrumentist
     * yet ("À couvrir" — see AbsenceImpactPreview.tsx).
     */
    private function serializeImpactedMission(Mission $m, User $viewer): array
    {
        $isViewerSurgeon = $m->getSurgeon()?->getId() === $viewer->getId();
        $counterpart     = $isViewerSurgeon ? $m->getInstrumentist() : $m->getSurgeon();

        return [
            'missionId' => $m->getId(),
            'startAt'   => $m->getStartAt()->format(\DateTimeInterface::ATOM),
            'endAt'     => $m->getEndAt()->format(\DateTimeInterface::ATOM),
            'siteName'  => $m->getSite()?->getName(),
            'counterpart' => $counterpart ? ['id' => $counterpart->getId(), 'name' => self::displayName($counterpart)] : null,
        ];
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }

    private static function personRole(User $user): ?string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return 'INSTRUMENTIST';
        }
        if (in_array('ROLE_SURGEON', $roles, true)) {
            return 'SURGEON';
        }
        return null;
    }
}
