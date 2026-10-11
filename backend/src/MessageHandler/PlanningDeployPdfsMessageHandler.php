<?php

namespace App\MessageHandler;

use Psr\Log\LoggerInterface;
use App\Notification\OpenEpisode;
use App\Enum\MissionCommunicationSource;
use App\Notification\MissionCommunicationSpec;
use App\Notification\PushContent;
use App\Notification\EmailContent;
use App\Notification\DocumentReference;
use App\Notification\DocumentStore;
use App\Notification\PlanningDocumentIdentity;
use App\Notification\NotificationIntent;
use App\Notification\NotificationDispatcher;
use App\Notification\OpenMissionPoolNotifier;
use App\Enum\OpenEpisodeCause;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\PlanningDeploymentStatus;
use App\Message\PlanningDeployPdfsMessage;
use App\Service\NotificationService;
use App\Service\PdfService;
use App\Service\UncoveredReasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles the heavy work of PDF generation, email sending, and in-app notifications
 * after a planning deploy. Runs in the Messenger worker — completely decoupled from
 * the HTTP request.
 *
 * Idempotence (V1):
 *   - Checks PlanningDeployment.status == DONE at entry → returns immediately if already processed.
 *   - Sets PROCESSING before starting work; DONE on success; FAILED on unrecoverable error.
 *   - A crash between PROCESSING and DONE causes a retry that re-executes the full handler.
 *     Notifications are idempotent per deployment (dispatcher episode `deployment:{id}`, H4/H5a):
 *     a crash-retry completes the missing channels, never sends a second email.
 *
 * Pool notifications:
 *   - Only missions in $message->openUncoveredIds receive a pool notification.
 *   - Pre-existing OPEN missions (from earlier deploys) are never re-notified.
 *
 * Email policy — exactly ONE deploy email per recipient (surgeon, instrumentist, manager).
 * Change-summary "recap" emails (PlanningChangeSummaryService) are a separate, standalone
 * capability that this handler never invokes — they are reserved for a future trigger on
 * already-published plannings, not the initial deploy. See docs/decisions.md.
 *
 * Notifications (H4/H5a): every planning notification — instrumentist, surgeon, manager
 * confirmation — goes through NotificationDispatcher (channels from preferences, every outcome
 * traced, idempotent per deployment); the pool through OpenMissionPoolNotifier. PDFs are
 * rendered once, stored (GeneratedDocument) and attached by reference.
 */
