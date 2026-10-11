<?php

namespace App\Notification;

use App\Enum\OpenEpisodeCause;
use App\Enum\OpenPoolExclusionReason;

/**
 * H3 (D-129) — "this mission became OPEN at this moment, for this cause".
 *
 * $episodeRef identifies the opening, and is the idempotency key of every pool
 * notification it produces: a Messenger retry of the same transition replays the SAME
 * episode (nothing new is sent), while OPEN → ASSIGNED → OPEN later is a NEW episode
 * (built from a different transition instant / batch), so people can be solicited again.
 *
 * $excluded: userId → contextual exclusion reason for THIS episode only (D-127).
 * The correlationId is the one of the operation being processed (CorrelationContext).
 */
final class OpenEpisode
{
    /** @param array<int, OpenPoolExclusionReason> $excluded */
    public function __construct(
        public readonly int $missionId,
        public readonly string $episodeRef,
        public readonly OpenEpisodeCause $cause,
        public readonly array $excluded = [],
        public readonly ?int $actorId = null,
    ) {
    }

    /**
     * Opening produced by one lifecycle transition (release, publication, take-over…):
     * the transition instant (microseconds, carried unchanged by the Messenger message)
     * makes retries replay the same episode and a later reopening a new one.
     *
     * @param array<int, OpenPoolExclusionReason> $excluded
     */
    public static function forTransition(int $missionId, OpenEpisodeCause $cause, \DateTimeImmutable $occurredAt, array $excluded = [], ?int $actorId = null): self
    {
        return new self(
            $missionId,
            sprintf('open:%d:%s:%s', $missionId, $cause->value, $occurredAt->format('Y-m-d\TH:i:s.uP')),
            $cause,
            $excluded,
            $actorId,
        );
    }

    /**
     * Opening produced by a batch operation identified by $batchKey (deployment id,
     * Modification batch id…).
     *
     * @param array<int, OpenPoolExclusionReason> $excluded
     */
    public static function forBatch(int $missionId, OpenEpisodeCause $cause, string $batchKey, array $excluded = [], ?int $actorId = null): self
    {
        return new self($missionId, sprintf('open:%d:%s:%s', $missionId, $cause->value, $batchKey), $cause, $excluded, $actorId);
    }

    /** @return array{missionId: int, episodeRef: string, cause: string, excluded: array<int, string>, actorId: ?int} */
    public function toArray(): array
    {
        return [
            'missionId'  => $this->missionId,
            'episodeRef' => $this->episodeRef,
            'cause'      => $this->cause->value,
            'excluded'   => array_map(static fn (OpenPoolExclusionReason $r) => $r->value, $this->excluded),
            'actorId'    => $this->actorId,
        ];
    }

    /** @param array{missionId: int, episodeRef: string, cause: string, excluded?: array<int, string>, actorId?: ?int} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['missionId'],
            (string) $data['episodeRef'],
            OpenEpisodeCause::from($data['cause']),
            array_map(static fn (string $r) => OpenPoolExclusionReason::from($r), $data['excluded'] ?? []),
            $data['actorId'] ?? null,
        );
    }
}
