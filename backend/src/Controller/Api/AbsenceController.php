<?php

namespace App\Controller\Api;

use App\Entity\Absence;
use App\Entity\User;
use App\Security\Voter\PlanningVoter;
use App\Service\AbsenceImpactService;
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

        $this->absenceImpactService->onAbsenceCreated($absence);

        return $this->json($this->serialize($absence), 201);
    }

    #[Route('/{id}', name: 'api_absences_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
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

        $this->absenceImpactService->onAbsenceUpdated($absence);

        return $this->json($this->serialize($absence));
    }

    #[Route('/{id}', name: 'api_absences_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
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
                'id'    => $user->getId(),
                'name'  => $name ?: $user->getEmail(),
                'email' => $user->getEmail(),
            ] : null,
            'dateStart' => $a->getDateStart()->format('Y-m-d'),
            'dateEnd'   => $a->getDateEnd()->format('Y-m-d'),
            'reason'    => $a->getReason(),
            'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
