<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-180 — suivi manager des heures instrumentistes (carte Top 5, page, tiroir).
 *
 * Aucune règle n'est réimplémentée : chaque prestation passe par
 * MyHoursService::prestationRow() (périmètre et statut D-179 via EncodingStateResolver,
 * durée via MissionExecutionService — parts imputées D-156 comprises, sans double
 * comptage —, montant via FinancialCalculationService::evaluate()). Les compteurs et
 * l'estimation d'une ligne sont ceux de MyHoursService::summarize()/estimateOf() : une
 * ligne manager est, à la minute et au centime, le relevé « Mes heures » de
 * l'instrumentiste pour le même mois et le même site.
 *
 * Comparaisons (heures VALIDÉES, arrondies ici, jamais côté client) :
 *  - vs mois précédent : (M − M-1) / M-1 ;
 *  - vs moyenne personnelle : moyenne des mois M-6 … M-1 restreints à l'historique
 *    observable, c.-à-d. aux mois ≥ mois de la première prestation connue de
 *    l'instrumentiste (périmètre D-179, TOUS sites). Ce n'est jamais une date d'entrée en
 *    activité : le modèle n'en a pas (handoff § 8). Mois sans prestation = 0 h ; le mois
 *    consulté n'entre jamais dans sa propre référence ;
 *  - filtre site : heures comparées du site, période calculée tous sites ;
 *  - mois en cours : chaque mois (M compris) limité aux jours 1→J, réponse « provisional ».
 *
 * Mois d'appartenance = startAt planifié en Europe/Brussels (comme D-179).
 */
final class InstrumentistHoursService
{
    public const SORTS = ['name', 'encodedMinutes', 'validatedMinutes', 'encodedAmount', 'validatedAmount', 'previousMonthPct', 'personalAveragePct'];

