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
 *          GET /api/incompatibilities/candidates, POST /api/incompatibilities.
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

        // D-152 — those he already blocks stay listed, flagged (shown « Bloqué », not blockable twice).
        $found = $this->incompatibilities->blockableInstrumentists($user, (string) $request->query->get('q', ''));
        $blocks = $this->incompatibilities->activeBlocksAmong($user, true, $found);

        return $this->json(['items' => array_map(
            static fn (User $u) => [
                'id'                => $u->getId(),
                'name'              => SurgeonInstrumentistIncompatibilityService::name($u),
                'blocked'           => isset($blocks[$u->getId()]),
                'incompatibilityId' => isset($blocks[$u->getId()]) ? $blocks[$u->getId()]->getId() : null,
            ],
            $found,
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

    /**
     * D-152 (réserve 1) — paginated server-side search of a manager's incompatibility
     * counterparts: `surgeonId` (→ instrumentists) or `instrumentistId` (→ surgeons), `q`,
     * `page` (≥ 1), `limit` (1–50, default 20). Each item says whether the pair is already
     * blocked (`blocked`, `incompatibilityId`): never created twice, lifted from there.
     */
    #[Route('/api/incompatibilities/candidates', name: 'api_incompatibilities_candidates', methods: ['GET'])]
    public function candidates(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(SurgeonInstrumentistIncompatibilityVoter::MANAGE);

        $surgeonId = $request->query->get('surgeonId');
        $instrumentistId = $request->query->get('instrumentistId');
        if (($surgeonId === null) === ($instrumentistId === null)) {
            throw new UnprocessableEntityHttpException('Exactement un des paramètres surgeonId ou instrumentistId est requis.');
        }
        $personIsSurgeon = $surgeonId !== null;
        $personId = $this->positiveInt($personIsSurgeon ? $surgeonId : $instrumentistId, $personIsSurgeon ? 'surgeonId' : 'instrumentistId');
        $person = $this->userOr404($personId);
        if (!in_array($personIsSurgeon ? 'ROLE_SURGEON' : 'ROLE_INSTRUMENTIST', $person->getRoles(), true)) {
            throw new UnprocessableEntityHttpException($personIsSurgeon ? "Cette personne n'est pas chirurgien." : "Cette personne n'est pas instrumentiste.");
        }
        $page = $this->positiveInt($request->query->get('page', '1'), 'page');
        $limit = $this->positiveInt($request->query->get('limit', '20'), 'limit');
        if ($limit > 50) {
            throw new UnprocessableEntityHttpException('limit ne peut pas dépasser 50.');
        }

        $result = $this->incompatibilities->managerCandidates($person, $personIsSurgeon, (string) $request->query->get('q', ''), $page, $limit);
        $blocks = $this->incompatibilities->activeBlocksAmong($person, $personIsSurgeon, $result['items']);

        return $this->json([
            'items'   => array_map(static fn (User $u) => [
                'id'                => $u->getId(),
                'name'              => SurgeonInstrumentistIncompatibilityService::name($u),
                'email'             => $u->getEmail(),
                'blocked'           => isset($blocks[$u->getId()]),
                'incompatibilityId' => isset($blocks[$u->getId()]) ? $blocks[$u->getId()]->getId() : null,
            ], $result['items']),
            'page'    => $page,
            'limit'   => $limit,
            'total'   => $result['total'],
            'hasMore' => $page * $limit < $result['total'],
        ]);
    }

    private function positiveInt(mixed $value, string $name): int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value < 1) {
            throw new UnprocessableEntityHttpException(sprintf('%s doit être un entier positif.', $name));
        }

        return (int) $value;
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
