<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Dto\FinancialStatisticsFilter;
use App\Enum\StatisticsGranularity;
use App\Exception\InvalidStatisticsFilterException;
use Symfony\Component\HttpFoundation\Request;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §6/§23 du lot : point d'entrée unique pour
 * transformer une Request HTTP en FinancialStatisticsFilter validé. Centralise la
 * résolution de période (§3 du lot — timezone métier D-066, jamais now() comme borne
 * fonctionnelle) pour que tous les endpoints partagent exactement la même règle.
 *
 * `from`/`to` absents = période non bornée (§6 : "un filtre absent signifie tous"),
 * jamais résolue via now() — un sentinel fixe (1970-01-01 / 9999-12-31, timezone
 * métier) joue ce rôle, jamais la date du jour.
 */
final class FinancialStatisticsRequestParser
{
    private const MIN_SENTINEL = '1970-01-01T00:00:00';
    private const MAX_SENTINEL = '9999-12-31T00:00:00';

    public function parseFilter(Request $request): FinancialStatisticsFilter
    {
        $businessTz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);

        $from = $this->parseDateTime($request->query->get('from'), self::MIN_SENTINEL, $businessTz, 'from');
        $to = $this->parseDateTime($request->query->get('to'), self::MAX_SENTINEL, $businessTz, 'to');

        if ($from >= $to) {
            throw new InvalidStatisticsFilterException('from doit être strictement antérieur à to (from inclusif, to exclusif).');
        }

        return new FinancialStatisticsFilter(
            from: $from,
            to: $to,
            siteId: $this->parseNullableId($request->query->get('siteId'), 'siteId'),
            surgeonId: $this->parseNullableId($request->query->get('surgeonId'), 'surgeonId'),
            instrumentistId: $this->parseNullableId($request->query->get('instrumentistId'), 'instrumentistId'),
            firmId: $this->parseNullableId($request->query->get('firmId'), 'firmId'),
            interventionTypeId: $this->parseNullableId($request->query->get('interventionTypeId'), 'interventionTypeId'),
            currency: $this->parseNullableCurrency($request->query->get('currency')),
        );
    }

    public function parseGranularity(Request $request): StatisticsGranularity
    {
        $raw = $request->query->get('granularity', 'DAY');
        try {
            return StatisticsGranularity::from(strtoupper((string) $raw));
        } catch (\ValueError) {
            throw new InvalidStatisticsFilterException('granularity invalide (DAY, WEEK ou MONTH attendu).');
        }
    }

    /**
     * §30 du lot — page/limit whitelistés, limite maximale imposée. `sortBy` doit
     * appartenir à `$allowedSortFields` (whitelist stricte fournie par l'appelant).
     *
     * @param string[] $allowedSortFields
     * @return array{page: int, limit: int, sortBy: string, sortDirection: string}
     */
    public function parsePagination(Request $request, array $allowedSortFields, string $defaultSortBy, int $maxLimit = 100): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min($maxLimit, (int) $request->query->get('limit', 20)));

        $sortBy = (string) $request->query->get('sortBy', $defaultSortBy);
        if (!in_array($sortBy, $allowedSortFields, true)) {
            throw new InvalidStatisticsFilterException(sprintf('sortBy invalide — valeurs autorisées : %s.', implode(', ', $allowedSortFields)));
        }

        $sortDirection = strtoupper((string) $request->query->get('sortDirection', 'DESC'));
        if (!in_array($sortDirection, ['ASC', 'DESC'], true)) {
            throw new InvalidStatisticsFilterException('sortDirection invalide (ASC ou DESC attendu).');
        }

        return ['page' => $page, 'limit' => $limit, 'sortBy' => $sortBy, 'sortDirection' => $sortDirection];
    }

    private function parseDateTime(mixed $raw, string $sentinel, \DateTimeZone $businessTz, string $field): \DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return new \DateTimeImmutable($sentinel, $businessTz);
        }

        try {
            // Une chaîne ISO 8601 avec offset/'Z' explicite conserve son offset ; sans
            // offset, elle est interprétée en timezone métier (D-066) — jamais UTC
            // implicite, jamais now().
            return new \DateTimeImmutable((string) $raw, $businessTz);
        } catch (\Exception) {
            throw new InvalidStatisticsFilterException(sprintf('%s : format de date invalide (ISO 8601 attendu).', $field));
        }
    }

    private function parseNullableId(mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (int) $raw <= 0) {
            throw new InvalidStatisticsFilterException(sprintf('%s doit être un entier positif.', $field));
        }
        return (int) $raw;
    }

    private function parseNullableCurrency(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $currency = strtoupper((string) $raw);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidStatisticsFilterException('currency doit être un code ISO 4217 à 3 lettres.');
        }
        return $currency;
    }
}
