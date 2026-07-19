<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Dto\FinancialStatisticsDrilldownItemDto;
use App\Dto\FinancialStatisticsFilter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET .../missions, .../calculations,
 * .../documents (§18 du lot) : le manager doit pouvoir passer d'un chiffre agrégé à sa
 * liste source. Réutilise EXACTEMENT les mêmes filtres de population/période que les
 * agrégats (mêmes noms de query params) pour que le drill-down corresponde
 * précisément au chiffre affiché.
 */
final class FinancialStatisticsDrilldownService
{
    private readonly Connection $connection;

    public function __construct(EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
    }

    /** @return array{items: FinancialStatisticsDrilldownItemDto[], total: int, page: int, limit: int} */
    public function missions(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');

        $countSql = "SELECT COUNT(*) FROM mission m
                     LEFT JOIN mission_execution me ON me.mission_id = m.id
                     WHERE COALESCE(me.actual_start_at, m.start_at) >= :fBusiness
                       AND COALESCE(me.actual_start_at, m.start_at) < :tBusiness
                       $missionWhere";

        $params = $missionParams + ['fBusiness' => $this->businessDateTimeParam($filter->from), 'tBusiness' => $this->businessDateTimeParam($filter->to)];
        $types = $missionTypes + ['fBusiness' => ParameterType::STRING, 'tBusiness' => ParameterType::STRING];

        $total = (int) $this->connection->fetchOne($countSql, $params, $types);

        $sql = "SELECT m.id, COALESCE(me.actual_start_at, m.start_at) AS date, m.status,
                    u.firstname, u.lastname, u.email
                FROM mission m
                LEFT JOIN mission_execution me ON me.mission_id = m.id
                LEFT JOIN `user` u ON u.id = m.surgeon_id
                WHERE COALESCE(me.actual_start_at, m.start_at) >= :fBusiness
                  AND COALESCE(me.actual_start_at, m.start_at) < :tBusiness
                  $missionWhere
                ORDER BY date " . ($sortDirection === 'ASC' ? 'ASC' : 'DESC') . "
                LIMIT :limit OFFSET :offset";

        $rows = $this->connection->fetchAllAssociative($sql, $params + ['limit' => $limit, 'offset' => ($page - 1) * $limit], $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        $items = array_map(fn (array $row) => new FinancialStatisticsDrilldownItemDto(
            id: (int) $row['id'],
            date: new \DateTimeImmutable($row['date'], new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)),
            beneficiary: $this->displayName($row['firstname'], $row['lastname'], $row['email']),
            currency: null,
            amount: null,
            status: $row['status'],
            sourceType: 'MISSION',
            sourceId: (int) $row['id'],
        ), $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** @return array{items: FinancialStatisticsDrilldownItemDto[], total: int, page: int, limit: int} */
    public function calculations(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        $params = $missionParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)];
        $types = $missionTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING];

        $countSql = "SELECT COUNT(*) FROM financial_calculation fc
                     INNER JOIN mission m ON m.id = fc.mission_id
                     WHERE fc.effective_at >= :fDate AND fc.effective_at < :tDate $missionWhere";
        $total = (int) $this->connection->fetchOne($countSql, $params, $types);

        $sql = "SELECT fc.id, fc.effective_at AS date, fc.status, u.firstname, u.lastname, u.email
                FROM financial_calculation fc
                INNER JOIN mission m ON m.id = fc.mission_id
                LEFT JOIN `user` u ON u.id = m.surgeon_id
                WHERE fc.effective_at >= :fDate AND fc.effective_at < :tDate $missionWhere
                ORDER BY date " . ($sortDirection === 'ASC' ? 'ASC' : 'DESC') . "
                LIMIT :limit OFFSET :offset";

