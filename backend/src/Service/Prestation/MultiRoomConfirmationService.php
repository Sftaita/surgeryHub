<?php

namespace App\Service\Prestation;

use App\Entity\Mission;
use App\Entity\PlanningMultiRoomConfirmation;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-156 — targeted confirmation of multi-room assignments in a Planning V2 draft.
 *
 * Recurring posts keep their usual instrumentist; when they put the same instrumentist on
 * several simultaneous BLOC missions of the same surgeon on the same site and day, the
 * manager must confirm that common assignment (or reorganise it) before the draft can be
 * deployed. Only the DRAFT missions of the version are concerned: published missions and
 * post-deploy changes never are (no retroactive workflow).
 *
 * A group is confirmed when a PlanningMultiRoomConfirmation of the version carries its
 * CURRENT fingerprint (PrestationGrouping::missionGroupFingerprint()): a change of
 * composition or schedule makes it unconfirmed again. A confirmation is recorded either from
 * the editor's explicit "Confirmer" (lines carrying `multiRoomConfirmedKey` equal to the
 * group's key) or when the manager himself assigned the instrumentist to a mission of the
 * group (never asked to confirm a decision he just took).
 */
class MultiRoomConfirmationService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Multi-room groups (2+ missions) among the version's DRAFT missions.
     *
     * @return list<list<Mission>>
     */
    public function draftGroups(PlanningVersion $version): array
    {
        /** @var list<Mission> $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->andWhere('m.status = :draft')
            ->andWhere('m.instrumentist IS NOT NULL')->andWhere('m.type = :block')
            ->setParameter('v', $version)->setParameter('draft', MissionStatus::DRAFT)->setParameter('block', MissionType::BLOCK)
            ->orderBy('m.startAt', 'ASC')->addOrderBy('m.endAt', 'ASC')->addOrderBy('m.id', 'ASC')
            ->getQuery()->getResult();

        // Connected components of isMultiRoomPair() — same rule as everywhere else.
        $groups = [];
        $assigned = [];
        foreach ($missions as $i => $seed) {
            if (isset($assigned[$i])) {
                continue;
            }
            $assigned[$i] = true;
            $group = [$seed];
            for ($k = 0; $k < count($group); $k++) {
                foreach ($missions as $j => $other) {
                    if (!isset($assigned[$j]) && PrestationGrouping::isMultiRoomPair($group[$k], $other)) {
                        $assigned[$j] = true;
                        $group[] = $other;
                    }
                }
            }
            if (count($group) > 1) {
                usort($group, static fn (Mission $a, Mission $b) => [$a->getStartAt(), $a->getEndAt(), $a->getId()] <=> [$b->getStartAt(), $b->getEndAt(), $b->getId()]);
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @return array<string, true> fingerprints confirmed in this version */
    public function confirmedFingerprints(PlanningVersion $version): array
    {
        $rows = $this->em->createQuery(sprintf('SELECT c.fingerprint FROM %s c WHERE c.planningVersion = :v', PlanningMultiRoomConfirmation::class))
            ->setParameter('v', $version)->getScalarResult();

        return array_fill_keys(array_column($rows, 'fingerprint'), true);
    }

    /**
     * Groups that still need the manager's confirmation — what deploy() refuses.
     *
     * @return list<list<Mission>>
     */
    public function unconfirmedGroups(PlanningVersion $version): array
    {
        $confirmed = $this->confirmedFingerprints($version);

        return array_values(array_filter(
            $this->draftGroups($version),
            static fn (array $group) => !isset($confirmed[PrestationGrouping::missionGroupFingerprint($group)]),
        ));
    }

    /**
     * Records the confirmations decided by one save (generate() or a draft PATCH), on the
     * missions as they now stand (call after flush):
     *  - a group whose key the editor confirmed (`$confirmedKeys`, the lines'
     *    `multiRoomConfirmedKey`) — only if the key still matches: confirming a composition
     *    that changed before saving confirms nothing;
     *  - a group with a mission whose instrumentist the manager set himself in this save
     *    (`$explicitMissions`).
     *
     * @param array<string, true> $confirmedKeys
     * @param list<Mission>       $explicitMissions
     */
    public function recordFromSave(PlanningVersion $version, array $confirmedKeys, array $explicitMissions, User $actor): void
    {
        if ($confirmedKeys === [] && $explicitMissions === []) {
            return;
        }
        $explicitIds = array_fill_keys(array_map(static fn (Mission $m) => (int) $m->getId(), $explicitMissions), true);
        $existing = $this->confirmedFingerprints($version);

        foreach ($this->draftGroups($version) as $group) {
            $fingerprint = PrestationGrouping::missionGroupFingerprint($group);
            if (isset($existing[$fingerprint])) {
                continue;
            }
            $source = null;
            if (isset($confirmedKeys[$fingerprint])) {
                $source = PlanningMultiRoomConfirmation::SOURCE_CONFIRMED;
            } elseif (array_filter($group, static fn (Mission $m) => isset($explicitIds[(int) $m->getId()])) !== []) {
                $source = PlanningMultiRoomConfirmation::SOURCE_EXPLICIT_ASSIGNMENT;
            }
            if ($source === null) {
                continue;
            }
            $this->em->persist(new PlanningMultiRoomConfirmation(
                $version,
                $fingerprint,
                (int) $group[0]->getInstrumentist()->getId(),
                new \DateTimeImmutable(PrestationGrouping::localDate($group[0])),
                array_map(static fn (Mission $m) => (int) $m->getId(), $group),
                $source,
                $actor,
            ));
            $existing[$fingerprint] = true;
        }
        $this->em->flush();
    }

    /**
     * Sets `multiRoomConfirmation` ('REQUIRED' | 'CONFIRMED' | null) on annotated lines
     * (PrestationGrouping::annotatePreviewLines() first). A line backed by a DRAFT mission of
     * the version takes its group, key and status from the MISSIONS themselves — the very
     * groups deploy() checks — since a line keeps its post's times even after the mission's
     * own times were edited. A line without a mission yet keeps its line-based group, to
     * confirm.
     *
     * @param array<int, array<string, mixed>> &$lines
     */
    public function annotateConfirmation(array &$lines, PlanningVersion $version): void
    {
        $confirmed = $this->confirmedFingerprints($version);
        $byMission = [];
        foreach ($this->draftGroups($version) as $group) {
            $key = PrestationGrouping::missionGroupFingerprint($group);
            foreach ($group as $mission) {
                $byMission[(int) $mission->getId()] = [$key, count($group), isset($confirmed[$key]) ? 'CONFIRMED' : 'REQUIRED'];
            }
        }

        foreach ($lines as &$line) {
            $missionId = $line['existingMissionId'] ?? null;
            $existingStatus = $line['existingMissionStatus'] ?? null;
            if ($missionId !== null && ($existingStatus === null || $existingStatus === MissionStatus::DRAFT->value)) {
                [$key, $count, $status] = $byMission[(int) $missionId] ?? [null, null, null];
                $line['multiRoomGroupKey'] = $key;
                $line['multiRoomCount'] = $count;
                $line['multiRoomConfirmation'] = $status;
                continue;
            }
            $line['multiRoomConfirmation'] = ($line['multiRoomGroupKey'] ?? null) !== null ? 'REQUIRED' : null;
        }
        unset($line);
    }

    /**
     * Shape of the 409 MULTI_ROOM_CONFIRMATION_REQUIRED entries.
     *
     * @param list<list<Mission>> $groups
     * @return list<array<string, mixed>>
     */
    public static function describe(array $groups): array
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $name = static fn (?User $u) => $u === null ? null : (trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? '')) ?: $u->getEmail());

        return array_map(static fn (array $group) => [
            'date'              => PrestationGrouping::localDate($group[0]),
            'instrumentistId'   => $group[0]->getInstrumentist()?->getId(),
            'instrumentistName' => $name($group[0]->getInstrumentist()),
            'surgeonId'         => $group[0]->getSurgeon()?->getId(),
            'surgeonName'       => $name($group[0]->getSurgeon()),
            'siteId'            => $group[0]->getSite()?->getId(),
            'siteName'          => $group[0]->getSite()?->getName(),
            'missionIds'        => array_map(static fn (Mission $m) => (int) $m->getId(), $group),
            'slots'             => array_map(static fn (Mission $m) => $m->getStartAt()->setTimezone($tz)->format('H:i') . '–' . $m->getEndAt()->setTimezone($tz)->format('H:i'), $group),
            'groupKey'          => PrestationGrouping::missionGroupFingerprint($group),
        ], $groups);
    }
}
