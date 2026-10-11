<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Entity\SurgeonSchedulePost;
use App\Enum\ActivityEventType;

/**
 * H5b (D-132) — the habitual planning post of a surgeon: it governs the N1 absence impacts
 * (D-127) and the generation of every month, so who / where / when / with whom it changed
 * is traced, before → after. Never rewrites a Mission (the template only feeds the next
 * generations — D-130).
 */
final class SurgeonSchedulePostDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string
    {
        return SurgeonSchedulePost::class;
    }

    public function trackedFields(): array
    {
        return [
            'surgeon' => 'Chirurgien', 'site' => 'Site', 'type' => 'Type', 'period' => 'Période',
            'recurrence.frequency' => 'Fréquence', 'recurrence.interval' => 'Intervalle',
            'recurrence.weekdays' => 'Jours', 'recurrence.monthWeeks' => 'Semaines du mois',
            'recurrence.anchorDate' => 'Date de référence',
            'instrumentist' => 'Instrumentiste habituelle', 'startDate' => 'Début', 'endDate' => 'Fin',
            'active' => 'Actif',
        ];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var SurgeonSchedulePost $post */
        $post  = $c->entity;
        $label = self::label($post, $c->entityId);
        $actor = self::actorName($c);
        $dr    = $post->getSurgeon()?->getDrName() ?? 'chirurgien inconnu';
        $days  = FrenchLabels::weekdays($post->getRecurrence()->getWeekdays());

        [$type, $summary] = match (true) {
            $c->action === GovernedChange::CREATED => [
                ActivityEventType::SURGEON_SCHEDULE_POST_CREATED,
                sprintf('%s a créé le poste habituel du %s (%s, %s, %s) — instrumentiste habituelle : %s',
                    $actor, $dr, $days, FrenchLabels::PERIODS[$post->getPeriod()->value] ?? $post->getPeriod()->value,
                    $post->getSite()?->getName() ?? '—', ActivityJournal::displayName($post->getInstrumentist()) ?? 'aucune'),
            ],
            $c->action === GovernedChange::DELETED => [
                ActivityEventType::SURGEON_SCHEDULE_POST_DELETED,
                sprintf('%s a supprimé le poste habituel du %s du %s', $actor, $dr, $days),
            ],
            $c->changed('active') && $post->isActive() === false && count($c->changes) === 1 => [
                ActivityEventType::SURGEON_SCHEDULE_POST_DEACTIVATED,
                sprintf('%s a désactivé le poste habituel du %s du %s (%s)', $actor, $dr, $days, $post->getSite()?->getName() ?? '—'),
            ],
            default => [
                ActivityEventType::SURGEON_SCHEDULE_POST_UPDATED,
                sprintf('%s a modifié le poste habituel du %s du %s : %s', $actor, $dr, $days, $this->readableDiff($c)),
            ],
        };

        return $this->record($type, $c, 'SurgeonSchedulePost', $label, $summary, [
            'postId'            => $c->entityId,
            'surgeonId'         => $post->getSurgeon()?->getId(),
            'surgeonName'       => ActivityJournal::displayName($post->getSurgeon()),
            'instrumentistId'   => $post->getInstrumentist()?->getId(),
            'instrumentistName' => ActivityJournal::displayName($post->getInstrumentist()),
            'siteId'            => $post->getSite()?->getId(),
            'siteName'          => $post->getSite()?->getName(),
            'period'            => $post->getPeriod()->value,
            'weekdays'          => $days,
        ], siteId: $post->getSite()?->getId(), userId: $post->getSurgeon()?->getId());
    }

    private function readableDiff(GovernedChange $c): string
    {
        $parts = [];
        foreach ($c->changes as $ch) {
            $map = match ($ch['field']) {
                'period' => FrenchLabels::PERIODS,
                'type' => FrenchLabels::MISSION_TYPES,
                'recurrence.frequency' => FrenchLabels::FREQUENCIES,
                default => [],
            };
            [$from, $to] = match ($ch['field']) {
                'recurrence.weekdays' => [FrenchLabels::weekdays($ch['from']), FrenchLabels::weekdays($ch['to'])],
                'startDate', 'endDate', 'recurrence.anchorDate' => [FrenchLabels::isoDate($ch['from']), FrenchLabels::isoDate($ch['to'])],
                default => [self::display($ch['from'], $map), self::display($ch['to'], $map)],
            };
            $parts[] = $ch['field'] === 'instrumentist'
                ? sprintf('%s → %s', $from === '—' ? 'aucune' : $from, $to === '—' ? 'aucune' : $to)
                : sprintf('%s : %s → %s', $ch['label'], $from, $to);
        }

        return implode(' ; ', $parts);
    }

    private static function label(SurgeonSchedulePost $p, int $id): string
    {
        return sprintf('Poste habituel #%d — %s, %s %s', $id, $p->getSurgeon()?->getDrName() ?? '—',
            FrenchLabels::weekdays($p->getRecurrence()->getWeekdays()), FrenchLabels::PERIODS[$p->getPeriod()->value] ?? '');
    }
}