        $rows = $this->connection->fetchAllAssociative($sql, $params + ['limit' => $limit, 'offset' => ($page - 1) * $limit], $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        $items = array_map(fn (array $row) => new FinancialStatisticsDrilldownItemDto(
            id: (int) $row['id'],
            date: new \DateTimeImmutable($row['date'], new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)),
            beneficiary: $this->displayName($row['firstname'], $row['lastname'], $row['email']),
            currency: null,
            amount: null,
            status: $row['status'],
            sourceType: 'FINANCIAL_CALCULATION',
            sourceId: (int) $row['id'],
        ), $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * §18 — documents STANDARD uniquement (facture ET décompte confondus dans une même
     * liste), triés par date décroissante. Une correction (CREDIT_NOTE/DEBIT_NOTE) a sa
     * propre forme documentaire (voir GET .../corrections, Lot 6) — hors du périmètre
     * minimal de ce drill-down.
     *
     * @return array{items: FinancialStatisticsDrilldownItemDto[], total: int, page: int, limit: int}
     */
    public function documents(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortDirection): array
    {
        [$invoiceWhere, $invoiceParams, $invoiceTypes] = $this->documentPopulationClause($filter, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId, 'fi');
        [$stmtWhere, $stmtParams, $stmtTypes] = $this->documentPopulationClause($filter, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId, 'is');
        $currencyInvoice = $filter->currency !== null ? 'AND fi.currency = :currencyFilter' : '';
        $currencyStmt = $filter->currency !== null ? 'AND `is`.currency = :currencyFilter' : '';

        $fUtc = $this->utcDateTimeParam($filter->from);
        $tUtc = $this->utcDateTimeParam($filter->to);
        $fCreated = $filter->from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $tCreated = $filter->to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $unionSql = "
            SELECT fi.id, 'FIRM_INVOICE' AS sourceType, COALESCE(fi.sent_at, fi.generated_at, fi.created_at) AS date,
                   f.name AS beneficiary, fi.currency, fi.total_amount AS amount, fi.status
            FROM firm_invoice fi
            LEFT JOIN firm f ON f.id = fi.firm_id
            WHERE fi.document_type = 'STANDARD'
              AND COALESCE(fi.sent_at, fi.generated_at, fi.created_at) >= :fUtc
              AND COALESCE(fi.sent_at, fi.generated_at, fi.created_at) < :tUtc
              $invoiceWhere $currencyInvoice
            UNION ALL
            SELECT `is`.id, 'INSTRUMENTIST_STATEMENT' AS sourceType, COALESCE(`is`.sent_at, `is`.created_at) AS date,
                   `is`.instrumentist_name_snapshot AS beneficiary, `is`.currency, `is`.total_amount AS amount, `is`.status
            FROM instrumentist_statement `is`
            WHERE `is`.document_type = 'STANDARD'
              AND COALESCE(`is`.sent_at, `is`.created_at) >= :fUtc2
              AND COALESCE(`is`.sent_at, `is`.created_at) < :tUtc2
              $stmtWhere $currencyStmt
        ";

        $params = $invoiceParams + $stmtParams + ['fUtc' => $fUtc, 'tUtc' => $tUtc, 'fUtc2' => $fUtc, 'tUtc2' => $tUtc];
        $types = $invoiceTypes + $stmtTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING, 'fUtc2' => ParameterType::STRING, 'tUtc2' => ParameterType::STRING];
        if ($filter->currency !== null) {
            $params['currencyFilter'] = $filter->currency;
            $types['currencyFilter'] = ParameterType::STRING;
        }

        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ($unionSql) u", $params, $types);

        $sql = "SELECT * FROM ($unionSql) u ORDER BY date " . ($sortDirection === 'ASC' ? 'ASC' : 'DESC') . " LIMIT :limit OFFSET :offset";
        $rows = $this->connection->fetchAllAssociative($sql, $params + ['limit' => $limit, 'offset' => ($page - 1) * $limit], $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

        $items = array_map(fn (array $row) => new FinancialStatisticsDrilldownItemDto(
            id: (int) $row['id'],
            date: new \DateTimeImmutable($row['date'], new \DateTimeZone('UTC')),
            beneficiary: (string) ($row['beneficiary'] ?? '—'),
            currency: $row['currency'],
            amount: $row['amount'],
            status: $row['status'],
            sourceType: $row['sourceType'],
            sourceId: (int) $row['id'],
        ), $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    private function displayName(?string $firstname, ?string $lastname, ?string $email): string
    {
        $name = trim(($firstname ?? '') . ' ' . ($lastname ?? ''));
        return $name !== '' ? $name : (string) ($email ?? '—');
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, int>} */
    private function missionPopulationClause(FinancialStatisticsFilter $filter, string $alias): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($filter->siteId !== null) { $conditions[] = "$alias.site_id = :siteId"; $params['siteId'] = $filter->siteId; $types['siteId'] = ParameterType::INTEGER; }
        if ($filter->surgeonId !== null) { $conditions[] = "$alias.surgeon_id = :surgeonId"; $params['surgeonId'] = $filter->surgeonId; $types['surgeonId'] = ParameterType::INTEGER; }
        if ($filter->instrumentistId !== null) { $conditions[] = "$alias.instrumentist_id = :instrumentistId"; $params['instrumentistId'] = $filter->instrumentistId; $types['instrumentistId'] = ParameterType::INTEGER; }
        if ($filter->firmId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM mission_intervention mif WHERE mif.mission_id = $alias.id AND mif.primary_firm_id = :firmId)";
            $params['firmId'] = $filter->firmId; $types['firmId'] = ParameterType::INTEGER;
        }
        if ($filter->interventionTypeId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM mission_intervention mit WHERE mit.mission_id = $alias.id AND mit.intervention_type_id = :interventionTypeId)";
            $params['interventionTypeId'] = $filter->interventionTypeId; $types['interventionTypeId'] = ParameterType::INTEGER;
        }

        return [count($conditions) > 0 ? ('AND ' . implode(' AND ', $conditions)) : '', $params, $types];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, int>} */
    private function documentPopulationClause(FinancialStatisticsFilter $filter, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId, string $alias): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($ownerId !== null) {
            $conditions[] = "$alias.$ownerColumn = :ownerId_$alias";
            $params["ownerId_$alias"] = $ownerId;
            $types["ownerId_$alias"] = ParameterType::INTEGER;
        }

