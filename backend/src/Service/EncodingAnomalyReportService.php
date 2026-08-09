<?php

namespace App\Service;

use App\Entity\EncodingAnomalyReport;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Exception\EncodingAnomalyReportAlreadyResolvedException;
use App\Message\EncodingAnomalyReportCreatedMessage;
use App\Message\EncodingAnomalyReportResolvedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 6 (D-100) — le chirurgien crée, seul manager/admin résout (V1). Résoudre ne
 * corrige jamais automatiquement l'encodage (§17) : ce service ne mute jamais
 * MissionIntervention/MaterialLine, uniquement le signalement lui-même.
 *
 * Statuts mission éligibles au signalement : exactement les mêmes que
 * MissionVoter::canViewEncoding() côté chirurgien — signaler n'a de sens que là où
 * consulter en a un (jamais une règle inventée séparément).
 */
class EncodingAnomalyReportService
{
    private const SURGEON_ELIGIBLE_STATUSES = [
        MissionStatus::DECLARED,
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::CLOSED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $auditService,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function create(Mission $mission, User $surgeon, string $type, string $comment): EncodingAnomalyReport
    {
        if ($mission->getSurgeon()?->getId() !== $surgeon->getId()) {
            throw new AccessDeniedHttpException('This mission does not belong to you.');
        }

        if (!in_array($mission->getStatus(), self::SURGEON_ELIGIBLE_STATUSES, true)) {
            throw new UnprocessableEntityHttpException('Encoding is not available for this mission yet.');
        }

        if (!in_array($type, EncodingAnomalyReport::TYPES, true)) {
            throw new UnprocessableEntityHttpException('Invalid anomaly type.');
        }

        $comment = trim($comment);
        if ($comment === '') {
            throw new UnprocessableEntityHttpException('comment is required.');
        }

        // §14 — éviter les doublons accidentels : un seul signalement OPEN à la fois
        // par (mission, reporter). Une fois résolu, un nouveau signalement redevient
        // possible (un second problème peut être découvert plus tard).
        $existingOpen = $this->em->createQueryBuilder()
            ->select('r')
            ->from(EncodingAnomalyReport::class, 'r')
            ->where('r.mission = :mission')
            ->andWhere('r.reporter = :reporter')
            ->andWhere('r.status = :status')
            ->setParameter('mission', $mission)
            ->setParameter('reporter', $surgeon)
            ->setParameter('status', EncodingAnomalyReport::STATUS_OPEN)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingOpen !== null) {
            throw new ConflictHttpException('A report is already pending for this mission.');
        }

        $report = new EncodingAnomalyReport();
        $report
            ->setMission($mission)
            ->setReporter($surgeon)
            ->setType($type)
            ->setComment($comment)
            ->setStatus(EncodingAnomalyReport::STATUS_OPEN);

        $this->em->persist($report);
        $this->em->flush();

        $this->auditService->record($mission, $surgeon, AuditEventType::ENCODING_ANOMALY_REPORTED, [
            'encodingAnomalyReportId' => $report->getId(),
            'surgeonId'   => $surgeon->getId(),
            'surgeonName' => self::displayName($surgeon),
            'type'        => $type,
        ]);
        $this->em->flush();

        $managers = $this->userRepository->findManagersAndAdmins(true);
        if (!empty($managers)) {
            $this->bus->dispatch(new EncodingAnomalyReportCreatedMessage(
                reportId: $report->getId(),
                missionId: $mission->getId(),
                surgeonId: $surgeon->getId(),
                surgeonName: self::displayName($surgeon),
                type: $type,
                recipientUserIds: array_map(static fn (User $m) => $m->getId(), $managers),
            ));
        }

        return $report;
    }

    /** @return EncodingAnomalyReport[] */
    public function findForMission(Mission $mission): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(EncodingAnomalyReport::class, 'r')
            ->where('r.mission = :mission')
            ->setParameter('mission', $mission)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function resolve(EncodingAnomalyReport $report, User $manager, string $resolutionComment): EncodingAnomalyReport
    {
        $resolutionComment = trim($resolutionComment);
        if ($resolutionComment === '') {
            throw new UnprocessableEntityHttpException('resolutionComment is required.');
        }

        $this->em->wrapInTransaction(function () use ($report, $manager, $resolutionComment): void {
            $this->em->lock($report, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($report);

            if ($report->getStatus() !== EncodingAnomalyReport::STATUS_OPEN) {
                throw new EncodingAnomalyReportAlreadyResolvedException(sprintf(
                    'EncodingAnomalyReport #%d is %s, not OPEN — cannot be resolved (again).',
                    $report->getId(),
                    $report->getStatus(),
                ));
            }

            $report->setStatus(EncodingAnomalyReport::STATUS_RESOLVED);
            $report->setResolvedBy($manager);
            $report->setResolvedAt(new \DateTimeImmutable());
            $report->setResolutionComment($resolutionComment);

            $this->auditService->record($report->getMission(), $manager, AuditEventType::ENCODING_ANOMALY_RESOLVED, [
                'encodingAnomalyReportId' => $report->getId(),
                'surgeonId' => $report->getReporter()?->getId(),
                'resolutionComment' => $resolutionComment,
            ]);

            $this->em->flush();
        });

        $this->bus->dispatch(new EncodingAnomalyReportResolvedMessage(
            reportId: $report->getId(),
            missionId: $report->getMission()->getId(),
            surgeonId: $report->getReporter()->getId(),
            resolutionComment: $resolutionComment,
        ));

        return $report;
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
