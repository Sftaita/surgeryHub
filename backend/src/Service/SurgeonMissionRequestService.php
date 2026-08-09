<?php

namespace App\Service;

use App\Dto\Request\MissionCreateRequest;
use App\Entity\Hospital;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionType;
use App\Exception\SurgeonMissionRequestAlreadyReviewedException;
use App\Exception\SurgeonMissionRequestConflictException;
use App\Message\SurgeonMissionRequestCreatedMessage;
use App\Message\SurgeonMissionRequestDecidedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 5 (D-099) — SurgeonMissionRequest est une intention chirurgien, jamais une
 * Mission. Seul accept() peut convertir une demande PENDING en Mission officielle, et
 * uniquement via MissionService::create() (R-04 : toute mutation Mission passe par
 * l'application service, jamais un `new Mission()` ad hoc ici).
 *
 * Atomicité (§3/§9) : accept()/reject() sont entièrement dans un
 * `$em->wrapInTransaction()`, avec un verrou pessimiste posé sur la demande AVANT toute
 * décision (refresh() après lock() — la demande a pu être chargée avant l'appel et donc
 * être périmée, même principe que MissionInterventionDraftService::resolve()). Il est
 * donc impossible d'observer `status=ACCEPTED` avec `createdMission=null` : soit toute
 * la transaction commit (Mission créée + demande transitionnée + audit, un seul
 * commit), soit elle rollback entièrement (aucune écriture visible).
 *
 * Concurrence (§22) : deux managers qui accept()/reject() la même demande en parallèle
 * — le second à obtenir le verrou relit `status` et le trouve déjà ACCEPTED/REJECTED,
 * lève SurgeonMissionRequestAlreadyReviewedException (409) plutôt que de rejouer la
 * transition.
 *
 * Conflits planning (§23) : avant de créer la Mission, réutilise
 * PlanningConflictDetectionService::findConflict() (même moteur que le reste du
 * planning, jamais une seconde implémentation) — un conflit fait échouer l'acceptation
 * proprement, la demande reste PENDING (rien n'a encore été écrit à ce stade).
 */
class SurgeonMissionRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionService $missionService,
        private readonly PlanningConflictDetectionService $conflictDetection,
        private readonly AuditService $auditService,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function create(
        User $surgeon,
        int $siteId,
        MissionType $type,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        ?string $comment,
    ): SurgeonMissionRequest {
        if ($endAt <= $startAt) {
            throw new UnprocessableEntityHttpException('endAt must be after startAt');
        }

        $site = $this->em->find(Hospital::class, $siteId);
        if (!$site instanceof Hospital) {
            throw new NotFoundHttpException('Site not found');
        }

        if (!$this->isSurgeonAffiliatedWithSite($surgeon, $site)) {
            throw new AccessDeniedHttpException('Surgeon is not affiliated with this site.');
        }

        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        $request = new SurgeonMissionRequest();
        $request
            ->setSurgeon($surgeon)
            ->setSite($site)
            ->setType($type)
            ->setStartAt($startAt)
            ->setEndAt($endAt)
            ->setComment($comment)
            ->setStatus(SurgeonMissionRequest::STATUS_PENDING);

        $this->em->persist($request);
        $this->em->flush();

        $this->auditService->recordGlobal($surgeon, AuditEventType::SURGEON_MISSION_REQUEST_CREATED, [
            'surgeonMissionRequestId' => $request->getId(),
            'surgeonId'   => $surgeon->getId(),
            'surgeonName' => self::displayName($surgeon),
            'siteId'      => $site->getId(),
            'siteName'    => $site->getName(),
            'type'        => $type->value,
            'startAt'     => $startAt->format(\DateTimeInterface::ATOM),
            'endAt'       => $endAt->format(\DateTimeInterface::ATOM),
        ]);
        $this->em->flush();

        $managers = $this->userRepository->findManagersAndAdmins(true);
        if (!empty($managers)) {
            $this->bus->dispatch(new SurgeonMissionRequestCreatedMessage(
                requestId: $request->getId(),
                surgeonId: $surgeon->getId(),
                surgeonName: self::displayName($surgeon),
                siteId: $site->getId(),
                siteName: (string) $site->getName(),
                type: $type->value,
                startAt: $startAt->format(\DateTimeInterface::ATOM),
                endAt: $endAt->format(\DateTimeInterface::ATOM),
                recipientUserIds: array_map(static fn (User $m) => $m->getId(), $managers),
            ));
        }

        return $request;
    }

    /** @return SurgeonMissionRequest[] */
    public function findForSurgeon(User $surgeon): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(SurgeonMissionRequest::class, 'r')
            ->where('r.surgeon = :surgeon')
            ->setParameter('surgeon', $surgeon)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function accept(SurgeonMissionRequest $request, User $manager, ?string $reviewComment = null): SurgeonMissionRequest
    {
        $reviewComment = self::normalizeComment($reviewComment);

        $this->em->wrapInTransaction(function () use ($request, $manager, $reviewComment): void {
            $this->em->lock($request, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($request);

            if ($request->getStatus() !== SurgeonMissionRequest::STATUS_PENDING) {
                throw new SurgeonMissionRequestAlreadyReviewedException(sprintf(
                    'SurgeonMissionRequest #%d is %s, not PENDING — cannot be accepted (again).',
                    $request->getId(),
                    $request->getStatus(),
                ));
            }

            $surgeon = $request->getSurgeon();
            $conflict = $this->conflictDetection->findConflict($surgeon, $request->getStartAt(), $request->getEndAt());
            if ($conflict !== null) {
                throw new SurgeonMissionRequestConflictException(sprintf(
                    'Surgeon #%d already has an active mission #%d overlapping this period.',
                    $surgeon->getId(),
                    $conflict->getId(),
                ));
            }

            // R-04 — toute mutation Mission passe par l'application service officielle,
            // jamais un `new Mission()` ad hoc dans ce service ou un contrôleur. Le statut
            // initial (DRAFT) est celui que MissionService::create() pose systématiquement
            // pour toute création manager ad hoc — voir docblock de classe et D-099.
            $dto = new MissionCreateRequest();
            $dto->siteId = $request->getSite()->getId();
            $dto->type = $request->getType();
            $dto->startAt = $request->getStartAt();
            $dto->endAt = $request->getEndAt();
            $dto->surgeonUserId = $surgeon->getId();
            $dto->instrumentistUserId = null;

            $mission = $this->missionService->create($dto, $manager);

            $request->setStatus(SurgeonMissionRequest::STATUS_ACCEPTED);
            $request->setCreatedMission($mission);
            $request->setReviewedBy($manager);
            $request->setReviewedAt(new \DateTimeImmutable());
            $request->setReviewComment($reviewComment);

            $this->auditService->record($mission, $manager, AuditEventType::SURGEON_MISSION_REQUEST_ACCEPTED, [
                'surgeonMissionRequestId' => $request->getId(),
                'surgeonId'   => $surgeon->getId(),
                'surgeonName' => self::displayName($surgeon),
                'reviewComment' => $reviewComment,
            ]);

            $this->em->flush();
        });

        $mission = $request->getCreatedMission();
        $this->bus->dispatch(new SurgeonMissionRequestDecidedMessage(
            requestId: $request->getId(),
            surgeonId: $request->getSurgeon()->getId(),
            accepted: true,
            missionId: $mission?->getId(),
            siteId: $request->getSite()->getId(),
            siteName: (string) $request->getSite()->getName(),
            startAt: $request->getStartAt()->format(\DateTimeInterface::ATOM),
            reviewComment: null,
        ));

        return $request;
    }

    public function reject(SurgeonMissionRequest $request, User $manager, string $reviewComment): SurgeonMissionRequest
    {
        $reviewComment = self::normalizeComment($reviewComment);
        if ($reviewComment === null) {
            throw new UnprocessableEntityHttpException('reviewComment is required to reject a request.');
        }

        $this->em->wrapInTransaction(function () use ($request, $manager, $reviewComment): void {
            $this->em->lock($request, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($request);

            if ($request->getStatus() !== SurgeonMissionRequest::STATUS_PENDING) {
                throw new SurgeonMissionRequestAlreadyReviewedException(sprintf(
                    'SurgeonMissionRequest #%d is %s, not PENDING — cannot be rejected (again).',
                    $request->getId(),
                    $request->getStatus(),
                ));
            }

            $request->setStatus(SurgeonMissionRequest::STATUS_REJECTED);
            $request->setReviewedBy($manager);
            $request->setReviewedAt(new \DateTimeImmutable());
            $request->setReviewComment($reviewComment);

            $this->auditService->recordGlobal($manager, AuditEventType::SURGEON_MISSION_REQUEST_REJECTED, [
                'surgeonMissionRequestId' => $request->getId(),
                'surgeonId' => $request->getSurgeon()->getId(),
                'reviewComment' => $reviewComment,
            ]);

            $this->em->flush();
        });

        $this->bus->dispatch(new SurgeonMissionRequestDecidedMessage(
            requestId: $request->getId(),
            surgeonId: $request->getSurgeon()->getId(),
            accepted: false,
            missionId: null,
            siteId: $request->getSite()->getId(),
            siteName: (string) $request->getSite()->getName(),
            startAt: $request->getStartAt()->format(\DateTimeInterface::ATOM),
            reviewComment: $reviewComment,
        ));

        return $request;
    }

    private function isSurgeonAffiliatedWithSite(User $surgeon, Hospital $site): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(sm.id)')
            ->from(\App\Entity\SiteMembership::class, 'sm')
            ->where('sm.user = :user')
            ->andWhere('sm.site = :site')
            ->setParameter('user', $surgeon)
            ->setParameter('site', $site)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    private static function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $trimmed = trim($comment);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
