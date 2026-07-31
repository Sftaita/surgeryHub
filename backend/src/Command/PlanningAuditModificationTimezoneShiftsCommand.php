<?php

namespace App\Command;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Enum\AuditEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-090 forensic audit — read-only, never mutates anything.
 *
 * Detects Mission rows whose stored startAt/endAt may have been shifted by the D-089/D-090
 * timezone bug in PlanningModificationService::combineDateTime() (fixed — see D-090): every
 * call to Planning V2 Modification mode's "Redéployer" (apply-modifications) that hit
 * MissionPostDeployService::updateSchedule() persisted a DST-offset-shifted instant (1h in
 * winter, 2h in summer) instead of the wall-clock time the manager actually typed.
 *
 * Method: MISSION_TIME_CHANGED_POST_DEPLOY AuditEvent rows already capture, per mutation,
 * `fromStartAt`/`toStartAt` (ATOM strings) — `fromStartAt` is always trustworthy (it's
 * `Mission::getStartAt()` read BEFORE this mutation, always correctly Brussels-labeled by
 * BusinessDateTimeImmutableType). `toStartAt` was captured from the in-memory entity
 * immediately after `setStartAt($buggyValue)` — i.e. BEFORE Doctrine's write-side
 * `convertToDatabaseValue()` ran its `setTimezone(Brussels)` shift on flush. Its wall-clock
 * digits (hour:minute) are therefore exactly what the manager's line said (combineDateTime's
 * "{date}T{time}:00" — the digits are correct, only the offset label was wrong), while the
 * value actually written to the `mission` table afterward is those SAME digits reinterpreted
 * as Brussels time by the shift — which is wrong whenever the naive default (UTC) offset
 * differed from the true Brussels offset for that date (always, except the few weeks per
 * year Brussels happens to be UTC+0... which never happens: Brussels is UTC+1/UTC+2, never
 * UTC+0. So EVERY mutation through the buggy code path is suspect, not just some).
 *
 * This command compares the audit's `toStartAt` wall-clock digits against the mission's
 * CURRENT stored startAt wall-clock digits. A mismatch of exactly 60 or 120 minutes is the
 * bug's signature. This is a STRONG signal, not proof: a mission could have been correctly
 * re-edited again afterward (masking or coincidentally reproducing the same delta), so every
 * flagged row needs manual confirmation (cross-check against the deployed PDF/email sent at
 * the time, or the surgeon/site's usual shift hours) before any correction is made — this
 * command deliberately does not attempt to "fix" anything.
 *
 * Usage: php bin/console app:planning:audit-modification-timezone-shifts [--env=prod]
 */
#[AsCommand(
    name: 'app:planning:audit-modification-timezone-shifts',
    description: 'D-090 — read-only audit for missions possibly time-shifted by the D-089 Modification-mode timezone bug. Never mutates data.',
)]
class PlanningAuditModificationTimezoneShiftsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('D-090 — Audit (lecture seule) des décalages horaires possibles (mode Modification)');

        /** @var AuditEvent[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(AuditEvent::class, 'e')
            ->where('e.eventType = :type')
            ->setParameter('type', AuditEventType::MISSION_TIME_CHANGED_POST_DEPLOY)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($events)) {
            $io->success('Aucun événement MISSION_TIME_CHANGED_POST_DEPLOY trouvé — aucune donnée à auditer.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('%d événement(s) MISSION_TIME_CHANGED_POST_DEPLOY trouvé(s).', count($events)));

        $rows      = [];
        $suspected = 0;

        foreach ($events as $event) {
            $payload = $event->getPayload() ?? [];
            $mission = $event->getMission();

            if ($mission === null || !isset($payload['toStartAt'])) {
                continue;
            }

            $auditedToStartAt = self::tryParseAtom($payload['toStartAt']);
            if ($auditedToStartAt === null) {
                continue;
            }

            $currentStartAt = $mission->getStartAt();
            if ($currentStartAt === null) {
                continue;
            }

            // Wall-clock digit comparison only — the audit's own offset label is exactly
            // what the bug got wrong, so we deliberately never trust it, only the digits.
            $auditedWallClock  = $auditedToStartAt->format('H:i');
            $currentWallClock  = $currentStartAt->format('H:i');

            if ($auditedWallClock === $currentWallClock) {
                continue; // consistent — no drift detected for this event
            }

            $deltaMinutes = self::wallClockDeltaMinutes($auditedWallClock, $currentWallClock);
            $isDstShapedDelta = in_array(abs($deltaMinutes), [60, 120], true);

            if ($isDstShapedDelta) {
                $suspected++;
            }

            $rows[] = [
                $event->getId(),
                $mission->getId(),
                $event->getCreatedAt()?->format('Y-m-d H:i') ?? '?',
                $currentStartAt->format('Y-m-d'),
                $auditedWallClock . ' (attendu, d\'après l\'audit)',
                $currentWallClock . ' (actuel en base)',
                $deltaMinutes . ' min',
                $isDstShapedDelta ? '<fg=red;options=bold>SUSPECTED_DST_SHIFT</>' : 'écart (autre cause)',
            ];
        }

        if (empty($rows)) {
            $io->success('Aucun écart détecté entre le résultat attendu (audit) et l\'heure actuellement stockée.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Audit#', 'Mission#', 'Événement (créé le)', 'Date mission', 'Attendu', 'Actuel', 'Delta', 'Diagnostic'],
            $rows,
        );

        $io->warning(sprintf(
            '%d ligne(s) avec écart, dont %d présentant la signature exacte du bug D-089 (delta de 1h ou 2h). '
            . 'Ceci est un signal, pas une preuve : vérifier manuellement chaque ligne (PDF/email envoyé au moment '
            . 'des faits, horaires habituels du chirurgien/site) avant toute correction. Aucune donnée n\'a été modifiée par cette commande.',
            count($rows),
            $suspected,
        ));

        return Command::SUCCESS;
    }

    private static function tryParseAtom(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Signed delta in minutes from $from to $to, both "H:i", wrapped to [-720, 720]. */
    private static function wallClockDeltaMinutes(string $from, string $to): int
    {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));
        $delta = ($th * 60 + $tm) - ($fh * 60 + $fm);

        if ($delta > 720) {
            $delta -= 1440;
        } elseif ($delta < -720) {
            $delta += 1440;
        }

        return $delta;
    }
}
