<?php

namespace App\MessageHandler;

use App\Entity\PlanningVersion;
use App\Message\PlanningModificationAppliedMessage;
use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use App\Service\PlanningChangeSummaryService;
use App\Service\PlanningVersionOperationalScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * H3 (D-129) — every notification of a committed Planning Modification batch, all under
 * the batch's correlationId (restored by CorrelationMiddleware from the message stamp):
 *
 *   1. the per-person recap (PLANNING_CHANGE_SUMMARY) — the people whose planning changed,
 *      an instrumentist the batch removed from a mission included (the recap is HER
 *      information about the removal: no second per-mission notice for the same batch);
 *   2. the pool for the missions the batch opened — OpenMissionPoolNotifier in BATCH mode:
 *      per-mission eligibility, the removed instrumentist excluded from her own mission's
 *      episode, ONE email and ONE push per recipient for the whole batch.
 *
 * Both steps are idempotent (dispatch keys built from the batchKey / episodeRefs): a
 * Messenger retry sends nothing twice. Each step is isolated from the other.
 */
#[AsMessageHandler]
final class PlanningModificationAppliedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningChangeSummaryService $changeSummary,
        private readonly OpenMissionPoolNotifier $poolNotifier,
        private readonly PlanningVersionOperationalScope $operationalScope,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PlanningModificationAppliedMessage $message): void
    {
        $version = $this->em->find(PlanningVersion::class, $message->planningVersionId);
        if ($version === null) {
            $this->logger->warning('PlanningModificationApplied: version not found', ['planningVersionId' => $message->planningVersionId]);
            return;
        }

        try {
            $this->changeSummary->dispatchSummaries(
                $version,
                $message->diff,
                $this->operationalScope->missions($version),
                $message->batchKey,
            );
        } catch (\Throwable $e) {
            $this->logger->error('PlanningModificationApplied: change summaries failed', [
                'planningVersionId' => $message->planningVersionId, 'batchKey' => $message->batchKey, 'error' => $e->getMessage(),
            ]);
        }

        if ($message->openedEpisodes === []) {
            return;
        }

        try {
            $this->poolNotifier->notifyBatch(array_map(
                static fn (array $e) => OpenEpisode::fromArray($e),
                $message->openedEpisodes,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('PlanningModificationApplied: pool solicitation failed', [
                'planningVersionId' => $message->planningVersionId, 'batchKey' => $message->batchKey, 'error' => $e->getMessage(),
            ]);
        }
    }
}
