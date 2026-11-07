<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\GovernedChange;
use App\Entity\NotificationPreference;
use App\Enum\ActivityEventType;
use App\Service\DefaultNotificationPreferenceResolver;

/**
 * H5b (D-132) — a person's notification preference for ONE type (one PATCH = one operation =
 * one event): only the channels that really changed, before → after. The first save of a
 * type materialises a row seeded from the defaults: its "before" is the type's default.
 */
final class NotificationPreferenceDescriber extends AbstractGovernedDescriber
{
    private const CHANNELS = ['inAppEnabled' => 'in-app', 'emailEnabled' => 'email', 'pushEnabled' => 'push'];

    public function entityClass(): string { return NotificationPreference::class; }

    public function trackedFields(): array
    {
        return ['inAppEnabled' => 'In-app', 'emailEnabled' => 'Email', 'pushEnabled' => 'Push'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action === GovernedChange::DELETED) {
            return null;
        }
        /** @var NotificationPreference $p */
        $p    = $c->entity;
        $type = $p->getNotificationType();

        if ($c->action === GovernedChange::CREATED) {
            $defaults = DefaultNotificationPreferenceResolver::defaultsFor($type);
            $before   = ['inAppEnabled' => $defaults->inApp, 'emailEnabled' => $defaults->email, 'pushEnabled' => $defaults->push];
            $after    = ['inAppEnabled' => $p->isInAppEnabled(), 'emailEnabled' => $p->isEmailEnabled(), 'pushEnabled' => $p->isPushEnabled()];
            $changes  = [];
            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[] = ['field' => $field, 'label' => $this->trackedFields()[$field], 'from' => $before[$field], 'to' => $value, 'fromId' => null, 'toId' => null, 'redacted' => false];
                }
            }
            if ($changes === []) {
                return null; // saved the defaults back: nothing changed for the person
            }
            $c = new GovernedChange($c->action, $c->entity, $c->entityId, $changes, [], $c->actor, $c->occurredAt);
        }

        $person = $p->getUser();
        $parts  = [];
        foreach ($c->changes as $ch) {
            $parts[] = sprintf('%s %s', self::CHANNELS[$ch['field']] ?? $ch['field'], $ch['to'] ? 'activé' : 'désactivé');
        }
        $typeLabel = self::typeLabel($type->value);
        $who       = $c->actor !== null && $person !== null && $c->actor->getId() !== $person->getId()
            ? sprintf('%s pour %s', self::actorName($c), ActivityJournal::displayName($person))
            : (ActivityJournal::displayName($person) ?? self::actorName($c));

        return $this->record(ActivityEventType::NOTIFICATION_PREFERENCES_UPDATED, $c, 'User', (string) ActivityJournal::displayName($person),
            sprintf('Préférence « %s » : %s — par %s', $typeLabel, implode(', ', $parts), $who),
            ['userId' => $person?->getId(), 'userName' => ActivityJournal::displayName($person), 'notificationType' => $type->value],
            userId: $person?->getId());
    }

    private static function typeLabel(string $type): string
    {
        return \App\Activity\Recorder\NotificationActivityRecorder::label($type);
    }
}
