<?php

namespace App\Notification;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\ActivityEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Service\MissionEligibilityService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * H3 (D-129) — THE ONLY entry point that solicits the instrumentist pool about a real
 * OPEN mission (OPEN_MISSION_AVAILABLE). Every path that opens a mission — release,
 * instrumentist absence, deployment, pool publication, room take-over, Modification
 * batch, restoration — builds OpenEpisode(s) and calls this service; none of them owns a
 * pool implementation any more (guarded by tests/Architecture/OpenMissionPoolSingleEntryTest).
 *
 *   OpenEpisode(s)
 *     → reload the missions (a mission no longer OPEN / already taken solicits nobody)
 *     → MissionEligibilityService::findEligibleByMission()  (missionId → who can take IT)
 *     → contextual exclusions of the episode (e.g. the person a manager just removed)
 *     → OPEN_POOL_EVALUATED in activity_event (eligible / excluded / solicited counts,
 *        zero candidates made explicit)
 *     → NotificationDispatcher: ISOLATED (one opening) or BATCH (one email + one push per
 *        recipient for the whole operation, listing only HER missions)
 *
 * Channels are never decided here: NotificationPreferenceResolver (OPEN_MISSION_AVAILABLE
 * defaults in-app / email / push on) decides through the dispatcher. Idempotent: the
 * dispatch key includes the episodeRef, so replaying an episode sends nothing new.
 * Content: date, time, period, site, surgeon, type, offers link — no patient, no money.
 */
class OpenMissionPoolNotifier
{
    private const WEEKDAYS = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEligibilityService $eligibility,
        private readonly NotificationDispatcher $dispatcher,
        private readonly ActivityJournal $journal,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    /** One isolated opening: one email per solicited person (if her preferences allow). */
    public function notify(OpenEpisode $episode): OpenPoolResult
    {
        return $this->run([$episode], batch: false)[0];
    }

    /**
     * Several openings of ONE operation (deployment, Modification batch…): in-app per
     * mission, but exactly one email and one push per recipient, listing only the missions
     * she can take.
     *
     * @param list<OpenEpisode> $episodes
     * @return list<OpenPoolResult>
     */
    public function notifyBatch(array $episodes): array
    {
        return $episodes === [] ? [] : $this->run($episodes, batch: true);
    }

