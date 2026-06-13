<?php

namespace App\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningDeploymentStatus;
use App\Message\PlanningDeployPdfsMessage;
use App\Message\SendBillingEmailMessage;
use App\Service\NotificationService;
use App\Service\PdfService;
use App\Service\PlanningDiffService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles the heavy work of PDF generation and email sending after a planning deploy.
 * Runs in the Messenger worker — completely decoupled from the HTTP request.
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
 * Change summary emails:
 *   - Only sent when sendChangeSummary = true AND the planning diff is non-empty.
 *   - Per-instrumentist: changes that concern them, grouped by day + uncovered slots at their site.
 *   - Per-surgeon: list of uncovered days + global PDF attached.
 *   - These are separate emails from the planning PDF emails (steps 5/6).
 */
#[AsMessageHandler]
final class PlanningDeployPdfsMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfService $pdfService,
        private readonly NotificationService $notificationService,
        private readonly WebPushService $webPushService,
        private readonly MessageBusInterface $bus,
        private readonly PlanningDiffService $diffService,
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

            // ── 5. Per-instrumentist: personal PDF + email ────────────────────
            foreach ($byInstrumentist as $instrId => $instrMissions) {
                $instrumentist = $this->em->find(User::class, $instrId);
                if ($instrumentist === null || !$instrumentist->getEmail()) {
                    continue;
                }
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
                        context: ['instrumentist' => $instrumentist, 'periodFrom' => $fromDate, 'periodTo' => $toDate],
                        attachmentBase64: base64_encode($pdf),
                        attachmentFilename: $filename,
                    ));
                } catch (\Throwable) {}
            }

            // ── 6. Per-surgeon: personal PDF + global PDF + email ─────────────
            $sentSurgeonIds = [];
            foreach ($bySurgeon as $surgeonId => $surgeonMissions) {
                if (isset($sentSurgeonIds[$surgeonId])) {
                    continue;
                }
                $surgeon = $this->em->find(User::class, $surgeonId);
                if ($surgeon === null || !$surgeon->getEmail()) {
                    continue;
                }
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

                    $this->bus->dispatch(new SendBillingEmailMessage(
                        to: $surgeon->getEmail(),
                        cc: [],
                        subject: sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                        fromAddress: $this->fromAddress,
                        fromName: $this->fromName,
                        htmlTemplate: 'emails/planning_surgeon.html.twig',
                        context: ['surgeon' => $surgeon, 'periodFrom' => $fromDate, 'periodTo' => $toDate, 'hasGlobal' => $globalPdf !== null],
                        attachmentBase64: base64_encode($personalPdf),
                        attachmentFilename: $filename,
                        extraAttachments: $globalPdf !== null
                            ? [['base64' => base64_encode($globalPdf), 'filename' => $globalFilename]]
                            : [],
                    ));
                    $sentSurgeonIds[$surgeonId] = true;
                } catch (\Throwable) {}
            }

            // ── 7. ASSIGNED notifications + push (one push per instrumentist) ──
            /** @var array<int, array{user: User, count: int}> $assignedByInstr */
            $assignedByInstr = [];
            foreach ($missions as $mission) {
                if ($mission->getInstrumentist() !== null && $mission->getStatus() === MissionStatus::ASSIGNED) {
                    try {
                        $this->notificationService->planningMissionAssignedNotifyInstrumentist($mission);
                    } catch (\Throwable) {}
                    $instr = $mission->getInstrumentist();
                    $id    = $instr->getId();
                    $assignedByInstr[$id] = [
                        'user'  => $instr,
                        'count' => ($assignedByInstr[$id]['count'] ?? 0) + 1,
                    ];
                }
            }
            foreach ($assignedByInstr as ['user' => $instr, 'count' => $count]) {
                try {
                    $body = $count === 1
                        ? 'Vous avez été assigné(e) à 1 mission.'
                        : "Vous avez été assigné(e) à {$count} missions.";
                    $this->webPushService->sendToUser($instr, 'Planning mis à jour', $body, [
                        'type' => 'PLANNING_MISSION_ASSIGNED',
                    ]);
                } catch (\Throwable) {}
            }

            // ── 8. Pool notifications — only for openUncoveredIds ─────────────
            $this->sendPoolNotifications($message, $missions);

            // ── 9. Manager notification ───────────────────────────────────────
            try {
                $this->notificationService->planningDeployedNotifyManager(
                    $deployedBy, count($missions), $message->from, $message->to
                );
            } catch (\Throwable) {}

            $this->em->flush();

            // ── 10. Change summary emails (async, only when requested) ────────
            $this->sendChangeSummaryEmails(
                $message, $missions, $byInstrumentist, $bySurgeon,
                $globalPdf, $globalFilename, $fromDate, $toDate,
            );

            $this->markDone($deployment);

        } catch (\Throwable $e) {
            $this->markFailed($deployment, $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }

    // ── Private — change summary ──────────────────────────────────────────────

    /**
     * Sends personalized diff recap emails to affected instrumentists and surgeons.
     * Only executed when sendChangeSummary = true AND the diff is non-empty.
     * Does NOT re-send the planning PDF emails (those are steps 5/6).
     *
     * @param Mission[]            $missions        All OPEN/ASSIGNED missions already loaded
     * @param array<int,Mission[]> $byInstrumentist Missions grouped by instrumentist ID
     * @param array<int,Mission[]> $bySurgeon       Missions grouped by surgeon ID
     */
    private function sendChangeSummaryEmails(
        PlanningDeployPdfsMessage $message,
        array $missions,
        array $byInstrumentist,
        array $bySurgeon,
        ?string $globalPdf,
        ?string $globalFilename,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
    ): void {
        if (!$message->sendChangeSummary || $message->versionId === null) {
            return;
        }

        $version = $this->em->find(PlanningVersion::class, $message->versionId);
        if ($version === null) {
            return;
        }

        $diff = $this->diffService->diff($version);

        // Skip entirely if the planning didn't change
        if (empty($diff['added']) && empty($diff['removed']) && empty($diff['modified'])) {
            return;
        }

        $subject = sprintf('Récapitulatif planning — %s au %s',
            $fromDate->format('d/m/Y'), $toDate->format('d/m/Y'));

        // ── Per-instrumentist: their changes + uncovered slots at their site ──
        foreach ($byInstrumentist as $instrId => $instrMissions) {
            $instrumentist = $this->em->find(User::class, $instrId);
            if ($instrumentist === null || !$instrumentist->getEmail()) {
                continue;
            }

            // Filter diff entries that concern this instrumentist (by ID)
            $myAdded = array_values(array_filter(
                $diff['added'],
                fn(array $m) => ($m['instrumentistId'] ?? null) === $instrId,
            ));
            $myRemoved = array_values(array_filter(
                $diff['removed'],
                fn(array $m) => ($m['instrumentistId'] ?? null) === $instrId,
            ));
            $myModified = array_values(array_filter(
                $diff['modified'],
                fn(array $entry) =>
                    ($entry['mission']['instrumentistId'] ?? null) === $instrId ||
                    ($entry['changes']['instrumentist']['from']['id'] ?? null) === $instrId ||
                    ($entry['changes']['instrumentist']['to']['id'] ?? null) === $instrId,
            ));

            // Uncovered pool slots at the instrumentist's site(s)
            $instrSiteIds = array_unique(array_map(
                fn(Mission $m) => $m->getSite()?->getId(),
                $instrMissions,
            ));
            $uncoveredForSite = array_values(array_filter(
                $missions,
                fn(Mission $m) =>
                    in_array($m->getId(), $message->openUncoveredIds, true) &&
                    in_array($m->getSite()?->getId(), $instrSiteIds, true),
            ));

            if (empty($myAdded) && empty($myRemoved) && empty($myModified) && empty($uncoveredForSite)) {
                continue;
            }

            try {
                $this->bus->dispatch(new SendBillingEmailMessage(
                    to: $instrumentist->getEmail(),
                    cc: [],
                    subject: $subject,
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: 'emails/planning_change_summary_instrumentist.html.twig',
                    context: [
                        'instrumentist' => $instrumentist,
                        'periodFrom'    => $fromDate,
                        'periodTo'      => $toDate,
                        'added'         => $myAdded,
                        'removed'       => $myRemoved,
                        'modified'      => $myModified,
                        'uncovered'     => array_values(array_map(
                            fn(Mission $m) => $this->serializeUncoveredMission($m),
                            $uncoveredForSite,
                        )),
                    ],
                ));
            } catch (\Throwable) {}
        }

        // ── Per-surgeon: only THEIR OWN uncovered slots + global PDF ─────────
        // Group pool missions by the surgeon they belong to.
        // Each surgeon receives only their own uncovered slots — never another surgeon's.
        $uncoveredBySurgeon = [];
        foreach ($missions as $mission) {
            if (
                $mission->getSurgeon() !== null &&
                in_array($mission->getId(), $message->openUncoveredIds, true)
            ) {
                $uncoveredBySurgeon[$mission->getSurgeon()->getId()][] = $mission;
            }
        }

        if (empty($uncoveredBySurgeon)) {
            return;
        }

        $sentSurgeonIds = [];
        foreach ($bySurgeon as $surgeonId => $_surgeonMissions) {
            if (isset($sentSurgeonIds[$surgeonId])) {
                continue;
            }

            // Skip surgeons who have no uncovered slots in this deploy
            $mySurgeonUncovered = $uncoveredBySurgeon[$surgeonId] ?? [];
            if (empty($mySurgeonUncovered)) {
                continue;
            }

            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null || !$surgeon->getEmail()) {
                continue;
            }

            try {
                $this->bus->dispatch(new SendBillingEmailMessage(
                    to: $surgeon->getEmail(),
                    cc: [],
                    subject: sprintf('Postes non couverts — planning %s au %s',
                        $fromDate->format('d/m/Y'), $toDate->format('d/m/Y')),
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: 'emails/planning_change_summary_surgeon.html.twig',
                    context: [
                        'surgeon'    => $surgeon,
                        'periodFrom' => $fromDate,
                        'periodTo'   => $toDate,
                        'uncovered'  => array_values(array_map(
                            fn(Mission $m) => $this->serializeUncoveredMission($m),
                            $mySurgeonUncovered,
                        )),
                    ],
                    extraAttachments: $globalPdf !== null
                        ? [['base64' => base64_encode($globalPdf), 'filename' => $globalFilename]]
                        : [],
                ));
                $sentSurgeonIds[$surgeonId] = true;
            } catch (\Throwable) {}
        }
    }

    // ── Private — pool notifications ──────────────────────────────────────────

    private function sendPoolNotifications(PlanningDeployPdfsMessage $message, array $missions): void
    {
        if (empty($message->openUncoveredIds)) {
            return;
        }

        $openBySite = [];
        foreach ($missions as $mission) {
            if (
                $mission->getSite() !== null &&
                in_array($mission->getId(), $message->openUncoveredIds, true)
            ) {
                $openBySite[$mission->getSite()->getId()][] = $mission;
            }
        }

        foreach ($openBySite as $siteId => $siteMissions) {
            $site = $siteMissions[0]->getSite();
            try {
                $siteInstrumentists = $this->em->createQuery(
                    'SELECT u FROM App\Entity\User u
                     JOIN u.siteMemberships sm
                     WHERE sm.site = :siteId AND u.active = true AND u.roles LIKE :role'
                )
                    ->setParameter('siteId', $siteId)
                    ->setParameter('role', '%ROLE_INSTRUMENTIST%')
                    ->getResult();

                $this->notificationService->planningNewOpenMissionsNotifySite(
                    $siteInstrumentists,
                    count($siteMissions),
                    $site->getName(),
                    $message->from,
                    $message->to,
                );

                $count = count($siteMissions);
                $pushBody = $count === 1
                    ? "1 nouvelle mission disponible à {$site->getName()}."
                    : "{$count} nouvelles missions disponibles à {$site->getName()}.";
                $this->webPushService->sendToUsers($siteInstrumentists, 'Nouvelles missions disponibles', $pushBody, [
                    'type' => 'PLANNING_OPEN_MISSIONS_AVAILABLE',
                ]);
            } catch (\Throwable) {}
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

    // ── Private — helpers ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function serializeUncoveredMission(Mission $m): array
    {
        return [
            'date'        => $m->getStartAt()?->format('Y-m-d'),
            'period'      => ((int) $m->getStartAt()?->format('G')) < 12 ? 'AM' : 'PM',
            'startAt'     => $m->getStartAt()?->format('H:i'),
            'endAt'       => $m->getEndAt()?->format('H:i'),
            'surgeonName' => $this->displayName($m->getSurgeon()),
            'siteName'    => $m->getSite()?->getName(),
        ];
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
