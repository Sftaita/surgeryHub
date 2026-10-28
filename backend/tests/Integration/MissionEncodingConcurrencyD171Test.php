<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\HoursSource;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\EncodingConcurrencyException;
use App\Service\AuditService;
use App\Service\EncodingTakeoverService;
use App\Service\MissionEncodingLock;
use App\Service\MissionEncodingWorkflowService;
use App\Service\MissionExecutionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-171 — aucune écriture concurrente ne peut aboutir sur un encodage validé. Même méthode que
 * FinancialCalculationConcurrencyTest : connexions DBAL réellement distinctes, un « worker » tient
 * ou valide une transaction pendant que l'autre agit avec un innodb_lock_wait_timeout court — un
 * blocage réel se traduit par un timeout déterministe, jamais un pari sur l'ordonnancement.
 *
 * Fenêtre couverte : l'écriture a été AUTORISÉE sur une mission encore ouverte (lecture au début
 * de la requête), puis une validation est validée en base avant son écriture.
 */
final class MissionEncodingConcurrencyD171Test extends KernelTestCase
{
    private const LOCK_TIMEOUT_SECONDS = 2;

    private EntityManagerInterface $em;
    private array $created = ['missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $c = $this->freshEntityManager()->getConnection();
        $missions = implode(',', array_map('intval', $this->created['missions'])) ?: '0';
        $users = implode(',', array_map('intval', $this->created['users'])) ?: '0';
        $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
        $c->executeStatement("DELETE FROM mission_execution WHERE mission_id IN ($missions)");
        $c->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
        $c->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($users)");
        $c->executeStatement("DELETE FROM user WHERE id IN ($users)");
        foreach ($this->created['sites'] as $id) {
            $c->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return new \Doctrine\ORM\EntityManager(
            \Doctrine\DBAL\DriverManager::getConnection($this->em->getConnection()->getParams()),
            $this->em->getConfiguration(),
        );
    }

    private function executionService(EntityManagerInterface $em): MissionExecutionService
    {
        return new MissionExecutionService($em, new AuditService($em), new MissionEncodingLock($em));
    }

    private function user(string $role): User
    {
        $u = (new User())->setEmail('d171c-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles([$role])->setActive(true)->setFirstname('Conc')->setLastname('D171');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function mission(MissionStatus $status, User $instrumentist): Mission
    {
        $site = (new Hospital())->setName('D171C-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();
        $surgeon = $this->user('ROLE_SURGEON');

        $start = (new \DateTimeImmutable('-1 day'))->setTime(8, 0);
        $m = (new Mission())->setType(MissionType::BLOCK)->setSite($site)->setSurgeon($surgeon)->setCreatedBy($surgeon)
            ->setStartAt($start)->setEndAt($start->modify('+4 hours'))->setStatus($status)->setInstrumentist($instrumentist);
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();

        return $m;
    }

    private function setLockTimeout(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_TIMEOUT_SECONDS);
    }

    private function isLockTimeout(\Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if (str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205')) {
                return true;
            }
        }

        return false;
    }

    /** Validation committée par « un autre processus » : exactement ce qu'écrit validate(). */
    private function commitValidation(int $missionId): void
    {
        $this->freshEntityManager()->getConnection()->executeStatement(
            "UPDATE mission SET status = 'VALIDATED', encoding_locked_at = NOW() WHERE id = ?",
            [$missionId],
        );
    }

    private function executionRows(int $missionId): int
    {
        return (int) $this->freshEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_execution WHERE mission_id = ?', [$missionId]);
    }

    public function test_a_write_authorized_before_a_committed_validation_is_refused_and_writes_nothing(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $id = (int) $this->mission(MissionStatus::ENCODING_IN_PROGRESS, $instr)->getId();

        // A lit la mission encore ouverte (droits accordés sur cet état)…
        $emA = $this->freshEntityManager();
        $missionA = $emA->find(Mission::class, $id);
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $missionA->getStatus());

        // … une validation est validée en base avant son écriture…
        $this->commitValidation($id);

        // … son écriture est refusée sous le verrou, rien n'est écrit.
        $start = $missionA->getStartAt();
        try {
            $this->executionService($emA)->updateActuals($missionA, $emA->find(User::class, $instr->getId()), $start, $start->modify('+3 hours'), null, HoursSource::INSTRUMENTIST);
            self::fail('écriture acceptée sur un encodage validé entre-temps');
        } catch (ConflictHttpException $e) {
            self::assertSame(MissionEncodingLock::CONFLICT_MESSAGE, $e->getMessage());
        }
        self::assertSame(0, $this->executionRows($id), 'aucune heure écrite');
    }

    public function test_a_write_waits_for_an_in_flight_validation_then_is_refused(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $id = (int) $this->mission(MissionStatus::ENCODING_IN_PROGRESS, $instr)->getId();

        // B : transition en cours, verrou de la mission tenu (comme MissionEncodingLock::run()).
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $emB->getConnection()->fetchOne('SELECT id FROM mission WHERE id = ? FOR UPDATE', [$id]);

        // A : son écriture attend réellement le verrou (timeout court), rien n'est écrit.
        $emA = $this->freshEntityManager();
        $this->setLockTimeout($emA);
        $missionA = $emA->find(Mission::class, $id);
        $start = $missionA->getStartAt();
        $blocked = false;
        try {
            $this->executionService($emA)->updateActuals($missionA, $emA->find(User::class, $instr->getId()), $start, $start->modify('+3 hours'), null, HoursSource::INSTRUMENTIST);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeout($e);
        }
        self::assertTrue($blocked, "l'écriture doit attendre la transition en cours, jamais passer pendant celle-ci");

        // B valide et libère ; A retente avec ce qu'il avait lu avant : refusée.
        $emB->getConnection()->executeStatement("UPDATE mission SET status = 'VALIDATED', encoding_locked_at = NOW() WHERE id = ?", [$id]);
        $emB->getConnection()->commit();

        $emA2 = $this->freshEntityManager();
        $missionA2 = $emA2->find(Mission::class, $id);
        $missionA2->setStatus(MissionStatus::ENCODING_IN_PROGRESS)->setEncodingLockedAt(null); // état lu avant la validation
        $this->expectException(ConflictHttpException::class);
        try {
            $this->executionService($emA2)->updateActuals($missionA2, $emA2->find(User::class, $instr->getId()), $start, $start->modify('+3 hours'), null, HoursSource::INSTRUMENTIST);
        } finally {
            self::assertSame(0, $this->executionRows($id));
        }
    }

    public function test_complete_and_validate_waits_for_an_in_flight_write_then_refuses_the_stale_revision(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission(MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $id = (int) $mission->getId();
        $revision = self::getContainer()->get(EncodingTakeoverService::class)->revision($mission);

        // B : écriture en cours (verrou de la mission tenu, heures insérées, non validées).
        $emB = $this->freshEntityManager();
        $emB->getConnection()->beginTransaction();
        $emB->getConnection()->fetchOne('SELECT id FROM mission WHERE id = ? FOR UPDATE', [$id]);
        $emB->getConnection()->executeStatement(
            'INSERT INTO mission_execution (mission_id, actual_start_at, actual_end_at, actual_duration_minutes, hours_source, created_at, updated_at) VALUES (?, ?, ?, 180, ?, NOW(), NOW())',
            [$id, $mission->getStartAt()->format('Y-m-d H:i:s'), $mission->getStartAt()->modify('+3 hours')->format('Y-m-d H:i:s'), 'INSTRUMENTIST'],
        );

        // A : « Terminer et valider » attend la fin de l'écriture (jamais validé pendant celle-ci).
        $workflow = self::getContainer()->get(MissionEncodingWorkflowService::class);
        $this->setLockTimeout($this->em);
        $blocked = false;
        try {
            $workflow->completeAndValidate($mission, $this->em->find(User::class, $manager->getId()), 'Bloc sans matériel', $revision, true);
        } catch (\Throwable $e) {
            $blocked = $this->isLockTimeout($e);
        }
        self::assertTrue($blocked, 'la validation doit attendre une écriture en cours');

        // B valide son écriture ; A retente sur ce qu'il avait relu : l'encodage a changé → 409.
        $emB->getConnection()->commit();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $fresh = $this->em->find(Mission::class, $id);
        try {
            self::getContainer()->get(MissionEncodingWorkflowService::class)
                ->completeAndValidate($fresh, $this->em->find(User::class, $manager->getId()), 'Bloc sans matériel', $revision, true);
            self::fail('validation acceptée sur un contenu que le manager n’a pas vu');
        } catch (EncodingConcurrencyException $e) {
            self::assertSame(EncodingConcurrencyException::CONCURRENT_MODIFICATION, $e->getErrorCode());
        }
        $status = $this->freshEntityManager()->getConnection()->fetchOne('SELECT status FROM mission WHERE id = ?', [$id]);
        self::assertSame('ENCODING_IN_PROGRESS', $status, 'rien validé');
        self::assertSame(0, (int) $this->freshEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_event WHERE mission_id = ? AND event_type IN ('MISSION_ENCODING_TAKEN_OVER','MISSION_ENCODING_COMPLETED','MISSION_ENCODING_VALIDATED')", [$id]));
    }

    public function test_two_concurrent_hour_declarations_after_a_takeover_never_overwrite_each_other(): void
    {
        $instr = $this->user('ROLE_INSTRUMENTIST');
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission(MissionStatus::VALIDATED, $instr);
        $mission->setEncodingLockedAt(new \DateTimeImmutable('-1 hour'))->setEncodingValidatedWithoutHoursAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();
        $id = (int) $mission->getId();
        $start = $mission->getStartAt();

        // A et B lisent la mission : les heures manquent, les deux sont autorisés.
        $emA = $this->freshEntityManager();
        $missionA = $emA->find(Mission::class, $id);
        $emB = $this->freshEntityManager();
        $missionB = $emB->find(Mission::class, $id);

        // B déclare (manager) et valide sa transaction.
        $this->executionService($emB)->updateActuals($missionB, $emB->find(User::class, $manager->getId()), $start, $start->modify('+2 hours'), null, HoursSource::MANAGER);

        // A (instrumentiste) écrirait par-dessus : refusé sous le verrou, les heures de B restent.
        try {
            $this->executionService($emA)->updateActuals($missionA, $emA->find(User::class, $instr->getId()), $start, $start->modify('+5 hours'), null, HoursSource::INSTRUMENTIST);
            self::fail('deuxième déclaration acceptée');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString("viennent d'être déclarées", $e->getMessage());
        }
        $minutes = (int) $this->freshEntityManager()->getConnection()->fetchOne('SELECT actual_duration_minutes FROM mission_execution WHERE mission_id = ?', [$id]);
        self::assertSame(120, $minutes, 'les heures déclarées en premier ne sont pas écrasées');
    }
}