        if ($filter->siteId !== null || $filter->surgeonId !== null || $filter->interventionTypeId !== null
            || ($filter->firmId !== null && $ownerColumn !== 'firm_id')
            || ($filter->instrumentistId !== null && $ownerColumn !== 'instrumentist_id')
        ) {
            $lineFk = $docTable === 'firm_invoice' ? 'invoice_id' : 'statement_id';
            $missionSub = [];
            if ($filter->siteId !== null) { $missionSub[] = 'm.site_id = :siteId'; $params['siteId'] = $filter->siteId; $types['siteId'] = ParameterType::INTEGER; }
            if ($filter->surgeonId !== null) { $missionSub[] = 'm.surgeon_id = :surgeonId'; $params['surgeonId'] = $filter->surgeonId; $types['surgeonId'] = ParameterType::INTEGER; }
            if ($filter->interventionTypeId !== null) {
                $missionSub[] = 'EXISTS (SELECT 1 FROM mission_intervention mit WHERE mit.mission_id = m.id AND mit.intervention_type_id = :interventionTypeId)';
                $params['interventionTypeId'] = $filter->interventionTypeId; $types['interventionTypeId'] = ParameterType::INTEGER;
            }
            if ($filter->firmId !== null && $ownerColumn !== 'firm_id') {
                $missionSub[] = 'EXISTS (SELECT 1 FROM mission_intervention mif WHERE mif.mission_id = m.id AND mif.primary_firm_id = :firmId)';
                $params['firmId'] = $filter->firmId; $types['firmId'] = ParameterType::INTEGER;
            }
            if ($filter->instrumentistId !== null && $ownerColumn !== 'instrumentist_id') {
                $missionSub[] = 'm.instrumentist_id = :instrumentistId';
                $params['instrumentistId'] = $filter->instrumentistId; $types['instrumentistId'] = ParameterType::INTEGER;
            }
            $missionWhere = implode(' AND ', $missionSub);
            $conditions[] = "EXISTS (SELECT 1 FROM $lineTable dl INNER JOIN mission m ON m.id = dl.mission_id WHERE dl.$lineFk = $alias.id AND $missionWhere)";
        }

        return [count($conditions) > 0 ? ('AND ' . implode(' AND ', $conditions)) : '', $params, $types];
    }

    private function businessDateTimeParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))->format('Y-m-d H:i:s');
    }

    private function utcDateTimeParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function dateParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))->format('Y-m-d');
    }
}
