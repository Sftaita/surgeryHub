<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-dev-user',
    description: 'Create or update a local development user with a hashed password (idempotent, dev/test only).'
)]
class CreateDevUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email (unique).', 'admin@surgicalhub.local')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Plain password (will be hashed).', 'ChangeMe123!')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Main role (e.g. ROLE_ADMIN, ROLE_MANAGER).', 'ROLE_MANAGER')
            ->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'First name.', 'Dev')
            ->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'Last name.', 'Admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->appEnv === 'prod') {
            $io->error('This command is reserved for local development/test environments and cannot run with APP_ENV=prod.');
            return Command::FAILURE;
        }

        $email = strtolower(trim((string) $input->getOption('email')));
        $plainPassword = (string) $input->getOption('password');
        $role = strtoupper(trim((string) $input->getOption('role')));
        $firstname = (string) $input->getOption('firstname');
        $lastname = (string) $input->getOption('lastname');

        if ($email === '') {
            $io->error('Email cannot be empty.');
            return Command::FAILURE;
        }

        if ($plainPassword === '') {
            $io->error('Password cannot be empty.');
            return Command::FAILURE;
        }

        if (!str_starts_with($role, 'ROLE_')) {
            $io->error('Role must start with "ROLE_" (e.g. ROLE_MANAGER).');
            return Command::FAILURE;
        }

        $repository = $this->em->getRepository(User::class);
        $user = $repository->findOneBy(['email' => $email]);

        $isNew = $user === null;
        if ($isNew) {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstname($firstname);
            $user->setLastname($lastname);
        }

        $user->setRoles([$role]);
        $user->setActive(true);
        $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);

        if ($isNew) {
            $this->em->persist($user);
        }
        $this->em->flush();

        $io->success(sprintf(
            '%s dev user "%s" with role %s.',
            $isNew ? 'Created' : 'Updated',
            $email,
            $role
        ));

        return Command::SUCCESS;
    }
}
