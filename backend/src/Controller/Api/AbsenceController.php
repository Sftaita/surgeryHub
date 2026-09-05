<?php

namespace App\Controller\Api;

use App\Entity\Absence;
use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\AbsenceImpactReconciliationService;
use App\Service\AbsenceImpactService;
use App\Service\AbsenceImpactSummaryService;
use App\Service\AbsenceMissionReactionService;
use App\Service\SurgeonAbsenceOccurrenceImpactService;
use App\Service\InstrumentistAbsenceOccurrenceImpactService;
use App\Service\RoomReleaseCommunicationService;
use App\Service\BlockManagementCommunicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/absences')]
class AbsenceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceImpactService $absenceImpactService,
        private readonly AbsenceMissionReactionService $absenceMissionReactionService,
        private readonly SurgeonAbsenceOccurrenceImpactService $surgeonAbsenceOccurrenceImpactService,
        private readonly InstrumentistAbsenceOccurrenceImpactService $instrumentistAbsenceOccurrenceImpactService,
        private readonly AbsenceImpactReconciliationService $reconciliationService,
        private readonly AbsenceImpactSummaryService $absenceImpactSummaryService,
        private readonly RoomReleaseCommunicationService $roomReleaseCommunicationService,
        private readonly BlockManagementCommunicationService $blockManagementCommunicationService,
    ) {}

    #[Route('', name: 'api_absences_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $qb = $this->em->createQueryBuilder()
            ->select('a', 'u')
            ->from(Absence::class, 'a')
            ->join('a.user', 'u')
            ->orderBy('a.dateStart', 'ASC');

        if ($userId = $request->query->getInt('userId')) {
            $qb->andWhere('a.user = :userId')->setParameter('userId', $userId);
        }

        if ($from = $request->query->get('from')) {
            $qb->andWhere('a.dateEnd >= :from')->setParameter('from', $from);
        }

        if ($to = $request->query->get('to')) {
            $qb->andWhere('a.dateStart <= :to')->setParameter('to', $to);
        }

        $absences = $qb->getQuery()->getResult();

        return $this->json(array_map(fn($a) => $this->serialize($a), $absences));
    }

    #[Route('', name: 'api_absences_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data      = json_decode($request->getContent(), true) ?? [];
        $userId    = $data['userId'] ?? null;
        $dateStart = $data['dateStart'] ?? null;
        $dateEnd   = $data['dateEnd'] ?? null;

        if (!$userId || !$dateStart || !$dateEnd) {
            return $this->json(['error' => ['message' => 'userId, dateStart et dateEnd sont requis.']], 400);
        }

        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            return $this->json(['error' => ['message' => 'Utilisateur introuvable.']], 404);
        }

        try {
            $start = new \DateTimeImmutable($dateStart);
            $end   = new \DateTimeImmutable($dateEnd);
        } catch (\Exception) {
            return $this->json(['error' => ['message' => 'Format de date invalide.']], 400);
        }

        if ($end < $start) {
            return $this->json(['error' => ['message' => 'dateEnd doit être >= dateStart.']], 400);
        }

        $absence = new Absence();
        $absence->setUser($user);
        $absence->setDateStart($start);
        $absence->setDateEnd($end);
        $absence->setReason($data['reason'] ?? null);
        $absence->setCreatedBy($currentUser);

        $this->em->persist($absence);
        $this->em->flush();

        // Mission auto-mutation MUST run before alert detection — AbsenceImpactService's own
        // overlap query naturally excludes whatever this just released/cancelled (instrumentist
        // now null, or status now CANCELLED), so it never raises a stale alert asking the
        // manager to do what was already done automatically. See AbsenceMissionReactionService's
        // class docblock for the full reasoning.
        $missionSummaries = $this->absenceMissionReactionService->onAbsenceCreated($absence, $currentUser);
        $this->absenceImpactService->onAbsenceCreated($absence);
        // Lot 3 (D-103) — future Post occurrences with no Mission yet, independent of the
        // two calls above (they only ever act on already-materialized Mission rows).
        $occurrenceResult = $this->surgeonAbsenceOccurrenceImpactService->onSurgeonAbsenceCreated($absence, $currentUser);
        // Complementary lot — symmetric case, an INSTRUMENTIST absence covering future Post
        // occurrences with no Mission yet. Independent of everything above (they only ever
        // act on SURGEON absences or already-materialized Mission rows).
        $this->instrumentistAbsenceOccurrenceImpactService->onInstrumentistAbsenceCreated($absence, $currentUser);

        // Lot 5 (D-105) — ONE consolidated manager recap for this create, combining both
        // results above; never dispatched if neither produced a real impact.
        $this->absenceImpactSummaryService->dispatch(
            absenceId: $absence->getId(),
            absentUserId: $user->getId(),
            absentUserName: self::displayName($user),
            absentUserRole: self::personRole($user) ?? 'INSTRUMENTIST',
            dateStart: $absence->getDateStart()->format('Y-m-d'),
            dateEnd: $absence->getDateEnd()->format('Y-m-d'),
            actor: $currentUser,
            action: 'CREATED',
            missionReactionSummaries: $missionSummaries,
            occurrenceNeutralized: $occurrenceResult['occurrences'],
        );

        // Communication des absences chirurgiens, Lot A (D-114) — « Libération de salle »
        // aux chirurgiens collègues du même site. Indépendant des 6 services ci-dessus
        // (aucun impact sur les Missions/PlanningAlert/PlanningOccurrenceException), jamais
        // appelé depuis delete() (une libération déjà communiquée n'est jamais rétractée).
        $this->roomReleaseCommunicationService->onAbsenceCreated($absence, $currentUser);

        // Lot B (D-114) — « gestion du bloc », 8ᵉ collaborateur indépendant. Aucun ordre
        // requis vis-à-vis de RoomReleaseCommunicationService (domaines disjoints : deux
        // destinataires, deux journaux de communications distincts).
        $this->blockManagementCommunicationService->onAbsenceCreated($absence, $currentUser);

        return $this->json($this->serialize($absence), 201);
    }

    #[Route('/{id}', name: 'api_absences_update', methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $absence = $this->em->find(Absence::class, $id);
        if (!$absence) {
            return $this->json(['error' => ['message' => 'Absence introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        // Captured BEFORE any mutation — D-104 (Lot 4) needs the range as it was before this
        // update, to reconcile whatever falls OUT of the new range (a shortened absence). The
        // Absence row itself only ever holds the current range once flushed below.
        $previousDateStart = $absence->getDateStart();
        $previousDateEnd   = $absence->getDateEnd();

        $dateStart = $absence->getDateStart();
        $dateEnd   = $absence->getDateEnd();

        if (array_key_exists('dateStart', $data) || array_key_exists('dateEnd', $data)) {
            try {
                if (array_key_exists('dateStart', $data) && $data['dateStart'] !== null) {
                    $dateStart = new \DateTimeImmutable($data['dateStart']);
                }
                if (array_key_exists('dateEnd', $data) && $data['dateEnd'] !== null) {
                    $dateEnd = new \DateTimeImmutable($data['dateEnd']);
                }
            } catch (\Exception) {
                return $this->json(['error' => ['message' => 'Format de date invalide.']], 400);
            }

            if ($dateEnd < $dateStart) {
                return $this->json(['error' => ['message' => 'dateEnd doit être >= dateStart.']], 400);
            }
        }

        $absence->setDateStart($dateStart);
        $absence->setDateEnd($dateEnd);

        if (array_key_exists('reason', $data)) {
            $absence->setReason($data['reason']);
        }

        $this->em->flush();

        // See create() — same ordering reasoning. Reconciliation (restoring what fell out of
        // a shortened range) runs last, after the "create new impacts" services above have
        // already reacted to the new (already-flushed) range.
        $missionSummaries = $this->absenceMissionReactionService->onAbsenceUpdated($absence, $currentUser);
        $this->absenceImpactService->onAbsenceUpdated($absence);
        $occurrenceResult = $this->surgeonAbsenceOccurrenceImpactService->onSurgeonAbsenceUpdated($absence, $currentUser);
        $this->instrumentistAbsenceOccurrenceImpactService->onInstrumentistAbsenceUpdated($absence, $currentUser, $previousDateStart, $previousDateEnd);
        $reconciliation = $this->reconciliationService->reconcileForUpdate($absence, $previousDateStart, $previousDateEnd, $currentUser);

        // Lot 5 (D-105) — ONE consolidated manager recap covering both new impacts (if the
        // range grew) and restorations (if it shrank) from this single update.
        $user = $absence->getUser();
        if ($user !== null) {
            $this->absenceImpactSummaryService->dispatch(
                absenceId: $absence->getId(),
                absentUserId: $user->getId(),
                absentUserName: self::displayName($user),
                absentUserRole: self::personRole($user) ?? 'INSTRUMENTIST',
                dateStart: $absence->getDateStart()->format('Y-m-d'),
                dateEnd: $absence->getDateEnd()->format('Y-m-d'),
                actor: $currentUser,
                action: 'UPDATED',
                missionReactionSummaries: $missionSummaries,
                occurrenceNeutralized: $occurrenceResult['occurrences'],
                reconciliation: $reconciliation,
            );
        }

        // Lot A (D-114) — complément « Libération de salle » uniquement si l'allongement du
        // congé révèle de nouvelles occurrences BLOCK jamais annoncées (voir
        // RoomReleaseCommunicationService::onAbsenceUpdated() pour le calcul du delta).
        $this->roomReleaseCommunicationService->onAbsenceUpdated($absence, $currentUser);

        // Lot B (D-114) — reschedule/annule/crée les communications « gestion du bloc »
        // selon les sites BLOCK désormais concernés (§14 : mutation en place tant que
        // jamais envoyée, sinon BLOCK_MANAGEMENT_MODIFICATION si les dates ont changé).
        $this->blockManagementCommunicationService->onAbsenceUpdated($absence, $currentUser, $previousDateStart, $previousDateEnd);

        return $this->json($this->serialize($absence));
    }

    #[Route('/{id}/deletion-info', name: 'api_absences_deletion_info', methods: ['GET'])]
    public function deletionInfo(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $absence = $this->em->find(Absence::class, $id);
        if (!$absence) {
            return $this->json(['error' => ['message' => 'Absence introuvable.']], 404);
        }

        return $this->json($this->blockManagementCommunicationService->deletionInfo($absence));
    }

    #[Route('/{id}', name: 'api_absences_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $absence = $this->em->find(Absence::class, $id);
        if (!$absence) {
            return $this->json(['error' => ['message' => 'Absence introuvable.']], 404);
        }

        // Lot B (D-114) — décision explicite transmise par le frontend (jamais déduite
        // localement, §19-20) : n'a d'effet que sur les sites déjà SENT, voir
        // BlockManagementCommunicationService::onAbsenceDeleted(). Appelé AVANT le
        // remove() ci-dessous, comme les autres collaborateurs de suppression — a besoin
        // de l'absence encore vivante pour construire le contenu de l'email d'annulation.
        $notifyBlockManagementCancellation = $request->query->getBoolean('notifyBlockManagementCancellation', false);
        $this->blockManagementCommunicationService->onAbsenceDeleted($absence, $currentUser, $notifyBlockManagementCancellation);

        // Resolve linked alerts BEFORE removing the row — PlanningAlert.absence is
        // ON DELETE SET NULL so history survives, but resolution must happen while
        // the association still exists for findActiveAlertsForAbsence() to find them.
        // Reconciliation (D-104, Lot 4) is deliberately split around the removal itself —
        // see AbsenceImpactReconciliationService's class docblock: occurrence restoration
        // needs the FK still intact (beginDeletion(), before removal), mission restoration
        // needs the Absence row physically gone for eligibility re-validation to be
        // accurate (completeDeletion(), after removal+flush).
        $absenceId = $absence->getId();
        $user      = $absence->getUser();
        $role      = $user !== null ? self::personRole($user) : null;
        $dateStart = $absence->getDateStart()->format('Y-m-d');
        $dateEnd   = $absence->getDateEnd()->format('Y-m-d');

        $this->absenceImpactService->onAbsenceDeleted($absence);
        $restoredOccurrences = $this->reconciliationService->beginDeletion($absence, $currentUser);
        $this->absenceMissionReactionService->onAbsenceDeleted($absence, $currentUser);

        $this->em->remove($absence);
        $this->em->flush();

        $reconciliation = $this->reconciliationService->completeDeletion($absence, $absenceId, $currentUser, $restoredOccurrences);

        // Lot 5 (D-105) — ONE consolidated manager recap, only ever covering restorations
        // (deletion never produces a new impact) — never dispatched if nothing was restored.
        if ($user !== null && $role !== null) {
            $this->absenceImpactSummaryService->dispatch(
                absenceId: $absenceId,
                absentUserId: $user->getId(),
                absentUserName: self::displayName($user),
                absentUserRole: $role,
                dateStart: $dateStart,
                dateEnd: $dateEnd,
                actor: $currentUser,
                action: 'DELETED',
                reconciliation: $reconciliation,
            );
        }

        return $this->json(null, 204);
    }

    private function serialize(Absence $a): array
    {
        $user = $a->getUser();
        $name = $user ? trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? '')) : null;

        return [
            'id'        => $a->getId(),
            'user'      => $user ? [
                'id'        => $user->getId(),
                'name'      => $name ?: $user->getEmail(),
                'firstname' => $user->getFirstname(),
                'lastname'  => $user->getLastname(),
                'email'     => $user->getEmail(),
                'role'      => self::personRole($user),
            ] : null,
            'dateStart' => $a->getDateStart()->format('Y-m-d'),
            'dateEnd'   => $a->getDateEnd()->format('Y-m-d'),
            'reason'    => $a->getReason(),
            'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Absences only ever concern instrumentists and surgeons in practice — this resolves
     * which one, for display/sort/filter purposes on the manager-facing list. Returns null
     * for any other role rather than guessing (defensive, should not normally happen since
     * only those two roles can be selected when creating an absence).
     */
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
