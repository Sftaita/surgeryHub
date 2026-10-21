<?php

namespace App\Tests\Unit\Service;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\DefaultNotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DefaultNotificationPreferenceResolverTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ?NotificationPreference $stored = null;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->stored = null;

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturnCallback(fn () => $this->stored);
        $this->em->method('getRepository')->willReturn($repo);
    }

    private function makeResolver(): DefaultNotificationPreferenceResolver
    {
        return new DefaultNotificationPreferenceResolver($this->em);
    }

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('user@test.com');
        return $u;
    }

    public function test_defaults_when_no_preference_row_exists(): void
    {
        $this->stored = null;

        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_ALERT);

        $this->assertTrue($channels->inApp, 'in-app must default to enabled');
        $this->assertTrue($channels->email, 'email must default to enabled for planning alerts');
        $this->assertFalse($channels->push, 'push must default to disabled until opt-in');
    }

    // ── Per-type email defaults (Batch 15A) ───────────────────────────────────

    /** H3 (D-129) — a mission becoming available is actionable: every channel on by default. */
    public function test_resolver_open_mission_available_defaults_to_every_channel(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::OPEN_MISSION_AVAILABLE);
        $this->assertTrue($channels->inApp);
        $this->assertTrue($channels->email, 'H3: email on by default for available missions');
        $this->assertTrue($channels->push, 'H3: push on by default for available missions');
    }

    /** D-161 — a room that became available: push by default, email OFF unless the surgeon opts in. */
    public function test_resolver_room_slot_available_defaults_to_push_without_email(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::ROOM_SLOT_AVAILABLE);
        $this->assertTrue($channels->inApp);
        $this->assertTrue($channels->push, 'D-161: push is the standard channel');
        $this->assertFalse($channels->email, 'D-161: email off by default, for every surgeon');
        $this->assertSame('DEFAULT', $channels->source);
        $this->assertTrue(NotificationType::ROOM_SLOT_AVAILABLE->isConfigurable());
        $this->assertSame([NotificationType::AUDIENCE_SURGEON], NotificationType::ROOM_SLOT_AVAILABLE->audienceRoles());
    }

    /** D-161 — an explicit choice made in the settings is always honoured. */
    public function test_room_slot_available_explicit_email_choice_is_honoured(): void
    {
        $pref = (new NotificationPreference())->setUser($this->makeUser())->setNotificationType(NotificationType::ROOM_SLOT_AVAILABLE)
            ->setInAppEnabled(true)->setEmailEnabled(true)->setPushEnabled(true);
        $this->stored = $pref;

        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::ROOM_SLOT_AVAILABLE);
        $this->assertTrue($channels->email);
        $this->assertTrue($channels->push);
        $this->assertSame('PREFERENCE', $channels->source);
    }

    public function test_resolver_planning_deployed_instrumentist_default_email_true(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST);
        $this->assertTrue($channels->inApp);
        $this->assertTrue($channels->email, 'email must default to true — deploy notification is important');
        $this->assertFalse($channels->push);
    }

    public function test_resolver_planning_deployed_surgeon_default_email_true(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_DEPLOYED_SURGEON);
        $this->assertTrue($channels->email, 'surgeon deploy notification defaults to email=true');
    }

    public function test_resolver_planning_deployed_manager_default_email_true(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_DEPLOYED_MANAGER);
        $this->assertTrue($channels->email, 'manager deploy notification defaults to email=true');
    }

    public function test_resolver_planning_mission_cancelled_default_email_true(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_MISSION_CANCELLED);
        $this->assertTrue($channels->inApp);
        $this->assertTrue($channels->email, 'mission cancelled is urgent — email must default to true');
        $this->assertFalse($channels->push);
    }

    public function test_resolver_surgeon_post_covered_default_email_false(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::SURGEON_POST_COVERED);
        $this->assertTrue($channels->inApp);
        $this->assertFalse($channels->email, 'coverage notification is informational — email defaults to false');
    }

    public function test_resolver_surgeon_post_uncovered_default_email_false(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::SURGEON_POST_UNCOVERED);
        $this->assertFalse($channels->email);
    }

    public function test_resolver_planning_mission_reassigned_default_email_false(): void
    {
        $this->stored = null;
        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_MISSION_REASSIGNED);
        $this->assertFalse($channels->email);
    }

    public function test_notification_type_all_values_lte_32_chars(): void
    {
        foreach (NotificationType::cases() as $case) {
            $this->assertLessThanOrEqual(
                32,
                strlen($case->value),
                sprintf(
                    'NotificationType::%s backing value "%s" is %d chars — exceeds the 32-char '
                    . 'notification_preference.notification_type VARCHAR(32) column limit.',
                    $case->name,
                    $case->value,
                    strlen($case->value),
                ),
            );
        }
    }

    public function test_uses_stored_preference_when_present(): void
    {
        $pref = new NotificationPreference();
        $pref->setUser($this->makeUser());
        $pref->setNotificationType(NotificationType::PLANNING_ALERT);
        $pref->setInAppEnabled(false);
        $pref->setEmailEnabled(false);
        $pref->setPushEnabled(true);
        $this->stored = $pref;

        $channels = $this->makeResolver()->resolve($this->makeUser(), NotificationType::PLANNING_ALERT);

        $this->assertFalse($channels->inApp);
        $this->assertFalse($channels->email);
        $this->assertTrue($channels->push);
    }
}
