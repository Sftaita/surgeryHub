<?php

namespace App\Security\Voter;

use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\ReleasedRoomSlotStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-124 — « Reprendre une salle libérée ». Seule source de vérité RBAC pour les deux actions
 * d'écriture sur un `ReleasedOperatingRoomSlot`, et pour leur exposition en `allowedActions`
 * (AvailableRoomsController) — le frontend n'en déduit jamais rien lui-même.
 *
 * Le voter ne décide que « qui peut tenter l'action sur ce créneau » à partir de l'état déjà
 * chargé (rôle, affiliation, statut, date, propriétaire). Les préconditions qui exigent une
 * lecture fraîche sous verrou — créneau toujours AVAILABLE, chirurgien libérant toujours
 * absent, repreneur libre à cet horaire — sont revérifiées par
 * `ReleasedRoomSlotTakeoverService` (409 métier), jamais ici.
 *
 * TAKE_OVER : chirurgien, affilié au site du créneau (`SiteMembership`, même règle que la
 *   liste `GET /api/me/available-rooms`), créneau pas passé, et jamais le chirurgien absent
 *   lui-même (il ne « reprend » pas sa propre salle — son absence doit être retirée).
 *   Volontairement SANS condition de statut : un créneau déjà repris est un conflit métier
 *   (409 `ROOM_SLOT_ALREADY_TAKEN` + nom du repreneur, levé par le service sous verrou), jamais
 *   un refus d'autorisation (403) — sinon le perdant d'une course séquentielle (page restée
 *   ouverte) ne saurait jamais que la salle vient d'être reprise, ni par qui. L'état est
 *   combiné à ce droit dans `ReleasedRoomSlotTakeoverService::allowedActions()`.
 * RELEASE : créneau CLAIMED, pas passé, Mission de reprise encore annulable (OPEN/ASSIGNED)
 *   ou déjà annulée par ailleurs (CANCELLED — la salle doit alors pouvoir être rendue au lieu
 *   de rester bloquée), et soit le repreneur lui-même, soit un manager/admin (même périmètre
 *   que `PlanningVoter::PLANNING_MANAGE`, aucun scoping site pour ce rôle).
 */
final class ReleasedRoomSlotVoter extends Voter
{
    public const TAKE_OVER = 'RELEASED_ROOM_SLOT_TAKE_OVER';
    public const RELEASE = 'RELEASED_ROOM_SLOT_RELEASE';

    private const RELEASABLE_MISSION_STATUSES = [MissionStatus::OPEN, MissionStatus::ASSIGNED, MissionStatus::CANCELLED];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::TAKE_OVER, self::RELEASE], true)
            && $subject instanceof ReleasedOperatingRoomSlot;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        /** @var ReleasedOperatingRoomSlot $subject */
        if ($subject->getOccurrenceDate() < new \DateTimeImmutable('today')) {
            return false;
        }

        return match ($attribute) {
            self::TAKE_OVER => $this->canTakeOver($subject, $user),
            self::RELEASE => $this->canRelease($subject, $user),
            default => false,
        };
    }

    private function canTakeOver(ReleasedOperatingRoomSlot $slot, User $user): bool
    {
        if (!in_array('ROLE_SURGEON', $user->getRoles(), true)) {
            return false;
        }
        if ($slot->getSurgeon()?->getId() === $user->getId()) {
            return false;
        }

        $siteId = $slot->getSite()?->getId();
        if ($siteId === null) {
            return false;
        }
        foreach ($user->getSiteMemberships() as $membership) {
            if ($membership->getSite()?->getId() === $siteId) {
                return true;
            }
        }

        return false;
    }

    private function canRelease(ReleasedOperatingRoomSlot $slot, User $user): bool
    {
        if ($slot->getStatus() !== ReleasedRoomSlotStatus::CLAIMED) {
            return false;
        }

        $mission = $slot->getTakeoverMission();
        if ($mission !== null && !in_array($mission->getStatus(), self::RELEASABLE_MISSION_STATUSES, true)) {
            return false; // mission already started/encoded — nothing left to release
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
            return true;
        }

        return in_array('ROLE_SURGEON', $roles, true)
            && $slot->getClaimedBy()?->getId() === $user->getId();
    }
}
