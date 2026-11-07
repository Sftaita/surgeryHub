<?php

namespace App\Activity\Governed;

use App\Activity\ActivityRecord;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * H5b (D-132) — how ONE entity class tells its own changes in the transversal journal:
 * which fields are tracked (an update touching none of them writes nothing), which ones are
 * free text (only "changed" is recorded, never the value), and the readable sentence + the
 * controlled payload of each event. Never decides anything: describes a change already made.
 */
#[AutoconfigureTag(self::TAG)]
interface GovernedEntityDescriber
{
    public const TAG = 'app.governed_entity_describer';

    /** @return class-string */
    public function entityClass(): string;

    /**
     * Tracked fields and their French label. Embedded fields use Doctrine's dotted name
     * ("recurrence.weekdays").
     *
     * @return array<string, string>
     */
    public function trackedFields(): array;

    /** @return list<string> tracked fields whose VALUE is never recorded (free text) */
    public function redactedFields(): array;

    /** null: this change is not an event (e.g. a technical refresh, an automatic row already audited) */
    public function describe(GovernedChange $change): ?ActivityRecord;
}
