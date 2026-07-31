<?php

namespace App\Tests\Unit\Command;

use App\Command\PromoteUserToAdminCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserAdministrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Lot 7 (audit PWA/mobile/admin 2026-07-29) — procédure contrôlée pour attribuer
 * ROLE_ADMIN à un compte existant. Réutilise UserAdministrationService::changeRole()
 * (déjà testé unitairement + fonctionnellement ailleurs) — ce test couvre uniquement
 * la couche commande : recherche insensible à la casse, acteur obligatoirement
 * ROLE_ADMIN, idempotence, jamais de création de compte.
 */
final class PromoteUserToAdminCommandTest extends TestCase
{
    private UserRepository&MockObject $users;
    private UserAdministrationService&MockObject $adminService;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->adminService = $this->createMock(UserAdministrationService::class);
    }

    private function makeUser(int $id, string $email, array $roles): User
    {
        $u = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($u, $id);
        $u->setEmail($email);
        $u->setRoles($roles);
        return $u;
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PromoteUserToAdminCommand($this->users, $this->adminService));
    }

    public function testPromotesTargetAndDelegatesToChangeRole(): void
    {
        $target = $this->makeUser(2, 'samy.ftaita89@gmail.com', ['ROLE_MANAGER']);
        $actor = $this->makeUser(1, 'admin@surgicalhub.be', ['ROLE_ADMIN']);

        $this->users->method('findOneByEmailInsensitive')
            ->willReturnMap([
                ['samy.ftaita89@gmail.com', $target],
                ['admin@surgicalhub.be', $actor],
            ]);

        $this->adminService->expects(self::once())
            ->method('changeRole')
            ->with($target, 'ROLE_ADMIN', $actor)
            ->willReturn($target);

        $exitCode = $this->tester()->execute([
            'email' => 'samy.ftaita89@gmail.com',
            'actorEmail' => 'admin@surgicalhub.be',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testLooksUpEmailsCaseInsensitively(): void
    {
        $target = $this->makeUser(2, 'samy.ftaita89@gmail.com', ['ROLE_MANAGER']);
        $actor = $this->makeUser(1, 'admin@surgicalhub.be', ['ROLE_ADMIN']);

        $this->users->expects(self::exactly(2))->method('findOneByEmailInsensitive')
            ->willReturnCallback(function (string $email) use ($target, $actor) {
                return match ($email) {
                    'samy.ftaita89@gmail.com' => $target,
                    'admin@surgicalhub.be' => $actor,
                    default => null,
                };
            });
        $this->adminService->method('changeRole')->willReturn($target);

        $exitCode = $this->tester()->execute([
            'email' => 'SAMY.Ftaita89@GMAIL.com',
            'actorEmail' => 'Admin@SurgicalHub.be',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testFailsWithoutCreatingAnythingWhenTargetNotFound(): void
    {
        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->adminService->expects(self::never())->method('changeRole');

        $tester = $this->tester();
        $exitCode = $tester->execute(['email' => 'nobody@surgicalhub.test', 'actorEmail' => 'admin@surgicalhub.be']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No user found', $tester->getDisplay());
    }

    public function testFailsWhenActorAccountNotFound(): void
    {
        $target = $this->makeUser(2, 'target@surgicalhub.test', ['ROLE_MANAGER']);
        $this->users->method('findOneByEmailInsensitive')
            ->willReturnMap([
                ['target@surgicalhub.test', $target],
                ['ghost@surgicalhub.test', null],
            ]);
        $this->adminService->expects(self::never())->method('changeRole');

        $tester = $this->tester();
        $exitCode = $tester->execute(['email' => 'target@surgicalhub.test', 'actorEmail' => 'ghost@surgicalhub.test']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No actor account found', $tester->getDisplay());
    }

    public function testFailsWhenActorIsNotAdmin(): void
    {
        $target = $this->makeUser(2, 'target@surgicalhub.test', ['ROLE_MANAGER']);
        $actor = $this->makeUser(3, 'manager@surgicalhub.test', ['ROLE_MANAGER']);
        $this->users->method('findOneByEmailInsensitive')
            ->willReturnMap([
                ['target@surgicalhub.test', $target],
                ['manager@surgicalhub.test', $actor],
            ]);
        $this->adminService->expects(self::never())->method('changeRole');

        $tester = $this->tester();
        $exitCode = $tester->execute(['email' => 'target@surgicalhub.test', 'actorEmail' => 'manager@surgicalhub.test']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('is not ROLE_ADMIN', $tester->getDisplay());
    }

    /**
     * Idempotence (exigée explicitement) : ré-exécuter la commande sur un compte déjà
     * ROLE_ADMIN ne doit ni échouer, ni ré-auditer, ni toucher à quoi que ce soit.
     */
    public function testIsIdempotentWhenTargetAlreadyAdmin(): void
    {
        $target = $this->makeUser(2, 'already-admin@surgicalhub.test', ['ROLE_ADMIN']);
        $actor = $this->makeUser(1, 'admin@surgicalhub.be', ['ROLE_ADMIN']);
        $this->users->method('findOneByEmailInsensitive')
            ->willReturnMap([
                ['already-admin@surgicalhub.test', $target],
                ['admin@surgicalhub.be', $actor],
            ]);
        $this->adminService->expects(self::never())->method('changeRole');

        $tester = $this->tester();
        $exitCode = $tester->execute(['email' => 'already-admin@surgicalhub.test', 'actorEmail' => 'admin@surgicalhub.be']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already ROLE_ADMIN', $tester->getDisplay());
    }

    public function testRefusesSelfPromotionWithoutCallingChangeRole(): void
    {
        // Checked before the role/idempotency checks (see command) — same account for
        // both arguments must be refused purely on identity, regardless of its role.
        $same = $this->makeUser(1, 'admin@surgicalhub.be', ['ROLE_ADMIN']);
        $this->users->method('findOneByEmailInsensitive')
            ->willReturnMap([
                ['admin@surgicalhub.be', $same],
            ]);
        $this->adminService->expects(self::never())->method('changeRole');

        $tester = $this->tester();
        $exitCode = $tester->execute(['email' => 'admin@surgicalhub.be', 'actorEmail' => 'admin@surgicalhub.be']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('cannot promote themselves', $tester->getDisplay());
    }

    public function testSurfacesBusinessRuleErrorsFromChangeRoleAsCleanFailures(): void
    {
        $target = $this->makeUser(2, 'target@surgicalhub.test', ['ROLE_INSTRUMENTIST']);
        $actor = $this->makeUser(1, 'admin@surgicalhub.be', ['ROLE_ADMIN']);
        $this->users->method('findOneByEmailInsensitive')
            ->willReturnMap([
                ['target@surgicalhub.test', $target],
                ['admin@surgicalhub.be', $actor],
            ]);
        $this->adminService->method('changeRole')
            ->willThrowException(new BadRequestHttpException('Invalid role: ROLE_ADMIN'));

        $tester = $this->tester();
        $exitCode = $tester->execute(['email' => 'target@surgicalhub.test', 'actorEmail' => 'admin@surgicalhub.be']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid role', $tester->getDisplay());
    }
}
