<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Enum\AuditEventType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-171 — reprise manager d'un encodage non soumis.
 *
 * revision() : empreinte de tout ce que « Terminer et valider » valide — interventions et lignes
 * de matériel (id + version D-154 : ajout, modification, suppression), brouillons d'intervention
 * (ajout, suppression) et heures réelles. Servie au manager avec le détail de mission, renvoyée
 * par la fenêtre de confirmation et recalculée par la transition : une différence signifie que
 * quelqu'un (l'instrumentiste, le chirurgien, un autre manager) a modifié l'encodage entre-temps
 * → 409, rien n'est validé sur un contenu que le manager n'a pas vu. Toujours lue en base (jamais
 * les collections en mémoire, qui peuvent être périmées) : la même requête des deux côtés.
 */
final class EncodingTakeoverService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function revision(Mission $mission): string
    {
        $rows = $this->em->getConnection()->fetchAllNumeric(
            "SELECT 'I', id, version FROM mission_intervention WHERE mission_id = :m
             UNION ALL SELECT 'M', id, version FROM material_line WHERE mission_id = :m
             UNION ALL SELECT 'D', id, 0 FROM mission_intervention_draft WHERE mission_id = :m
             UNION ALL SELECT 'H', 0, CONCAT_WS('|', COALESCE(actual_start_at, '-'), COALESCE(actual_end_at, '-'), COALESCE(actual_duration_minutes, '-'))
                 FROM mission_execution WHERE mission_id = :m",
            ['m' => $mission->getId()],
        );

        $parts = array_map(static fn (array $r): string => $r[0] . ':' . $r[1] . ':' . $r[2], $rows);
        sort($parts);

        return substr(hash('sha256', implode(';', $parts)), 0, 16);
    }

    /**
     * Dernière reprise de l'encodage de cette mission (audit MISSION_ENCODING_TAKEN_OVER), null
     * si aucune : affichée à l'instrumentiste (qui a repris son encodage, quand) et au manager.
     *
     * @return array{byName: ?string, at: string, hoursMissing: bool}|null
     */
    public function lastTakeover(Mission $mission): ?array
    {
        /** @var AuditEvent|null $event */
        $event = $this->em->createQueryBuilder()
            ->select('e')
            ->from(AuditEvent::class, 'e')
            ->where('e.mission = :mission')
            ->andWhere('e.eventType = :type')
            ->setParameter('mission', $mission)
            ->setParameter('type', AuditEventType::MISSION_ENCODING_TAKEN_OVER)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($event === null) {
            return null;
        }

        $payload = $event->getPayload() ?? [];

        return [
            'byName'       => is_string($payload['actorName'] ?? null) ? $payload['actorName'] : null,
            'at'           => $event->getCreatedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            'hoursMissing' => ($payload['hoursMissing'] ?? false) === true,
        ];
    }
}
