<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\Session\DeviceSessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * D-157 — security event (compromised device, password changed outside the app…): closes every
 * persistent session of one user. Access tokens already issued are refused at their next use
 * (SessionAccessTokenListener).
 */
#[AsCommand(
    name: 'app:auth:revoke-sessions',
    description: 'Revoke every persistent session (refresh tokens, all devices) of a user.'
)]
class RevokeUserSessionsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DeviceSessionService $sessions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'User email.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findOneByEmailInsensitive(trim((string) $input->getArgument('email')));
        if ($user === null) {
            $output->writeln('<error>No user with this email.</error>');

            return Command::FAILURE;
        }

        $count = $this->sessions->revokeAllForUser($user);
        $output->writeln(sprintf('%d refresh token(s) revoked for user #%d.', $count, $user->getId()));

        return Command::SUCCESS;
    }
}
