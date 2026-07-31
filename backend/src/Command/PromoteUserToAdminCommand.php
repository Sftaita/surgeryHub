<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserAdministrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Procédure contrôlée et auditable pour attribuer ROLE_ADMIN à un compte existant
 * (audit PWA/mobile/admin 2026-07-29, Lot 7) — remplace une migration applicative
 * générale qui aurait contenu une adresse email personnelle en dur. Réutilise
 * exactement UserAdministrationService::changeRole() (même chemin que l'action admin
 * "Changer le rôle" dans l'UI) : aucune règle métier dupliquée, mêmes garanties
 * (recherche insensible à la casse via findOneByEmailInsensitive, jamais de doublon —
 * setRoles() sur l'entité déjà managée est un UPDATE, jamais un INSERT —,
 * SiteMembership préservées, UserAuditEvent créé).
 *
 * Idempotente : si le compte cible est déjà ROLE_ADMIN, ne fait rien (SUCCESS, pas de
 * ré-audit ni de flush inutile). Exige un compte acteur ROLE_ADMIN existant (traçabilité
 * réelle — jamais un pseudo-acteur "système").
 *
 * Exemple (déploiement production, voir docs/production.md pour le pattern
 * `docker exec` déjà utilisé pour app:user:create) :
 *   docker exec surgicalhub-php php bin/console app:user:promote-to-admin \
 *     samy.ftaita89@gmail.com admin@surgicalhub.be --env=prod
 */
#[AsCommand(
    name: 'app:user:promote-to-admin',
    description: 'Attribue ROLE_ADMIN à un compte existant (procédure contrôlée, auditable, idempotente).',
)]
class PromoteUserToAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAdministrationService $adminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email (insensible à la casse) du compte à promouvoir ROLE_ADMIN.')
            ->addArgument('actorEmail', InputArgument::REQUIRED, "Email d'un compte ROLE_ADMIN existant — l'auteur de l'action pour la piste d'audit (UserAuditEvent).")
            ->setHelp(
                "Attribue ROLE_ADMIN à un compte utilisateur existant, de façon contrôlée et auditable.\n\n"
                . "  - Recherche insensible à la casse ; échoue si le compte n'existe pas (ne crée jamais de doublon).\n"
                . "  - Préserve les SiteMembership existantes et toutes les autres données du compte.\n"
                . "  - Idempotente : si le compte cible est déjà ROLE_ADMIN, ne fait rien et le signale.\n"
                . "  - Crée un UserAuditEvent (acteur = actorEmail, doit déjà être ROLE_ADMIN).\n\n"
                . "Exemple (production) :\n"
                . "  docker exec surgicalhub-php php bin/console app:user:promote-to-admin samy.ftaita89@gmail.com admin@surgicalhub.be --env=prod\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = strtolower(trim((string) $input->getArgument('email')));
        $actorEmail = strtolower(trim((string) $input->getArgument('actorEmail')));

        if ($email === '' || $actorEmail === '') {
            $output->writeln('<error>Both email and actorEmail are required.</error>');
            return Command::FAILURE;
        }

        $target = $this->users->findOneByEmailInsensitive($email);
        if ($target === null) {
            $output->writeln(sprintf('<error>No user found with email: %s</error>', $email));
            return Command::FAILURE;
        }

        $actor = $this->users->findOneByEmailInsensitive($actorEmail);
        if ($actor === null) {
            $output->writeln(sprintf('<error>No actor account found with email: %s</error>', $actorEmail));
            return Command::FAILURE;
        }

        // Checked before the role/idempotency checks below: identity takes priority —
        // "promoting yourself" is refused regardless of your current role, matching
        // UserAdministrationService::changeRole()'s own self-role-change guard.
        if ($target->getId() === $actor->getId()) {
            $output->writeln('<error>An admin cannot promote themselves (self-role-change guard in UserAdministrationService::changeRole) — use a different actor account.</error>');
            return Command::FAILURE;
        }

        if (!in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
            $output->writeln(sprintf(
                '<error>Actor account %s is not ROLE_ADMIN — refusing (the audit trail must attribute this to a real, already-privileged admin).</error>',
                $actor->getEmail(),
            ));
            return Command::FAILURE;
        }

        if (in_array('ROLE_ADMIN', $target->getRoles(), true)) {
            $output->writeln(sprintf('<info>%s is already ROLE_ADMIN — nothing to do (idempotent).</info>', $target->getEmail()));
            return Command::SUCCESS;
        }

        try {
            $this->adminService->changeRole($target, 'ROLE_ADMIN', $actor);
        } catch (HttpExceptionInterface $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%s is now ROLE_ADMIN (audited, actor: %s).</info>',
            $target->getEmail(),
            $actor->getEmail(),
        ));

        return Command::SUCCESS;
    }
}
