<?php

namespace App\Notification;

use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-161 — THE announcement of a room that has just become available
 * (ROOM_SLOT_AVAILABLE). Replaces the automatic D-114 « Libération de salle » email.
 *
 *   created slot ids (ReleasedRoomSlotsCreatedMessage, one absence processing)
 *     → reload: a slot no longer AVAILABLE, or already past, is announced to nobody
 *     → site toggle AbsenceCommunicationSiteConfig::notifyColleaguesEnabled (unchanged
 *       organisational switch: off = no announcement for this site)
 *     → recipients = the surgeons allowed to take the room over (ReleasedRoomSlotVoter::
 *       TAKE_OVER): active surgeons affiliated to the site, never the absent surgeon
 *     → NotificationDispatcher BATCH: one in-app item per room, ONE push and ONE email per
 *       recipient for the whole operation.
 *
 * Channels are never decided here: NotificationPreferenceResolver decides (default in-app +
 * push on, email OFF — the email is sent only to a surgeon who turned it on, never as an
 * automatic fallback of a missing push). Idempotent: the dispatch key carries
 * `room_slot:{id}`, so a Messenger retry never sends a second push or email for a room.
 */
class ReleasedRoomAvailabilityNotifier
{
    public const ROOMS_PATH = '/app/s/planning/salles-disponibles';

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const PERIOD_LABELS = [
        ShiftPeriod::MATIN->value => 'matin',
        ShiftPeriod::APRES_MIDI->value => 'après-midi',
        ShiftPeriod::JOURNEE->value => 'journée',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * @param list<int> $slotIds rooms really created by one absence processing
     * @return int number of (recipient, room) notifications handed to the dispatcher
     */
    public function announce(array $slotIds): int
    {
        if ($slotIds === []) {
            return 0;
        }

        $today = new \DateTimeImmutable('today');
        /** @var list<ReleasedOperatingRoomSlot> $slots */
        $slots = $this->em->getRepository(ReleasedOperatingRoomSlot::class)->findBy(['id' => array_values(array_unique($slotIds))], ['occurrenceDate' => 'ASC', 'id' => 'ASC']);

        $intents = [];
        $recipientsBySite = [];
        foreach ($slots as $slot) {
            $site = $slot->getSite();
            if ($site === null || $slot->getStatus() !== ReleasedRoomSlotStatus::AVAILABLE || $slot->getOccurrenceDate() < $today) {
                continue;
            }

            $siteId = (int) $site->getId();
            if (!array_key_exists($siteId, $recipientsBySite)) {
                $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
                $recipientsBySite[$siteId] = $config !== null && $config->isNotifyColleaguesEnabled()
                    ? $this->users->findSurgeonsAffiliatedToSite($siteId, activeOnly: true)
                    : null;
                if ($recipientsBySite[$siteId] === null) {
                    $this->logger->info('Released room not announced: colleague notifications disabled for this site.', ['siteId' => $siteId]);
                }
            }

            foreach ($recipientsBySite[$siteId] ?? [] as $recipient) {
                if ($recipient->getId() === $slot->getSurgeon()?->getId()) {
                    continue; // the absent surgeon never takes over his own room
                }
                $intents[] = $this->intent($recipient, $slot);
            }
        }

        if ($intents === []) {
            return 0;
        }

        $this->dispatcher->dispatchBatch(
            $intents,
            fn (User $u, array $its) => $this->email($u, $this->slotsOf($its)),
            fn (User $u, array $its) => $this->push($this->slotsOf($its)),
        );

        return count($intents);
    }

    private function intent(User $recipient, ReleasedOperatingRoomSlot $slot): NotificationIntent
    {
        return new NotificationIntent(
            recipient: $recipient,
            type: NotificationType::ROOM_SLOT_AVAILABLE,
            inAppPayload: [
                'roomSlotId'     => $slot->getId(),
                'siteName'       => $slot->getSite()?->getName(),
                'occurrenceDate' => $slot->getOccurrenceDate()->format('Y-m-d'),
                'dayLabel'       => $this->dateLabel($slot),
                'startTime'      => $slot->getStartTime()?->format('H:i'),
                'endTime'        => $slot->getEndTime()?->format('H:i'),
                'periodLabel'    => self::PERIOD_LABELS[$slot->getPeriod()->value] ?? $slot->getPeriod()->value,
            ],
            email: $this->email($recipient, [$slot]),
            push: $this->push([$slot]),
            episodeRef: 'room_slot:' . $slot->getId(),
            subjectType: 'ReleasedOperatingRoomSlot',
            subjectId: $slot->getId(),
        );
    }

    /** @param list<ReleasedOperatingRoomSlot> $slots */
    private function push(array $slots): PushContent
    {
        $count = count($slots);
        $first = $slots[0];

        return new PushContent(
            $count > 1 ? sprintf('%d salles opératoires disponibles', $count) : 'Salle opératoire disponible',
            $count > 1
                ? sprintf("%s\net %d autre%s créneau%s à récupérer.", $this->line($first), $count - 1, $count > 2 ? 's' : '', $count > 2 ? 'x' : '')
                : sprintf("%s\nUne salle vient de se libérer et peut être récupérée.", $this->line($first)),
            [
                'type'       => NotificationType::ROOM_SLOT_AVAILABLE->value,
                'roomSlotId' => $count === 1 ? $first->getId() : null,
                'url'        => self::roomsPath($count === 1 ? $first->getId() : null),
            ],
        );
    }

    /** @param list<ReleasedOperatingRoomSlot> $slots */
    private function email(User $recipient, array $slots): EmailContent
    {
        $count = count($slots);

        return new EmailContent(
            subject: $count > 1
                ? sprintf('SurgicalHub — %d salles opératoires disponibles', $count)
                : sprintf('SurgicalHub — Salle opératoire disponible : %s', $this->line($slots[0])),
            htmlTemplate: 'emails/room_slot_available.html.twig',
            context: [
                'greeting'  => $this->notificationService->greetingFor($recipient),
                'slots'     => array_map(fn (ReleasedOperatingRoomSlot $s) => ['id' => $s->getId(), 'line' => $this->line($s)], $slots),
                'roomsUrl'  => rtrim($this->frontendUrl, '/') . self::roomsPath($count === 1 ? $slots[0]->getId() : null),
                'missionUrl' => rtrim($this->frontendUrl, '/') . self::roomsPath($count === 1 ? $slots[0]->getId() : null),
                'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
            ],
        );
    }

    public static function roomsPath(?int $slotId): string
    {
        return $slotId !== null ? sprintf('%s?slot=%d', self::ROOMS_PATH, $slotId) : self::ROOMS_PATH;
    }

    /** « CHIREC - Hôpital Delta — 23 décembre, 07h45–13h00 » (no patient, no money). */
    private function line(ReleasedOperatingRoomSlot $slot): string
    {
        $start = $slot->getStartTime();
        $end = $slot->getEndTime();
        $when = $start !== null && $end !== null
            ? sprintf('%s–%s', $start->format('H\hi'), $end->format('H\hi'))
            : (self::PERIOD_LABELS[$slot->getPeriod()->value] ?? $slot->getPeriod()->value);

        return sprintf('%s — %s, %s', $slot->getSite()?->getName() ?? 'Site', $this->dateLabel($slot), $when);
    }

    private function dateLabel(ReleasedOperatingRoomSlot $slot): string
    {
        $date = $slot->getOccurrenceDate();
        $label = sprintf('%d %s', (int) $date->format('j'), self::MONTHS[(int) $date->format('n')]);

        return $date->format('Y') === (new \DateTimeImmutable('today'))->format('Y') ? $label : $label . ' ' . $date->format('Y');
    }

    /**
     * The recipient's rooms of this operation, in date order (already in the identity map).
     *
     * @param list<NotificationIntent> $intents
     * @return list<ReleasedOperatingRoomSlot>
     */
    private function slotsOf(array $intents): array
    {
        $slots = array_values(array_filter(array_map(
            fn (NotificationIntent $i) => $this->em->find(ReleasedOperatingRoomSlot::class, (int) $i->subjectId),
            $intents,
        )));
        usort($slots, static fn (ReleasedOperatingRoomSlot $a, ReleasedOperatingRoomSlot $b) => [$a->getOccurrenceDate(), $a->getId()] <=> [$b->getOccurrenceDate(), $b->getId()]);

        return $slots;
    }
}
