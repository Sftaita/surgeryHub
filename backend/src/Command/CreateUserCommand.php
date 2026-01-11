<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user with a hashed password (dev utility).'
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email (unique).')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password (will be hashed).')
            ->addArgument(
                'role',
                InputArgument::OPTIONAL,
                'Main role (e.g. ROLE_ADMIN, ROLE_MANAGER, ROLE_INSTRUMENTIST). Defaults to ROLE_ADMIN.',
                'ROLE_ADMIN'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = strtolower(trim((string) $input->getArgument('email')));
        $plainPassword = (string) $input->getArgument('password');
        $role = strtoupper(trim((string) $input->getArgument('role')));

        if ($email === '') {
            $output->writeln('<error>Email cannot be empty.</error>');
            return Command::FAILURE;
        }

        if ($plainPassword === '') {
            $output->writeln('<error>Password cannot be empty.</error>');
            return Command::FAILURE;
        }

        if ($role === '' || !str_starts_with($role, 'ROLE_')) {
            $output->writeln('<error>Role must start with "ROLE_" (e.g. ROLE_ADMIN).</error>');
            return Command::FAILURE;
        }

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $output->writeln(sprintf('<error>User already exists with email: %s</error>', $email));
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);

        // Note: getRoles() always adds ROLE_USER, so we only store the "main" roles array here.
        // Avoid storing ROLE_USER explicitly (optional), but it's not harmful if you do.
        $user->setRoles([$role]);

        $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);

        // Keep defaults: active=true, defaultCurrency=EUR, employmentType=null, etc.
        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('<info>User created successfully.</info>');
        $output->writeln(sprintf('Email: %s', $user->getEmail()));
        $output->writeln(sprintf('Roles stored: [%s] (ROLE_USER is always added at runtime)', implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
