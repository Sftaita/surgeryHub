<?php

namespace App\Tests\Architecture;

use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

/**
 * D-066 guardrail — no PHPStan/Psalm is configured in this project (checked: neither
 * appears in composer.json), so this is a plain PHPUnit reflection test instead of a
 * static-analysis rule. It enforces the convention from D-065/D-066: any
 * DateTimeImmutable entity column that could ever hold a client-submitted,
 * offset-bearing wall-clock moment (as opposed to a date-only value or a
 * system-generated `now()` timestamp) must use
 * `App\Doctrine\Type\BusinessDateTimeImmutableType` (`business_datetime_immutable`),
 * never the plain `datetime_immutable` — see that type's docblock for why.
 *
 * How it works: reflect every entity under src/Entity, find every property typed
 * DateTimeImmutable, and require each one to be EITHER on business_datetime_immutable
 * OR explicitly listed in SAFE_ALLOWLIST below with a one-line reason. `createdAt`/
 * `updatedAt` (the TimestampableTrait convention — always `new \DateTimeImmutable()`,
 * never client input) are exempted by name, not by allowlist entry, since they follow
 * a project-wide, structurally-guaranteed-safe pattern rather than a per-column decision.
 *
 * The point: adding a NEW DateTimeImmutable column to ANY entity, without a conscious
 * decision recorded either as the correct Doctrine type or as an allowlist entry with a
 * reason, fails this test immediately instead of silently repeating the D-065 bug class.
 */
final class BusinessDateTimeColumnConventionTest extends TestCase
{
    private const BUSINESS_TYPE = 'business_datetime_immutable';

    /**
     * "ClassName::property" => reason it is safe to stay on the plain datetime_immutable
     * type. Every entry here was verified in the D-065 audit (2026-07-15) to never
     * receive a client-submitted offset-bearing value — only a date-only value (no
     * time-of-day, so no offset to mislabel) or a server-generated `new
     * \DateTimeImmutable()` "now" stamp (always genuinely UTC, no mislabeling possible).
     *
     * Re-verify the reason still holds before adding an entry — do not add an entry
     * just to silence a failing test.
     */
    private const SAFE_ALLOWLIST = [
        // Always date-only (Y-m-d), no time-of-day component is ever read or written —
        // an offset has nothing to attach to.
        'App\Entity\Absence::dateStart' => 'date-only (Y-m-d), never formatted with a time/offset component',
        'App\Entity\Absence::dateEnd' => 'date-only (Y-m-d), never formatted with a time/offset component',
        'App\Entity\FirmInvoice::periodStart' => 'date-only (Y-m-d)',
        'App\Entity\FirmInvoice::periodEnd' => 'date-only (Y-m-d)',
        'App\Entity\PlanningDeployment::periodFrom' => 'date-only (Y-m-d)',
        'App\Entity\PlanningDeployment::periodTo' => 'date-only (Y-m-d)',
        'App\Entity\PlanningOccurrenceException::occurrenceDate' => 'date-only (Y-m-d)',
        'App\Entity\PlanningOccurrenceException::overrideDate' => 'date-only (Y-m-d)',
        'App\Entity\PlanningOccurrenceException::overrideStartTime' => 'time-of-day only (H:i), no date/offset component',
        'App\Entity\PlanningOccurrenceException::overrideEndTime' => 'time-of-day only (H:i), no date/offset component',
        'App\Entity\PlanningSlot::startTime' => 'time-of-day only (H:i)',
        'App\Entity\PlanningSlot::endTime' => 'time-of-day only (H:i)',
        'App\Entity\PlanningVersion::periodStart' => 'date-only (Y-m-d)',
        'App\Entity\PlanningVersion::periodEnd' => 'date-only (Y-m-d)',
        'App\Entity\PricingRule::validFrom' => 'date-only (Y-m-d) — Lot 1, jamais un instant client à décalage horaire',
        'App\Entity\PricingRule::validTo' => 'date-only (Y-m-d) — Lot 1, jamais un instant client à décalage horaire',
        'App\Entity\RecurrenceRule::anchorDate' => 'date-only (Y-m-d)',
        'App\Entity\ShiftPeriodConfig::startTime' => 'time-of-day only (H:i)',
        'App\Entity\ShiftPeriodConfig::endTime' => 'time-of-day only (H:i)',
        'App\Entity\SurgeonSchedulePost::startDate' => 'date-only (Y-m-d)',
        'App\Entity\SurgeonSchedulePost::endDate' => 'date-only (Y-m-d)',
        'App\Entity\WeeklyTemplate::startTime' => 'time-of-day only (H:i)',
        'App\Entity\WeeklyTemplate::endTime' => 'time-of-day only (H:i)',

        // Always `new \DateTimeImmutable()` (a genuine server "now") or a relative
        // modifier of it (e.g. "+48 hours") — never parsed from client-submitted input,
        // so the container's UTC label is truthful, not mislabeled.
        'App\Entity\Mission::submittedAt' => 'set from new \DateTimeImmutable() at submit time, never client input',
        'App\Entity\Mission::encodingLockedAt' => 'set from new \DateTimeImmutable(), never client input',
        'App\Entity\Mission::invoiceGeneratedAt' => 'set from new \DateTimeImmutable(), never client input',
        'App\Entity\Mission::declaredAt' => 'set from new \DateTimeImmutable() at declare time, never client input',
        'App\Entity\FirmInvoice::generatedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\FirmInvoice::sentAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\FirmInvoice::paidAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\InstrumentistStatement::sentAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\InstrumentistStatement::paidAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\InstrumentistStatementLine::missionDateSnapshot' => 'date-only digits copied from Mission.startAt, never reformatted with an offset',
        'App\Entity\MissionClaim::claimedAt' => 'set from new \DateTimeImmutable() in the constructor',
        'App\Entity\MissionPublication::publishedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\NotificationEvent::sentAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\NotificationEvent::failedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\NotificationEvent::seenAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\PlanningAlert::detectedAt' => 'set from new \DateTimeImmutable() in the constructor',
        'App\Entity\PlanningAlert::resolvedAt' => 'set from new \DateTimeImmutable() in resolve()',
        'App\Entity\PlanningDeployment::deployedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\PlanningDeployment::startedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\PlanningDeployment::completedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\PlanningOccurrenceException::createdAt' => 'not covered by TimestampableTrait on this entity; set from new \DateTimeImmutable()',
        'App\Entity\PlanningTemplate::createdAt' => 'not covered by TimestampableTrait on this entity; set from new \DateTimeImmutable()',
        'App\Entity\PlanningVersion::generatedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\PlanningVersion::deployedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\PlanningVersion::archivedAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\SiteGroup::createdAt' => 'not covered by TimestampableTrait on this entity; set from new \DateTimeImmutable()',
        'App\Entity\SurgeonSchedulePost::createdAt' => 'not covered by TimestampableTrait on this entity; set from new \DateTimeImmutable()',
        'App\Entity\User::invitationExpiresAt' => "set from new \\DateTimeImmutable('+N hours'), relative to server now, never client input",
        'App\Entity\User::invitationLastSentAt' => 'set from new \DateTimeImmutable()',
        'App\Entity\UserAuditEvent::createdAt' => 'set from new \DateTimeImmutable() in the constructor',
        'App\Entity\Absence::createdAt' => 'not covered by TimestampableTrait on this entity; set from new \DateTimeImmutable()',
    ];