#[AsMessageHandler]
final class PlanningDeployPdfsMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly PdfService                  $pdfService,
        private readonly NotificationService         $notificationService,
        private readonly LoggerInterface             $logger,
        private readonly UncoveredReasonResolver        $uncoveredReasonResolver,
        private readonly OpenMissionPoolNotifier        $poolNotifier,
        private readonly NotificationDispatcher         $dispatcher,
        private readonly DocumentStore                  $documents,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {}

    public function __invoke(PlanningDeployPdfsMessage $message): void
    {
        // ── 0. Idempotence guard ──────────────────────────────────────────────
        $deployment = null;
        if ($message->deploymentId !== null) {
            $deployment = $this->em->find(PlanningDeployment::class, $message->deploymentId);
            if ($deployment !== null && $deployment->getStatus() === PlanningDeploymentStatus::DONE) {
                return; // already processed — skip silently
            }
            if ($deployment !== null) {
                $deployment->setStatus(PlanningDeploymentStatus::PROCESSING)
                           ->setStartedAt(new \DateTimeImmutable());
                $this->em->flush();
            }
        }

        $fromDate = new \DateTimeImmutable($message->from);
        $toDate   = new \DateTimeImmutable($message->to);

        // ── 1. Resolve the deploying user ─────────────────────────────────────
        $deployedBy = $this->em->find(User::class, $message->deployedById);
        if ($deployedBy === null) {
            $this->markFailed($deployment, 'User ' . $message->deployedById . ' not found — permanent error.');
            return;
        }

        try {
            // ── 2. Load all published missions (OPEN / ASSIGNED) ──────────────
            $qb = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Mission::class, 'm')
                ->where('m.startAt >= :from')
                ->andWhere('m.startAt <= :to')
                ->andWhere('m.status IN (:statuses)')
                ->setParameter('from', $fromDate->setTime(0, 0, 0))
                ->setParameter('to', $toDate->setTime(23, 59, 59))
                ->setParameter('statuses', [MissionStatus::OPEN, MissionStatus::ASSIGNED]);

            if ($message->siteId !== null) {
                $site = $this->em->find(Hospital::class, $message->siteId);
                if ($site !== null) {
                    $qb->andWhere('m.site = :site')->setParameter('site', $site);
                }
            }

            /** @var Mission[] $missions */
            $missions = $qb->getQuery()->getResult();

            if (empty($missions)) {
                $this->markDone($deployment);
                return;
            }

            // ── 3. Group by instrumentist and surgeon ─────────────────────────
            $byInstrumentist = [];
            $bySurgeon       = [];
            foreach ($missions as $mission) {
                if ($mission->getInstrumentist() !== null) {
                    $byInstrumentist[$mission->getInstrumentist()->getId()][] = $mission;
                }
                if ($mission->getSurgeon() !== null) {
                    $bySurgeon[$mission->getSurgeon()->getId()][] = $mission;
                }
            }

            // ── 4. Global PDF — rendered once, stored once (H5a: GeneratedDocument), attached
            //    by reference to the manager's confirmation only (manager-only content).
            $globalPdf = null;
            if (!empty($bySurgeon)) {
                $globalPdf = $this->pdfAttachment('PLANNING_GLOBAL', 'pdf/planning_global.html.twig', [
                    'missions'   => $missions,
                    'periodFrom' => $fromDate,
                    'periodTo'   => $toDate,
                ], sprintf('planning-global-%s-%s.pdf', $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')),
                    PlanningDocumentIdentity::global($message->siteId, $fromDate, $toDate, $missions));
            }

            // ── 5. Per-instrumentist: personal PDF + planning notification ────
            // H4 (D-130) — through NotificationDispatcher (channels from preferences, every
            // outcome traced, idempotent per deployment). A channel that actually reaches her
            // makes each of her missions communicated (mission_communication, DEPLOY).
            $episode = 'deployment:' . ($message->deploymentId ?? ('legacy:' . $message->from . ':' . $message->to));
            $subject = sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y'));
            foreach ($byInstrumentist as $instrId => $instrMissions) {
                $instrumentist = $this->em->find(User::class, $instrId);
                if ($instrumentist === null) {
                    continue;
                }

                $attachment = $this->pdfAttachment('PLANNING_INSTRUMENTIST', 'pdf/planning_instrumentist.html.twig', [
                    'instrumentist' => $instrumentist, 'missions' => $instrMissions, 'periodFrom' => $fromDate, 'periodTo' => $toDate,
                ], sprintf('planning-%s-%s-%s.pdf', strtolower(str_replace(' ', '-', $this->displayName($instrumentist))), $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')),
                    PlanningDocumentIdentity::personal(PlanningDocumentIdentity::ROLE_INSTRUMENTIST, $instrumentist, $fromDate, $toDate, $instrMissions));

                $this->dispatchPlanning($instrumentist, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, [
                    'periodLabel'  => $this->periodLabel($fromDate, $toDate),
                    'missionCount' => count($instrMissions),
                    'deployedAt'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ], new EmailContent($subject, 'emails/planning_instrumentist.html.twig', [
                    'instrumentist'     => $instrumentist,
                    'periodFrom'        => $fromDate,
                    'periodTo'          => $toDate,
                    'missionCount'      => count($instrMissions),
                    // H3 (D-129) — the "missions disponibles" section moved out of the
                    // planning email: the deployment's OPEN missions are proposed by
                    // OpenMissionPoolNotifier (step 7), never twice.
                    'availableMissions' => [],
                    'availableMissionsUrl' => rtrim($this->frontendUrl, '/') . '/app/i/offers',
                ], attachments: $attachment !== null ? [$attachment] : []), $episode, $message, $instrMissions, MissionCommunicationSpec::ROLE_INSTRUMENTIST);
            }

            // ── 6. Per-surgeon: personal PDF + planning notification ──────────
            foreach ($bySurgeon as $surgeonId => $surgeonMissions) {
                $surgeon = $this->em->find(User::class, $surgeonId);
                if ($surgeon === null) {
                    continue;
                }
                $posts = $this->buildSurgeonPosts($surgeonMissions);
                $coveredCount = count(array_filter($posts, fn (array $p) => $p['covered']));

                // Only the surgeon's own PDF is attached — the site-wide global PDF is
                // manager-only content and must not be duplicated here.
                $attachment = $this->pdfAttachment('PLANNING_SURGEON', 'pdf/planning_surgeon.html.twig', [
                    'surgeon' => $surgeon, 'missions' => $surgeonMissions, 'periodFrom' => $fromDate, 'periodTo' => $toDate,
                ], sprintf('planning-chirurgien-%s-%s-%s.pdf', strtolower(str_replace(' ', '-', $this->displayName($surgeon))), $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')),
                    PlanningDocumentIdentity::personal(PlanningDocumentIdentity::ROLE_SURGEON, $surgeon, $fromDate, $toDate, $surgeonMissions));

                $this->dispatchPlanning($surgeon, NotificationType::PLANNING_DEPLOYED_SURGEON, [
                    'periodLabel' => $this->periodLabel($fromDate, $toDate),
                    'posts'       => $posts,
                ], new EmailContent($subject, 'emails/planning_surgeon.html.twig', [
                    'surgeon'        => $surgeon,
                    'periodFrom'     => $fromDate,
                    'periodTo'       => $toDate,
                    'totalCount'     => count($posts),
                    'coveredCount'   => $coveredCount,
                    'uncoveredCount' => count($posts) - $coveredCount,
                ], attachments: $attachment !== null ? [$attachment] : []), $episode, $message, $surgeonMissions, MissionCommunicationSpec::ROLE_SURGEON);
            }

            // ── 7. Pool — H3 (D-129): the single entry point, in BATCH mode (one email /
            //    push per eligible recipient for the whole deployment, only her missions).
            $this->solicitPool($message);

            // ── 8. Manager: deployment confirmation — H5a: through the dispatcher (in-app +
            //    email per preferences, traced, idempotent per deployment). Formerly an
            //    untraced SendBillingEmailMessage; the global PDF is attached by reference.
            $assignedCount = count(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::ASSIGNED));
            $openPoolCount = count(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::OPEN));
            try {
                $this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $deployedBy,
                    type: NotificationType::PLANNING_DEPLOYED_MANAGER,
                    inAppPayload: [
                        'missionCount'  => count($missions),
                        'assignedCount' => $assignedCount,
                        'openPoolCount' => $openPoolCount,
                        'periodLabel'   => $this->periodLabel($fromDate, $toDate),
                    ],
                    // As before: the confirmation email exists only with its global PDF.
                    email: $globalPdf === null ? null : new EmailContent(
                        sprintf('Déploiement confirmé — planning %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                        'emails/planning_manager.html.twig',
                        [
                            'manager'       => $deployedBy,
                            'periodFrom'    => $fromDate,
                            'periodTo'      => $toDate,
                            'missionCount'  => count($missions),
                            'assignedCount' => $assignedCount,
                            'openPoolCount' => $openPoolCount,
                        ],
                        attachments: [$globalPdf],
                    ),
                    episodeRef: $episode,
                    subjectType: 'PlanningDeployment',
                    subjectId: $message->deploymentId,
                ));
            } catch (\Throwable $e) {
                $this->logger->error('PlanningDeployPdfs: manager confirmation failed', ['userId' => $deployedBy->getId(), 'error' => $e->getMessage()]);
            }

            $this->em->flush();

            $this->markDone($deployment);

        } catch (\Throwable $e) {
            $this->markFailed($deployment, $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }

    // ── Private — planning notifications (H4, D-130) ─────────────────────────

    /**
     * @param array<string, mixed> $payload
     * @param Mission[]            $missions the recipient's missions of this deployment
     */
    private function dispatchPlanning(User $recipient, NotificationType $type, array $payload, EmailContent $email, string $episode, PlanningDeployPdfsMessage $message, array $missions, string $role): void
    {
        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $recipient,
                type: $type,
                inAppPayload: $payload,
                email: $email,
                push: new PushContent('Planning publié', sprintf('Votre planning %s est disponible.', $payload['periodLabel'] ?? ''), ['type' => $type->value]),
                episodeRef: $episode,
                subjectType: 'PlanningDeployment',
                subjectId: $message->deploymentId,
                communicates: new MissionCommunicationSpec(
                    MissionCommunicationSource::DEPLOY, $role,
                    array_values(array_filter(array_map(static fn (Mission $m) => $m->getId(), $missions))),
                ),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('PlanningDeployPdfs: planning notification failed', [
                'type' => $type->value, 'userId' => $recipient->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /** H5a (§16) — rendered and stored once, attached by reference; a PDF failure never blocks the notification. */
    /** @param array{sourceKey: string, fingerprint: string} $identity H5b — rendered only if this exact document does not exist yet */
    private function pdfAttachment(string $documentType, string $template, array $context, string $filename, array $identity): ?DocumentReference
    {
        try {
            return $this->documents->storeOnce($documentType, $identity['sourceKey'], $identity['fingerprint'], $filename,
                fn () => $this->pdfService->generateFromTemplate($template, $context));
        } catch (\Throwable $e) {
            $this->logger->error('PlanningDeployPdfs: personal PDF failed', ['template' => $template, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // ── Private — pool (H3, D-129) ────────────────────────────────────────────

    private function solicitPool(PlanningDeployPdfsMessage $message): void
    {
        if (empty($message->openUncoveredIds)) {
            return;
        }

        $batchKey = 'deployment:' . ($message->deploymentId ?? 'legacy:' . $message->from . ':' . $message->to);
        $episodes = array_map(
            static fn (int $missionId) => OpenEpisode::forBatch($missionId, OpenEpisodeCause::DEPLOYED, $batchKey, actorId: $message->deployedById),
            array_values(array_unique(array_map('intval', $message->openUncoveredIds))),
        );

        try {
            $this->poolNotifier->notifyBatch($episodes);
        } catch (\Throwable $e) {
            // Never fail the deployment's own notifications (PDFs, planning emails) for it.
            $this->logger->error('PlanningDeployPdfs: pool solicitation failed', [
                'deploymentId' => $message->deploymentId, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Private — status tracking ─────────────────────────────────────────────

    private function markDone(?PlanningDeployment $deployment): void
    {
        if ($deployment === null) {
            return;
        }
        $deployment->setStatus(PlanningDeploymentStatus::DONE)
                   ->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function markFailed(?PlanningDeployment $deployment, string $error): void
    {
        if ($deployment === null) {
            return;
        }
        try {
            $deployment->setStatus(PlanningDeploymentStatus::FAILED)
                       ->setErrorLog(substr($error, 0, 65535));
            $this->em->flush();
        } catch (\Throwable) {}
    }

    // ── Private — notification helpers ───────────────────────────────────────

    private function buildSurgeonPosts(array $missions): array
    {
        $posts = [];
        foreach ($missions as $mission) {
            $covered = $mission->getStatus() === MissionStatus::ASSIGNED;
            $instr   = $mission->getInstrumentist();

            $uncoveredReasonLabel = null;
            if (!$covered) {
                try {
                    $uncoveredReasonLabel = $this->uncoveredReasonResolver->resolveForMission($mission)->label();
                } catch (\Throwable) {}
            }

            $posts[] = [
                'missionId'            => $mission->getId(),
                'date'                 => $mission->getStartAt()?->format('Y-m-d'),
                'dayLabel'             => $mission->getStartAt()?->format('l'),
                'siteName'             => $mission->getSite()?->getName(),
                'periodLabel'          => $mission->getStartAt() !== null
                    ? (((int) $mission->getStartAt()->format('G')) < 12 ? 'Matin' : 'Après-midi')
                    : null,
                'covered'              => $covered,
                'instrumentistName'    => $covered && $instr !== null ? $this->displayName($instr) : null,
                'uncoveredReasonLabel' => $uncoveredReasonLabel,
            ];
        }

        usort($posts, fn (array $a, array $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        return $posts;
    }


    private function periodLabel(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return sprintf('%s au %s', $from->format('d/m/Y'), $to->format('d/m/Y'));
    }

    // ── Private — helpers ─────────────────────────────────────────────────────

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
