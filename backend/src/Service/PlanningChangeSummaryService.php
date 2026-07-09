<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Message\SendBillingEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends personalized "what changed" recap emails to affected instrumentists and
 * surgeons, comparing a PlanningVersion's current state against its diff baseline.
 *
 * This is a standalone, on-demand capability — NOT part of the initial deploy flow.
 * Initial deploy sends exactly one email per recipient (see PlanningDeployPdfsMessageHandler);
 * this service exists for a *later* trigger, once a published planning changes
 * (reassignment, cancellation, added mission, etc.). No such trigger is wired up yet —
 * this service is kept ready and callable so that capability isn't lost, per the
 * "Planning Change Summary" policy: never sent during initial deployment, only after.
 */
class PlanningChangeSummaryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly PlanningDiffService $diffService,
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
    ): void {
        $version = $this->em->find(PlanningVersion::class, $versionId);
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

            $instrSiteIds = array_unique(array_map(
                fn(Mission $m) => $m->getSite()?->getId(),
                $instrMissions,
            ));
            $uncoveredForSite = array_values(array_filter(
                $missions,
                fn(Mission $m) =>
                    in_array($m->getId(), $openUncoveredIds, true) &&
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
        $uncoveredBySurgeon = [];
        foreach ($missions as $mission) {
            if (
                $mission->getSurgeon() !== null &&
                in_array($mission->getId(), $openUncoveredIds, true)
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
                    subject: sprintf('Missions en attente d\'affectation — planning %s au %s',
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
