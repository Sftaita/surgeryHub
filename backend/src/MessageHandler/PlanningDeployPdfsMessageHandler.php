<?php

namespace App\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningDeployment;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Enum\PlanningDeploymentStatus;
use App\Enum\PublicationChannel;
use App\Message\PlanningDeployPdfsMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\MissionEligibilityService;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\PdfService;
use App\Service\UncoveredReasonResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles the heavy work of PDF generation, email sending, and in-app notifications
 * after a planning deploy. Runs in the Messenger worker — completely decoupled from
 * the HTTP request.
 *
 * Idempotence (V1):
 *   - Checks PlanningDeployment.status == DONE at entry → returns immediately if already processed.
 *   - Sets PROCESSING before starting work; DONE on success; FAILED on unrecoverable error.
 *   - A crash between PROCESSING and DONE causes a retry that re-executes the full handler.
 *     Some emails/notifications may be sent twice on crash-retry (V1 accepted limit).
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
 * Notification preferences:
 *   - Every email and in-app notification is gated through NotificationPreferenceResolver.
 *   - Push notifications for pool missions are kept as-is (not gated, existing behavior).
 */
#[AsMessageHandler]
final class PlanningDeployPdfsMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly PdfService                  $pdfService,
        private readonly NotificationService         $notificationService,
        private readonly WebPushServiceInterface     $webPushService,
        private readonly MessageBusInterface         $bus,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly UncoveredReasonResolver        $uncoveredReasonResolver,
        private readonly MissionEligibilityService      $eligibilityService,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
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

            // ── 4. Generate global PDF once (attached to surgeon emails) ──────
            $globalPdf      = null;
            $globalFilename = null;
            if (!empty($bySurgeon)) {
                try {
                    $globalPdf = $this->pdfService->generateFromTemplate('pdf/planning_global.html.twig', [
                        'missions'   => $missions,
                        'periodFrom' => $fromDate,
                        'periodTo'   => $toDate,
                    ]);
                    $globalFilename = sprintf('planning-global-%s-%s.pdf',
                        $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
                } catch (\Throwable) {}
            }

            // ── 5. Per-instrumentist: personal PDF + email + in-app notification
            foreach ($byInstrumentist as $instrId => $instrMissions) {
                $instrumentist = $this->em->find(User::class, $instrId);
                if ($instrumentist === null) {
                    continue;
                }

                $channels = $this->resolveChannelsSafely($instrumentist, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST);

                if ($channels->email && $instrumentist->getEmail()) {
                    try {
                        $pdf = $this->pdfService->generateFromTemplate('pdf/planning_instrumentist.html.twig', [
                            'instrumentist' => $instrumentist,
                            'missions'      => $instrMissions,
                            'periodFrom'    => $fromDate,
                            'periodTo'      => $toDate,
                        ]);
                        $name     = $this->displayName($instrumentist);
                        $filename = sprintf('planning-%s-%s-%s.pdf',
                            strtolower(str_replace(' ', '-', $name)),
                            $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));

                        $this->bus->dispatch(new SendBillingEmailMessage(
                            to: $instrumentist->getEmail(),
                            cc: [],
                            subject: sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                            fromAddress: $this->fromAddress,
                            fromName: $this->fromName,
                            htmlTemplate: 'emails/planning_instrumentist.html.twig',
                            context: [
                                'instrumentist' => $instrumentist,
                                'periodFrom'    => $fromDate,
                                'periodTo'      => $toDate,
                                'missionCount'  => count($instrMissions),
                            ],
                            attachmentBase64: base64_encode($pdf),
                            attachmentFilename: $filename,
                        ));
                    } catch (\Throwable) {}
                }

                if ($channels->inApp) {
                    try {
                        $this->createNotificationEvent($instrumentist, NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, [
                            'periodLabel'  => $this->periodLabel($fromDate, $toDate),
                            'missionCount' => count($instrMissions),
                            'deployedAt'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        ]);
                    } catch (\Throwable) {}
                }
            }

            // ── 6. Per-surgeon: personal PDF + global PDF + email + in-app notification
            $sentSurgeonIds = [];
            foreach ($bySurgeon as $surgeonId => $surgeonMissions) {
                if (isset($sentSurgeonIds[$surgeonId])) {
                    continue;
                }
                $surgeon = $this->em->find(User::class, $surgeonId);
                if ($surgeon === null) {
                    continue;
                }

                $channels = $this->resolveChannelsSafely($surgeon, NotificationType::PLANNING_DEPLOYED_SURGEON);
                $posts    = $this->buildSurgeonPosts($surgeonMissions);

                if ($channels->email && $surgeon->getEmail()) {
                    try {
                        $personalPdf = $this->pdfService->generateFromTemplate('pdf/planning_surgeon.html.twig', [
                            'surgeon'    => $surgeon,
                            'missions'   => $surgeonMissions,
                            'periodFrom' => $fromDate,
                            'periodTo'   => $toDate,
                        ]);
                        $name     = $this->displayName($surgeon);
                        $filename = sprintf('planning-chirurgien-%s-%s-%s.pdf',
                            strtolower(str_replace(' ', '-', $name)),
                            $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));

                        $coveredCount = count(array_filter($posts, fn (array $p) => $p['covered']));

                        $this->bus->dispatch(new SendBillingEmailMessage(
                            to: $surgeon->getEmail(),
                            cc: [],
                            subject: sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                            fromAddress: $this->fromAddress,
                            fromName: $this->fromName,
                            htmlTemplate: 'emails/planning_surgeon.html.twig',
                            context: [
                                'surgeon'        => $surgeon,
                                'periodFrom'     => $fromDate,
                                'periodTo'       => $toDate,
                                'totalCount'     => count($posts),
                                'coveredCount'   => $coveredCount,
                                'uncoveredCount' => count($posts) - $coveredCount,
                            ],
                            // Only the surgeon's own PDF is attached — the site-wide global
                            // PDF is manager-only content and must not be duplicated here.
                            attachmentBase64: base64_encode($personalPdf),
                            attachmentFilename: $filename,
                        ));
                        $sentSurgeonIds[$surgeonId] = true;
                    } catch (\Throwable) {}
                }

                if ($channels->inApp) {
                    try {
                        $this->createNotificationEvent($surgeon, NotificationType::PLANNING_DEPLOYED_SURGEON, [
                            'periodLabel' => $this->periodLabel($fromDate, $toDate),
                            'posts'       => $posts,
                        ]);
                    } catch (\Throwable) {}
                }
            }

            // ── 7. Pool notifications — only for openUncoveredIds ─────────────
            $this->sendPoolNotifications($message, $missions, $fromDate, $toDate);

            // ── 8. Manager: deployment confirmation email + in-app notification ──
            try {
                $mgrChannels   = $this->resolveChannelsSafely($deployedBy, NotificationType::PLANNING_DEPLOYED_MANAGER);
                $assignedCount = count(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::ASSIGNED));
                $openPoolCount = count(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::OPEN));

                if ($mgrChannels->email && $deployedBy->getEmail() && $globalPdf !== null) {
                    try {
                        $this->bus->dispatch(new SendBillingEmailMessage(
                            to: $deployedBy->getEmail(),
                            cc: [],
                            subject: sprintf('Déploiement confirmé — planning %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                            fromAddress: $this->fromAddress,
                            fromName: $this->fromName,
                            htmlTemplate: 'emails/planning_manager.html.twig',
                            context: [
                                'manager'       => $deployedBy,
                                'periodFrom'    => $fromDate,
                                'periodTo'      => $toDate,
                                'missionCount'  => count($missions),
                                'assignedCount' => $assignedCount,
                                'openPoolCount' => $openPoolCount,
                            ],
                            attachmentBase64: base64_encode($globalPdf),
                            attachmentFilename: $globalFilename,
                        ));
                    } catch (\Throwable) {}
                }

                if ($mgrChannels->inApp) {
                    $this->createNotificationEvent($deployedBy, NotificationType::PLANNING_DEPLOYED_MANAGER, [
                        'missionCount'  => count($missions),
                        'assignedCount' => $assignedCount,
                        'openPoolCount' => $openPoolCount,
                        'periodLabel'   => $this->periodLabel($fromDate, $toDate),
                    ]);
                }
            } catch (\Throwable) {}

            $this->em->flush();

            $this->markDone($deployment);

        } catch (\Throwable $e) {
            $this->markFailed($deployment, $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }

    // ── Private — pool notifications ──────────────────────────────────────────

    private function sendPoolNotifications(
        PlanningDeployPdfsMessage $message,
        array $missions,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
    ): void {
        if (empty($message->openUncoveredIds)) {
            return;
        }

        // Filter to only the open missions selected for pool notification
        $openMissions = array_values(array_filter(
            $missions,
            fn (Mission $m) => in_array($m->getId(), $message->openUncoveredIds, true),
        ));

        if (empty($openMissions)) {
            return;
        }

        // Batch-resolve eligible instrumentists (≤ 3 queries, D-036)
        try {
            $eligibleBySiteId = $this->eligibilityService->findEligible($openMissions);
        } catch (\Throwable) {
            $eligibleBySiteId = [];
        }

        // Group open missions by site for per-site notifications
        $openBySite = [];
        foreach ($openMissions as $m) {
            $siteId = $m->getSite()?->getId();
            if ($siteId !== null) {
                $openBySite[$siteId][] = $m;
            }
        }

        $periodLabel = $this->periodLabel($fromDate, $toDate);

        foreach ($openBySite as $siteId => $siteMissions) {
            $site         = $siteMissions[0]->getSite();
            $count        = count($siteMissions);
            $eligibleUsers = $eligibleBySiteId[$siteId] ?? [];

            if (empty($eligibleUsers)) {
                continue;
            }

            $openMissionIds = array_map(fn (Mission $m) => $m->getId(), $siteMissions);

            foreach ($eligibleUsers as $instrumentist) {
                try {
                    $ch = $this->resolveChannelsSafely($instrumentist, NotificationType::OPEN_MISSION_AVAILABLE);
                    if ($ch->inApp) {
                        $this->createNotificationEvent($instrumentist, NotificationType::OPEN_MISSION_AVAILABLE, [
                            'openMissionIds' => $openMissionIds,
                            'missionCount'   => $count,
                            'siteName'       => $site->getName(),
                            'periodLabel'    => $periodLabel,
                        ]);
                    }
                } catch (\Throwable) {}
            }

            $pushBody = $count === 1
                ? "1 nouvelle mission disponible à {$site->getName()}."
                : "{$count} nouvelles missions disponibles à {$site->getName()}.";
            $this->webPushService->sendToUsers($eligibleUsers, 'Nouvelles missions disponibles', $pushBody, [
                'type' => 'PLANNING_OPEN_MISSIONS_AVAILABLE',
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

    private function resolveChannelsSafely(User $user, NotificationType $type): NotificationChannels
    {
        try {
            return $this->preferenceResolver->resolve($user, $type);
        } catch (\Throwable) {
            return new NotificationChannels(inApp: true, email: true, push: false);
        }
    }

    private function createNotificationEvent(User $user, NotificationType $type, array $payload): void
    {
        $evt = (new NotificationEvent())
            ->setUser($user)
            ->setEventType($type->value)
            ->setChannel(PublicationChannel::IN_APP)
            ->setSentAt(new \DateTimeImmutable())
            ->setPayload($payload);
        $this->em->persist($evt);
    }

    /** @param Mission[] $missions */
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
