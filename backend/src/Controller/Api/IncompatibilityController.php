<?php

namespace App\Controller\Api;

use App\Entity\PlanningAlert;
use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\User;
use App\Enum\IncompatibilityOrigin;
use App\Exception\IncompatibilityException;
use App\Security\Voter\SurgeonInstrumentistIncompatibilityVoter;
use App\Service\SurgeonInstrumentistIncompatibilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-152 — surgeon–instrumentist incompatibilities.
 *
 * Surgeon (own blocks): GET/POST /api/me/blocked-instrumentists, GET /api/me/blockable-instrumentists.
 * Manager: GET /api/surgeons/{id}/incompatibilities, GET /api/instrumentists/{id}/incompatibilities,
 *          POST /api/incompatibilities.
 * Both: POST /api/incompatibilities/{id}/lift (SurgeonInstrumentistIncompatibilityVoter::LIFT).
 */
final class IncompatibilityController extends AbstractController
{
    public function __construct(
        private readonly SurgeonInstrumentistIncompatibilityService $incompatibilities,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/me/blocked-instrumentists', name: 'api_me_blocked_instrumentists', methods: ['GET'])]
    public function myBlocks(#[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::SELF_MANAGE);

        $active = array_filter($this->incompatibilities->historyOf($user, true), static fn ($i) => $i->isActive());

        return $this->json(['items' => array_map(fn ($i) => $this->serialize($i, $user, false), array_values($active))]);
    }

    #[Route('/api/me/blockable-instrumentists', name: 'api_me_blockable_instrumentists', methods: ['GET'])]
    public function blockable(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::SELF_MANAGE);

        return $this->json(['items' => array_map(
            static fn (User $u) => ['id' => $u->getId(), 'name' => SurgeonInstrumentistIncompatibilityService::name($u)],
            $this->incompatibilities->blockableInstrumentists($user, (string) $request->query->get('q', '')),
        )]);
    }

    #[Route('/api/me/blocked-instrumentists', name: 'api_me_block_instrumentist', methods: ['POST'])]
    public function block(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::SELF_MANAGE);

        $instrumentist = $this->userFromBody($request, 'instrumentistId');
        $incompatibility = $this->incompatibilities->create($user, $instrumentist, IncompatibilityOrigin::SURGEON, $user);

        return $this->json($this->serialize($incompatibility, $user, false), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/surgeons/{id}/incompatibilities', name: 'api_surgeon_incompatibilities', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ofSurgeon(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::MANAGE);

        return $this->json(['items' => array_map(
            fn ($i) => $this->serialize($i, $user, true),
            $this->incompatibilities->historyOf($this->userOr404($id), true),
        )]);
    }

    #[Route('/api/instrumentists/{id}/incompatibilities', name: 'api_instrumentist_incompatibilities', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ofInstrumentist(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::MANAGE);

        return $this->json(['items' => array_map(
            fn ($i) => $this->serialize($i, $user, true),
            $this->incompatibilities->historyOf($this->userOr404($id), false),
        )]);
    }

    #[Route('/api/incompatibilities', name: 'api_incompatibilities_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::MANAGE);

        $incompatibility = $this->incompatibilities->create(
            $this->userFromBody($request, 'surgeonId'),
            $this->userFromBody($request, 'instrumentistId'),
            IncompatibilityOrigin::MANAGER,
            $user,
        );

        return $this->json($this->serialize($incompatibility, $user, true), JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/incompatibilities/{id}/lift', name: 'api_incompatibilities_lift', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function lift(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $incompatibility = $this->em->find(SurgeonInstrumentistIncompatibility::class, $id)
            ?? throw new NotFoundHttpException('Incompatibilité introuvable.');
        if (!$incompatibility->isActive()) {
            throw new IncompatibilityException(IncompatibilityException::ALREADY_LIFTED);
        }
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::LIFT, $incompatibility);

        $this->incompatibilities->lift($incompatibility, $user);

        $isManager = $this->isGranted(SurgeonInstrumentistIncompatibilityVoter::MANAGE);

        return $this->json($this->serialize($incompatibility, $user, $isManager));
    }

    /** @return array<string, mixed> */
    private function serialize(SurgeonInstrumentistIncompatibility $i, User $viewer, bool $managerView): array
    {
        $data = [
            'id'            => $i->getId(),
            'surgeon'       => ['id' => $i->getSurgeon()->getId(), 'name' => SurgeonInstrumentistIncompatibilityService::name($i->getSurgeon())],
            'instrumentist' => ['id' => $i->getInstrumentist()->getId(), 'name' => SurgeonInstrumentistIncompatibilityService::name($i->getInstrumentist())],
            'origin'        => $i->getOrigin()->value,
            'active'        => $i->isActive(),
            'createdAt'     => $i->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'createdByName' => $i->getCreatedBy() !== null ? SurgeonInstrumentistIncompatibilityService::name($i->getCreatedBy()) : null,
            'liftedAt'      => $i->getLiftedAt()?->format(\DateTimeInterface::ATOM),
            'liftedByName'  => $i->getLiftedBy() !== null ? SurgeonInstrumentistIncompatibilityService::name($i->getLiftedBy()) : null,
            'canLift'       => $this->isGranted(SurgeonInstrumentistIncompatibilityVoter::LIFT, $i),
        ];

        // Managers see the future missions still affected to the pair, to be resolved.
        if ($managerView) {
            $data['conflicts'] = $i->isActive() ? array_map(static fn (PlanningAlert $a) => [
                'alertId'   => $a->getId(),
                'missionId' => $a->getMission()->getId(),
                'startAt'   => $a->getMission()->getStartAt()?->format(\DateTimeInterface::ATOM),
                'siteName'  => $a->getMission()->getSite()?->getName(),
            ], $this->incompatibilities->openConflicts($i)) : [];
        }

        return $data;
    }

    private function userFromBody(Request $request, string $key): User
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $id   = is_array($body) && is_numeric($body[$key] ?? null) ? (int) $body[$key] : null;
        if ($id === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s is required', $key));
        }

        return $this->userOr404($id);
    }

    private function userOr404(int $id): User
    {
        return $this->em->find(User::class, $id) ?? throw new NotFoundHttpException('Utilisateur introuvable.');
    }
}
