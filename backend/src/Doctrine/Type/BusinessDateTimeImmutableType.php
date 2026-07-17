<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * D-066 — structural fix for the Mission.startAt/endAt timezone-mislabeling bug
 * documented in D-065. Same underlying SQL column type as the built-in
 * `datetime_immutable` (DATETIME, no migration needed) — only the PHP-side hydration
 * and persistence are overridden.
 *
 * The problem this solves: a plain `datetime_immutable` column stores a naive
 * "Y-m-d H:i:s" string (MySQL DATETIME carries no offset). On read, Doctrine's base
 * type parses that string using the CONTAINER's default timezone (UTC here) — so a
 * value that actually represents Brussels wall-clock time gets mislabeled as UTC.
 * `format(ATOM)` then emits a false "+00:00" instead of the true "+01:00"/"+02:00".
 *
 * This type makes BUSINESS_TIMEZONE the single, explicit source of truth for what a
 * Mission's stored wall-clock digits mean, on both sides:
 *
 * - Read (`convertToPHPValue`): parse the raw stored string as Europe/Brussels wall
 *   clock, not the container default. The digits are never shifted — only correctly
 *   labeled. A DB value of "2026-07-15 10:11:00" becomes the PHP instant
 *   "2026-07-15T10:11:00+02:00" (DST), never "+00:00" and never converted to some other
 *   apparent hour.
 * - Write (`convertToDatabaseValue`): whatever offset the incoming DateTimeImmutable
 *   carries (client-submitted "+02:00", explicit "+00:00", or already Brussels-labeled
 *   from a prior read), convert it to its true Europe/Brussels wall-clock equivalent
 *   before formatting for storage — so "2026-07-15T08:11:00+00:00" and
 *   "2026-07-15T10:11:00+02:00" (the same real instant, two representations) both store
 *   identically as "2026-07-15 10:11:00".
 *
 * Every consumer of `Mission::getStartAt()`/`getEndAt()` — MissionMapper,
 * SurgeonServiceManager, InstrumentistServiceManager, PlanningAlertService,
 * ExportService, MissionPostDeployService's audit payload, NotificationService,
 * AbsenceImpactService's alert snapshot — receives an already-correctly-labeled object
 * with zero code changes on their part. See docs/decisions.md D-066.
 *
 * Scope: applied ONLY to Mission.startAt/endAt today (the only columns proven to
 * receive client-submitted, offset-bearing datetime input — see D-065's audit). Do not
 * apply this type to a new business-schedule column without re-reading that audit's
 * reasoning; see the architecture test `BusinessDateTimeColumnConventionTest` for the
 * guardrail that flags candidate columns going forward.
 */
final class BusinessDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'business_datetime_immutable';

    public const BUSINESS_TIMEZONE = 'Europe/Brussels';

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $value
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $businessTz = new \DateTimeZone(self::BUSINESS_TIMEZONE);
        $dateTime = \DateTimeImmutable::createFromFormat(
            $platform->getDateTimeFormatString(),
            $value,
            $businessTz,
        );

        if ($dateTime === false) {
            throw ConversionException::conversionFailedFormat(
                $value,
                $this->getName(),
                $platform->getDateTimeFormatString(),
            );
        }

        return $dateTime;
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $value
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof \DateTimeImmutable) {
            throw ConversionException::conversionFailedInvalidType(
                $value,
                $this->getName(),
                ['null', \DateTimeImmutable::class],
            );
        }

        $businessTz = new \DateTimeZone(self::BUSINESS_TIMEZONE);

        return $value->setTimezone($businessTz)->format($platform->getDateTimeFormatString());
    }
}
