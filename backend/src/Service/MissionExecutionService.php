<?php

namespace App\Service;

use App\Dto\EffectiveDuration;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\DisputeReasonCode;
use App\Enum\DisputeStatus;
use App\Enum\EffectiveDurationSource;
use App\Enum\HoursSource;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Lot 1 (Exécution & Valorisation) — point d'entrée métier unique pour le RÉALISÉ
 * d'une mission (§8 du lot). Remplace les méthodes service/dispute qui vivaient dans
 * InstrumentistServiceManager — le reste de ce manager (gestion administrative des
 * instrumentistes : création, tarifs, sites, planning) est hors périmètre de ce lot et
 * n'est pas touché.
 *
 * Ne contient aucune logique financière : ni montant, ni tarif, ni statut financier.
 * resolveEffectiveDuration() est le seul point d'extension prévu pour le futur moteur
 * FinancialCalculation (§11 du lot) — un simple calcul de durée, jamais un calcul de coût.
 */
final class MissionExecutionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
    ) {}

    /**
     * §3.1 — résolution centralisée et testable de la durée effective : horaires réels
     * si connus, sinon durée explicite déclarée, sinon repli sur le planifié
     * (Mission.startAt/endAt). Ne crée jamais de MissionExecution (lecture seule) — une
     * mission sans exécution déclarée retourne simplement la durée planifiée.
     */
    public function resolveEffectiveDuration(Mission $mission): EffectiveDuration
    {
        $execution = $mission->getExecution();

        if ($execution !== null) {
            $start = $execution->getActualStartAt();
            $end   = $execution->getActualEndAt();

            if ($start !== null && $end !== null) {
                $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
                return new EffectiveDuration(max(0, $minutes), EffectiveDurationSource::ACTUAL_TIMES);
            }

            if ($execution->getActualDurationMinutes() !== null) {
                return new EffectiveDuration($execution->getActualDurationMinutes(), EffectiveDurationSource::ACTUAL_EXPLICIT);
            }
        }

        $plannedStart = $mission->getStartAt();
        $plannedEnd   = $mission->getEndAt();
        $plannedMinutes = ($plannedStart !== null && $plannedEnd !== null)
            ? max(0, (int) round(($plannedEnd->getTimestamp() - $plannedStart->getTimestamp()) / 60))
            : 0;

        return new EffectiveDuration($plannedMinutes, EffectiveDurationSource::PLANNED);
    }

    /** Lecture seule — ne crée jamais de MissionExecution (voir findOrCreateExecution()). */
    public function getExecution(Mission $mission): ?MissionExecution
    {
        return $mission->getExecution();
    }

    /**
     * Création paresseuse : seules les écritures (updateActuals(), openDispute()) créent
     * une MissionExecution — une simple consultation n'en persiste jamais une vide.
     */
    public function findOrCreateExecution(Mission $mission, User $actor): MissionExecution
    {
        $execution = $mission->getExecution();
        if ($execution !== null) {
            return $execution;
        }

        $execution = new MissionExecution();
        $execution->setMission($mission);

        $this->em->persist($execution);
        // Synchronise le graphe en mémoire — Doctrine ne le fait pas automatiquement
        // depuis le seul FK côté propriétaire (même correction que Lot 7, addComment()).
        $mission->setExecution($execution);

        $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_CREATED);
        $this->em->flush();

        return $execution;
    }

    /**
     * §3.2 — règle de cohérence unique : si actualStartAt ET actualEndAt sont fournis
     * (dans l'état résultant, pas seulement dans cette requête), la durée est TOUJOURS
     * dérivée des deux horaires — jamais deux sources de vérité. Une durée explicite
     * fournie dans la même requête qui contredirait cette dérivation est un 422, jamais
     * une acceptation silencieuse (§3.2 : "ne pas accepter silencieusement des valeurs
     * contradictoires"). Fournir un seul des deux horaires (sans l'autre, existant ou
     * nouveau) est également un 422 — un horaire seul ne décrit aucune durée.
     *
     * Idempotent : appeler deux fois avec les mêmes valeurs ne produit qu'un seul
     * AuditEvent (aucun changement détecté au deuxième appel → aucun événement).
     */
    public function updateActuals(
        Mission $mission,
        User $actor,
        ?\DateTimeImmutable $actualStartAt,
        ?\DateTimeImmutable $actualEndAt,
        ?int $actualDurationMinutes,
        ?HoursSource $hoursSource,
    ): MissionExecution {
        $execution = $this->findOrCreateExecution($mission, $actor);

        $resultStart = $actualStartAt ?? $execution->getActualStartAt();
        $resultEnd   = $actualEndAt ?? $execution->getActualEndAt();
        $explicitDurationThisCall = $actualDurationMinutes !== null;
        $resultDuration = $actualDurationMinutes ?? $execution->getActualDurationMinutes();

        if (($resultStart !== null) !== ($resultEnd !== null)) {
            throw new UnprocessableEntityHttpException('actualStartAt et actualEndAt doivent être fournis ensemble, jamais un seul des deux.');
        }

        if ($resultStart !== null && $resultEnd !== null) {
            if ($resultEnd <= $resultStart) {
                throw new UnprocessableEntityHttpException('actualEndAt doit être postérieur à actualStartAt.');
            }

            $derivedMinutes = (int) round(($resultEnd->getTimestamp() - $resultStart->getTimestamp()) / 60);

            if ($explicitDurationThisCall && $actualDurationMinutes !== $derivedMinutes) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'actualDurationMinutes (%d) est incohérent avec la durée calculée depuis actualStartAt/actualEndAt (%d).',
                    $actualDurationMinutes,
                    $derivedMinutes,
                ));
            }

            $resultDuration = $derivedMinutes;
        }

        $durationChanged = $execution->getActualDurationMinutes() !== $resultDuration;
        $startChanged     = $execution->getActualStartAt()?->getTimestamp() !== $resultStart?->getTimestamp();
        $endChanged       = $execution->getActualEndAt()?->getTimestamp() !== $resultEnd?->getTimestamp();
        $sourceChanged    = $hoursSource !== null && $execution->getHoursSource() !== $hoursSource;
        $anythingChanged  = $durationChanged || $startChanged || $endChanged || $sourceChanged;

        $execution->setActualStartAt($resultStart);
        $execution->setActualEndAt($resultEnd);
        $execution->setActualDurationMinutes($resultDuration);
        if ($hoursSource !== null) {
            $execution->setHoursSource($hoursSource);
        }

        if ($anythingChanged) {
            if ($durationChanged) {
                $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_DURATION_CHANGED, [
                    'actualDurationMinutes' => $resultDuration,
                    'effectiveDurationSource' => ($resultStart !== null && $resultEnd !== null)
                        ? EffectiveDurationSource::ACTUAL_TIMES->value
                        : EffectiveDurationSource::ACTUAL_EXPLICIT->value,
                ]);
            } else {
                $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_UPDATED, [
                    'hoursSource' => $hoursSource?->value,
                ]);
            }
            $this->em->flush();
        }

        return $execution;
    }

    /** §6 — même mécanique que ServiceHoursDispute : une seule contestation OPEN à la fois. */
    public function openDispute(Mission $mission, MissionExecution $execution, User $surgeon, DisputeReasonCode $reasonCode, ?string $comment): MissionExecutionDispute
    {
        $existingOpen = $this->em->getRepository(MissionExecutionDispute::class)->findOneBy([
            'missionExecution' => $execution,
            'status' => DisputeStatus::OPEN,
        ]);
        if ($existingOpen !== null) {
            throw new BadRequestHttpException('An open dispute already exists for this execution');
        }

        $dispute = new MissionExecutionDispute();
        $dispute
            ->setMission($mission)
            ->setMissionExecution($execution)
            ->setRaisedBy($surgeon)
            ->setReasonCode($reasonCode)
            ->setComment($comment);

        $this->em->persist($dispute);
        $execution->getDisputes()->add($dispute);

        $this->audit->record($mission, $surgeon, AuditEventType::MISSION_EXECUTION_DISPUTE_OPENED, [
            'reasonCode' => $reasonCode->value,
        ]);
        $this->em->flush();

        return $dispute;
    }

    /** §6 — le manager traite (statut) et/ou commente la résolution. Workflow inchangé. */
    public function updateDispute(MissionExecutionDispute $dispute, User $actor, ?DisputeStatus $status, ?string $resolutionComment): MissionExecutionDispute
    {
        if ($status !== null) {
            $dispute->setStatus($status);
        }
        if ($resolutionComment !== null) {
            $dispute->setResolutionComment($resolutionComment);
        }

        $mission = $dispute->getMission();

        if ($status === DisputeStatus::RESOLVED) {
            $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_DISPUTE_RESOLVED, [
                'disputeId' => $dispute->getId(),
                'resolutionComment' => $resolutionComment,
            ]);
        } elseif ($status === DisputeStatus::REJECTED) {
            $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_DISPUTE_REJECTED, [
                'disputeId' => $dispute->getId(),
                'resolutionComment' => $resolutionComment,
            ]);
        }

        $this->em->flush();

        return $dispute;
    }

    /** @return array{items: MissionExecutionDispute[], total: int, page: int, limit: int} */
    public function listDisputes(?string $status, int $page = 1, int $limit = 20): array
    {
        $qb = $this->em->getRepository(MissionExecutionDispute::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.mission', 'm')->addSelect('m')
            ->leftJoin('d.missionExecution', 'e')->addSelect('e')
            ->orderBy('d.id', 'DESC');

        if ($status !== null) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        $qb->setMaxResults($limit)->setFirstResult(($page - 1) * $limit);

        $paginator = new Paginator($qb);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getExecutionOr404(int $id): MissionExecution
    {
        return $this->em->find(MissionExecution::class, $id) ?? throw new NotFoundHttpException('Execution not found');
    }

    public function getDisputeOr404(int $id): MissionExecutionDispute
    {
        return $this->em->find(MissionExecutionDispute::class, $id) ?? throw new NotFoundHttpException('Dispute not found');
    }
}