    /**
     * @param list<OpenEpisode> $episodes
     * @return list<OpenPoolResult>
     */
    private function run(array $episodes, bool $batch): array
    {
        $ids = array_values(array_unique(array_map(static fn (OpenEpisode $e) => $e->missionId, $episodes)));
        /** @var array<int, Mission> $missions */
        $missions = [];
        foreach ($this->em->getRepository(Mission::class)->findBy(['id' => $ids]) as $m) {
            $missions[$m->getId()] = $m;
        }

        $eligibleByMission = $this->eligibility->findEligibleByMission(array_values($missions));
        $excludedUsers     = $this->loadExcludedUsers($episodes);

        $intents = [];
        $results = [];
        foreach ($episodes as $episode) {
            $mission  = $missions[$episode->missionId] ?? null;
            $isOpen   = $mission !== null && $mission->getStatus() === MissionStatus::OPEN && $mission->getInstrumentist() === null;
            $eligible = $isOpen ? ($eligibleByMission[$episode->missionId] ?? []) : [];

            $solicited = [];
            $excluded  = [];
            $eligibleIds = array_map(static fn (User $u) => $u->getId(), $eligible);
            foreach ($episode->excluded as $userId => $reason) {
                $excluded[] = [
                    'userId'      => $userId,
                    'userName'    => ActivityJournal::displayName($excludedUsers[$userId] ?? null),
                    'reason'      => $reason->value,
                    'wasEligible' => in_array($userId, $eligibleIds, true),
                ];
            }
            foreach ($eligible as $candidate) {
                if (!isset($episode->excluded[$candidate->getId()])) {
                    $solicited[] = $candidate;
                }
            }

            $this->recordEvaluation($episode, $mission, $isOpen, count($eligible), $excluded, count($solicited), $batch, count($episodes));

            if ($mission !== null) {
                foreach ($solicited as $candidate) {
                    $intents[] = $this->intent($candidate, $mission, $episode);
                }
            }
            $results[] = new OpenPoolResult($episode, $isOpen, count($eligible), count($excluded), count($solicited));
        }

        if ($intents !== []) {
            if ($batch) {
                $this->dispatcher->dispatchBatch(
                    $intents,
                    fn (User $u, array $its) => $this->email($u, array_map(static fn (NotificationIntent $i) => $i->mission, $its)),
                    fn (User $u, array $its) => $this->push(array_map(static fn (NotificationIntent $i) => $i->mission, $its)),
                );
            } else {
                foreach ($intents as $intent) {
                    try {
                        $this->dispatcher->dispatch($intent);
                    } catch (\Throwable $e) {
                        // One recipient's failure never prevents the next one.
                        $this->logger->error('OpenMissionPoolNotifier: dispatch failed', [
                            'missionId' => $intent->mission?->getId(), 'userId' => $intent->recipient->getId(), 'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $results;
    }

    private function intent(User $candidate, Mission $mission, OpenEpisode $episode): NotificationIntent
    {
        $facts = $this->facts($mission);

        return new NotificationIntent(
            recipient: $candidate,
            type: NotificationType::OPEN_MISSION_AVAILABLE,
            inAppPayload: [
                'missionId'      => $mission->getId(),
                'openMissionIds' => [$mission->getId()],
                'missionCount'   => 1,
                'missionDate'    => $facts['dateLabel'],
                'startTime'      => $mission->getStartAt()?->format('H:i'),
                'endTime'        => $mission->getEndAt()?->format('H:i'),
                'periodLabel'    => $facts['periodLabel'],
                'siteName'       => $facts['siteName'],
                'surgeonName'    => $facts['surgeonName'],
                'cause'          => $episode->cause->value,
            ],
            mission: $mission,
            email: $this->email($candidate, [$mission]),
            push: $this->push([$mission]),
            episodeRef: $episode->episodeRef,
        );
    }

    /** @param list<Mission|null> $missions */
    private function email(User $recipient, array $missions): EmailContent
    {
        $missions = array_values(array_filter($missions));
        usort($missions, static fn (Mission $a, Mission $b) => $a->getStartAt() <=> $b->getStartAt());
        $count = count($missions);

        return new EmailContent(
            subject: $count > 1
                ? sprintf('SurgicalHub — %d missions disponibles', $count)
                : sprintf('SurgicalHub — Mission disponible le %s', $missions[0]->getStartAt()?->format('d/m/Y') ?? ''),
            htmlTemplate: 'emails/open_missions_available.html.twig',
            context: [
                'greeting'  => $this->notificationService->greetingFor($recipient),
                'count'     => $count,
                'missions'  => array_map(fn (Mission $m) => $this->facts($m), $missions),
                'offersUrl' => rtrim($this->frontendUrl, '/') . '/app/i/offers',
                'missionUrl' => rtrim($this->frontendUrl, '/') . '/app/i/offers',
                'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
            ],
        );
    }

    /** @param list<Mission|null> $missions */
    private function push(array $missions): PushContent
    {
        $missions = array_values(array_filter($missions));
        $count = count($missions);
        $first = $missions[0];

        return new PushContent(
            $count > 1 ? sprintf('%d nouvelles missions disponibles', $count) : 'Nouvelle mission disponible',
            $count > 1
                ? sprintf('%d missions compatibles avec votre planning sont disponibles.', $count)
                : sprintf('%s à %s.', trim(($this->facts($first)['weekday'] ?? '') . ' ' . $this->facts($first)['dateLabel']), $first->getSite()?->getName() ?? ''),
            ['type' => 'PLANNING_OPEN_MISSIONS_AVAILABLE', 'missionId' => $count === 1 ? $first->getId() : null, 'url' => '/app/i/offers'],
        );
    }

    /** @return array<string, ?string> operational facts only — no patient, no money */
    private function facts(Mission $mission): array
    {
        $start = $mission->getStartAt();
        $end   = $mission->getEndAt();
        $surgeon = $mission->getSurgeon();
        $surgeonName = $surgeon !== null ? trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')) : '';

        return [
            'weekday'     => $start !== null ? self::WEEKDAYS[(int) $start->format('N')] : null,
            'dateLabel'   => $start?->format('d/m/Y'),
            'timeLabel'   => $start !== null && $end !== null ? $start->format('H:i') . ' – ' . $end->format('H:i') : null,
            'periodLabel' => $start !== null ? (((int) $start->format('G')) < 12 ? 'Matin' : 'Après-midi') : null,
            'siteName'    => $mission->getSite()?->getName(),
            'surgeonName' => $surgeonName !== '' ? $surgeonName : null,
            'typeLabel'   => $mission->getType() === MissionType::CONSULTATION ? 'Consultation' : 'Bloc opératoire',
        ];
    }

    /**
     * @param list<array{userId: int, userName: ?string, reason: string, wasEligible: bool}> $excluded
     */
    private function recordEvaluation(OpenEpisode $episode, ?Mission $mission, bool $isOpen, int $eligibleCount, array $excluded, int $notifiedCount, bool $batch, int $batchSize): void
    {
        if (!$this->journal->isEnabled()) {
            return;
        }

        $label = $mission !== null ? sprintf('mission #%d', $mission->getId()) : sprintf('mission #%d', $episode->missionId);
        $summary = match (true) {
            !$isOpen => sprintf('Pool non sollicité pour la %s : elle n\'est plus ouverte', $label),
            $eligibleCount === 0 => sprintf('Aucune instrumentiste éligible pour la %s — elle reste à couvrir', $label),
            default => sprintf('Pool sollicité pour la %s — %d éligible(s), %d sollicitée(s)%s', $label, $eligibleCount, $notifiedCount,
                $excluded !== [] ? sprintf(', %d exclue(s) pour cet épisode', count($excluded)) : ''),
        };
        $facts = $mission !== null ? $this->facts($mission) : [];

        $this->em->getConnection()->transactional(fn () => $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::OPEN_POOL_EVALUATED,
            subjectType: 'Mission',
            subjectId: $episode->missionId,
            subjectLabel: $mission !== null ? \App\Activity\Mirror\AuditEventActivityMapper::missionLabel($mission) : null,
            summary: $summary,
            payload: [
                'missionId'            => $episode->missionId,
                'episodeRef'           => $episode->episodeRef,
                'cause'                => $episode->cause->value,
                'mode'                 => $batch ? 'BATCH' : 'ISOLATED',
                'missionOpen'          => $isOpen,
                'missionDate'          => $facts['dateLabel'] ?? null,
                'startTime'            => $mission?->getStartAt()?->format('H:i'),
                'endTime'              => $mission?->getEndAt()?->format('H:i'),
                'siteId'               => $mission?->getSite()?->getId(),
                'siteName'             => $facts['siteName'] ?? null,
                'surgeonName'          => $facts['surgeonName'] ?? null,
                'eligibleCount'        => $eligibleCount,
                'excludedCount'        => count($excluded),
                'notifiedCount'        => $notifiedCount,
                'noEligibleCandidates' => $isOpen && $eligibleCount === 0,
                'batchSize'            => $batchSize,
                'excluded'             => $excluded,
            ],
            siteId: $mission?->getSite()?->getId(),
            planningVersionId: $mission?->getPlanningVersion()?->getId(),
            missionId: $episode->missionId,
            idempotencyKey: 'open_pool:' . hash('sha256', $episode->episodeRef),
            causeAnchors: ['mission:' . $episode->missionId],
            // The dispatches that follow point to this evaluation.
            anchorKey: 'mission:' . $episode->missionId,
        )));
    }

    /**
     * @param list<OpenEpisode> $episodes
     * @return array<int, User>
     */
    private function loadExcludedUsers(array $episodes): array
    {
        $ids = [];
        foreach ($episodes as $episode) {
            foreach (array_keys($episode->excluded) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $users = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]) as $u) {
            $users[$u->getId()] = $u;
        }

        return $users;
    }
}
