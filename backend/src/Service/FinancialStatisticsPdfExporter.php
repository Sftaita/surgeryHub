<?php

namespace App\Service;

use App\Dto\FinancialStatisticsFilter;
use App\Enum\StatisticsGranularity;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-186 — export PDF d'une vue « Statistiques financières ». Aucun calcul propre : les
 * lignes, soldes et totaux sont EXACTEMENT ceux renvoyés à l'écran par
 * FinancialStatisticsQueryService/FinancialStatisticsRankingService (même filtre, même
 * tri), toutes pages confondues. Ce service ne fait que les mettre en forme.
 *
 * Convention d'affichage (identique au frontend) : recette = « + » vert, dépense = « − »
 * rouge (les montants de dépense sont des magnitudes positives dans l'API — le signe est
 * appliqué une seule fois, ici), solde = signe porté par la donnée, vert/rouge/neutre.
 */
final class FinancialStatisticsPdfExporter
{
    public const VIEWS = ['overview', 'timeseries', 'by-firm', 'by-instrumentist', 'by-surgeon', 'by-intervention', 'top-materials'];

    private const VIEW_TITLES = [
        'overview' => "Vue d'ensemble",
        'timeseries' => 'Séries temporelles',
        'by-firm' => 'Par firme',
        'by-instrumentist' => 'Par instrumentiste',
        'by-surgeon' => 'Par chirurgien',
        'by-intervention' => 'Par intervention',
        'top-materials' => 'Top matériels',
    ];

    /** Aucune limite fonctionnelle : l'export reprend toutes les lignes filtrées. */
    private const ALL_ROWS = 100000;

    private const GRANULARITY_LABELS = ['DAY' => 'Jour', 'WEEK' => 'Semaine', 'MONTH' => 'Mois'];

    private readonly Connection $connection;

    public function __construct(
        EntityManagerInterface $em,
        private readonly PdfService $pdfService,
        private readonly FinancialStatisticsQueryService $queryService,
        private readonly FinancialStatisticsRankingService $rankingService,
    ) {
        $this->connection = $em->getConnection();
    }

