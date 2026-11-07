<?php

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\InstrumentistHoursVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * D-180 — le suivi des heures de toutes les instrumentistes est réservé à MANAGER et ADMIN.
 */
final class InstrumentistHoursVoterTest extends TestCase
{
    private function user(string $role, bool $active = true): User
    {
        $u = new User();
        $u->setRoles([$role]);
        $u->setEmail(strtolower($role) . '@surgicalhub.test');
        $u->setActive($active);
        return $u;
    }

    private function vote(User $actor, string $attribute = InstrumentistHoursVoter::VIEW): int
    {
        $token = new UsernamePasswordToken($actor, 'main', $actor->getRoles());
        return (new InstrumentistHoursVoter())->vote($token, null, [$attribute]);
    }

    public function test_manager_and_admin_only(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->user('ROLE_MANAGER')));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->user('ROLE_ADMIN')));
        foreach (['ROLE_INSTRUMENTIST', 'ROLE_SURGEON', 'ROLE_USER'] as $role) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->user($role)), $role);
        }
    }

    public function test_inactive_manager_is_denied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->user('ROLE_MANAGER', false)));
    }

    public function test_abstains_on_other_attributes(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->user('ROLE_MANAGER'), 'BILLING_MANAGE'));
    }
}
