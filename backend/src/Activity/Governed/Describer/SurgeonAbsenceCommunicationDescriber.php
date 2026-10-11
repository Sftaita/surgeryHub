<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Entity\SurgeonAbsenceCommunication;
use App\Enum\ActivityEventType;

/**
 * H5b (D-132) — a D-114 communication recorded in its specialised journal (room release to
 * colleagues, block management notice / modification / cancellation), and each later revision.
 * The journal keeps the proof (subject / body snapshots, occurrences, deliveries); the global
 * timeline links it to its absence (cause) and to its deliveries (NOTIFICATION_CHANNEL_* on the
 * common delivery layer). Never the body, never the recipients' addresses.
 */
final class SurgeonAbsenceCommunicationDescriber extends AbstractGovernedDescriber
{
    private const TYPES = [
        'ROOM_RELEASE' => 'libération de salle aux collègues',
        'BLOCK_MANAGEMENT_ABSENCE' => 'absence annoncée à la gestion du bloc',
        'BLOCK_MANAGEMENT_MODIFICATION' => 'modification annoncée à la gestion du bloc',
        'BLOCK_MANAGEMENT_CANCELLATION' => 'annulation annoncée à la gestion du bloc',
    ];

    public function entityClass(): string { return SurgeonAbsenceCommunication::class; }

    public function trackedFields(): array
    {
        return ['revisionNumber' => 'Révision'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action === GovernedChange::DELETED) {
            return null;
        }
        /** @var SurgeonAbsenceCommunication $comm */
        $comm    = $c->entity;
        $absence = $comm->getAbsence();
        $kind    = self::TYPES[$comm->getType()->value] ?? $comm->getType()->value;
        $dr      = $comm->getSurgeon()?->getDrName() ?? '—';
        $site    = $comm->getSite()?->getName() ?? '—';
        $count   = count($comm->getOccurrencesSnapshot());

        return $this->record(ActivityEventType::SURGEON_ABSENCE_COMMUNICATION_RECORDED, $c, 'SurgeonAbsenceCommunication',
            sprintf('Communication #%d — %s', $c->entityId, $kind),
            sprintf('Communication d\'absence enregistrée : %s pour le %s à %s (absence du %s au %s%s)%s',
                $kind, $dr, $site, FrenchLabels::date($comm->getAbsenceDateStartSnapshot()), FrenchLabels::date($comm->getAbsenceDateEndSnapshot()),
                // Block management is told the whole period (D-114 §9), never slots.
                $count > 0 ? sprintf(', %d créneau%s', $count, $count > 1 ? 'x' : '') : '',
                $comm->getRevisionNumber() > 0 ? sprintf(' — révision %d', $comm->getRevisionNumber()) : ''),
            [
                'communicationId' => $c->entityId, 'communicationType' => $comm->getType()->value, 'revision' => $comm->getRevisionNumber(),
                'occurrenceCount' => $count, 'surgeonId' => $comm->getSurgeon()?->getId(), 'siteId' => $comm->getSite()?->getId(),
                'siteName' => $site, 'absenceId' => $absence?->getId(),
            ],
            siteId: $comm->getSite()?->getId(), userId: $comm->getSurgeon()?->getId(),
            causeAnchors: $absence !== null ? ['absence:' . $absence->getId()] : [], absenceId: $absence?->getId(),
            // §31 — absence → communication → delivery: the deliveries point to this event.
            anchorKey: 'surgeon_absence_communication:' . $c->entityId,
        );
    }
}
