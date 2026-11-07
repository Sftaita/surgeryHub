<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * H1 (D-126) — absolute instant, stored as UTC wall clock in a DATETIME(6) column, with
 * microseconds. Used by activity_event.occurred_at only.
 *
 * Deliberately NOT BusinessDateTimeImmutableType (D-066): that type labels stored digits
 * as Europe/Brussels business wall clock, which is right for a Mission's planned time but
 * wrong for "when did this happen" — an audit instant must be unambiguous across DST
 * changes. Presentation in Europe/Brussels is the (future) History API's job.
 *
 * The built-in datetime_immutable type formats 'Y-m-d H:i:s' and would silently drop the
 * microseconds; this type keeps them so that (occurred_at, id) is a fine-grained,
 * stable ordering.
 */
final class UtcDateTimeMicroImmutableType extends Type
{
    public const NAME = 'utc_datetime_micro_immutable';

    private const FORMAT = 'Y-m-d H:i:s.u';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'DATETIME(6)';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof \DateTimeInterface) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', \DateTimeInterface::class]);
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $utc    = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT, (string) $value, $utc)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, $utc);

        if ($parsed === false) {
            throw ConversionException::conversionFailedFormat((string) $value, self::NAME, self::FORMAT);
        }

        return $parsed;
    }

    public static function format(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }
}