    /** Exempt by name, not by allowlist entry — the project-wide TimestampableTrait convention. */
    private const TIMESTAMPABLE_TRAIT_PROPERTIES = ['createdAt', 'updatedAt'];

    public function test_every_datetime_immutable_entity_column_is_either_business_typed_or_explicitly_allowlisted(): void
    {
        $violations = [];
        $staleAllowlistEntries = self::SAFE_ALLOWLIST;

        foreach ($this->entityClasses() as $class) {
            $rc = new \ReflectionClass($class);
            $usesTimestampableTrait = in_array('App\Entity\Traits\TimestampableTrait', $rc->getTraitNames(), true);

            foreach ($rc->getProperties() as $property) {
                $type = $property->getType();
                if ($type === null || $type->getName() !== \DateTimeImmutable::class) {
                    continue;
                }

                $columnAttrs = $property->getAttributes(Column::class);
                if ($columnAttrs === []) {
                    continue; // not a mapped column (e.g. a plain in-memory property)
                }

                $name = $property->getName();

                if ($usesTimestampableTrait && in_array($name, self::TIMESTAMPABLE_TRAIT_PROPERTIES, true)) {
                    continue;
                }

                $args = $columnAttrs[0]->getArguments();
                $columnType = $args['type'] ?? 'datetime_immutable'; // Doctrine's own default when omitted

                if ($columnType === self::BUSINESS_TYPE) {
                    continue;
                }

                $key = $class . '::' . $name;
                unset($staleAllowlistEntries[$key]);

                if (array_key_exists($key, self::SAFE_ALLOWLIST)) {
                    continue;
                }

                $violations[] = $key;
            }
        }

        self::assertSame(
            [],
            $violations,
            "New DateTimeImmutable column(s) found that are neither on business_datetime_immutable " .
            "nor in this test's SAFE_ALLOWLIST: " . implode(', ', $violations) . ". " .
            "Make a conscious decision: if this column can ever receive a client-submitted, " .
            "offset-bearing wall-clock value, use App\\Doctrine\\Type\\BusinessDateTimeImmutableType " .
            "(see D-066). If it is always date-only or always server-generated (new " .
            "\\DateTimeImmutable()), add it to SAFE_ALLOWLIST above with a one-line reason.",
        );

        self::assertSame(
            [],
            array_keys($staleAllowlistEntries),
            'Stale SAFE_ALLOWLIST entries (property no longer exists, or its exact type/name changed) — ' .
            'remove or update: ' . implode(', ', array_keys($staleAllowlistEntries)),
        );
    }

    public function test_mission_start_and_end_at_use_the_business_type(): void
    {
        // Concrete regression lock for the one column this whole fix was about — a
        // narrower, more specific companion to the general scan above.
        $rc = new \ReflectionClass(\App\Entity\Mission::class);

        foreach (['startAt', 'endAt'] as $name) {
            $property = $rc->getProperty($name);
            $args = $property->getAttributes(Column::class)[0]->getArguments();

            self::assertSame(
                self::BUSINESS_TYPE,
                $args['type'] ?? null,
                "Mission::{$name} must stay on business_datetime_immutable (D-066).",
            );
        }
    }

    /** @return list<class-string> */
    private function entityClasses(): array
    {
        $dir = __DIR__ . '/../../src/Entity';
        $files = glob($dir . '/*.php');
        self::assertNotEmpty($files, 'No entity files found — check the path.');

        $classes = [];
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            $fqcn = 'App\\Entity\\' . $basename;
            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }
}
