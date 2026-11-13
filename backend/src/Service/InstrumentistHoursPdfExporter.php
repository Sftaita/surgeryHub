<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\EffectiveDurationSource;
use App\Enum\InstrumentistRateType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-189 — exports PDF de « Heures instrumentistes » (D-180). Aucun calcul propre :
 *  - synthèse : InstrumentistHoursService::overview() tel quel (mêmes filtres, même tri, toutes
 *    les lignes, totaux du serveur) — le PDF est l'écran ;
 *  - décompte individuel : MyHoursService::statement() (le tiroir = « Mes heures »), restreint
 *    aux prestations VALIDÉES, une ligne par prestation, dans l'ordre chronologique. Durée =
 *    MissionExecutionService, tarif et montant = FinancialCalculationService::evaluate() par
 *    prestation ; le total imprimé est MyHoursService::estimateOf() des mêmes lignes (somme au
 *    centime des montants imprimés, contrôlée ici), donc égal au « Coût estimé validé » de la page.
 *
 * Jamais de donnée inventée : ni TVA (aucun régime configuré), ni émetteur (aucune entité
 * configurée), ni statut de paiement (le décompte n'est pas un InstrumentistStatement émis) ;
 * une information absente n'est pas imprimée. Aucune donnée patient ni texte libre.
 */
final class InstrumentistHoursPdfExporter
{
    private const TIMEZONE = 'Europe/Brussels';
    private const PHOTO_MAX_BYTES = 5_000_000;
    private const PHOTO_MIME = ['image/jpeg', 'image/png', 'image/gif'];
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public function __construct(
        private readonly InstrumentistHoursService $hours,
        private readonly MyHoursService $myHours,
        private readonly PdfService $pdf,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
        #[Autowire('%app.profile_picture.upload_dir%')]
        private readonly string $profilePictureDir,
    ) {}

    /** @return array{pdf: string, filename: string} */
    public function overview(int $year, int $month, ?int $siteId, ?string $q, string $sort, string $direction, \DateTimeImmutable $now): array
    {
        $data = $this->overviewData($year, $month, $siteId, $q, $sort, $direction, $now);
        $pdf = $this->pdf->generateFromTemplate('pdf/instrumentist_hours_overview.html.twig', $data, 'landscape', 'SurgicalHub — Heures instrumentistes ' . $data['period']);

        return ['pdf' => $pdf, 'filename' => sprintf('heures-instrumentistes-%04d-%02d.pdf', $year, $month)];
    }

    /** @return array{pdf: string, filename: string} */
    public function statement(User $instrumentist, int $year, int $month, ?int $siteId, \DateTimeImmutable $now): array
    {
        $data = $this->statementData($instrumentist, $year, $month, $siteId, $now);
        $pdf = $this->pdf->generateFromTemplate('pdf/instrumentist_hours_statement.html.twig', $data, 'portrait', sprintf('SurgicalHub — Relevé %s', $data['number']));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->ascii($instrumentist->getLastname() . ' ' . $instrumentist->getFirstname()))), '-');

        return ['pdf' => $pdf, 'filename' => sprintf('decompte-%04d-%02d-%s.pdf', $year, $month, $slug !== '' ? $slug : $instrumentist->getId())];
    }

    /**
     * Données imprimées de la synthèse (exposées pour les tests de concordance).
     *
     * @return array<string, mixed>
     */
    public function overviewData(int $year, int $month, ?int $siteId, ?string $q, string $sort, string $direction, \DateTimeImmutable $now): array
    {
        $o = $this->hours->overview($year, $month, $siteId, $q, $sort, $direction, null, $now);
        $t = $o['totals'];
        $siteName = $this->siteName($o['sites'], $siteId);
        $previous = $o['reference']['previousMonth'];
        // Total financier partiel : au moins un instrumentiste a des prestations sans tarif (non valorisées).
        $unratedPeople = $t['unratedInstrumentistCount'];
        $partial = $unratedPeople > 0 ? sprintf(
            'Total financier partiel : %d instrumentiste%s sans tarif, %d prestation%s non valorisée%s. Les montants ne couvrent que les prestations tarifées.',
            $unratedPeople, $unratedPeople > 1 ? 's' : '',
            $t['unratedCount'], $t['unratedCount'] > 1 ? 's' : '', $t['unratedCount'] > 1 ? 's' : '',
        ) : null;

        $rows = array_map(function (array $i): array {
            $e = $i['estimate'];
            $unrated = $i['estimateState'] === 'UNRATED';

            return [
                'name' => $this->fullName($i['instrumentist']['firstname'], $i['instrumentist']['lastname']),
                'inactive' => !$i['instrumentist']['active'],
                'count' => $i['count'],
                'encoded' => $this->duration($i['encodedMinutes']),
                'validated' => $this->duration($i['validatedMinutes']),
                'pending' => $this->duration($i['pendingMinutes']),
                'encodedAmount' => $unrated ? 'Tarif manquant' : ($e !== null ? $this->approx($e['encodedAmount']) : '—'),
                'validatedAmount' => $unrated ? 'Tarif manquant' : (($e['validatedAmount'] ?? null) !== null ? $this->approx($e['validatedAmount']) : '—'),
                'unratedNote' => ($e['unratedCount'] ?? 0) > 0 ? sprintf('+ %d sans tarif', $e['unratedCount']) : null,
                'missingRate' => $unrated,
                'previous' => $this->delta($i['comparison']['previousMonth']['pct']),
                'average' => $this->delta($i['comparison']['personalAverage']['pct']),
            ];
        }, $o['items']);

        return [
            'period' => $this->monthLabel($o['month']),
            'provisional' => $o['provisional'] ? sprintf('Mois en cours — provisoire au %d %s', $o['asOfDay'], self::MONTHS_SHORT[(int) substr($o['month'], 5, 2) - 1]) : null,
            'filters' => array_values(array_filter([
                ['label' => 'Site', 'value' => $siteName ?? 'Tous les sites'],
                $o['q'] !== null ? ['label' => 'Recherche', 'value' => '« ' . $o['q'] . ' »'] : null,
            ])),
            'generatedAt' => $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m/Y à H:i'),
            'kpis' => [
                ['label' => 'Heures encodées', 'value' => $this->duration($t['encodedMinutes']), 'hint' => $this->prestations($t['count']) . ' · en attente + validées', 'tone' => ''],
                ['label' => 'Heures validées', 'value' => $this->duration($t['validatedMinutes']), 'hint' => $t['validatedPct'] . ' % des heures encodées', 'tone' => ''],
                ['label' => 'En attente de validation', 'value' => $this->duration($t['pendingMinutes']), 'hint' => sprintf('%d prestation%s', $t['pendingCount'], $t['pendingCount'] > 1 ? 's soumises' : ' soumise'), 'tone' => $t['count'] > 0 ? 'warn' : ''],
                ['label' => 'Coût estimé validé', 'value' => $t['validatedAmount'] !== null ? $this->approx($t['validatedAmount']) : '—',
                    'hint' => ($t['encodedAmount'] !== null ? 'encodé ' . $this->approx($t['encodedAmount']) : 'aucune prestation valorisée')
                        . ($unratedPeople > 0 ? sprintf(' · total partiel : %d instrumentiste%s sans tarif', $unratedPeople, $unratedPeople > 1 ? 's' : '') : ''),
                    'tone' => ''],
            ],
            'previousHeading' => $o['asOfDay'] === null ? 'vs ' . $this->monthShort($previous) : sprintf('vs 1–%d %s', $o['asOfDay'], $this->monthShort($previous)),
            'rows' => $rows,
            'totals' => [
                'label' => sprintf('Total · %d instrumentiste%s', $t['instrumentistCount'], $t['instrumentistCount'] > 1 ? 's' : ''),
                'count' => $t['count'],
                'encoded' => $this->duration($t['encodedMinutes']),
                'validated' => $this->duration($t['validatedMinutes']),
                'pending' => $this->duration($t['pendingMinutes']),
                'encodedAmount' => $t['encodedAmount'] !== null ? $this->approx($t['encodedAmount']) : '—',
                'validatedAmount' => $t['validatedAmount'] !== null ? $this->approx($t['validatedAmount']) : '—',
                'partial' => $partial !== null,
            ],
            'partialNotice' => $partial,
            'raw' => $o,
        ];
    }

    /**
     * Données imprimées du décompte individuel (exposées pour les tests de concordance).
     *
     * @return array<string, mixed>
     */
    public function statementData(User $instrumentist, int $year, int $month, ?int $siteId, \DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $now = $now->setTimezone($tz);
        $statement = $this->myHours->statement($instrumentist, $year, $month, $siteId, true);

        $validated = array_values(array_filter($statement['items'], static fn (array $r) => $r['status'] === 'validated'));
        usort($validated, static fn (array $a, array $b) => [$a['date'], $a['start'] ?? '', $a['missionId']] <=> [$b['date'], $b['start'] ?? '', $b['missionId']]);
        $pendingCount = count($statement['items']) - count($validated);

        $summary = $this->myHours->summarize($validated);
        $estimate = $this->myHours->estimateOf($validated);

        $lines = [];
        $cents = 0;
        $currency = $estimate['currency'] ?? 'EUR';
        foreach ($validated as $r) {
            $e = $r['estimate'];
            if ($e !== null) {
                $cents += (int) round((float) $e['amount'] * 100);
            }
            $lines[] = [
                'missionId' => $r['missionId'],
                'date' => (new \DateTimeImmutable($r['date'], $tz))->format('d/m/Y'),
                'site' => (string) ($r['site']['name'] ?? '—'),
                'surgeon' => $r['surgeonName'] ?? '—',
                'type' => $r['missionType'] === 'CONSULTATION' ? 'Consultation' : 'Bloc',
                'note' => $r['durationSource'] === EffectiveDurationSource::PRESENCE_SHARE->value ? 'part de présence commune' : null,
                'start' => $r['start'] !== null ? (new \DateTimeImmutable($r['start']))->setTimezone($tz)->format('H\hi') : '—',
                'end' => $r['end'] !== null ? (new \DateTimeImmutable($r['end']))->setTimezone($tz)->format('H\hi') . ($r['endsNextDay'] ? ' (+1)' : '') : '—',
                'minutes' => $r['minutes'],
                'duration' => $r['minutes'] !== null ? $this->duration($r['minutes']) : '—',
                'rate' => $e === null
                    ? ($this->myHours->isUnrated($r) ? 'Tarif manquant' : '—')
                    : ($e['rateType'] === InstrumentistRateType::HOURLY_RATE->value ? $this->money($e['unitAmount'], $e['currency']) . ' / h' : 'Forfait ' . $this->money($e['unitAmount'], $e['currency'])),
                'missingRate' => $e === null && $this->myHours->isUnrated($r),
                'amountRaw' => $e['amount'] ?? null,
                'amount' => $e !== null ? $this->money($e['amount'], $e['currency']) : '—',
            ];
        }

        $total = $estimate['validatedAmount'] ?? '0.00';
        if ((int) round((float) $total * 100) !== $cents) {
            // Garde-fou : le total imprimé doit être exactement la somme des lignes imprimées.
            throw new \LogicException('Décompte incohérent : la somme des lignes ne correspond pas au total.');
        }

        $period = sprintf('%04d-%02d', $year, $month);
        $siteName = $this->siteName($statement['sites'], $siteId);
        $unrated = count(array_filter($lines, static fn (array $l) => $l['missingRate']));

        return [
            'number' => $this->number($instrumentist, $period, $siteId, $validated),
            'period' => $this->monthLabel($period),
            'periodRange' => sprintf('du 01/%02d/%04d au %s', $month, $year, (new \DateTimeImmutable($period . '-01', $tz))->modify('last day of this month')->format('d/m/Y')),
            'provisional' => $now->format('Y-m') === $period ? sprintf('Mois en cours — prestations validées au %s', $now->format('d/m/Y')) : null,
            'siteName' => $siteName,
            'issuedAt' => $now->format('d/m/Y'),
            'generatedAt' => $now->format('d/m/Y à H:i'),
            'instrumentist' => [
                'name' => $this->fullName($instrumentist->getFirstname(), $instrumentist->getLastname()),
                'companyName' => $this->filled($instrumentist->getCompanyName()),
                'vatNumber' => $this->filled($instrumentist->getVatNumber()),
                'email' => $this->filled($instrumentist->getEmail()),
                'phone' => $this->filled($instrumentist->getPhone()),
                'photo' => $this->photo($instrumentist->getProfilePicturePath()),
                'initials' => mb_strtoupper(mb_substr((string) $instrumentist->getFirstname(), 0, 1) . mb_substr((string) $instrumentist->getLastname(), 0, 1)),
            ],
            'lines' => $lines,
            'summary' => [
                'count' => count($validated),
                'minutes' => $summary['validatedMinutes'],
                'hours' => $this->duration($summary['validatedMinutes']),
                'currency' => $currency,
                'totalRaw' => number_format($cents / 100, 2, '.', ''),
                'total' => $this->money(number_format($cents / 100, 2, '.', ''), $currency),
                'unratedCount' => $unrated,
                'pendingCount' => $pendingCount,
            ],
        ];
    }

    /**
     * Référence du décompte : période, instrumentiste, site éventuel et empreinte des prestations
     * imprimées (mission, durée, montant). Recalculée, jamais stockée : deux exports au contenu
     * identique portent la même référence, toute modification du contenu en change l'empreinte.
     * Imprimée « Référence du relevé » : ce n'est pas un numéro de décompte comptable.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function number(User $instrumentist, string $period, ?int $siteId, array $rows): string
    {
        $fingerprint = implode(';', array_map(static fn (array $r) => $r['missionId'] . ':' . ($r['minutes'] ?? '') . ':' . ($r['estimate']['amount'] ?? ''), $rows));

        return sprintf(
            'DPH-%s-%04d%s-%s',
            str_replace('-', '', $period),
            $instrumentist->getId(),
            $siteId !== null ? '-S' . $siteId : '',
            strtoupper(substr(hash('sha256', $fingerprint), 0, 6)),
        );
    }

    /**
     * Photo de profil existante (ProfilePictureStorage), embarquée en data URI : dompdf n'a pas
     * accès au réseau. Jamais lue hors du dossier des photos ; absente ou illisible → initiales.
     */
    private function photo(?string $publicPath): ?string
    {
        if ($publicPath === null || trim($publicPath) === '' || preg_match('#^https?://#i', $publicPath)) {
            return null;
        }
        $file = realpath($this->publicDir . '/' . ltrim($publicPath, '/'));
        $root = realpath($this->profilePictureDir);
        if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || filesize($file) > self::PHOTO_MAX_BYTES) {
            return null;
        }
        $info = @getimagesize($file);
        if ($info === false || !in_array($info['mime'], self::PHOTO_MIME, true)) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === false ? null : 'data:' . $info['mime'] . ';base64,' . base64_encode($content);
    }

    /** @return array{text: string, tone: string} */
    private function delta(?int $pct): array
    {
        return match (true) {
            $pct === null => ['text' => '—', 'tone' => 'none'],
            $pct > 0 => ['text' => '▲ +' . $pct . ' %', 'tone' => 'up'],
            $pct < 0 => ['text' => '▼ −' . abs($pct) . ' %', 'tone' => 'down'],
            default => ['text' => '0 %', 'tone' => 'flat'],
        };
    }

    /** @param list<array{id: int, name: string}> $sites */
    private function siteName(array $sites, ?int $siteId): ?string
    {
        if ($siteId === null) {
            return null;
        }
        foreach ($sites as $s) {
            if ($s['id'] === $siteId) {
                return (string) $s['name'];
            }
        }

        return null;
    }

    private function duration(int $minutes): string
    {
        $m = max(0, $minutes);

        return intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }

    private function money(string $amount, string $currency): string
    {
        return number_format((float) $amount, 2, ',', ' ') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    private function approx(string $amount): string
    {
        return '≈ ' . $this->money($amount, 'EUR');
    }

    private function prestations(int $n): string
    {
        return $n . ' prestation' . ($n > 1 ? 's' : '');
    }

    private function monthLabel(string $month): string
    {
        $label = self::MONTHS[(int) substr($month, 5, 2) - 1];

        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1) . ' ' . substr($month, 0, 4);
    }

    private function monthShort(string $month): string
    {
        return self::MONTHS_SHORT[(int) substr($month, 5, 2) - 1];
    }

    private function fullName(?string $firstname, ?string $lastname): string
    {
        $name = trim(($firstname ?? '') . ' ' . ($lastname ?? ''));

        return $name !== '' ? $name : 'Instrumentiste';
    }

    private function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function ascii(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        }

        return $s;
    }
}
