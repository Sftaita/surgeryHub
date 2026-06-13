<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed',
    description: 'Purge all data and seed the database with demo users.'
)]
class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SurgicalHub — Seed');

        // ── 1. Purge toutes les tables (FK désactivées) ───────────────────────
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        $tables = $conn->fetchFirstColumn("SHOW TABLES");
        foreach ($tables as $table) {
            $conn->executeStatement("TRUNCATE TABLE `$table`");
        }

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        $io->success('Base de données purgée.');

        // ── 2. Création des utilisateurs ──────────────────────────────────────
        $users = [
            // Manager
            [
                'email'     => 'manager@surgeryhub.be',
                'firstname' => 'Sophie',
                'lastname'  => 'Moreau',
                'role'      => 'ROLE_MANAGER',
                'password'  => 'password',
            ],
            // Chirurgiens
            [
                'email'     => 'c.fontaine@surgeryhub.be',
                'firstname' => 'Charles',
                'lastname'  => 'Fontaine',
                'role'      => 'ROLE_SURGEON',
                'password'  => 'password',
                'specialties' => ['Genou', 'Hanche'],
            ],
            [
                'email'     => 'p.dubois@surgeryhub.be',
                'firstname' => 'Pierre',
                'lastname'  => 'Dubois',
                'role'      => 'ROLE_SURGEON',
                'password'  => 'password',
                'specialties' => ['Épaule', 'Main / Poignet'],
            ],
            [
                'email'     => 'a.lecomte@surgeryhub.be',
                'firstname' => 'Amélie',
                'lastname'  => 'Lecomte',
                'role'      => 'ROLE_SURGEON',
                'password'  => 'password',
                'specialties' => ['Rachis', 'Neurochirurgie'],
            ],
            [
                'email'     => 'j.maes@surgeryhub.be',
                'firstname' => 'Jacques',
                'lastname'  => 'Maes',
                'role'      => 'ROLE_SURGEON',
                'password'  => 'password',
                'specialties' => ['Cardiothoracique', 'Viscéral'],
            ],
            [
                'email'     => 'n.gerard@surgeryhub.be',
                'firstname' => 'Nathalie',
                'lastname'  => 'Gérard',
                'role'      => 'ROLE_SURGEON',
                'password'  => 'password',
                'specialties' => ['Gynécologie', 'Urologie'],
            ],
            // Instrumentistes
            [
                'email'     => 'thomas.lambert@surgeryhub.be',
                'firstname' => 'Thomas',
                'lastname'  => 'Lambert',
                'role'      => 'ROLE_INSTRUMENTIST',
                'password'  => 'password',
                'specialties' => ['Genou', 'Hanche', 'Épaule'],
            ],
            [
                'email'     => 'julie.simon@surgeryhub.be',
                'firstname' => 'Julie',
                'lastname'  => 'Simon',
                'role'      => 'ROLE_INSTRUMENTIST',
                'password'  => 'password',
                'specialties' => ['Rachis', 'Neurochirurgie'],
            ],
            [
                'email'     => 'marc.renard@surgeryhub.be',
                'firstname' => 'Marc',
                'lastname'  => 'Renard',
                'role'      => 'ROLE_INSTRUMENTIST',
                'password'  => 'password',
                'specialties' => ['Cardiothoracique', 'Viscéral'],
            ],
            [
                'email'     => 'claire.dupont@surgeryhub.be',
                'firstname' => 'Claire',
                'lastname'  => 'Dupont',
                'role'      => 'ROLE_INSTRUMENTIST',
                'password'  => 'password',
                'specialties' => ['Genou', 'Pied / Cheville'],
            ],
            [
                'email'     => 'kevin.adam@surgeryhub.be',
                'firstname' => 'Kévin',
                'lastname'  => 'Adam',
                'role'      => 'ROLE_INSTRUMENTIST',
                'password'  => 'password',
                'specialties' => ['Gynécologie', 'Urologie', 'Pédiatrique'],
            ],
        ];

        foreach ($users as $data) {
            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstname($data['firstname']);
            $user->setLastname($data['lastname']);
            $user->setRoles([$data['role']]);
            $user->setPassword($this->hasher->hashPassword($user, $data['password']));
            if (!empty($data['specialties'])) {
                $user->setSpecialties($data['specialties']);
            }
            $this->em->persist($user);
        }

        $this->em->flush();
        $io->success(sprintf('%d utilisateurs créés.', count($users)));

        $io->table(
            ['Email', 'Nom', 'Rôle', 'Mot de passe'],
            array_map(fn($u) => [
                $u['email'],
                $u['firstname'] . ' ' . $u['lastname'],
                $u['role'],
                $u['password'],
            ], $users)
        );

        return Command::SUCCESS;
    }
}
