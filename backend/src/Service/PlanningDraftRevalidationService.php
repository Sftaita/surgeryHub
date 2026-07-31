<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revalidates every DRAFT mission of a PlanningVersion against CURRENT absence data,
 * right before deploy() publishes it — D-090. A draft's instrumentist/surgeon assignment
 * was correct against absences at the moment PlanningGeneratorServiceV2::preview()/generate()
 * ran; nothing re-checks it if an absence is created or modified afterward but before the
 * manager clicks Déployer (DRAFT is deliberately never touched by AbsenceMissionReactionService,
 * which only reacts to already-published ASSIGNED/OPEN missions — see its class docblock).
 * This service closes that gap.
 *
 * Two distinct outcomes, by design (see docs/decisions.md D-090):
 *   - Surgeon absent → the mission's own activity is cancelled; there is no "vacant post"
 *     left to fill, so the mission is neutralized (CANCELLED) rather than published as OPEN.
 *     This mirrors AbsenceMissionReactionService::processSurgeonAbsence()'s existing rule
 *     for already-published missions, extended to the DRAFT stage — never a new status.
 *   - Instrumentist absent (surgeon present) → the assignment itself is what's wrong, and
 *     dropping it silently would publish a plan the manager never actually reviewed. This
 *     blocks the ENTIRE deploy (see PlanningDraftConflictException) rather than excluding
 *     just the affected missions — a partially-published plan without explicit manager
 *     intent is the exact failure mode D-090 exists to close.
 *
 * A mission whose surgeon AND instrumentist are both absent is reported only as
 * "neutralized" (surgeon check runs first) — once the surgeon's own activity is cancelled,
 * whichever instrumentist was attached to it is no longer a meaningful conflict to block on.
 */
class PlanningDraftRevalidationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceOverlapService $absenceOverlap,
        private readonly MissionPostDeployService $postDeploy,
    ) {}

    /**
     * Read-only pass — never mutates anything. Call this first; only call neutralize()
     * afterward, and only if blockingConflicts is empty.
     *
     * @return array{
     *   blockingConflicts: list<array{missionId:int,date:string,siteId:?int,siteName:?string,surgeonId:?int,surgeonName:?string,instrumentistId:int,instrumentistName:string,reason:string}>,
     *   neutralized: list<array{missionId:int,date:string,siteId:?int,siteName:?string,surgeonId:int,surgeonName:string,previousInstrumentistId:?int,previousInstrumentistName:?string,reason:string}>,
     * }
     */
    public function revalidate(PlanningVersion $version): array
    {
        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.planningVersion = :version')
            ->andWhere('m.status = :draft')
            ->setParameter('version', $version)
            ->setParameter('draft', MissionStatus::DRAFT)
            ->getQuery()
            ->getResult();

        $blockingConflicts = [];
        $neutralized        = [];

        foreach ($missions as $mission) {
            $start         = $mission->getStartAt();
            $end           = $mission->getEndAt();
            $surgeon       = $mission->getSurgeon();
            $instrumentist = $mission->getInstrumentist();

            if ($start === null || $end === null) {
                continue; // defensive — never actually null on a persisted Mission
            }

            if ($surgeon !== null && $this->absenceOverlap->isUserAbsentDuring($surgeon, $start, $end)) {
                $neutralized[] = [
                    'missionId'                 => $mission->getId(),
                    'date'                      => $start->format('Y-m-d'),
                    'siteId'                    => $mission->getSite()?->getId(),
                    'siteName'                  => $mission->getSite()?->getName(),
                    'surgeonId'                 => $surgeon->getId(),
                    'surgeonName'               => self::displayName($surgeon),
                    'previousInstrumentistId'   => $instrumentist?->getId(),
                    'previousInstrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
                    'reason'                    => sprintf(
                        'Chirurgien %s absent le %s — poste neutralisé automatiquement (revalidation au déploiement).',
                        self::displayName($surgeon),
                        $start->format('d/m/Y'),
                    ),
                ];
                continue;
            }

            if ($instrumentist !== null && $this->absenceOverlap->isUserAbsentDuring($instrumentist, $start, $end)) {
                $blockingConflicts[] = [
                    'missionId'         => $mission->getId(),
                    'date'              => $start->format('Y-m-d'),
                    'siteId'            => $mission->getSite()?->getId(),
                    'siteName'          => $mission->getSite()?->getName(),
                    'surgeonId'         => $surgeon?->getId(),
                    'surgeonName'       => $surgeon !== null ? self::displayName($surgeon) : null,
                    'instrumentistId'   => $instrumentist->getId(),
                    'instrumentistName' => self::displayName($instrumentist),
                    'reason'            => sprintf(
                        'Instrumentiste %s absent le %s — déploiement bloqué.',
                        self::displayName($instrumentist),
                        $start->format('d/m/Y'),
                    ),
                ];
            }
        }

        return ['blockingConflicts' => $blockingConflicts, 'neutralized' => $neutralized];
    }

    /**
     * Applies the "neutralized" outcomes from revalidate() — cancels each mission via the
     * existing, already-audited/notified MissionPostDeployService::cancel() path (never a
     * bespoke mutation here). notify:true deliberately — reuses the existing
     * MissionLifecycleChangedMessage → MissionLifecycleChangedMessageHandler pipeline so a
     * previously-assigned instrumentist IS told their assignment was just cancelled, without
     * this service having to reimplement that notification.
     *
     * Only ever call this after confirming revalidate()'s blockingConflicts is empty —
     * deploy() must never publish a plan while also silently dropping conflicting instrumentist
     * assignments.
     *
     * @param list<array{missionId:int,reason:string,...}> $neutralized
     */
    public function neutralize(array $neutralized, User $actor): void
    {
        foreach ($neutralized as $entry) {
            $mission = $this->em->find(Mission::class, $entry['missionId']);
            if ($mission === null || $mission->getStatus() !== MissionStatus::DRAFT) {
                continue; // concurrent change since revalidate() ran — skip rather than mutate an unexpected state
            }

            $this->postDeploy->cancel($mission, $actor, reason: $entry['reason'], notify: true);
        }
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
