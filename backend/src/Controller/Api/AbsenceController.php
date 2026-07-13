<?php

namespace App\Controller\Api;

use App\Entity\Absence;
use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\AbsenceImpactService;
use App\Service\AbsenceMissionReactionService;
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
        $this->absenceMissionReactionService->onAbsenceCreated($absence, $currentUser);
        $this->absenceImpactService->onAbsenceCreated($absence);

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

        // See create() — same ordering reasoning.
        $this->absenceMissionReactionService->onAbsenceUpdated($absence, $currentUser);
        $this->absenceImpactService->onAbsenceUpdated($absence);

        return $this->json($this->serialize($absence));
    }

    #[Route('/{id}', name: 'api_absences_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $currentUser): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $absence = $this->em->find(Absence::class, $id);
        if (!$absence) {
            return $this->json(['error' => ['message' => 'Absence introuvable.']], 404);
        }

        // Resolve linked alerts BEFORE removing the row — PlanningAlert.absence is
        // ON DELETE SET NULL so history survives, but resolution must happen while
        // the association still exists for findActiveAlertsForAbsence() to find them.
        $this->absenceImpactService->onAbsenceDeleted($absence);
        $this->absenceMissionReactionService->onAbsenceDeleted($absence, $currentUser);

        $this->em->remove($absence);
        $this->em->flush();

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