    /** @return array{pdf: string, rowCount: int, filename: string} */
    public function export(string $view, FinancialStatisticsFilter $filter, StatisticsGranularity $granularity, string $sortBy, string $sortDirection): array
    {
        $report = match ($view) {
            'overview' => $this->overview($filter),
            'timeseries' => $this->timeseries($filter, $granularity),
            'by-firm' => $this->byFirm($filter, $sortBy, $sortDirection),
            'by-instrumentist' => $this->byInstrumentist($filter, $sortBy, $sortDirection),
            'by-surgeon' => $this->bySurgeon($filter, $sortBy, $sortDirection),
            'by-intervention' => $this->byIntervention($filter, $sortBy, $sortDirection),
            'top-materials' => $this->topMaterials($filter, $sortBy, $sortDirection),
        };

        $filters = $this->filterLabels($filter);
        if ($view === 'timeseries') {
            $filters[] = ['label' => 'Granularité', 'value' => self::GRANULARITY_LABELS[$granularity->value]];
        }

        $pdf = $this->pdfService->generateFromTemplate('pdf/financial_statistics.html.twig', $report + [
            'viewTitle' => self::VIEW_TITLES[$view],
            'period' => $this->periodLabel($filter),
            'filters' => $filters,
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))->format('d/m/Y H:i'),
        ], 'landscape');

        return [
            'pdf' => $pdf,
            'rowCount' => $report['rowCount'],
            'filename' => sprintf('statistiques-%s-%s.pdf', $view, $filter->from->format('Y') === '1970' ? 'toutes-dates' : $filter->from->format('Y-m-d')),
        ];
    }

    // ── Vues ─────────────────────────────────────────────────────────────

    private function overview(FinancialStatisticsFilter $filter): array
    {
        $dto = $this->queryService->overview($filter);
        $blocks = [];
        foreach ($dto->currencies as $c) {
            $cur = $c->currency;
            $blocks[] = [
                'currency' => $cur,
                'layers' => [
                    ['title' => 'Calculé — valorisation des missions', 'cards' => [
                        $this->card('Total recettes (CA firmes)', $c->generatedFirmRevenue, 'revenue', $cur),
                        $this->card('Total dépenses (rémunérations instrumentistes)', $c->generatedInstrumentistCompensation, 'expense', $cur),
                        $this->card('Solde net calculé', $c->generatedContributionMargin, 'net', $cur),
                    ]],
                    ['title' => 'Facturé / Décompté — documents émis', 'cards' => [
                        $this->card('Net facturé aux firmes', $c->invoicedNetAmount, 'revenue', $cur),
                        $this->card('Net décompté aux instrumentistes', $c->statementNetAmount, 'expense', $cur),
                        $this->card('Solde net documenté', $c->documentedNetBalance, 'net', $cur),
                    ]],
                    ['title' => 'Encaissé / Décaissé — paiements enregistrés', 'cards' => [
                        $this->card('Encaissé', $c->paymentsIn, 'revenue', $cur),
                        $this->card('Décaissé', $c->paymentsOut, 'expense', $cur),
                        $this->card('Flux net', $c->netCashFlow, 'net', $cur),
                    ]],
                    ['title' => 'Soldes ouverts sur documents émis', 'cards' => [
                        $this->card('Restant à recevoir des firmes', $c->openFirmBalance, 'amount', $cur),
                        $this->card('Restant à payer aux instrumentistes', $c->openInstrumentistBalance, 'amount', $cur),
                    ]],
                ],
            ];
        }

        $a = $dto->activity;
        return [
            'activity' => [
                ['label' => 'Missions', 'value' => (string) $a->missionCount],
                ['label' => 'Missions exécutées', 'value' => (string) $a->executedMissionCount],
                ['label' => 'Missions validées', 'value' => (string) $a->validatedMissionCount],
                ['label' => 'Durée moyenne d\'exécution', 'value' => $a->averageExecutionDurationMinutes . ' min'],
            ],
            'currencyBlocks' => $blocks,
            'rowCount' => count($blocks),
        ];
    }

    private function timeseries(FinancialStatisticsFilter $filter, StatisticsGranularity $granularity): array
    {
        $points = $this->queryService->timeseries($filter, $granularity);
        $columns = [
            $this->col('Période'), $this->col('Missions', 'int'),
            $this->col('Recettes calculées', 'revenue'), $this->col('Dépenses calculées', 'expense'), $this->col('Solde calculé', 'net'),
            $this->col('Net facturé', 'revenue'), $this->col('Net décompté', 'expense'),
            $this->col('Encaissé', 'revenue'), $this->col('Décaissé', 'expense'), $this->col('Flux net', 'net'),
        ];
        $fields = ['generatedFirmRevenue', 'generatedInstrumentistCompensation', 'generatedNetBalance', 'invoicedNetAmount', 'statementNetAmount', 'paymentsIn', 'paymentsOut', 'netCashFlow'];

        $rows = [];
        foreach ($points as $p) {
            $label = $this->periodStartLabel($p->periodStart, $granularity);
            if ($p->currencies === []) {
                $rows[] = ['cells' => array_merge([$this->text($label), $this->text((string) $p->missionCount, 'int')], array_map(fn () => $this->text('—', 'muted'), $fields))];
                continue;
            }
            foreach ($p->currencies as $i => $c) {
                $cells = [$this->text($i === 0 ? $label : ''), $this->text($i === 0 ? (string) $p->missionCount : '', 'int')];
                foreach ($fields as $k => $field) {
                    $cells[] = $this->money($c->{$field}, $columns[$k + 2]['kind'], $c->currency);
                }
                $rows[] = ['cells' => $cells];
            }
        }

        $totals = $this->queryService->timeseriesTotals($points);
        return [
            'summaries' => $this->summaries($totals, 'generatedFirmRevenue', 'generatedInstrumentistCompensation', 'generatedNetBalance'),
            'columns' => $columns,
            'rows' => $rows,
            'totalRows' => array_map(fn (array $t) => ['cells' => array_merge(
                [$this->text('TOTAL GÉNÉRAL'), $this->text('')],
                array_map(fn (string $f, int $k) => $this->money($t[$f], $columns[$k + 2]['kind'], $t['currency']), $fields, array_keys($fields)),
            )], $totals),
            'rowCount' => count($rows),
        ];
    }

    private function byFirm(FinancialStatisticsFilter $filter, string $sortBy, string $sortDirection): array
    {
        $result = $this->rankingService->byFirm($filter, 1, self::ALL_ROWS, $sortBy, $sortDirection);
        return $this->table($result, [
            ['Firme', 'firmNameSnapshot', 'text'],
            ['Missions', 'missionCount', 'int'],
            ['CA interventions', 'interventionRevenue', 'revenue'],
            ['CA matériel', 'materialRevenue', 'revenue'],
            ['Total recettes', 'generatedRevenue', 'revenue'],
            ['Net facturé', 'invoicedNetAmount', 'revenue'],
            ['Encaissé', 'paidAmount', 'revenue'],
            ['Restant dû', 'remainingAmount', 'amount'],
            ['CA moyen / mission', 'averageRevenuePerMission', 'amount'],
        ], revenueField: 'generatedRevenue', note: 'Recettes uniquement : aucune rémunération instrumentiste n\'a de lien fiable avec une firme (une mission peut concerner plusieurs firmes).');
    }

    private function byInstrumentist(FinancialStatisticsFilter $filter, string $sortBy, string $sortDirection): array
    {
        $result = $this->rankingService->byInstrumentist($filter, 1, self::ALL_ROWS, $sortBy, $sortDirection);
        return $this->table($result, [
            ['Instrumentiste', 'instrumentistNameSnapshot', 'text'],
            ['Missions', 'missionCount', 'int'],
            ['Heures', 'executedMinutes', 'minutes'],
            ['Rémun. horaire', 'hourlyCompensation', 'expense'],
            ['Consultations', 'consultationFees', 'expense'],
            ['Total dépenses', 'generatedCompensation', 'expense'],
            ['Net décompté', 'statementNetAmount', 'expense'],
            ['Décaissé', 'paidAmount', 'expense'],
            ['Restant à payer', 'remainingAmount', 'amount'],
            ['Coût moyen / mission', 'averageCompensationPerMission', 'amount'],
        ], expenseField: 'generatedCompensation', note: 'Les rémunérations sont des dépenses de SurgicalHub.');
    }

    private function bySurgeon(FinancialStatisticsFilter $filter, string $sortBy, string $sortDirection): array
    {
        $result = $this->rankingService->bySurgeon($filter, 1, self::ALL_ROWS, $sortBy, $sortDirection);
        return $this->table($result, [
            ['Chirurgien', 'surgeonNameSnapshot', 'text'],
            ['Missions', 'missionCount', 'int'],
            ['Exécutées', 'executedMissionCount', 'int'],
            ['CA firme', 'generatedFirmRevenue', 'revenue'],
            ['Frais instrumentiste', 'generatedInstrumentistCompensation', 'expense'],
            ['Solde net', 'netBalance', 'net'],
        ], revenueField: 'generatedFirmRevenue', expenseField: 'generatedInstrumentistCompensation', netField: 'netBalance',
            note: 'Le chirurgien est un axe analytique : ces montants ne constituent pas une rémunération versée au chirurgien.');
    }

    private function byIntervention(FinancialStatisticsFilter $filter, string $sortBy, string $sortDirection): array
    {
        $result = $this->rankingService->byIntervention($filter, 1, self::ALL_ROWS, $sortBy, $sortDirection);
        return $this->table($result, [
            ['Code', 'interventionCodeSnapshot', 'text'],
            ['Libellé', 'interventionNameSnapshot', 'text'],
            ['Interventions', 'interventionCount', 'int'],
            ['Missions', 'missionCount', 'int'],
            ['CA interventions', 'interventionRevenue', 'revenue'],
            ['CA matériel', 'materialRevenue', 'revenue'],
            ['Total recettes', 'totalRevenue', 'revenue'],
            ['Recette moy. / intervention', 'averageRevenuePerIntervention', 'amount'],
            ['Coût moyen de mission associée', 'averageAssociatedMissionCost', 'amount'],
            ['Durée moy. (min)', 'averageDurationMinutes', 'int'],
        ], revenueField: 'totalRevenue', expenseField: 'missionInstrumentistCompensation', netField: 'netBalance',
            expenseLabel: 'Total dépenses (au niveau mission)',
            note: 'La rémunération instrumentiste appartient à la mission, jamais à une intervention : aucune part n\'est imputée à un type d\'intervention. « Coût moyen de mission associée » = rémunération totale moyenne des missions comportant ce type (indicatif ; une mission à plusieurs types figure dans chaque groupe) — ce n\'est pas un coût par intervention. Les dépenses totales sont comptées une seule fois par mission.');
    }

    private function topMaterials(FinancialStatisticsFilter $filter, string $sortBy, string $sortDirection): array
    {
        $result = $this->rankingService->topMaterials($filter, self::ALL_ROWS, $sortBy, $sortDirection);
        return $this->table($result, [
            ['Référence', 'materialReferenceSnapshot', 'text'],
            ['Matériel', 'materialNameSnapshot', 'text'],
            ['Firme', 'firmSnapshot', 'text'],
            ['Quantité', 'quantity', 'int'],
            ['Missions', 'missionCount', 'int'],
            ['CA généré', 'generatedRevenue', 'revenue'],
            ['CA moyen / unité', 'averageUnitRevenue', 'amount'],
        ], revenueField: 'generatedRevenue');
    }

    // ── Mise en forme ────────────────────────────────────────────────────

    /**
     * @param array{items: object[], totals: array<int, array<string, string>>} $result
     * @param array<int, array{0: string, 1: string, 2: string}> $spec [libellé, propriété, nature]
     */
    private function table(array $result, array $spec, ?string $revenueField = null, ?string $expenseField = null, ?string $netField = null, ?string $note = null, string $expenseLabel = 'Total dépenses'): array
    {
        $columns = array_map(fn (array $s) => $this->col($s[0], $s[2]), $spec);
        $rows = [];
        foreach ($result['items'] as $item) {
            $rows[] = ['cells' => array_map(fn (array $s) => $this->cell($item->{$s[1]}, $s[2], $item->currency), $spec)];
        }

        $totalRows = [];
        foreach ($result['totals'] as $t) {
            $cells = [];
            foreach ($spec as $i => $s) {
                $cells[] = match (true) {
                    $i === 0 => $this->text('TOTAL GÉNÉRAL' . (count($result['totals']) > 1 ? ' (' . $t['currency'] . ')' : '')),
                    isset($t[$s[1]]) && in_array($s[2], ['revenue', 'expense', 'net'], true) => $this->money($t[$s[1]], $s[2], $t['currency']),
                    default => $this->text(''),
                };
            }
            $totalRows[] = ['cells' => $cells];
        }

        return [
            'summaries' => $this->summaries($result['totals'], $revenueField, $expenseField, $netField, $expenseLabel),
            'columns' => $columns,
            'rows' => $rows,
            'totalRows' => $totalRows,
            'note' => $note,
            'rowCount' => count($rows),
        ];
    }

    /** @param array<int, array<string, string>> $totals */
    private function summaries(array $totals, ?string $revenueField, ?string $expenseField, ?string $netField, string $expenseLabel = 'Total dépenses'): array
    {
        return array_map(fn (array $t) => ['currency' => $t['currency'], 'cards' => array_values(array_filter([
            $revenueField !== null ? $this->card('Total recettes', $t[$revenueField], 'revenue', $t['currency']) : null,
            $expenseField !== null ? $this->card($expenseLabel, $t[$expenseField], 'expense', $t['currency']) : null,
            $netField !== null ? $this->card('Solde net', $t[$netField], 'net', $t['currency']) : null,
        ]))], $totals);
    }

    private function col(string $label, string $kind = 'text'): array
    {
        return ['label' => $label, 'kind' => $kind, 'align' => $kind === 'text' ? 'left' : 'right'];
    }

    private function cell(mixed $value, string $kind, string $currency): array
    {
        return match (true) {
            $value === null => $this->text('—', 'muted'),
            in_array($kind, ['revenue', 'expense', 'net', 'amount'], true) => $this->money((string) $value, $kind, $currency),
            $kind === 'minutes' => $this->text(sprintf('%d h %02d', intdiv((int) $value, 60), (int) $value % 60), 'int'),
            $kind === 'int' => $this->text((string) $value, 'int'),
            default => $this->text($value === null || $value === '' ? '—' : (string) $value),
        };
    }

    private function card(string $label, string $amount, string $kind, string $currency): array
    {
        return ['label' => $label] + $this->money($amount, $kind, $currency);
    }

    private function text(string $text, string $class = ''): array
    {
        return ['text' => $text, 'class' => $class];
    }

    /** Même règle que frontend `formatSignedMoney()` : le signe n'est appliqué qu'une fois. */
    private function money(string $amount, string $kind, string $currency): array
    {
        $cents = (int) round((float) $amount * 100);
        $formatted = number_format(abs($cents) / 100, 2, ',', ' ') . ' ' . ($currency === 'EUR' ? '€' : $currency);
        [$sign, $class] = match ($kind) {
            'revenue' => [$cents === 0 ? '' : '+', $cents === 0 ? 'zero' : 'pos'],
            'expense' => [$cents === 0 ? '' : '−', $cents === 0 ? 'zero' : 'neg'],
            'net' => [$cents > 0 ? '+' : ($cents < 0 ? '−' : ''), $cents > 0 ? 'pos' : ($cents < 0 ? 'neg' : 'zero')],
            default => [$cents < 0 ? '−' : '', ''],
        };

        return ['text' => $sign . $formatted, 'class' => $class];
    }

    private function periodLabel(FinancialStatisticsFilter $filter): string
    {
        $tz = new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $from = $filter->from->setTimezone($tz);
        $to = $filter->to->setTimezone($tz);
        $open = $from->format('Y') === '1970';
        $unbounded = $to->format('Y') === '9999';
        if ($open && $unbounded) {
            return 'Toutes dates';
        }
        $lastDay = $to->modify('-1 day');

        return match (true) {
            $open => 'Jusqu\'au ' . $lastDay->format('d/m/Y') . ' inclus',
            $unbounded => 'À partir du ' . $from->format('d/m/Y'),
            default => sprintf('Du %s au %s inclus', $from->format('d/m/Y'), $lastDay->format('d/m/Y')),
        };
    }

    private function periodStartLabel(\DateTimeImmutable $start, StatisticsGranularity $granularity): string
    {
        return match ($granularity) {
            StatisticsGranularity::MONTH => $start->format('m/Y'),
            StatisticsGranularity::WEEK => 'Sem. du ' . $start->format('d/m/Y'),
            StatisticsGranularity::DAY => $start->format('d/m/Y'),
        };
    }

    /** @return array<int, array{label: string, value: string}> */
    private function filterLabels(FinancialStatisticsFilter $filter): array
    {
        $labels = [];
        if ($filter->siteId !== null) {
            $labels[] = ['label' => 'Site', 'value' => (string) ($this->connection->fetchOne('SELECT name FROM hospital WHERE id = ?', [$filter->siteId]) ?: '#' . $filter->siteId)];
        }
        if ($filter->firmId !== null) {
            $labels[] = ['label' => 'Firme', 'value' => (string) ($this->connection->fetchOne('SELECT name FROM firm WHERE id = ?', [$filter->firmId]) ?: '#' . $filter->firmId)];
        }
        if ($filter->instrumentistId !== null) {
            $labels[] = ['label' => 'Instrumentiste', 'value' => $this->userName($filter->instrumentistId)];
        }
        if ($filter->surgeonId !== null) {
            $labels[] = ['label' => 'Chirurgien', 'value' => $this->userName($filter->surgeonId)];
        }
        if ($filter->interventionTypeId !== null) {
            $row = $this->connection->fetchAssociative('SELECT code, label FROM intervention_type WHERE id = ?', [$filter->interventionTypeId]);
            $labels[] = ['label' => 'Intervention', 'value' => $row !== false ? $row['code'] . ' — ' . $row['label'] : '#' . $filter->interventionTypeId];
        }
        $labels[] = ['label' => 'Devise', 'value' => $filter->currency ?? 'Toutes (jamais additionnées entre elles)'];

        return $labels;
    }

    private function userName(int $id): string
    {
        $row = $this->connection->fetchAssociative('SELECT firstname, lastname, email FROM `user` WHERE id = ?', [$id]);
        if ($row === false) {
            return '#' . $id;
        }
        $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));

        return $name !== '' ? $name : (string) $row['email'];
    }
}
