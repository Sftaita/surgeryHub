<?php

namespace App\Controller\Api;

use App\Dto\Request\AddInstrumentistSiteMembershipRequest;
use App\Dto\Request\CreateInstrumentistRequest;
use App\Dto\Request\UpdateInstrumentistRatesRequest;
use App\Dto\Request\Response\InstrumentistCreateResponse;
use App\Dto\Request\Response\InstrumentistListItemResponse;
use App\Dto\Request\Response\InstrumentistProfileResponse;
use App\Dto\Request\Response\InstrumentistRatesResponse;
use App\Dto\Request\Response\InstrumentistSiteMembershipResponse;
use App\Dto\Request\Response\InstrumentistWithRatesListItemResponse;
use App\Dto\Request\Response\SiteSummaryResponse;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\InstrumentistVoter;
use App\Service\InstrumentistServiceManager;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/instrumentists')]
final class InstrumentistController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly InstrumentistServiceManager $instrumentistServiceManager,
        private readonly NotificationService $notificationService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * POST /api/instrumentists
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Création rapide d’un instrumentiste par un manager
     * - Envoi d’email d’invitation non bloquant
     */
    #[Route('', name: 'api_instrumentists_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::CREATE, User::class);

        /** @var CreateInstrumentistRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), CreateInstrumentistRequest::class);

        $instrumentist = $this->instrumentistServiceManager->createInstrumentist($dto);

        $firstname = $instrumentist->getFirstname();
        $lastname = $instrumentist->getLastname();

        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
        $displayName = $name !== '' ? $name : (string) $instrumentist->getEmail();

        $employmentType = $instrumentist->getEmploymentType();
        $employmentTypeValue = $employmentType ? $employmentType->value : null;

        $siteIds = [];
        foreach ($instrumentist->getSiteMemberships() as $membership) {
            $site = $membership->getSite();
            if ($site?->getId() !== null) {
                $siteIds[] = (int) $site->getId();
            }
        }

        $warnings = [];

        try {
            $this->notificationService->sendInstrumentistInvitation($instrumentist);
        } catch (\Throwable) {
            $warnings[] = [
                'code' => 'INVITATION_EMAIL_NOT_SENT',
                'message' => 'Instrumentist created successfully but the invitation email could not be queued.',
            ];
        }

        return $this->json([
            'instrumentist' => new InstrumentistCreateResponse(
                id: (int) $instrumentist->getId(),
                email: (string) $instrumentist->getEmail(),
                firstname: $firstname,
                lastname: $lastname,
                displayName: $displayName,
                active: $instrumentist->isActive(),
                employmentType: $employmentTypeValue,
                defaultCurrency: $instrumentist->getDefaultCurrency(),
                siteIds: array_values(array_unique($siteIds)),
                invitationExpiresAt: $instrumentist->getInvitationExpiresAt()?->format(\DateTimeInterface::ATOM),
            ),
            'warnings' => $warnings,
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * GET /api/instrumentists
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Retourne la liste manager des instrumentistes
     * - Aucun champ financier
     * - Filtres supportés: search, active, siteId
     * - Tri: lastname, firstname, email
     */
    #[Route('', name: 'api_instrumentists_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST, User::class);

        $search = $request->query->get('search');
        $search = is_string($search) ? trim($search) : null;
        if ($search === '') {
            $search = null;
        }

        $active = $this->parseNullableBoolQuery($request, 'active');
        if ($active === null) {
            $active = true;
        }

        $siteId = $request->query->get('siteId');
        if ($siteId !== null && (!is_scalar($siteId) || !ctype_digit((string) $siteId))) {
            throw new BadRequestHttpException('Query parameter "siteId" must be a positive integer.');
        }
        $siteId = $siteId !== null ? (int) $siteId : null;

        $items = $this->users->findInstrumentists($search, $active, $siteId);

        $dtos = array_map(static function (User $u): InstrumentistListItemResponse {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();

            $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
            $displayName = $name !== '' ? $name : (string) $u->getEmail();

            $employmentType = $u->getEmploymentType();
            $employmentTypeValue = $employmentType ? $employmentType->value : null;

            return new InstrumentistListItemResponse(
                id: (int) $u->getId(),
                email: (string) $u->getEmail(),
                firstname: $firstname,
                lastname: $lastname,
                active: $u->isActive(),
                employmentType: $employmentTypeValue,
                defaultCurrency: $u->getDefaultCurrency(),
                displayName: $displayName,
            );
        }, $items);

        return $this->json([
            'items' => $dtos,
            'total' => count($dtos),
        ]);
    }

    /**
     * GET /api/instrumentists/{id}
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Retourne le détail manager d’un instrumentiste
     * - Sans logique d’édition
     * - 404 si introuvable
     */
    #[Route('/{id}', name: 'api_instrumentists_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $firstname = $instrumentist->getFirstname();
        $lastname = $instrumentist->getLastname();

        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
        $displayName = $name !== '' ? $name : (string) $instrumentist->getEmail();

        $employmentType = $instrumentist->getEmploymentType();
        $employmentTypeValue = $employmentType ? $employmentType->value : null;

        $siteMemberships = [];
        foreach ($instrumentist->getSiteMemberships() as $membership) {
            $site = $membership->getSite();

            if ($site === null || $site->getId() === null) {
                continue;
            }

            $siteRole = $membership->getSiteRole();

            $siteMemberships[] = new InstrumentistSiteMembershipResponse(
                id: (int) $membership->getId(),
                site: new SiteSummaryResponse(
                    id: (int) $site->getId(),
                    name: (string) $site->getName(),
                ),
                siteRole: is_string($siteRole) ? $siteRole : $siteRole->value,
            );
        }

        return $this->json(new InstrumentistProfileResponse(
            id: (int) $instrumentist->getId(),
            email: (string) $instrumentist->getEmail(),
            firstname: $firstname,
            lastname: $lastname,
            displayName: $displayName,
            active: $instrumentist->isActive(),
            employmentType: $employmentTypeValue,
            defaultCurrency: $instrumentist->getDefaultCurrency(),
            hourlyRate: $instrumentist->getHourlyRate(),
            consultationFee: $instrumentist->getConsultationFee(),
            siteMemberships: $siteMemberships,
        ));
    }

    /**
     * GET /api/instrumentists/{id}/planning
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Lecture seule orientée calendrier
     * - 404 si instrumentiste introuvable
     * - 422 si from/to manquent, sont invalides ou si la fenêtre est incohérente
     */
    #[Route('/{id}/planning', name: 'api_instrumentists_planning', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function planning(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $from = $this->parseRequiredDateTimeQuery($request, 'from');
        $to = $this->parseRequiredDateTimeQuery($request, 'to');

        if ($from >= $to) {
            throw new UnprocessableEntityHttpException('from must be strictly before to');
        }

        return $this->json(
            $this->instrumentistServiceManager->getPlanning($instrumentist, $from, $to)
        );
    }

    /**
     * PATCH /api/instrumentists/{id}/rates
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Met à jour uniquement hourlyRate et/ou consultationFee
     * - 404 si instrumentiste introuvable
     * - Réponse simple compatible autosave
     */
    #[Route('/{id}/rates', name: 'api_instrumentists_update_rates', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateRates(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::UPDATE_RATES, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        /** @var UpdateInstrumentistRatesRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), UpdateInstrumentistRatesRequest::class);

        $instrumentist = $this->instrumentistServiceManager->updateRates($instrumentist, $dto);

        return $this->json(new InstrumentistRatesResponse(
            id: (int) $instrumentist->getId(),
            hourlyRate: $instrumentist->getHourlyRate(),
            consultationFee: $instrumentist->getConsultationFee(),
        ));
    }

    /**
     * POST /api/instrumentists/{id}/site-memberships
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Ajoute une affiliation site à un instrumentiste
     * - 404 si instrumentiste introuvable
     * - 404 si site introuvable
     * - 409 si doublon instrumentiste/site
     */
    #[Route('/{id}/site-memberships', name: 'api_instrumentists_add_site_membership', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addSiteMembership(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::ADD_SITE_MEMBERSHIP, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        /** @var AddInstrumentistSiteMembershipRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), AddInstrumentistSiteMembershipRequest::class);

        $membership = $this->instrumentistServiceManager->addSiteMembership($instrumentist, $dto);

        return $this->json([
            'id' => (int) $membership->getId(),
            'site' => [
                'id' => (int) $membership->getSite()->getId(),
                'name' => (string) $membership->getSite()->getName(),
            ],
            'siteRole' => $membership->getSiteRole(),
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * DELETE /api/instrumentists/{id}/site-memberships/{membershipId}
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Supprime une affiliation site d’un instrumentiste
     * - 404 si instrumentiste introuvable
     * - 404 si membership introuvable
     * - 404 si membership ne correspond pas à l’instrumentiste ciblé
     */
    #[Route('/{id}/site-memberships/{membershipId}', name: 'api_instrumentists_delete_site_membership', methods: ['DELETE'], requirements: ['id' => '\d+', 'membershipId' => '\d+'])]
    public function deleteSiteMembership(int $id, int $membershipId): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::DELETE_SITE_MEMBERSHIP, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $this->instrumentistServiceManager->deleteSiteMembership($instrumentist, $membershipId);

        return $this->json([
            'id' => $membershipId,
            'deleted' => true,
        ]);
    }

    /**
     * POST /api/instrumentists/{id}/suspend
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Suspend un instrumentiste en positionnant active à false
     * - 404 si instrumentiste introuvable
     * - Comportement idempotent
     */
    #[Route('/{id}/suspend', name: 'api_instrumentists_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::SUSPEND, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $instrumentist = $this->instrumentistServiceManager->suspendInstrumentist($instrumentist);

        return $this->json([
            'id' => (int) $instrumentist->getId(),
            'active' => $instrumentist->isActive(),
        ]);
    }

    /**
     * POST /api/instrumentists/{id}/activate
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Réactive un instrumentiste en positionnant active à true
     * - 404 si instrumentiste introuvable
     * - Comportement idempotent
     */
    #[Route('/{id}/activate', name: 'api_instrumentists_activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::ACTIVATE, User::class);

        $instrumentist = $this->users->findInstrumentistById($id);
        if (!$instrumentist) {
            throw new NotFoundHttpException('Instrumentist not found');
        }

        $instrumentist = $this->instrumentistServiceManager->activateInstrumentist($instrumentist);

        return $this->json([
            'id' => (int) $instrumentist->getId(),
            'active' => $instrumentist->isActive(),
        ]);
    }

    /**
     * GET /api/instrumentists/with-rates
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Retourne tous les instrumentistes (actifs + inactifs)
     * - Inclut les champs financiers
     * - Tri: lastname, firstname, email
     */
    #[Route('/with-rates', name: 'api_instrumentists_list_with_rates', methods: ['GET'])]
    public function listWithRates(): JsonResponse
    {
        $this->denyAccessUnlessGranted(InstrumentistVoter::LIST_WITH_RATES, User::class);

        $items = $this->users->findInstrumentists(null, null, null);

        $dtos = array_map(static function (User $u): InstrumentistWithRatesListItemResponse {
            $firstname = $u->getFirstname();
            $lastname = $u->getLastname();

            $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));
            $displayName = $name !== '' ? $name : (string) $u->getEmail();

            $employmentType = $u->getEmploymentType();
            $employmentTypeValue = $employmentType ? $employmentType->value : null;

            return new InstrumentistWithRatesListItemResponse(
                id: (int) $u->getId(),
                email: (string) $u->getEmail(),
                firstname: $firstname,
                lastname: $lastname,
                active: $u->isActive(),
                employmentType: $employmentTypeValue,
                defaultCurrency: $u->getDefaultCurrency(),
                hourlyRate: $u->getHourlyRate(),
                consultationFee: $u->getConsultationFee(),
                displayName: $displayName,
            );
        }, $items);

        return $this->json([
            'items' => $dtos,
            'total' => count($dtos),
        ]);
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        try {
            $dto = $this->serializer->deserialize($json, $class, 'json');
        } catch (SerializerExceptionInterface $e) {
            throw new BadRequestHttpException('Invalid JSON payload', $e);
        }

        $this->validateObject($dto);

        return $dto;
    }

    private function validateObject(object $dto): void
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }
    }

    private function parseNullableBoolQuery(Request $request, string $key): ?bool
    {
        $value = $request->query->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            throw new BadRequestHttpException(sprintf('Query parameter "%s" must be a boolean.', $key));
        }

        return match (mb_strtolower((string) $value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new BadRequestHttpException(sprintf('Query parameter "%s" must be "true", "false", "1" or "0".', $key)),
        };
    }

    private function parseRequiredDateTimeQuery(Request $request, string $key): \DateTimeImmutable
    {
        $value = $request->query->get($key);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new UnprocessableEntityHttpException(sprintf('Query parameter "%s" is required', $key));
        }

        if (!is_scalar($value)) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s datetime format (ISO 8601 expected)', $key));
        }

        $normalized = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+\-]\d{2}:\d{2})$/', $normalized) !== 1) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s datetime format (ISO 8601 expected)', $key));
        }

        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s datetime format (ISO 8601 expected)', $key));
        }
    }
}