    private const TIMEZONE = 'Europe/Brussels';
    private const REFERENCE_MONTHS = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MyHoursService $myHours,
        private readonly UserRepository $users,
    ) {}

    /**
     * Liste manager : une ligne par instrumentiste active (même à 0h00) et par
     * instrumentiste inactive ayant presté dans le mois et le site ; totaux du périmètre
     * filtré (site + q), jamais limités par $limit.
     *
     * @param int|null $limit classement : les $limit premières lignes ayant des heures validées
     *
     * @return array<string, mixed>
     */
    public function overview(int $year, int $month, ?int $siteId, ?string $q, string $sort, string $direction, ?int $limit, \DateTimeImmutable $now): array
    {
        $p = $this->period($year, $month, $now);
        $missions = $this->myHours->loadMissions(null, $p['windowFrom'], $p['to']);

        $monthRows = [];
        $sites = [];
        $people = [];
        foreach ($missions as $mission) {
            if ($mission->getStartAt() < $p['from']) {
                continue;
            }
            $row = $this->myHours->prestationRow($mission, $p['now'], $p['tz'], true);
            if ($row === null) {
                continue;
            }
            $sites[$row['site']['id']] = ['id' => $row['site']['id'], 'name' => (string) $row['site']['name']];
            if ($siteId !== null && $row['site']['id'] !== $siteId) {
                continue;
            }
            $instrumentist = $mission->getInstrumentist();
            $people[$instrumentist->getId()] = $instrumentist;
            $monthRows[$instrumentist->getId()][] = ['row' => $row, 'day' => $this->plannedDay($mission, $p['tz'])];
        }
        foreach ($this->users->findInstrumentists(null, true) as $active) {
            $people[$active->getId()] ??= $active;
        }

        $needle = $this->normalize((string) $q);
        if ($needle !== '') {
            $people = array_filter($people, fn (User $u) => str_contains($this->normalize($u->getFirstname() . ' ' . $u->getLastname()), $needle)
                || str_contains($this->normalize($u->getLastname() . ' ' . $u->getFirstname()), $needle));
        }

        $references = $this->referenceMinutes($missions, $p, $siteId);
        $firstKnown = $this->firstKnownMonths(null, $p['to']);

        $items = [];
        foreach ($people as $id => $instrumentist) {
            $entries = $monthRows[$id] ?? [];
            $items[] = $this->line($instrumentist, $entries, $p, $references[$id] ?? [], $firstKnown[$id] ?? null);
        }

        $totals = $this->totals($items);
        $max = max([0, ...array_map(static fn (array $i) => $i['validatedMinutes'], $items)]);
        foreach ($items as &$item) {
            $item['ratio'] = $max > 0 ? round($item['validatedMinutes'] / $max, 3) : 0;
        }
        unset($item);

        $items = $this->sort($items, $sort, $direction);
        if ($limit !== null) {
            $items = array_slice(array_values(array_filter($items, static fn (array $i) => $i['validatedMinutes'] > 0)), 0, $limit);
        }

        $sites = array_values($sites);
        usort($sites, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return [
            'month' => $p['from']->format('Y-m'),
            'provisional' => $p['provisional'],
            'asOfDay' => $p['asOfDay'],
            'siteId' => $siteId,
            'q' => $needle === '' ? null : trim((string) $q),
            'sort' => $sort,
            'direction' => $direction,
            'sites' => $sites,
            'reference' => ['previousMonth' => $p['from']->modify('-1 month')->format('Y-m')],
            'totals' => $totals,
            'items' => array_map(static function (array $i): array {
                unset($i['sortName']);
                return $i;
            }, $items),
        ];
    }

    /**
     * Comparaisons d'une instrumentiste (tiroir) — même calcul que sa ligne de liste.
     *
     * @return array{provisional: bool, asOfDay: int|null, comparison: array<string, mixed>}
     */
    public function comparisonFor(User $instrumentist, int $year, int $month, ?int $siteId, \DateTimeImmutable $now): array
    {
        $p = $this->period($year, $month, $now);
        $missions = $this->myHours->loadMissions($instrumentist, $p['windowFrom'], $p['to']);

        $current = 0;
        foreach ($missions as $mission) {
            if ($mission->getStartAt() < $p['from'] || ($siteId !== null && $mission->getSite()?->getId() !== $siteId)) {
                continue;
            }
            $row = $this->myHours->prestationRow($mission, $p['now'], $p['tz'], false);
            if ($row !== null) {
                $current += $this->comparableMinutes($row, $this->plannedDay($mission, $p['tz']), $p);
            }
        }
        $references = $this->referenceMinutes($missions, $p, $siteId);
        $firstKnown = $this->firstKnownMonths($instrumentist, $p['to']);

        return [
            'provisional' => $p['provisional'],
            'asOfDay' => $p['asOfDay'],
            'comparison' => $this->compare($current, $references[$instrumentist->getId()] ?? [], $firstKnown[$instrumentist->getId()] ?? null, $p),
        ];
    }

    /** @return array<string, mixed> */
    private function period(int $year, int $month, \DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $now = $now->setTimezone($tz);
        $provisional = $now->format('Y-m') === $from->format('Y-m');

        $referenceMonths = [];
        for ($k = self::REFERENCE_MONTHS; $k >= 1; $k--) {
            $referenceMonths[] = $from->modify(sprintf('-%d months', $k))->format('Y-m');
        }

        return [
            'tz' => $tz,
            'now' => $now,
            'from' => $from,
            'to' => $from->modify('first day of next month'),
            'windowFrom' => $from->modify(sprintf('-%d months', self::REFERENCE_MONTHS)),
            'provisional' => $provisional,
            'asOfDay' => $provisional ? (int) $now->format('j') : null,
            'referenceMonths' => $referenceMonths,
        ];
    }

    /**
     * @param list<array{row: array<string, mixed>, day: int}> $entries
     * @param array<string, int> $references
     *
     * @return array<string, mixed>
     */
    private function line(User $instrumentist, array $entries, array $p, array $references, ?string $observableSince): array
    {
        $rows = array_column($entries, 'row');
        $summary = $this->myHours->summarize($rows);
        $estimate = $this->myHours->estimateOf($rows);

        $unrated = 0;
        $consultationFees = 0;
        $validatedRated = false;
        $hasValidated = false;
        $pendingCount = 0;
        $current = 0;
        foreach ($entries as $e) {
            $r = $e['row'];
            if ($this->myHours->isUnrated($r)) {
                $unrated++;
            }
            if ($r['estimate'] !== null && $r['estimate']['rateType'] === InstrumentistRateType::CONSULTATION_FEE->value) {
                $consultationFees++;
            }
            if ($r['status'] === 'validated') {
                $hasValidated = true;
                if ($r['estimate'] !== null) {
                    $validatedRated = true;
                }
            }
            if ($r['status'] === 'pending') {
                $pendingCount++;
            }
            $current += $this->comparableMinutes($r, $e['day'], $p);
        }

        $state = match (true) {
            $estimate !== null => 'RATED',
            $unrated > 0 => 'UNRATED',
            // Aucune prestation, ou prestations sans heures : rien à valoriser (« — »).
            default => 'NO_PRESTATION',
        };

        return [
            'instrumentist' => [
                'id' => $instrumentist->getId(),
                'firstname' => $instrumentist->getFirstname(),
                'lastname' => $instrumentist->getLastname(),
                'active' => $instrumentist->isActive(),
            ],
            'count' => $summary['count'],
            'pendingCount' => $pendingCount,
            'missingHoursCount' => $summary['missingHoursCount'],
            'encodedMinutes' => $summary['encodedMinutes'],
            'validatedMinutes' => $summary['validatedMinutes'],
            'pendingMinutes' => $summary['pendingMinutes'],
            'estimateState' => $state,
            'estimate' => $estimate === null ? null : [
                'currency' => $estimate['currency'],
                'encodedAmount' => $estimate['encodedAmount'],
                // Tarif connu, rien de validé : 0,00 réel (≈ 0,00 €), comme les totaux et le tiroir.
                // null (« — ») seulement quand des prestations validées n'ont aucune estimation.
                'validatedAmount' => $validatedRated || !$hasValidated ? $estimate['validatedAmount'] : null,
                'consultationFeeCount' => $consultationFees,
                'unratedCount' => $unrated,
            ],
            'ratio' => 0,
            'comparison' => $this->compare($current, $references, $observableSince, $p),
            'sortName' => $this->normalize($instrumentist->getLastname() . ' ' . $instrumentist->getFirstname()),
            'unratedCount' => $unrated,
        ];
    }

    /**
     * @param array<string, int> $references heures validées par mois YYYY-MM
     *
     * @return array<string, mixed>
     */
    private function compare(int $current, array $references, ?string $observableSince, array $p): array
    {
        $previousKey = $p['referenceMonths'][self::REFERENCE_MONTHS - 1];
        if ($observableSince === null || $previousKey < $observableSince) {
            $previous = ['validatedMinutes' => 0, 'pct' => null, 'available' => false, 'reason' => 'NO_REFERENCE'];
        } else {
            $ref = $references[$previousKey] ?? 0;
            $previous = $ref === 0
                ? ['validatedMinutes' => 0, 'pct' => null, 'available' => false, 'reason' => 'ZERO_REFERENCE']
                : ['validatedMinutes' => $ref, 'pct' => (int) round(($current - $ref) * 100 / $ref), 'available' => true, 'reason' => null];
        }

        $months = $observableSince === null ? [] : array_values(array_filter($p['referenceMonths'], static fn (string $m) => $m >= $observableSince));
        if ($months === []) {
            $average = ['validatedMinutes' => 0, 'pct' => null, 'available' => false, 'reason' => 'NO_REFERENCE', 'monthsCounted' => 0, 'from' => null, 'to' => null];
        } else {
            $sum = 0;
            foreach ($months as $m) {
                $sum += $references[$m] ?? 0;
            }
            $avg = $sum / count($months);
            $average = [
                'validatedMinutes' => (int) round($avg),
                'pct' => $avg > 0 ? (int) round(($current - $avg) * 100 / $avg) : null,
                'available' => $avg > 0,
                'reason' => $avg > 0 ? null : 'ZERO_REFERENCE',
                'monthsCounted' => count($months),
                'from' => $months[0],
                'to' => $months[count($months) - 1],
            ];
        }
        $average['observableSince'] = $observableSince;

        return ['previousMonth' => $previous, 'personalAverage' => $average];
    }

    /**
     * Heures validées par instrumentiste et par mois de référence (M-6 … M-1), site filtré,
     * jours 1→J si le mois consulté est en cours.
     *
     * @param Mission[] $missions
     *
     * @return array<int, array<string, int>>
     */
    private function referenceMinutes(array $missions, array $p, ?int $siteId): array
    {
        $acc = [];
        foreach ($missions as $mission) {
            if ($mission->getStartAt() >= $p['from'] || ($siteId !== null && $mission->getSite()?->getId() !== $siteId)) {
                continue;
            }
            $row = $this->myHours->prestationRow($mission, $p['now'], $p['tz'], false);
            if ($row === null) {
                continue;
            }
            $minutes = $this->comparableMinutes($row, $this->plannedDay($mission, $p['tz']), $p);
            $key = $mission->getStartAt()->setTimezone($p['tz'])->format('Y-m');
            $id = $mission->getInstrumentist()->getId();
            $acc[$id][$key] = ($acc[$id][$key] ?? 0) + $minutes;
        }

        return $acc;
    }

    /** Minutes validées comptées dans une comparaison (0 si en attente, sans heures ou après J). */
    private function comparableMinutes(array $row, int $day, array $p): int
    {
        if ($row['status'] !== 'validated' || ($p['asOfDay'] !== null && $day > $p['asOfDay'])) {
            return 0;
        }

        return (int) ($row['minutes'] ?? 0);
    }

    private function plannedDay(Mission $mission, \DateTimeZone $tz): int
    {
        return (int) $mission->getStartAt()->setTimezone($tz)->format('j');
    }

    /**
     * Mois (YYYY-MM) de la première prestation connue par instrumentiste, tous sites,
     * avant la fin du mois consulté. Les statuts candidats sont toujours résolus en
     * soumis/validé/verrouillé par EncodingStateResolver : le MIN sur le statut suffit.
     * startAt est stocké en heure murale de Bruxelles (BusinessDateTimeImmutableType).
     *
     * @return array<int, string>
     */
    private function firstKnownMonths(?User $instrumentist, \DateTimeImmutable $to): array
    {
        $query = $this->em->createQuery(
            'SELECT IDENTITY(m.instrumentist) AS instrumentistId, MIN(m.startAt) AS firstAt
             FROM App\Entity\Mission m
             WHERE m.instrumentist IS NOT NULL AND m.status IN (:statuses) AND m.startAt < :to'
            . ($instrumentist !== null ? ' AND m.instrumentist = :me' : '')
            . ' GROUP BY m.instrumentist'
        )
            ->setParameter('statuses', MyHoursService::STATUSES)
            ->setParameter('to', $to);
        if ($instrumentist !== null) {
            $query->setParameter('me', $instrumentist);
        }

        $months = [];
        foreach ($query->getArrayResult() as $r) {
            $months[(int) $r['instrumentistId']] = substr((string) $r['firstAt'], 0, 7);
        }

        return $months;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function totals(array $items): array
    {
        $cents = static fn (?string $amount): int => $amount === null ? 0 : (int) round((float) $amount * 100);
        $money = static fn (int $c): string => number_format($c / 100, 2, '.', '');

        $t = ['count' => 0, 'pendingCount' => 0, 'encodedMinutes' => 0, 'validatedMinutes' => 0, 'pendingMinutes' => 0];
        $encodedCents = 0;
        $validatedCents = 0;
        $currency = null;
        $unratedInstrumentists = 0;
        $unrated = 0;
        foreach ($items as $i) {
            foreach (array_keys($t) as $k) {
                $t[$k] += $i[$k];
            }
            if ($i['estimate'] !== null) {
                $currency ??= $i['estimate']['currency'];
                $encodedCents += $cents($i['estimate']['encodedAmount']);
                $validatedCents += $cents($i['estimate']['validatedAmount']);
            }
            if ($i['unratedCount'] > 0) {
                $unratedInstrumentists++;
                $unrated += $i['unratedCount'];
            }
        }

        return [
            'instrumentistCount' => count($items),
            ...$t,
            'validatedPct' => $t['encodedMinutes'] > 0 ? (int) round($t['validatedMinutes'] / $t['encodedMinutes'] * 100) : 0,
            'currency' => $currency,
            'encodedAmount' => $currency === null ? null : $money($encodedCents),
            'validatedAmount' => $currency === null ? null : $money($validatedCents),
            'unratedInstrumentistCount' => $unratedInstrumentists,
            'unratedCount' => $unrated,
        ];
    }

    /**
     * Tri serveur ; valeurs indisponibles (null) toujours en fin de liste, égalité → nom.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sort(array $items, string $sort, string $direction): array
    {
        $value = static fn (array $i): int|string|null => match ($sort) {
            'name' => $i['sortName'],
            'encodedMinutes' => $i['encodedMinutes'],
            'validatedMinutes' => $i['validatedMinutes'],
            'encodedAmount' => $i['estimate'] === null ? null : (int) round((float) $i['estimate']['encodedAmount'] * 100),
            'validatedAmount' => ($i['estimate']['validatedAmount'] ?? null) === null ? null : (int) round((float) $i['estimate']['validatedAmount'] * 100),
            'previousMonthPct' => $i['comparison']['previousMonth']['pct'],
            'personalAveragePct' => $i['comparison']['personalAverage']['pct'],
        };
        $sign = $direction === 'asc' ? 1 : -1;

        usort($items, static function (array $a, array $b) use ($value, $sign): int {
            $va = $value($a);
            $vb = $value($b);
            if ($va === null || $vb === null) {
                $c = ($va === null) <=> ($vb === null);
            } else {
                $c = $sign * ($va <=> $vb);
            }

            return $c !== 0 ? $c : strcmp($a['sortName'], $b['sortName']);
        });

        return array_values(array_map(static function (array $i): array {
            unset($i['unratedCount']);
            return $i;
        }, $items));
    }

    /** Minuscules sans accents, pour la recherche et le tri par nom. */
    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        }

        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
