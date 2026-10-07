<?php

namespace App\Service\FirmBilling;

use App\Enum\FirmBillingStatus;
use App\Service\Export\XlsxWriter;
use App\Service\PdfService;

/**
 * D-133 — export PDF / Excel d'une SÉLECTION de lignes de la worklist. Ne reçoit que des
 * lignes déjà projetées par FirmBillingWorklistService::selectRows() : aucune donnée n'est
 * relue ni recalculée ici, et aucune donnée patient n'existe dans ces lignes.
 *
 * Le total n'additionne que les lignes BILLABLE (réellement facturables), par devise.
 */
final class FirmBillingWorklistExporter
{
    private const COLUMNS = ['Date', 'Site', 'Chirurgien', 'Type', 'Prestation', 'Référence', 'Firme', 'Quantité', 'Facturation', 'Motif', 'Montant', 'Devise', 'Facture'];

    public function __construct(
        private readonly PdfService $pdfService,
        private readonly XlsxWriter $xlsxWriter,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[]                         $firmNames vide = toutes les firmes
     */
    public function xlsx(array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to, array $firmNames): string
    {
        $sheet = [
            [['v' => 'Facturation firmes — sélection exportée', 's' => XlsxWriter::STYLE_BOLD]],
            ['Période', $this->periodLabel($from, $to)],
            ['Firmes sélectionnées', $this->firmsLabel($firmNames)],
            ['Lignes exportées', count($rows)],
            [],
            array_map(static fn (string $c) => ['v' => $c, 's' => XlsxWriter::STYLE_HEADER], self::COLUMNS),
        ];

        foreach ($rows as $row) {
            $sheet[] = [
                $this->date($row['mission']['date'] ?? null),
                $row['mission']['site'] ?? '',
                $row['mission']['surgeon'] ?? '',
                $this->typeLabel($row['sourceType']),
                $row['label'] ?? '',
                $row['reference'] ?? '',
                $row['firm']['name'] ?? '',
                $row['quantity'] !== null ? (float) $row['quantity'] : null,
                $row['billingStatusLabel'],
                $row['reasonLabel'],
                $row['amount'] !== null ? ['v' => (float) $row['amount'], 's' => XlsxWriter::STYLE_MONEY] : null,
                $row['amount'] !== null ? ($row['currency'] ?? '') : '',
                $this->invoiceLabel($row),
            ];
        }

        $sheet[] = [];
        foreach ($this->billableTotals($rows) ?: ['EUR' => '0.00'] as $currency => $amount) {
            $sheet[] = [
                ['v' => 'Total facturable', 's' => XlsxWriter::STYLE_BOLD], '', '', '', '', '', '', '', '', '',
                ['v' => (float) $amount, 's' => XlsxWriter::STYLE_BOLD_MONEY], $currency,
            ];
        }

        return $this->xlsxWriter->build('Prestations', $sheet, [11, 22, 22, 13, 36, 16, 20, 9, 15, 30, 12, 7, 18]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[]                         $firmNames
     */
    public function pdf(array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to, array $firmNames): string
    {
        $lines = array_map(fn (array $row) => [
            'date' => $this->date($row['mission']['date'] ?? null),
            'site' => $row['mission']['site'] ?? '—',
            'surgeon' => $row['mission']['surgeon'] ?? '—',
            'type' => $this->typeLabel($row['sourceType']),
            'label' => $row['label'] ?? '—',
            'reference' => $row['reference'] ?? '',
            'firm' => $row['firm']['name'] ?? '—',
            'quantity' => $row['quantity'] ?? '',
            'status' => $row['billingStatus'],
            'statusLabel' => $row['billingStatusLabel'],
            'reason' => $row['reasonLabel'],
            'amount' => $row['amount'] !== null ? $this->money($row['amount'], $row['currency'] ?? 'EUR') : '—',
            'invoice' => $this->invoiceLabel($row),
        ], $rows);

        $totals = [];
        foreach ($this->billableTotals($rows) ?: ['EUR' => '0.00'] as $currency => $amount) {
            $totals[] = $this->money($amount, $currency);
        }

        return $this->pdfService->generateFromTemplate('pdf/firm_billing_worklist.html.twig', [
            'period' => $this->periodLabel($from, $to),
            'firms' => $this->firmsLabel($firmNames),
            'lines' => $lines,
            'totals' => $totals,
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))->format('d/m/Y H:i'),
        ], 'landscape');
    }

    /** @return array<string, string> */
    private function billableTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            if ($row['billingStatus'] === FirmBillingStatus::BILLABLE->value && $row['amount'] !== null) {
                $currency = (string) ($row['currency'] ?? 'EUR');
                $totals[$currency] = number_format(round((float) ($totals[$currency] ?? 0) + (float) $row['amount'], 2), 2, '.', '');
            }
        }
        ksort($totals);

        return $totals;
    }

    private function invoiceLabel(array $row): string
    {
        $invoice = $row['currentInvoice'] ?? null;
        if ($invoice === null) {
            return $row['invoiceStateLabel'] ?? '';
        }

        return sprintf('%s (%s)', $invoice['number'] ?? '#' . $invoice['id'], $invoice['statusLabel']);
    }

    private function typeLabel(string $sourceType): string
    {
        return $sourceType === FirmBillingWorklistService::TYPE_MATERIAL ? 'Matériel' : 'Intervention';
    }

    private function periodLabel(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return sprintf('du %s au %s', $from->format('d/m/Y'), $to->format('d/m/Y'));
    }

    /** @param string[] $firmNames */
    private function firmsLabel(array $firmNames): string
    {
        return $firmNames === [] ? 'Toutes les firmes' : implode(', ', $firmNames);
    }

    private function date(?string $ymd): string
    {
        if ($ymd === null) {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $d !== false ? $d->format('d/m/Y') : $ymd;
    }

    private function money(string $amount, string $currency): string
    {
        return number_format((float) $amount, 2, ',', ' ') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }
}
