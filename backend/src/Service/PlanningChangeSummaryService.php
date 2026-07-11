<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Message\SendBillingEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends personalized "what changed" recap emails to affected instrumentists and
 * surgeons, comparing a PlanningVersion's current state against its diff baseline.
 *
 * This is a standalone capability — NOT part of the initial deploy flow. Initial deploy
 * sends exactly one email per recipient (see PlanningDeployPdfsMessageHandler); this
 * service is for a *later* trigger, once a published planning changes (reassignment,
 * cancellation, added mission, etc.), per the "Planning Change Summary" policy: never
 * sent during initial deployment, only after. Wired up since Batch 15K by
 * PlanningModificationService, the only caller — Planning V2 Modification mode's
 * apply-modifications endpoint. See docs/api.md §26.6c.
 */
class PlanningChangeSummaryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly PlanningDiffService $diffService,
        private readonly PdfService $pdfService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {}

    /**
     * @param Mission[]            $missions         All OPEN/ASSIGNED missions in the period
     * @param array<int,Mission[]> $byInstrumentist  Missions grouped by instrumentist ID
     * @param array<int,Mission[]> $bySurgeon        Missions grouped by surgeon ID
     * @param int[]                $openUncoveredIds Mission IDs published as pool (no instrumentist)
     * @param array{added:array<array>,removed:array<array>,modified:array<array>}|null $precomputedDiff
     *        When null (default, existing behaviour): diffs $versionId against the previously
     *        ACTIVE/ARCHIVED version for the same site/period, via PlanningDiffService::diff().
     *        Pass a diff explicitly for a different baseline — e.g. Planning V2 Modification
     *        mode diffs the *same* version against a before-edit-session snapshot of itself,
     *        via PlanningDiffService::computeDiffFromSnapshots(), not diff().
     */
    public function sendChangeSummaryEmails(
        int $versionId,
        array $missions,
        array $byInstrumentist,
        array $bySurgeon,
        array $openUncoveredIds,
        ?string $globalPdf,
        ?string $globalFilename,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        ?array $precomputedDiff = null,
    ): void {
        $version = $this->em->find(PlanningVersion::class, $versionId);
        if ($version === null) {
            return;
        }

        $diff = $precomputedDiff ?? $this->diffService->diff($version);

        // Skip entirely if the planning didn't change
        if (empty($diff['added']) && empty($diff['removed']) && empty($diff['modified'])) {
            return;
        }

        $subject = sprintf('Récapitulatif planning — %s au %s',
            $fromDate->format('d/m/Y'), $toDate->format('d/m/Y'));

        // Correlates every log line produced by this single sendChangeSummaryEmails() call
        // (one per redeploy/change-summary trigger) — there is no PlanningDeployment record
        // for this flow (Modification mode mutates Missions directly, D-052), so this is the
        // closest equivalent to a "redeployment id" for log correlation.
        $batchId = bin2hex(random_bytes(8));

        // ── Per-instrumentist: only THEIR OWN diff-relevant changes ───────────
        // Gated strictly on the diff — NOT on "any open mission at my site", which would
        // notify every instrumentist who happens to share a site with an unrelated pool
        // mission (that's the existing, separate OPEN_MISSION_AVAILABLE pool-eligibility
        // notification — Batch 15C/15D — not this targeted "what changed for you" recap).
        foreach ($byInstrumentist as $instrId => $instrMissions) {
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

            if (empty($myAdded) && empty($myRemoved) && empty($myModified)) {
                continue;
            }

            $instrumentist = $this->em->find(User::class, $instrId);
            if ($instrumentist === null || !$instrumentist->getEmail()) {
                continue;
            }

            // Attach the instrumentist's own up-to-date planning (all their CURRENT missions
            // in this version, post-mutation — not just the diff) alongside the recap, same
            // template/mechanism as the initial deploy email (PlanningDeployPdfsMessageHandler).
            [$pdfBase64, $pdfFilename] = $this->buildPersonalPdfOrLog(
                'pdf/planning_instrumentist.html.twig',
                ['instrumentist' => $instrumentist, 'missions' => $instrMissions, 'periodFrom' => $fromDate, 'periodTo' => $toDate],
                sprintf('planning-%s-%s-%s.pdf', $this->slug($instrumentist), $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')),
                $versionId, $batchId, $instrId, 'instrumentist',
            );

            $this->dispatchOrLog(
                new SendBillingEmailMessage(
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
                        'uncovered'     => [],
                    ],
                    attachmentBase64: $pdfBase64,
                    attachmentFilename: $pdfFilename,
                ),
                versionId: $versionId,
                batchId: $batchId,
                recipientId: $instrId,
                recipientRole: 'instrumentist',
            );
        }

        // ── Per-surgeon: only THEIR OWN diff-relevant changes ─────────────────
        // Mirrors the instrumentist loop above: a surgeon is only notified when one of
        // THEIR OWN interventions is in the diff (added, removed, or modified — instrumentist
        // change, horaire/site/type change, cancellation). A surgeon with unrelated open
        // missions elsewhere in the version, or with no diff entry at all, receives nothing.
        foreach ($bySurgeon as $surgeonId => $surgeonMissions) {
            $myAdded = array_values(array_filter(
                $diff['added'],
                fn(array $m) => ($m['surgeonId'] ?? null) === $surgeonId,
            ));
            $myRemoved = array_values(array_filter(
                $diff['removed'],
                fn(array $m) => ($m['surgeonId'] ?? null) === $surgeonId,
            ));
            $myModified = array_values(array_filter(
                $diff['modified'],
                fn(array $entry) => ($entry['mission']['surgeonId'] ?? null) === $surgeonId,
            ));

            if (empty($myAdded) && empty($myRemoved) && empty($myModified)) {
                continue;
            }

            $surgeon = $this->em->find(User::class, $surgeonId);
            if ($surgeon === null || !$surgeon->getEmail()) {
                continue;
            }

            // Of THIS surgeon's own newly-added missions, the ones still without an
            // instrumentist are surfaced separately ("needs an instrumentist") — never a
            // pre-existing unrelated open mission from elsewhere in the version.
            $myStillUncovered = array_values(array_filter(
                $myAdded,
                fn(array $m) => ($m['instrumentistId'] ?? null) === null,
            ));

            // Attach the surgeon's own up-to-date planning (all their CURRENT missions in
            // this version, post-mutation) — same template/mechanism as the initial deploy.
            [$pdfBase64, $pdfFilename] = $this->buildPersonalPdfOrLog(
                'pdf/planning_surgeon.html.twig',
                ['surgeon' => $surgeon, 'missions' => $surgeonMissions, 'periodFrom' => $fromDate, 'periodTo' => $toDate],
                sprintf('planning-chirurgien-%s-%s-%s.pdf', $this->slug($surgeon), $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')),
                $versionId, $batchId, $surgeonId, 'surgeon',
            );

            $this->dispatchOrLog(
                new SendBillingEmailMessage(
                    to: $surgeon->getEmail(),
                    cc: [],
                    subject: $subject,
                    fromAddress: $this->fromAddress,
                    fromName: $this->fromName,
                    htmlTemplate: 'emails/planning_change_summary_surgeon.html.twig',
                    context: [
                        'surgeon'    => $surgeon,
                        'periodFrom' => $fromDate,
                        'periodTo'   => $toDate,
                        'added'      => $myAdded,
                        'removed'    => $myRemoved,
                        'modified'   => $myModified,
                        'uncovered'  => $myStillUncovered,
                    ],
                    attachmentBase64: $pdfBase64,
                    attachmentFilename: $pdfFilename,
                    extraAttachments: $globalPdf !== null
                        ? [['base64' => base64_encode($globalPdf), 'filename' => $globalFilename]]
                        : [],
                ),
                versionId: $versionId,
                batchId: $batchId,
                recipientId: $surgeonId,
                recipientRole: 'surgeon',
            );
        }
    }

    /**
     * Dispatches a change-summary email; a dispatch failure is never allowed to disappear
     * silently. The planning mutation itself is not rolled back on notification failure —
     * only the notification attempt is logged, with enough context to find and resend it.
     */
    private function dispatchOrLog(
        SendBillingEmailMessage $message,
        int $versionId,
        string $batchId,
        int $recipientId,
        string $recipientRole,
    ): void {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch planning change-summary notification', [
                'planningVersionId' => $versionId,
                'redeploymentBatchId' => $batchId,
                'recipientId'    => $recipientId,
                'recipientEmail' => $message->to,
                'recipientRole'  => $recipientRole,
                'summaryType'    => 'planning_change_summary',
                'template'       => $message->htmlTemplate,
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
                'exceptionTrace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Renders the recipient's personal up-to-date planning PDF (same templates/mechanism as
     * PlanningDeployPdfsMessageHandler's initial-deploy emails). A rendering failure must not
     * block the change-summary email itself — it's logged and the email is sent without the
     * attachment rather than lost entirely.
     *
     * @return array{0: ?string, 1: ?string} [base64Pdf, filename] — both null on failure.
     */
    private function buildPersonalPdfOrLog(
        string $template,
        array $context,
        string $filename,
        int $versionId,
        string $batchId,
        int $recipientId,
        string $recipientRole,
    ): array {
        try {
            $pdf = $this->pdfService->generateFromTemplate($template, $context);
            return [base64_encode($pdf), $filename];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate personal planning PDF for change-summary notification', [
                'planningVersionId'   => $versionId,
                'redeploymentBatchId' => $batchId,
                'recipientId'         => $recipientId,
                'recipientRole'       => $recipientRole,
                'summaryType'         => 'planning_change_summary',
                'template'            => $template,
                'exceptionClass'      => $e::class,
                'exceptionMessage'    => $e->getMessage(),
                'exceptionTrace'      => $e->getTraceAsString(),
            ]);
            return [null, null];
        }
    }

    private function slug(User $user): string
    {
        return strtolower(str_replace(' ', '-', $this->displayName($user)));
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
