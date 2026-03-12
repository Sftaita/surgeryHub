<?php

namespace App\Controller\Api;

use App\Dto\Request\CreateInstrumentistRequest;
use App\Dto\Request\Response\InstrumentistCreateResponse;
use App\Dto\Request\Response\InstrumentistListItemResponse;
use App\Dto\Request\Response\InstrumentistProfileResponse;
use App\Dto\Request\Response\InstrumentistWithRatesListItemResponse;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\InstrumentistVoter;
use App\Service\InstrumentistServiceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/instrumentists')]
final class InstrumentistController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly InstrumentistServiceManager $instrumentistServiceManager,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * POST /api/instrumentists
     * - Accès: ROLE_ADMIN / ROLE_MANAGER (via voter)
     * - Création rapide d’un instrumentiste par un manager
     * - Pas d’envoi d’email dans ce lot
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
            'warnings' => [],
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

        return $this->json(new InstrumentistProfileResponse(
            id: (int) $instrumentist->getId(),
            email: (string) $instrumentist->getEmail(),
            firstname: $firstname,
            lastname: $lastname,
            displayName: $displayName,
            active: $instrumentist->isActive(),
            employmentType: $employmentTypeValue,
            defaultCurrency: $instrumentist->getDefaultCurrency(),
        ));
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
        $dto = $this->serializer->deserialize($json, $class, 'json');

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
}