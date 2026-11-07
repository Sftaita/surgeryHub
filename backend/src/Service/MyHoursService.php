<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EffectiveDurationSource;
use App\Enum\EncodingState;
use App\Enum\FinancialLineType;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-179 — relevé personnel « Mes heures » d'une instrumentiste pour un mois.
 *
 * Aucune règle n'est réimplémentée ici, tout est délégué aux sources uniques existantes :
 *  - périmètre : missions de l'instrumentiste dont l'encodage est SOUMIS (en attente de
 *    validation) ou VALIDÉ (VALIDATED, ou CLOSED/facturée = LOCKED). Brouillons, missions à
 *    encoder, rejetées (repassées en ENCODING_IN_PROGRESS) et annulées n'entrent jamais ;
 *  - statut : EncodingStateResolver (D-118) — SUBMITTED → en attente, VALIDATED|LOCKED → validé ;
 *  - durée : MissionExecutionService::resolveEffectiveDuration() — fin − début réels (le
 *    passage de minuit est porté par la date de fin), ou durée déclarée seule
 *    (ACTUAL_EXPLICIT), ou part imputée d'une présence commune multi-salles (PRESENCE_SHARE,
 *    D-156 : la somme des parts = la présence, jamais comptée deux fois). La source PLANNED
 *    n'est JAMAIS retenue : sans heures réelles, la
 *    prestation compte 0 h et n'est pas valorisée (D-171) ;
 *  - montant : FinancialCalculationService::evaluate($mission, instrumentistOnly: true) — le
 *    chemin de résolution unique du moteur (tarif personnel HOURLY_RATE pour un bloc,
 *    forfait CONSULTATION_FEE pour une consultation, mêmes dates d'effet et mêmes arrondis).
 *    Lecture seule, rien n'est persisté ; montant indicatif, jamais un montant validé.
 *
 * Le filtre par site agit sur la liste, les totaux et l'estimation ; la liste des sites
 * (puces) couvre toujours le mois entier. Une seule requête, aucune donnée patient ni texte
 * libre (commentaires, interventions, matériel) n'est exposé.
 */
final class MyHoursService
{
    /** Statuts candidats ; le statut affiché est ensuite tranché par EncodingStateResolver. */
    public const STATUSES = [MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED];

    private const TIMEZONE = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncodingStateResolver $encodingStateResolver,
        private readonly MissionExecutionService $missionExecutionService,
        private readonly FinancialCalculationService $financialCalculationService,
    ) {}

    /**
     * @param int      $year     année du mois demandé
     * @param int      $month    1–12
     * @param int|null $siteId   null = tous les sites
     * @param bool     $withEstimate l'appelant a VIEW_OWN_ESTIMATE sur $instrumentist
     *
     * @return array<string, mixed>
     */
    public function statement(User $instrumentist, int $year, int $month, ?int $siteId, bool $withEstimate): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $to = $from->modify('first day of next month');
        $now = new \DateTimeImmutable('now', $tz);

        $rows = [];
        foreach ($this->loadMissions($instrumentist, $from, $to) as $mission) {
            $row = $this->prestationRow($mission, $now, $tz, $withEstimate);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $sites = $this->sites($rows);
        $scoped = $siteId === null ? $rows : array_values(array_filter($rows, static fn (array $r) => $r['site']['id'] === $siteId));

        return [
            'month' => $from->format('Y-m'),
            'siteId' => $siteId,
            'sites' => $sites,
            'summary' => $this->summarize($scoped),
            'estimate' => $withEstimate ? $this->estimateOf($scoped) : null,
            'items' => array_map(static function (array $r) use ($withEstimate): array {
                if (!$withEstimate) {
                    unset($r['estimate']);
                }
                return $r;
            }, $scoped),
        ];
    }

    /**
     * Missions candidates (statuts STATUSES) d'une instrumentiste — ou de toutes quand
     * $instrumentist est null (vue manager, D-180) — dont le startAt planifié tombe dans
     * [$from ; $to[. Une seule requête ; le statut affiché reste tranché par prestationRow().
     *
     * @return Mission[]
     */
    public function loadMissions(?User $instrumentist, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $query = $this->em->createQuery(
            // ml/mi : chargés d'emblée car EncodingStateResolver::factsFromMission() les lit
            // (sinon 2 requêtes paresseuses par mission). Jamais exposés dans la réponse.
            'SELECT m, e, s, su, ml, mi, i FROM App\Entity\Mission m
             JOIN m.site s
             JOIN m.instrumentist i
             LEFT JOIN m.execution e
             LEFT JOIN m.surgeon su
             LEFT JOIN m.materialLines ml
             LEFT JOIN m.interventions mi
             WHERE m.status IN (:statuses)
               AND m.startAt >= :from AND m.startAt < :to'
            . ($instrumentist !== null ? ' AND m.instrumentist = :me' : '')
            . ' ORDER BY m.startAt DESC, m.id DESC'
        )
            ->setParameter('statuses', self::STATUSES)
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        if ($instrumentist !== null) {
            $query->setParameter('me', $instrumentist);
        }

        return $query->getResult();
    }

    /**
     * Ligne de prestation (périmètre, statut, durée, estimation) — source unique partagée
     * avec la vue manager (D-180). null = hors périmètre (ni soumise ni validée).
     *
     * @return array<string, mixed>|null
     */
    public function prestationRow(Mission $mission, \DateTimeImmutable $now, \DateTimeZone $tz, bool $withEstimate): ?array
    {
        $state = $this->encodingStateResolver->resolveForMission($mission, $now);
        $status = match ($state) {
            EncodingState::SUBMITTED => 'pending',
            EncodingState::VALIDATED, EncodingState::LOCKED => 'validated',
            default => null,
        };
        if ($status === null) {
            return null;
        }

        $duration = $this->missionExecutionService->resolveEffectiveDuration($mission);
        $hasRealHours = $duration->source !== EffectiveDurationSource::PLANNED;

        $execution = $mission->getExecution();
        $start = $hasRealHours && $duration->source === EffectiveDurationSource::ACTUAL_TIMES ? $execution?->getActualStartAt()?->setTimezone($tz) : null;
        $end = $hasRealHours && $duration->source === EffectiveDurationSource::ACTUAL_TIMES ? $execution?->getActualEndAt()?->setTimezone($tz) : null;
        $day = ($start ?? $mission->getStartAt()?->setTimezone($tz))?->format('Y-m-d');

        $surgeon = $mission->getSurgeon();
        $site = $mission->getSite();

        return [
            'missionId' => $mission->getId(),
            'date' => $day,
            'start' => $start?->format(\DateTimeInterface::ATOM),
            'end' => $end?->format(\DateTimeInterface::ATOM),
            'endsNextDay' => $start !== null && $end !== null && $end->format('Y-m-d') !== $start->format('Y-m-d'),
            'minutes' => $hasRealHours ? $duration->minutes : null,
            'durationSource' => $hasRealHours ? $duration->source->value : null,
            'missionType' => $mission->getType()?->value,
            'status' => $status,
            'surgeonName' => $surgeon !== null ? trim(sprintf('Dr %s %s', $surgeon->getFirstname() ?? '', $surgeon->getLastname() ?? '')) : null,
            'site' => ['id' => $site?->getId(), 'name' => $site?->getName()],
            'estimate' => $withEstimate ? $this->lineEstimate($mission) : null,
        ];
    }

    /** @return array{rateType: string, unitAmount: string, amount: string, currency: string}|null */
    private function lineEstimate(Mission $mission): ?array
    {
        $evaluation = $this->financialCalculationService->evaluate($mission, true);
        foreach ($evaluation->lineSpecs as $spec) {
            $type = $spec['lineType'] ?? null;
            if ($type !== FinancialLineType::INSTRUMENTIST_HOURLY && $type !== FinancialLineType::INSTRUMENTIST_CONSULTATION_FEE) {
                continue;
            }

            return [
                'rateType' => $type === FinancialLineType::INSTRUMENTIST_HOURLY
                    ? InstrumentistRateType::HOURLY_RATE->value
                    : InstrumentistRateType::CONSULTATION_FEE->value,
                'unitAmount' => (string) $spec['unitAmount'],
                'amount' => (string) $spec['totalAmount'],
                'currency' => (string) $spec['currency'],
            ];
        }

        return null;
    }

    /**
     * Sites du mois entier, triés par nombre de prestations puis par nom.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, count: int}>
     */
    private function sites(array $rows): array
    {
        $sites = [];
        foreach ($rows as $r) {
            $id = $r['site']['id'];
            $sites[$id] ??= ['id' => $id, 'name' => (string) $r['site']['name'], 'count' => 0];
            $sites[$id]['count']++;
        }
        $sites = array_values($sites);
        usort($sites, static fn (array $a, array $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return $sites;
    }

    /**
     * Heures encodées = en attente + validées ; une prestation sans heures réelles compte 0.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{count: int, encodedMinutes: int, validatedMinutes: int, pendingMinutes: int, validatedPct: int, missingHoursCount: int}
     */
    public function summarize(array $rows): array
    {
        $encoded = 0;
        $validated = 0;
        $missing = 0;
        foreach ($rows as $r) {
            if ($r['minutes'] === null) {
                $missing++;
                continue;
            }
            $encoded += $r['minutes'];
            if ($r['status'] === 'validated') {
                $validated += $r['minutes'];
            }
        }

        return [
            'count' => count($rows),
            'encodedMinutes' => $encoded,
            'validatedMinutes' => $validated,
            'pendingMinutes' => $encoded - $validated,
            'validatedPct' => $encoded > 0 ? (int) round($validated / $encoded * 100) : 0,
            'missingHoursCount' => $missing,
        ];
    }

    /**
     * Estimation indicative du périmètre ; null quand aucune prestation n'est valorisable
     * (aucune section financière, jamais une section à 0 €).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public function estimateOf(array $rows): ?array
    {
        $encodedCents = 0;
        $validatedCents = 0;
        $lines = [];
        $hourlyRates = [];
        $currency = null;
        $unrated = 0;

        foreach ($rows as $r) {
            $e = $r['estimate'];
            if ($e === null) {
                if ($this->isUnrated($r)) {
                    $unrated++;
                }
                continue;
            }
            $cents = (int) round((float) $e['amount'] * 100);
            $encodedCents += $cents;
            if ($r['status'] === 'validated') {
                $validatedCents += $cents;
            }
            $currency ??= $e['currency'];
            if ($e['rateType'] === InstrumentistRateType::HOURLY_RATE->value) {
                $hourlyRates[] = $e['unitAmount'];
            }

            $key = $r['site']['id'] . '|' . $e['rateType'] . '|' . $e['unitAmount'];
            $lines[$key] ??= [
                'siteName' => (string) $r['site']['name'],
                'rateType' => $e['rateType'],
                'unitAmount' => $e['unitAmount'],
                'minutes' => 0,
                'count' => 0,
                'amountCents' => 0,
            ];
            $lines[$key]['count']++;
            $lines[$key]['minutes'] += (int) ($r['minutes'] ?? 0);
            $lines[$key]['amountCents'] += $cents;
        }

        if ($lines === []) {
            return null;
        }

        $lines = array_values($lines);
        usort($lines, static fn (array $a, array $b) => $b['amountCents'] <=> $a['amountCents']);
        $money = static fn (int $cents): string => number_format($cents / 100, 2, '.', '');

        return [
            'currency' => $currency,
            'encodedAmount' => $money($encodedCents),
            'validatedAmount' => $money($validatedCents),
            'hourlyRateMin' => $hourlyRates === [] ? null : $money((int) round(min(array_map('floatval', $hourlyRates)) * 100)),
            'hourlyRateMax' => $hourlyRates === [] ? null : $money((int) round(max(array_map('floatval', $hourlyRates)) * 100)),
            'unratedCount' => $unrated,
            'lines' => array_map(static fn (array $l): array => [
                'siteName' => $l['siteName'],
                'rateType' => $l['rateType'],
                'unitAmount' => $l['unitAmount'],
                'minutes' => $l['rateType'] === InstrumentistRateType::HOURLY_RATE->value ? $l['minutes'] : null,
                'count' => $l['count'],
                'amount' => $money($l['amountCents']),
            ], $lines),
        ];
    }

    /**
     * Prestation non valorisée faute de tarif (« tarif manquant »), à distinguer d'une
     * prestation sans heures, déjà signalée par missingHoursCount.
     *
     * @param array<string, mixed> $r ligne construite avec l'estimation
     */
    public function isUnrated(array $r): bool
    {
        if ($r['estimate'] !== null) {
            return false;
        }
        // D-156 — part imputée nulle d'une présence commune : rien à valoriser ici, la
        // présence est payée sur les autres missions de la prestation.
        $zeroShare = $r['durationSource'] === EffectiveDurationSource::PRESENCE_SHARE->value && $r['minutes'] === 0;

        return !$zeroShare && ($r['minutes'] !== null || $r['missionType'] === 'CONSULTATION');
    }
}
