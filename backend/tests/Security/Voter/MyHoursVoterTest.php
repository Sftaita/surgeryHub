<?php

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\MyHoursVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * D-179 — l'instrumentiste ne voit que SON relevé et SA propre estimation ; aucun autre rôle.
 */
final class MyHoursVoterTest extends TestCase
{
    private function user(int $id, string $role): User
    {
        $u = new User();
        $u->setRoles([$role]);
        $u->setEmail("u$id@surgicalhub.test");
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
        return $u;
    }

    private function vote(User $actor, string $attribute, mixed $subject = null): int
    {
        $token = new UsernamePasswordToken($actor, 'main', $actor->getRoles());
        return (new MyHoursVoter())->vote($token, $subject, [$attribute]);
    }

    public function test_self_access_is_instrumentist_only(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->user(1, 'ROLE_INSTRUMENTIST'), MyHoursVoter::SELF_ACCESS));
        foreach (['ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SURGEON'] as $role) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->user(2, $role), MyHoursVoter::SELF_ACCESS), $role);
        }
    }

    public function test_estimate_only_for_oneself(): void
    {
        $me = $this->user(1, 'ROLE_INSTRUMENTIST');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($me, MyHoursVoter::VIEW_OWN_ESTIMATE, $me));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($me, MyHoursVoter::VIEW_OWN_ESTIMATE, $this->user(2, 'ROLE_INSTRUMENTIST')));

        $manager = $this->user(3, 'ROLE_MANAGER');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($manager, MyHoursVoter::VIEW_OWN_ESTIMATE, $manager));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($manager, MyHoursVoter::VIEW_OWN_ESTIMATE, $me));
    }

    public function test_abstains_on_unknown_attribute_or_wrong_subject(): void
    {
        $me = $this->user(1, 'ROLE_INSTRUMENTIST');
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($me, 'BILLING_MANAGE'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($me, MyHoursVoter::VIEW_OWN_ESTIMATE, null));
    }
}
