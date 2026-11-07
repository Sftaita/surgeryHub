<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Notification\DocumentReference;
use App\Notification\DocumentStore;
use App\Notification\PlanningDocumentIdentity;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\MissionCommunicationSpec;
use App\Notification\NotificationIntent;
use App\Enum\MissionCommunicationSource;
use App\Notification\PushContent;
use App\Twig\DateExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Personalized "what changed" recap of a Planning V2 Modification batch, one per affected
 * instrumentist / surgeon (only THEIR OWN changes), with their up-to-date planning PDF.
 * Never sent during an initial deployment (PlanningDeployPdfsMessageHandler sends the
 * planning itself), only after a published planning changes — see docs/api.md §26.6c.
 *
 * H3 (D-129): no longer an opaque SendBillingEmailMessage path. The recap is a
 * PLANNING_CHANGE_SUMMARY notification sent through NotificationDispatcher — decision in
 * notification_dispatch, in-app / email / push per preferences, each channel traced in
 * outbound_notification, idempotent per batch — and it runs asynchronously, from
 * PlanningModificationAppliedMessageHandler, only once the whole batch has committed.
 * The recap IS the information of the people whose planning changed (an instrumentist the
 * batch removed from a mission included): no second per-mission notification is sent to
 * them for the same batch.
 *
 * recipients() is pure: the synchronous apply() uses it to report, before anything is
 * sent, exactly who will be informed.
 */
class PlanningChangeSummaryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfService $pdfService,
        private readonly LoggerInterface $logger,
        private readonly DateExtension $dateExtension,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly NotificationTargetResolver $targetResolver,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
        private readonly DocumentStore $documents,
    ) {}

    /**
     * Who is affected by $diff: every instrumentist / surgeon with at least one of THEIR OWN
     * missions added, removed or modified (an instrumentist moved away from a mission
     * included, even if she has no mission left in the scope).
     *
     * @param array{added: array<array>, removed: array<array>, modified: array<array>} $diff
     * @return array{instrumentists: list<int>, surgeons: list<int>}
     */
    public function recipients(array $diff): array
    {
        $instr = [];
        $surg  = [];
        foreach (['added', 'removed'] as $key) {
            foreach ($diff[$key] ?? [] as $m) {
                if (($m['instrumentistId'] ?? null) !== null) {
                    $instr[(int) $m['instrumentistId']] = true;
                }
                if (($m['surgeonId'] ?? null) !== null) {
                    $surg[(int) $m['surgeonId']] = true;
                }
            }
        }
        foreach ($diff['modified'] ?? [] as $entry) {
            foreach ([$entry['mission']['instrumentistId'] ?? null, $entry['changes']['instrumentist']['from']['id'] ?? null, $entry['changes']['instrumentist']['to']['id'] ?? null] as $iid) {
                if ($iid !== null) {
                    $instr[(int) $iid] = true;
                }
            }
            if (($entry['mission']['surgeonId'] ?? null) !== null) {
                $surg[(int) $entry['mission']['surgeonId']] = true;
            }
        }

        return ['instrumentists' => array_keys($instr), 'surgeons' => array_keys($surg)];
    }

    /**
     * Sends one PLANNING_CHANGE_SUMMARY per affected person through the dispatcher.
     *
     * @param array{added: array<array>, removed: array<array>, modified: array<array>} $diff
     * @param Mission[] $scopeMissions every current mission of the version's operational scope (post-batch)
     * @return list<int> user ids a recap was dispatched to
     */
    public function dispatchSummaries(PlanningVersion $version, array $diff, array $scopeMissions, string $batchKey): array
    {
        if (empty($diff['added']) && empty($diff['removed']) && empty($diff['modified'])) {
            return [];
        }

        $fromDate = $version->getPeriodStart();
        $toDate   = $version->getPeriodEnd();
        $period   = sprintf('%s %s', $this->dateExtension->frenchMonthLong($fromDate), $fromDate->format('Y'));
        // Deliberately distinct from the initial-deploy subject ("Planning du {from} au {to}",
        // D-058): this is an after-edit recap and the subject says so upfront.
        $subject  = sprintf('Modification de votre planning – %s', $period);

        $byInstrumentist = [];
        $bySurgeon       = [];
        foreach ($scopeMissions as $mission) {
            if ($mission->getInstrumentist() !== null) {
                $byInstrumentist[$mission->getInstrumentist()->getId()][] = $mission;
            }
            if ($mission->getSurgeon() !== null) {
                $bySurgeon[$mission->getSurgeon()->getId()][] = $mission;
            }
        }

        $recipients = $this->recipients($diff);
        $notified   = [];

        foreach ($recipients['instrumentists'] as $instrId) {
            $mine = $this->changesOf($diff, static fn (array $m) => ($m['instrumentistId'] ?? null) === $instrId,
                static fn (array $e) => ($e['mission']['instrumentistId'] ?? null) === $instrId
                    || ($e['changes']['instrumentist']['from']['id'] ?? null) === $instrId
                    || ($e['changes']['instrumentist']['to']['id'] ?? null) === $instrId);
            $instrumentist = $this->em->find(User::class, $instrId);
            if ($instrumentist === null || $mine === null) {
                continue;
            }

            $attachment = $this->pdfOrNull('pdf/planning_instrumentist.html.twig',
                ['instrumentist' => $instrumentist, 'missions' => $byInstrumentist[$instrId] ?? [], 'periodFrom' => $fromDate, 'periodTo' => $toDate],
                sprintf('planning-%s-%s-%s.pdf', $this->slug($instrumentist), $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')), $version, $instrId);

            $this->dispatch($instrumentist, $version, $batchKey, $period, $subject, 'emails/planning_change_summary_instrumentist.html.twig', [
                'instrumentist' => $instrumentist,
                'periodFrom'    => $fromDate,
                'periodTo'      => $toDate,
                'added'         => $mine['added'],
                'removed'       => $mine['removed'],
                'modified'      => $mine['modified'],
                'uncovered'     => [],
            ], $mine, $attachment, self::communicated($mine, 'instrumentistId', $instrId, MissionCommunicationSpec::ROLE_INSTRUMENTIST));
            $notified[] = $instrId;
        }

        foreach ($recipients['surgeons'] as $surgeonId) {
            $mine = $this->changesOf($diff, static fn (array $m) => ($m['surgeonId'] ?? null) === $surgeonId,
                static fn (array $e) => ($e['mission']['surgeonId'] ?? null) === $surgeonId);
            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null || $mine === null) {
                continue;
            }

            $attachment = $this->pdfOrNull('pdf/planning_surgeon.html.twig',
                ['surgeon' => $surgeon, 'missions' => $bySurgeon[$surgeonId] ?? [], 'periodFrom' => $fromDate, 'periodTo' => $toDate],
                sprintf('planning-chirurgien-%s-%s-%s.pdf', $this->slug($surgeon), $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')), $version, $surgeonId);

            $this->dispatch($surgeon, $version, $batchKey, $period, $subject, 'emails/planning_change_summary_surgeon.html.twig', [
                'surgeon'    => $surgeon,
                'periodFrom' => $fromDate,
                'periodTo'   => $toDate,
                'added'      => $mine['added'],
                'removed'    => $mine['removed'],
                'modified'   => $mine['modified'],
                // Of THIS surgeon's own newly-added missions, the ones still uncovered.
                'uncovered'  => array_values(array_filter($mine['added'], static fn (array $m) => ($m['instrumentistId'] ?? null) === null)),
            ], $mine, $attachment, self::communicated($mine, 'surgeonId', $surgeonId, MissionCommunicationSpec::ROLE_SURGEON));
            $notified[] = $surgeonId;
        }

        return $notified;
    }

    /**
     * @param array{added: array<array>, removed: array<array>, modified: array<array>} $diff
     * @return array{added: list<array>, removed: list<array>, modified: list<array>}|null null when nothing concerns this person
     */
    private function changesOf(array $diff, callable $ownsMission, callable $ownsModification): ?array
    {
        $mine = [
            'added'    => array_values(array_filter($diff['added'] ?? [], $ownsMission)),
            'removed'  => array_values(array_filter($diff['removed'] ?? [], $ownsMission)),
            'modified' => array_values(array_filter($diff['modified'] ?? [], $ownsModification)),
        ];

        return ($mine['added'] === [] && $mine['removed'] === [] && $mine['modified'] === []) ? null : $mine;
    }

    /**
     * @param array<string, mixed>                                                    $context
     * @param array{added: list<array>, removed: list<array>, modified: list<array>} $mine
     */
    private function dispatch(User $recipient, PlanningVersion $version, string $batchKey, string $period, string $subject, string $template, array $context, array $mine, ?DocumentReference $attachment, ?MissionCommunicationSpec $communicates = null): void
    {
        $changes = count($mine['added']) + count($mine['removed']) + count($mine['modified']);
        $path    = $this->targetResolver->resolve(NotificationType::PLANNING_CHANGE_SUMMARY, null, $recipient);

        try {
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $recipient,
                type: NotificationType::PLANNING_CHANGE_SUMMARY,
                inAppPayload: [
                    'planningVersionId' => $version->getId(),
                    'periodLabel'       => $period,
                    'addedCount'        => count($mine['added']),
                    'removedCount'      => count($mine['removed']),
                    'modifiedCount'     => count($mine['modified']),
                ],
                email: new EmailContent(
                    subject: $subject,
                    htmlTemplate: $template,
                    context: $context + [
                        'missionUrl' => $path !== null ? rtrim($this->frontendUrl, '/') . $path : null,
                        'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
                    ],
                    attachments: $attachment !== null ? [$attachment] : [],
                ),
                push: new PushContent(
                    'Votre planning a été modifié',
                    sprintf('%d changement(s) sur votre planning de %s.', $changes, $period),
                    ['type' => 'PLANNING_CHANGE_SUMMARY', 'url' => $path],
                ),
                episodeRef: 'planning_modification:' . $batchKey,
                subjectType: 'PlanningVersion',
                subjectId: $version->getId(),
                communicates: $communicates,
            ));
        } catch (\Throwable $e) {
            // One recipient's failure never prevents the next one (logged, never silent).
            $this->logger->error('Planning change-summary dispatch failed', [
                'planningVersionId' => $version->getId(), 'batchKey' => $batchKey,
                'recipientId' => $recipient->getId(), 'exceptionClass' => $e::class, 'exceptionMessage' => $e->getMessage(),
            ]);
        }
    }

    /**
     * H4 (D-130) — the missions this recap presents as the recipient's own after the batch
     * (added to her, or modified while she still holds them) — counted as communicated
     * (MODIFICATION) once a channel reaches her. Removed ones are withdrawals, not this.
     *
     * @param array{added: list<array>, removed: list<array>, modified: list<array>} $mine
     */
    private static function communicated(array $mine, string $key, int $userId, string $role): ?MissionCommunicationSpec
    {
        $ids = [];
        foreach ($mine['added'] as $m) {
            if (($m[$key] ?? null) === $userId && ($m['missionId'] ?? null) !== null) {
                $ids[] = (int) $m['missionId'];
            }
        }
        foreach ($mine['modified'] as $e) {
            if (($e['mission'][$key] ?? null) === $userId && ($e['mission']['missionId'] ?? null) !== null) {
                $ids[] = (int) $e['mission']['missionId'];
            }
        }

        return $ids === [] ? null : new MissionCommunicationSpec(MissionCommunicationSource::MODIFICATION, $role, array_values(array_unique($ids)));
    }

    /**
     * The recipient's up-to-date planning PDF. A rendering failure never blocks the recap:
     * logged, and the email goes without the attachment. H5a (§16): stored once
     * (GeneratedDocument), attached by reference — never bytes in a message or a record.
     */
    private function pdfOrNull(string $template, array $context, string $filename, PlanningVersion $version, int $recipientId): ?DocumentReference
    {
        try {
            $isSurgeon = str_contains($template, 'surgeon');
            // H5b (D-132) — the up-to-date personal planning: the same identity as the deploy /
            // resend one, so an unchanged planning is never rendered (and sent) as a new document.
            $identity = PlanningDocumentIdentity::personal(
                $isSurgeon ? PlanningDocumentIdentity::ROLE_SURGEON : PlanningDocumentIdentity::ROLE_INSTRUMENTIST,
                $isSurgeon ? $context['surgeon'] : $context['instrumentist'], $context['periodFrom'], $context['periodTo'], $context['missions'],
            );

            return $this->documents->storeOnce($isSurgeon ? 'PLANNING_SURGEON' : 'PLANNING_INSTRUMENTIST', $identity['sourceKey'], $identity['fingerprint'],
                $filename, fn () => $this->pdfService->generateFromTemplate($template, $context));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate personal planning PDF for change-summary notification', [
                'planningVersionId' => $version->getId(), 'recipientId' => $recipientId, 'template' => $template,
                'exceptionClass' => $e::class, 'exceptionMessage' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function slug(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return strtolower(str_replace(' ', '-', $name !== '' ? $name : 'utilisateur-' . $user->getId()));
    }
}
