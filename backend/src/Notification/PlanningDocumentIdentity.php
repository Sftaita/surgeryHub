<?php

namespace App\Notification;

use App\Entity\Mission;
use App\Entity\User;

/**
 * H5b (D-132) — WHAT a personal planning PDF represents: the person, her role, the period, and
 * exactly the mission facts the template prints (date, hours, type, status, site, the other
 * person). Two renderings of the same identity are the same document (DocumentStore::storeOnce):
 * a resend reuses the bytes already sent instead of rendering again — while any change of what
 * the PDF shows (a time, a site, the instrumentist…) is a new document, as it must be.
 */
final class PlanningDocumentIdentity
{
    public const ROLE_INSTRUMENTIST = 'INSTRUMENTIST';
    public const ROLE_SURGEON       = 'SURGEON';

    /**
     * @param Mission[] $missions
     * @return array{sourceKey: string, fingerprint: string}
     */
    public static function personal(string $role, User $person, \DateTimeInterface $from, \DateTimeInterface $to, array $missions): array
    {
        $rows = array_map(static fn (Mission $m) => [
            'id'     => $m->getId(),
            'start'  => $m->getStartAt()?->format('Y-m-d H:i'),
            'end'    => $m->getEndAt()?->format('Y-m-d H:i'),
            'type'   => $m->getType()?->value,
            'status' => $m->getStatus()?->value,
            'site'   => $m->getSite()?->getName(),
            'other'  => $role === self::ROLE_INSTRUMENTIST
                ? [$m->getSurgeon()?->getFirstname(), $m->getSurgeon()?->getLastname()]
                : [$m->getInstrumentist()?->getFirstname(), $m->getInstrumentist()?->getLastname()],
        ], array_values($missions));
        usort($rows, static fn (array $a, array $b) => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        $input = [
            'role'   => $role,
            'person' => [$person->getId(), $person->getFirstname(), $person->getLastname()],
            'from'   => $from->format('Y-m-d'),
            'to'     => $to->format('Y-m-d'),
            'rows'   => $rows,
        ];

        return [
            'sourceKey'   => sprintf('planning_personal:%s:%d:%s:%s', $role, (int) $person->getId(), $from->format('Y-m-d'), $to->format('Y-m-d')),
            'fingerprint' => hash('sha256', (string) json_encode($input, JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * @param Mission[] $missions
     * @return array{sourceKey: string, fingerprint: string}
     */
    public static function global(?int $siteId, \DateTimeInterface $from, \DateTimeInterface $to, array $missions): array
    {
        $rows = array_map(static fn (Mission $m) => [
            $m->getId(), $m->getStartAt()?->format('Y-m-d H:i'), $m->getEndAt()?->format('Y-m-d H:i'), $m->getStatus()?->value,
            $m->getSite()?->getName(), $m->getSurgeon()?->getId(), $m->getInstrumentist()?->getId(),
        ], array_values($missions));
        sort($rows);

        return [
            'sourceKey'   => sprintf('planning_global:%s:%s:%s', $siteId ?? 'all', $from->format('Y-m-d'), $to->format('Y-m-d')),
            'fingerprint' => hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_UNICODE)),
        ];
    }
}
