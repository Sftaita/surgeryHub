<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\ReleasedRoomSlotStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-177 — does a surgeon's mission request designate a released operating room? The 4 Nov
 * incident: a free request and a released room describing the same slot lived as two
 * independent circuits (request accepted as a DRAFT mission, room still AVAILABLE). Read by
 * the request creation (the surgeon is told at once and offered the take-over), the manager
 * list (the match is shown) and the acceptance (a certain match becomes a real take-over).
 *
 * Candidates: AVAILABLE rooms of the request's site, on its (Brussels) day, released by
 * another surgeon, whose real interval (ReleasedRoomSlotTakeoverService::slotInterval())
 * overlaps the request. Statuses:
 *   NONE                — no candidate: the request is an ordinary request.
 *   CERTAIN             — exactly one candidate AND the request lies within its hours.
 *   AMBIGUOUS           — several candidates, or a partial overlap: the manager chooses
 *                         explicitly (a mere overlap is never converted automatically).
 *   ALREADY_TAKEN_OVER  — the requester already holds the take-over of an overlapping room of
 *                         that site and day (accepting would create a second mission).
 */
class ReleasedRoomRequestMatcher
{
    public const NONE = 'NONE';
    public const CERTAIN = 'CERTAIN';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const ALREADY_TAKEN_OVER = 'ALREADY_TAKEN_OVER';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReleasedRoomSlotTakeoverService $takeovers,
    ) {
    }

    public function matchRequest(SurgeonMissionRequest $request): RoomRequestMatch
    {
        $site = $request->getSite();
        $surgeon = $request->getSurgeon();
        if ($site === null || $surgeon === null || $request->getStartAt() === null || $request->getEndAt() === null) {
            return new RoomRequestMatch(self::NONE, [], null);
        }

        return $this->match($site, $surgeon, $request->getStartAt(), $request->getEndAt());
    }

    public function match(Hospital $site, User $surgeon, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): RoomRequestMatch
    {
        $day = new \DateTimeImmutable(
            $startAt->setTimezone(new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))->format('Y-m-d'),
        );
        if ($day < new \DateTimeImmutable('today')) {
            return new RoomRequestMatch(self::NONE, [], null);
        }

        /** @var list<ReleasedOperatingRoomSlot> $slots */
        $slots = $this->em->createQueryBuilder()
            ->select('s')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.site = :site')
            ->andWhere('s.occurrenceDate = :day')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('site', $site)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->setParameter('statuses', [ReleasedRoomSlotStatus::AVAILABLE, ReleasedRoomSlotStatus::CLAIMED])
            ->orderBy('s.startTime', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        $candidates = [];
        $takenOver = null;
        foreach ($slots as $slot) {
            if ($slot->getSurgeon()?->getId() === $surgeon->getId()) {
                continue; // his own released room is never "taken over" by him
            }
            $interval = $this->takeovers->slotInterval($slot);
            if ($interval === null || !($startAt < $interval[1] && $endAt > $interval[0])) {
                continue;
            }

            if ($slot->getStatus() === ReleasedRoomSlotStatus::CLAIMED) {
                $mission = $slot->getTakeoverMission();
                if ($slot->getClaimedBy()?->getId() === $surgeon->getId()
                    && $mission !== null && $mission->getStatus() !== MissionStatus::CANCELLED) {
                    $takenOver ??= new RoomMatchCandidate($slot, $interval, $startAt >= $interval[0] && $endAt <= $interval[1]);
                }
                continue;
            }

            $candidates[] = new RoomMatchCandidate($slot, $interval, $startAt >= $interval[0] && $endAt <= $interval[1]);
        }

        if ($takenOver !== null) {
            return new RoomRequestMatch(self::ALREADY_TAKEN_OVER, $candidates, null, $takenOver);
        }
        if ($candidates === []) {
            return new RoomRequestMatch(self::NONE, [], null);
        }
        if (count($candidates) === 1 && $candidates[0]->coversRequest) {
            return new RoomRequestMatch(self::CERTAIN, $candidates, $candidates[0]->slot->getId());
        }

        return new RoomRequestMatch(self::AMBIGUOUS, $candidates, null);
    }
}
