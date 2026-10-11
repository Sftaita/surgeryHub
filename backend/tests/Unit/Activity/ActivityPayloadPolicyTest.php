<?php

namespace App\Tests\Unit\Activity;

use App\Activity\ActivityPayloadPolicy;
use App\Enum\ActivityDomain;
use App\Enum\ActivityEventType;
use App\Enum\AuditEventType;
use App\Enum\UserAuditEventType;
use PHPUnit\Framework\TestCase;

/** H1 (D-126) — strict per-type payload allow-list. */
final class ActivityPayloadPolicyTest extends TestCase
{
    private ActivityPayloadPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ActivityPayloadPolicy();
    }

    public function test_unknown_keys_are_dropped_and_allowed_keys_kept(): void
    {
        $out = $this->policy->filter(ActivityEventType::ABSENCE_CREATED, ActivityDomain::ABSENCES, [
            'absenceId' => 58, 'userName' => 'Philippe Schiepers', 'dateStart' => '2026-09-29',
            'reason' => 'Congrès', 'whatever' => 'x', 'nested' => ['a' => 1],
        ]);

        self::assertSame(['absenceId' => 58, 'userName' => 'Philippe Schiepers', 'dateStart' => '2026-09-29'], $out);
    }

    public function test_sensitive_key_names_are_dropped_even_for_audit_mirrors(): void
    {
        $out = $this->policy->filter(UserAuditEventType::USER_EMAIL_CHANGED, ActivityDomain::USERS, [
            'oldEmail' => 'a@b.c', 'newEmail' => 'd@e.f', 'email' => 'x@y.z', 'password' => 'p',
            'token' => 't', 'jwt' => 'j', 'patientName' => 'p', 'diagnosis' => 'd', 'ip' => '1.2.3.4',
            'stackTrace' => '#0', 'body' => '<p>', 'count' => 3,
        ]);

        self::assertSame(['count' => 3], $out);
    }

    public function test_free_text_audit_fields_are_never_copied(): void
    {
        $out = $this->policy->filter(AuditEventType::MISSION_CANCELLED_POST_DEPLOY, ActivityDomain::MISSIONS, [
            'missionId' => 485, 'reason' => 'patient X', 'note' => 'n', 'reviewComment' => 'c', 'hasReason' => true,
        ]);

        self::assertSame(['missionId' => 485, 'hasReason' => true], $out);
    }

    public function test_financial_amounts_only_kept_for_financial_domains(): void
    {
        $payload = ['pricingRuleId' => 3, 'amount' => '120.00', 'currency' => 'EUR'];

        self::assertSame($payload, $this->policy->filter(AuditEventType::PRICING_RULE_CREATED, ActivityDomain::PRICING, $payload));
        self::assertSame(['pricingRuleId' => 3], $this->policy->filter(AuditEventType::PRICING_RULE_CREATED, ActivityDomain::MISSIONS, $payload));
    }

    public function test_email_addresses_inside_allowed_values_are_masked(): void
    {
        $out = $this->policy->filter(AuditEventType::ROOM_SLOT_MADE_AVAILABLE, ActivityDomain::ROOMS, [
            'originalSurgeonName' => 'Dr someone@example.org',
        ]);

        self::assertStringNotContainsString('@', (string) $out['originalSurgeonName']);
    }

    public function test_nested_lists_maps_and_objects_follow_their_own_spec(): void
    {
        $out = $this->policy->filter(ActivityEventType::PLANNING_GENERATED, ActivityDomain::PLANNING, [
            'lineStatusCounts' => ['SKIPPED' => 2, 'COVERED' => 5, 'token' => 1],
            'occurrences'      => [['date' => '2026-10-02', 'reason' => 'SURGEON_ABSENT', 'patient' => 'x', 'extra' => 1]],
            'siteIds'          => [1, ['nested'], 3],
        ]);

        self::assertSame(['SKIPPED' => 2, 'COVERED' => 5], $out['lineStatusCounts']);
        self::assertSame([['date' => '2026-10-02', 'reason' => 'SURGEON_ABSENT']], $out['occurrences']);
        self::assertSame([1, 3], $out['siteIds']);

        $updated = $this->policy->filter(ActivityEventType::ABSENCE_UPDATED, ActivityDomain::ABSENCES, [
            'before' => ['dateStart' => '2026-09-29', 'reason' => 'secret'],
            'after'  => ['dateStart' => '2026-09-30'],
        ]);
        self::assertSame(['dateStart' => '2026-09-29'], $updated['before']);
    }

    public function test_lists_and_strings_are_capped(): void
    {
        $out = $this->policy->filter(ActivityEventType::PLANNING_DRAFT_DELETED, ActivityDomain::PLANNING, [
            'occurrences' => array_fill(0, 600, ['missionId' => 1]),
            'source'      => str_repeat('x', 400),
        ]);

        self::assertCount(500, $out['occurrences']);
        self::assertSame(255, mb_strlen($out['source']));
    }

    public function test_channel_names_are_structural_keys_but_email_fields_stay_forbidden(): void
    {
        $out = $this->policy->filter(ActivityEventType::NOTIFICATION_DISPATCHED, ActivityDomain::NOTIFICATIONS, [
            'channels'      => ['EMAIL' => ['status' => 'QUEUED', 'email' => 'x@y.z'], 'IN_APP' => ['status' => 'SENT']],
            'recipientName' => 'Françoise Detrembleur',
        ]);

        self::assertSame(['status' => 'QUEUED'], $out['channels']['EMAIL']);
        self::assertSame(['status' => 'SENT'], $out['channels']['IN_APP']);
    }

    public function test_every_native_type_has_a_policy(): void
    {
        foreach (ActivityEventType::cases() as $type) {
            self::assertIsArray($this->policy->filter($type, $type->domain(), []));
        }
    }
}
