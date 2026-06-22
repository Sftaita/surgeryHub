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
