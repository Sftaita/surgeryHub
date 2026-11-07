<?php

namespace App\Message;

use App\Notification\OpenEpisode;

/**
 * H3 (D-129) — asks OpenMissionPoolNotifier, asynchronously and after the business commit
 * (R-07), to solicit the pool for one or several opening episodes. Used by the synchronous
 * paths that open a mission without a lifecycle message of their own (e.g. a cancelled
 * mission restored as OPEN after its surgeon absence was removed).
 *
 * Episodes are carried as plain arrays (OpenEpisode::toArray()) — serializer-agnostic.
 */
final class OpenMissionPoolMessage
{
    /** @param list<array<string, mixed>> $episodes */
    public function __construct(
        public readonly array $episodes,
        public readonly bool $batch = false,
    ) {
    }

    /** @param list<OpenEpisode> $episodes */
    public static function of(array $episodes, bool $batch = false): self
    {
        return new self(array_map(static fn (OpenEpisode $e) => $e->toArray(), $episodes), $batch);
    }

    /** @return list<OpenEpisode> */
    public function toEpisodes(): array
    {
        return array_map(static fn (array $e) => OpenEpisode::fromArray($e), $this->episodes);
    }
}
