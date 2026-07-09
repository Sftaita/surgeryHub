<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\PlanningVersion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only timeline builder for a deployed PlanningVersion.
 * Never calls EntityManager::flush() or ::persist() — Batch 15F.
 *
 * Timeline structure:
 *   1. DEPLOYED entry — derived from PlanningVersion.deployedAt + generatedBy + summaryJson.
 *      PlanningDeployment has no FK to PlanningVersion; the version itself carries all needed data.
 *   2. AuditEvent entries — all events on missions linked to the version, sorted ASC by createdAt.
 *
 * Two DB queries total regardless of version size.
 */
class PlanningVersionHistoryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Returns a chronological (ASC) timeline array, or null if the version does not exist.
     *
     * Each entry has at minimum: type (string), occurredAt (ISO 8601).
     * DEPLOYED entry also has: deployedById, deployedByName, missionCount, openPoolCount.
     * AuditEvent entries also have: missionId, actorId, actorName, payload.
     */
    public function buildTimeline(int $versionId): ?array
    {
        $version = $this->em->find(PlanningVersion::class, $versionId);
        if ($version === null) {
            return null;
        }

        $timeline = [];

        // ── 1. DEPLOYED entry ─────────────────────────────────────────────────
        if ($version->getDeployedAt() !== null) {
            $summary    = $version->getSummaryJson();
            $deployedBy = $version->getGeneratedBy();
            $timeline[] = [
                'type'           => 'DEPLOYED',
                'occurredAt'     => $version->getDeployedAt()->format(\DateTimeInterface::ATOM),
                'deployedById'   => $deployedBy?->getId(),
                'deployedByName' => $deployedBy !== null ? $this->displayName($deployedBy) : null,
                'missionCount'   => $summary['missions']['total'] ?? null,
                'openPoolCount'  => $summary['missions']['open'] ?? null,
            ];
        }

        // ── 2. AuditEvents on missions belonging to this version (ASC) ────────
        /** @var AuditEvent[] $events */
        $events = $this->em->createQuery(
            'SELECT a, actor FROM App\Entity\AuditEvent a
             JOIN a.actor actor
             JOIN a.mission m
             WHERE m.planningVersion = :version
             ORDER BY a.createdAt ASC'
        )
            ->setParameter('version', $version)
            ->getResult();

        foreach ($events as $ae) {
            $actor      = $ae->getActor();
            $timeline[] = [
                'type'       => $ae->getEventType()->value,
                'occurredAt' => $ae->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'missionId'  => $ae->getMission()?->getId(),
                'actorId'    => $actor?->getId(),
                'actorName'  => $actor !== null ? $this->displayName($actor) : null,
                'payload'    => $ae->getPayload(),
            ];
        }

        return $timeline;
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
