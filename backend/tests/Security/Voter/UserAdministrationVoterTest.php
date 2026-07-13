<?php

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\UserAdministrationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class UserAdministrationVoterTest extends TestCase
{
    private UserAdministrationVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new UserAdministrationVoter();
    }

    #[DataProvider('adminAttributesProvider')]
    public function testAdminIsGrantedAllAttributes(string $attribute): void
    {
        $token = $this->tokenForUser(['ROLE_ADMIN']);
        $result = $this->voter->vote($token, null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result, "Expected GRANTED for $attribute");
    }

    #[DataProvider('adminAttributesProvider')]
    public function testManagerIsDeniedAllAttributes(string $attribute): void
    {
        $token = $this->tokenForUser(['ROLE_MANAGER']);
        $result = $this->voter->vote($token, null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, "Expected DENIED for $attribute");
    }

    #[DataProvider('adminAttributesProvider')]
    public function testInstrumentistIsDeniedAllAttributes(string $attribute): void
    {
        $token = $this->tokenForUser(['ROLE_INSTRUMENTIST']);
        $result = $this->voter->vote($token, null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, "Expected DENIED for $attribute");
    }

    #[DataProvider('adminAttributesProvider')]
    public function testSurgeonIsDeniedAllAttributes(string $attribute): void
    {
        $token = $this->tokenForUser(['ROLE_SURGEON']);
        $result = $this->voter->vote($token, null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result, "Expected DENIED for $attribute");
    }

    public function testVoterAbstainsOnUnknownAttribute(): void
    {
        $token = $this->tokenForUser(['ROLE_ADMIN']);
        $result = $this->voter->vote($token, null, ['SOME_UNRELATED_ATTRIBUTE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAdminIsGrantedUpdateEmail(): void
    {
        $token = $this->tokenForUser(['ROLE_ADMIN']);
        $result = $this->voter->vote($token, null, [UserAdministrationVoter::UPDATE_EMAIL]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testManagerIsGrantedUpdateEmail(): void
    {
        $token = $this->tokenForUser(['ROLE_MANAGER']);
        $result = $this->voter->vote($token, null, [UserAdministrationVoter::UPDATE_EMAIL]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testInstrumentistIsDeniedUpdateEmail(): void
    {
        $token = $this->tokenForUser(['ROLE_INSTRUMENTIST']);
        $result = $this->voter->vote($token, null, [UserAdministrationVoter::UPDATE_EMAIL]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testSurgeonIsDeniedUpdateEmail(): void
    {
        $token = $this->tokenForUser(['ROLE_SURGEON']);
        $result = $this->voter->vote($token, null, [UserAdministrationVoter::UPDATE_EMAIL]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public static function adminAttributesProvider(): array
    {
        return [
            [UserAdministrationVoter::LIST],
            [UserAdministrationVoter::VIEW],
            [UserAdministrationVoter::UPDATE],
            [UserAdministrationVoter::CREATE],
            [UserAdministrationVoter::SUSPEND],
            [UserAdministrationVoter::ACTIVATE],
            [UserAdministrationVoter::CHANGE_ROLE],
            [UserAdministrationVoter::RESEND_INVITATION],
            [UserAdministrationVoter::ADD_SITE],
            [UserAdministrationVoter::REMOVE_SITE],
        ];
    }

    private function tokenForUser(array $roles): UsernamePasswordToken
    {
        $user = new User();
        $user->setRoles($roles);

        // In Symfony 7, UsernamePasswordToken(user, firewallName, roles=[]).
        // The voter reads roles from $user->getRoles(), not from the token's role list.
        return new UsernamePasswordToken($user, 'main');
    }
}
