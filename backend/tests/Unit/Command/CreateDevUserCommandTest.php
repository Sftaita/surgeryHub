<?php

namespace App\Tests\Unit\Command;

use App\Command\CreateDevUserCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateDevUserCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $repository;
    private UserPasswordHasherInterface&MockObject $hasher;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);

        $this->em->method('getRepository')
            ->with(User::class)
            ->willReturn($this->repository);

        $this->hasher->method('hashPassword')
            ->willReturnCallback(fn (User $user, string $plain) => 'hashed:'.$plain);
    }

    private function makeCommand(string $appEnv = 'dev'): Command
    {
        return new CreateDevUserCommand($this->em, $this->hasher, $appEnv);
    }

    public function testCreatesUserWhenAbsent(): void
    {
        $this->repository->method('findOneBy')
            ->with(['email' => 'admin@surgicalhub.local'])
            ->willReturn(null);

        $persisted = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(User::class))
            ->willReturnCallback(function (User $user) use (&$persisted) {
                $persisted = $user;
            });
        $this->em->expects($this->once())->method('flush');

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Created dev user "admin@surgicalhub.local"', $tester->getDisplay());

        $this->assertNotNull($persisted);
        $this->assertSame('admin@surgicalhub.local', $persisted->getEmail());
        $this->assertContains('ROLE_MANAGER', $persisted->getRoles());
        $this->assertTrue($persisted->isActive());
        $this->assertSame('hashed:ChangeMe123!', $persisted->getPassword());
    }

    public function testNoDuplicateWhenUserAlreadyExists(): void
    {
        $existing = new User();
        $existing->setEmail('admin@surgicalhub.local');
        $existing->setRoles(['ROLE_INSTRUMENTIST']);
        $existing->setPassword('old-hash');

        $this->repository->method('findOneBy')
            ->with(['email' => 'admin@surgicalhub.local'])
            ->willReturn($existing);

        // Must not create a new (duplicate) entity
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $tester = new CommandTester($this->makeCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Updated dev user "admin@surgicalhub.local"', $tester->getDisplay());

        // Role and password are refreshed on the existing entity
        $this->assertContains('ROLE_MANAGER', $existing->getRoles());
        $this->assertNotContains('ROLE_INSTRUMENTIST', $existing->getRoles());
        $this->assertSame('hashed:ChangeMe123!', $existing->getPassword());
        $this->assertTrue($existing->isActive());
    }

    public function testPasswordIsHashedNotStoredInPlainText(): void
    {
        $this->repository->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['--password' => 'SuperSecret42!']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testCustomRoleIsApplied(): void
    {
        $persisted = null;
        $this->repository->method('findOneBy')->willReturn(null);
        $this->em->method('persist')->willReturnCallback(function (User $user) use (&$persisted) {
            $persisted = $user;
        });
        $this->em->method('flush');

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['--role' => 'ROLE_ADMIN']);

        $this->assertContains('ROLE_ADMIN', $persisted->getRoles());
        $this->assertNotContains('ROLE_MANAGER', $persisted->getRoles());
    }

    public function testRefusesToRunInProd(): void
    {
        $this->em->expects($this->never())->method('getRepository');

        $tester = new CommandTester($this->makeCommand('prod'));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('cannot run with APP_ENV=prod', $tester->getDisplay());
    }
}
