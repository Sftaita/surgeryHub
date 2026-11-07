<?php

namespace App\MessageHandler;

use App\Notification\OpenEpisode;
use App\Notification\OpenMissionPoolNotifier;
use App\Enum\OpenEpisodeCause;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\MissionPublishedMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Point 8 (audit UX) — side effects of a Mission publish (DRAFT → OPEN):
 *
 * 1. The pool — H3 (D-129): OpenMissionPoolNotifier, i.e. only the instrumentists really
 *    eligible for this mission, through NotificationDispatcher (in-app / email / push per
 *    preferences). Formerly a push to every instrumentist of the site.
 * 2. The mission's surgeon — in-app, push first, email fallback only if push wasn't actually
 *    accepted (D-083 pattern) — H5a: through NotificationDispatcher, every channel traced.
 *    No patient data — date/time/site/type only.
 *
 * Failure isolation, matching MissionLifecycleChangedMessageHandler: each side effect is
 * independently wrapped in try/catch. Mission reloaded fresh from the DB (never from a
 * message snapshot).
 */
#[AsMessageHandler]
final class MissionPublishedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OpenMissionPoolNotifier $poolNotifier,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(MissionPublishedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        if ($mission === null) {
            $this->logger->warning('MissionPublished: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        $this->solicitPool($mission, $message);
        $this->notifySurgeon($mission, $message);
    }

    /**
     * H3 (D-129) — the pool is solicited through the single entry point: only the
     * instrumentists who can really take THIS mission (affiliation / freelancer, not absent,
     * no overlapping mission), with their preferences per channel. Replaces the former push
     * to EVERY instrumentist of the site (no eligibility, no in-app, no email).
     */
    private function solicitPool(Mission $mission, MissionPublishedMessage $message): void
    {
        try {
            $this->poolNotifier->notify(OpenEpisode::forTransition(
                (int) $mission->getId(), OpenEpisodeCause::PUBLISHED_TO_POOL, $message->occurredAt, actorId: $message->actorId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('MissionPublished: pool solicitation failed', [
                'missionId' => $mission->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifySurgeon(Mission $mission, MissionPublishedMessage $message): void
    {
        // Défensif — Mission.surgeon n'est pas nullable en base, mais jamais supposé ici.
        $surgeon = $mission->getSurgeon();
        if (!$surgeon instanceof User) {
            return;
        }

        // Centre de notifications — publication issue de l'acceptation de SA demande : il en
        // est informé une seule fois, par SURGEON_MISSION_REQUEST_ACCEPTED (qui précise si la
        // mission est encore sans instrumentiste). `?? null` : un message mis en file avant ce
        // champ n'a pas la propriété initialisée.
        if (($message->fromSurgeonRequestId ?? null) !== null) {
            $this->logger->info('MissionPublished: surgeon told by the request acceptance — no separate notice', [
                'missionId' => $mission->getId(),
                'requestId' => $message->fromSurgeonRequestId,
            ]);
            return;
        }

        // H5a — via NotificationDispatcher : in-app, push d'abord, repli email (politique
        // FALLBACK_WHEN_PUSH_NOT_SENT, orchestration D-083 inchangée), chaque canal tracé,
        // idempotent par publication.
        try {
            $type = NotificationType::SURGEON_MISSION_OPEN_PUBLISHED;
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $surgeon,
                type: $type,
                inAppPayload: [
                    'missionId' => $mission->getId(),
                    'dayLabel'  => $mission->getStartAt()?->format('l'),
                    'siteName'  => $mission->getSite()?->getName(),
                    'missionDate' => $mission->getStartAt()?->format('Y-m-d'),
                    'startTime'   => $mission->getStartAt()?->format('H:i'),
                    'endTime'     => $mission->getEndAt()?->format('H:i'),
                ],
                mission: $mission,
                email: $this->notificationService->missionOpenSurgeonEmail($mission, $surgeon),
                push: new PushContent(
                    'Nouvelle mission ouverte',
                    'Une mission vous concernant a été publiée, actuellement sans instrumentiste.',
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $surgeon)],
                ),
                episodeRef: 'published:' . $mission->getId() . ':' . $message->occurredAt->format('Y-m-d\TH:i:s.uP'),
                emailPolicy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('MissionPublished: surgeon notification failed', [
                'missionId' => $mission->getId(),
                'surgeonId' => $surgeon->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
