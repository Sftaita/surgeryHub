<?php

namespace App\Controller\Api;

use App\Dto\Request\MissionExecutionUpdateRequest;
use App\Dto\Request\Response\MissionExecutionDisputeDto;
use App\Dto\Request\Response\MissionExecutionDto;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\WorkedPresence;
use App\Exception\PrestationConflictException;
use App\Service\Prestation\PrestationGroupResolver;
use App\Service\Prestation\PrestationGrouping;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Security\Voter\MissionExecutionVoter;
use App\Service\MissionExecutionService;
use App\Service\MissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Lot 1 (Exécution & Valorisation) — forme cible des endpoints d'exécution, additive
 * aux endpoints legacy conservés dans ServiceController. Toujours 200 même sans
 * MissionExecution persistée (§3.1) : hasExecutionRecord=false et
 * effectiveDurationMinutes/Source reflètent alors le repli sur le planifié.
 */
#[Route('/api/missions/{missionId}/execution')]
final class MissionExecutionController extends AbstractController
{
    public function __construct(
        private readonly MissionService $missionService,
        private readonly MissionExecutionService $executionService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly PrestationGroupResolver $prestationGroups,
    ) {}

    #[Route('', name: 'api_missions_execution_get', methods: ['GET'])]
    public function get(int $missionId): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionExecutionVoter::VIEW, $mission);

        return $this->json($this->toDto($mission));
    }

    #[Route('', name: 'api_missions_execution_update', methods: ['PATCH'])]
    public function update(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->missionService->getOr404($missionId);
        $this->denyAccessUnlessGranted(MissionExecutionVoter::UPDATE, $mission);

        /** @var MissionExecutionUpdateRequest $dto */
        $dto = $this->deserializeAndValidate($request->getContent(), MissionExecutionUpdateRequest::class);

        self::assertNotPartOfPrestation($mission, $this->prestationGroups);

        $this->executionService->updateActuals(
            $mission,
            $user,
            $this->parseDate($dto->actualStartAt),
            $this->parseDate($dto->actualEndAt),
            $dto->actualDurationMinutes,
            $dto->hoursSource,
        );

        return $this->json($this->toDto($mission), Response::HTTP_OK);
    }

    private function toDto(Mission $mission): MissionExecutionDto
    {
        $execution = $mission->getExecution();
        $effective = $this->executionService->resolveEffectiveDuration($mission);

        return new MissionExecutionDto(
            missionId: (int) $mission->getId(),
            hasExecutionRecord: $execution instanceof MissionExecution,
            actualStartAt: $execution?->getActualStartAt()?->format(\DateTimeInterface::ATOM),
            actualEndAt: $execution?->getActualEndAt()?->format(\DateTimeInterface::ATOM),
            actualDurationMinutes: $execution?->getActualDurationMinutes(),
            hoursSource: $execution?->getHoursSource()?->value,
            effectiveDurationMinutes: $effective->minutes,
            effectiveDurationSource: $effective->source->value,
            disputes: $execution !== null
                ? array_map($this->toDisputeDto(...), $execution->getDisputes()->toArray())
                : [],
            presence: self::presenceDto($execution?->getPresence()),
            prestationMissionIds: PrestationGrouping::ids($this->prestationGroups->groupOf($mission)),
        );
    }

    /** @return array<string,mixed>|null */
    public static function presenceDto(?WorkedPresence $presence): ?array
    {
        if ($presence === null) {
            return null;
        }

        return [
            'id' => $presence->getId(),
            'revision' => $presence->getRevision(),
            'actualStartAt' => $presence->getActualStartAt()?->format(\DateTimeInterface::ATOM),
            'actualEndAt' => $presence->getActualEndAt()?->format(\DateTimeInterface::ATOM),
            'totalMinutes' => $presence->getTotalMinutes(),
            'missionIds' => PrestationGrouping::ids($presence->getMissions()),
        ];
    }

    /**
     * D-156 — dans une prestation regroupée (2+ missions, ou déjà liée à une présence), les
     * heures réelles se saisissent UNE fois sur la prestation : jamais mission par mission,
     * ce qui recréerait des heures simultanées comptées deux fois.
     */
    public static function assertNotPartOfPrestation(Mission $mission, PrestationGroupResolver $groups): void
    {
        $group = $groups->groupOf($mission);
        if (count($group) > 1 || $mission->getExecution()?->getPresence() !== null) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_REQUIRES_COMMON_HOURS,
                "Cette mission fait partie d'une prestation multi-salles : ses heures réelles se saisissent une seule fois sur la prestation.",
                ['missionId' => $mission->getId(), 'prestationMissionIds' => PrestationGrouping::ids($group)],
            );
        }
    }

    private function toDisputeDto(MissionExecutionDispute $d): MissionExecutionDisputeDto
    {
        $author = $d->getRaisedBy();
        $authorName = $author !== null
            ? trim(($author->getFirstname() ?? '') . ' ' . ($author->getLastname() ?? ''))
            : '';

        return new MissionExecutionDisputeDto(
            id: (int) $d->getId(),
            reasonCode: $d->getReasonCode()?->value ?? '',
            comment: $d->getComment(),
            status: $d->getStatus()?->value ?? '',
            resolutionComment: $d->getResolutionComment(),
            raisedByDisplayName: $authorName,
            createdAt: (string) $d->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Date invalide (ISO 8601 attendu).');
        }
    }

    private function deserializeAndValidate(string $json, string $class): object
    {
        $dto = $this->serializer->deserialize($json === '' ? '{}' : $json, $class, 'json');

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException((string) $errors);
        }

        return $dto;
    }
}
